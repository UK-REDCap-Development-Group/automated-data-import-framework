<?php
namespace UKModules\ADIF\Cache;

/**
 * Reads cache choices from ADIF's own config.json settings.
 *
 * System (Control Center) settings apply to every source:
 *   adif-cache-enabled            checkbox  – whether anything is cached on this server
 *   adif-cache-max-scope          dropdown  – record | project | system
 *
 * Project settings are sub-settings of the repeatable "api-sources" group, so
 * each comes back as an array indexed by source position:
 *   cache-enabled (the project's opt-in for that source), cache-mode,
 *   cache-max-age-days, cache-on-failure, cache-scope
 *
 * Caching is opt-in at both levels: a source is cached only when the server
 * has caching enabled AND the project ticked that source. ADIF doesn't block
 * outbound requests, so network access and identifying egress are always allowed.
 * (lookups-are-public and data-version are read by ConfiguredApiProvider.)
 */
final class AdifSettingsSource implements SettingsSource
{
    private array $projectCache = [];

    /**
     * @param object $module the ADIF AbstractExternalModule instance
     * @param array<string,int> $sourceIndexById providerId => api-sources index
     */
    public function __construct(private object $module, private array $sourceIndexById) {}

    public function system(string $providerId, string $key, mixed $default = null): mixed
    {
        $m = $this->module;
        return match ($key) {
            'enabled'                  => true,
            'caching_enabled'          => (bool) $m->getSystemSetting('adif-cache-enabled'),
            'allow_network'            => true,
            'allow_identifying_egress' => true,
            'allowed_modes'            => array_column(FetchMode::cases(), 'value'),
            'max_scope'                => $m->getSystemSetting('adif-cache-max-scope') ?: null,
            default                    => $default,
        };
    }

    public function project(int $projectId, string $providerId, string $key, mixed $default = null): mixed
    {
        $index = $this->sourceIndexById[$providerId] ?? null;
        if ($index === null) {
            return $default;
        }
        if ($key === 'allow_identifying_egress') {
            // The designer configured this source and classified its piped values; that is the opt-in.
            return true;
        }
        $setting = [
            'caching_enabled' => 'cache-enabled',
            'mode'         => 'cache-mode',
            'max_age_days' => 'cache-max-age-days',
            'failure_mode' => 'cache-on-failure',
            'cache_scope'  => 'cache-scope',
        ][$key] ?? null;
        if ($setting === null) {
            return $default;
        }

        $this->projectCache[$projectId][$setting] ??=
            (array) ($this->module->getProjectSetting($setting, $projectId) ?: []);
        $value = $this->projectCache[$projectId][$setting][$index] ?? null;

        if ($key === 'caching_enabled') {
            // Unticked (or never saved) means not cached
            return !empty($value);
        }
        if ($value === null || $value === '') {
            // Sharing across projects is opt-in, even for public data.
            return $key === 'cache_scope' ? CachePolicy::SCOPE_PROJECT : $default;
        }
        if ($key === 'max_age_days') {
            return is_numeric($value) && $value > 0 ? (int) $value : $default;
        }
        return $value;
    }
}
