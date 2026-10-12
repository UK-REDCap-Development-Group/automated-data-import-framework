<?php
namespace UKModules\ADIF\Cache;

use UKModules\ADIF\Cache\Provider\DataProvider;

/**
 * Layers the four sources of control, each only able to TIGHTEN privacy:
 *
 *   1. Provider declaration  – hard ceiling (sensitivity, max scope, freshness)
 *   2. Admin (system)        – what is allowed on this server
 *   3. Project               – what this study chooses
 *   4. Run override          – one-off, e.g. "Refresh now" (must be allowed by 2)
 *
 * Freshness choices (mode, max age, version) are user preferences and flow
 * downward normally. Privacy choices (network egress, scope) can only narrow.
 *
 * Setting keys (per provider):
 *   system:  enabled, allow_network, allow_identifying_egress,
 *            allowed_modes (string[]), default_mode, max_scope
 *   project: mode, max_age_days, failure_mode, version,
 *            allow_identifying_egress, cache_scope
 */
final class PolicyResolver
{
    public function __construct(private SettingsSource $settings) {}

    public function resolve(DataProvider $provider, FetchContext $ctx): EffectivePolicy
    {
        $id      = $provider->id();
        $pid     = $ctx->projectId;
        $ceiling = $provider->cachePolicy();
        $notices = [];

        if (!$this->settings->system($id, 'enabled', true)) {
            throw new PolicyViolation("Provider '$id' is disabled by the administrator.");
        }

        // ---- Mode ---------------------------------------------------------
        $allowed = array_map(
            fn($m) => FetchMode::from($m),
            $this->settings->system($id, 'allowed_modes', array_column(FetchMode::cases(), 'value'))
        );
        $adminDefault = FetchMode::tryFrom((string) $this->settings->system($id, 'default_mode', ''))
            ?? FetchMode::CacheFirst;
        $projectMode  = FetchMode::tryFrom((string) $this->settings->project($pid, $id, 'mode', ''));

        if ($ctx->modeOverride !== null) {
            // Explicit user action: refuse rather than silently do something else.
            if (!in_array($ctx->modeOverride, $allowed, true)) {
                throw new PolicyViolation("Mode '{$ctx->modeOverride->value}' is not allowed for '$id' on this server.");
            }
            $mode = $ctx->modeOverride;
        } else {
            $mode = $projectMode ?? $adminDefault;
            if (!in_array($mode, $allowed, true)) {
                // A stored setting can become invalid when an admin changes policy later.
                $notices[] = "Project mode '{$mode->value}' is no longer allowed; using '{$adminDefault->value}'.";
                $mode = $adminDefault;
            }
        }

        // ---- Network egress gates (privacy: can only narrow) --------------
        if ($mode->usesNetwork() && !$this->settings->system($id, 'allow_network', true)) {
            $notices[] = 'Administrator has disabled network access for this source; running offline.';
            $mode = FetchMode::Offline;
        }
        if ($mode->usesNetwork() && $provider->sendsIdentifyingData()) {
            $adminOk   = (bool) $this->settings->system($id, 'allow_identifying_egress', false);
            $projectOk = (bool) $this->settings->project($pid, $id, 'allow_identifying_egress', false);
            if (!($adminOk && $projectOk)) {
                $notices[] = 'This source sends identifying data off-server and has not been approved '
                    . ($adminOk ? 'for this project' : 'by the administrator') . '; running offline.';
                $mode = FetchMode::Offline;
            }
        }

        // ---- Scope (privacy: narrowest wins) ------------------------------
        $scope = $ceiling->maxScope;
        foreach ([
            $this->settings->system($id, 'max_scope'),
            $this->settings->project($pid, $id, 'cache_scope'),
        ] as $s) {
            if ($s !== null) {
                $scope = CachePolicy::narrowerScope($scope, $s);
            }
        }
        if ($scope === CachePolicy::SCOPE_RECORD && $ctx->recordId === null) {
            throw new PolicyViolation("Provider '$id' caches at record scope; a record id is required.");
        }

        // ---- Freshness ----------------------------------------------------
        $cachingEnabled = $ceiling->freshness !== CachePolicy::FRESHNESS_NONE;
        $maxAgeDays     = $this->settings->project($pid, $id, 'max_age_days');
        $maxAge         = $maxAgeDays !== null ? (int) $maxAgeDays * 86400 : $ceiling->ttlSeconds;
        if ($mode === FetchMode::RefreshIfStale && $maxAge === null) {
            if ($ceiling->freshness === CachePolicy::FRESHNESS_IMMUTABLE) {
                $notices[] = 'This source is versioned and never goes stale; "refresh if stale" behaves like "cache first". '
                    . 'Choose a newer version, or "Always fetch", to get new data.';
            }
            $mode = FetchMode::CacheFirst;
        }

        // ---- Version ------------------------------------------------------
        $versions = $provider->availableVersions();
        $pinned   = (string) $this->settings->project($pid, $id, 'version', 'latest');
        if ($pinned === 'latest') {
            $version = $versions[0];
        } elseif (in_array($pinned, $versions, true)) {
            $version = $pinned;
        } else {
            $notices[] = "Pinned version '$pinned' is unavailable; using '{$versions[0]}'.";
            $version = $versions[0];
        }

        $failure = FailureMode::tryFrom((string) $this->settings->project($pid, $id, 'failure_mode', ''))
            ?? FailureMode::UseStale;

        return new EffectivePolicy($mode, $failure, $cachingEnabled, $scope, $maxAge, $version, $notices);
    }
}
