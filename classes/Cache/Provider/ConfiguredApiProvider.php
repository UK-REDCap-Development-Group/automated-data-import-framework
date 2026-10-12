<?php
namespace UKModules\ADIF\Cache\Provider;

use UKModules\ADIF\Cache\CachePolicy;

/**
 * Wraps one entry of the "api-sources" project setting so the cache layer can
 * treat it like any other provider. One input = one request path with the
 * record's [placeholder] values already piped in, e.g. "?locationid=21197960100".
 *
 * Unlike code-defined providers, the privacy declaration comes from the
 * project designer, because only they know what the piped values are:
 *   - Paths with no [placeholders] are always public.
 *   - Paths with placeholders are treated as identifying unless the designer
 *     ticks "the values piped into this source are public codes".
 *
 * Max scope follows from that: identifying -> project; public with an API key
 * -> project (responses may be licensed or account-specific); public and
 * keyless -> system, so projects calling the same open endpoint share results.
 */
final class ConfiguredApiProvider implements DataProvider
{
    /** @var callable(string $baseUrl, string $apiKey, string $path): array */
    private $send;

    /**
     * @param callable $send same contract as Proxy::sendApiRequest()
     */
    public function __construct(
        private int     $sourceIndex,
        private string  $url,
        private string  $apiKey,
        callable        $send,
        private bool    $lookupsArePublic = false,
        private bool    $usesPlaceholders = true,
        private ?string $dataVersion = null,
    ) {
        $this->send = $send;
    }

    /**
     * Derived from the URL rather than the source's position, so reordering
     * sources in the config dialog doesn't mix up their caches, and pointing a
     * source at a different URL starts a fresh cache.
     */
    public function id(): string
    {
        return 'src_' . substr(sha1(strtolower(rtrim($this->url, '/'))), 0, 12);
    }

    public function label(): string
    {
        return $this->url;
    }

    public function sourceIndex(): int
    {
        return $this->sourceIndex;
    }

    public function isIdentifying(): bool
    {
        return $this->usesPlaceholders && !$this->lookupsArePublic;
    }

    public function cachePolicy(): CachePolicy
    {
        if ($this->isIdentifying()) {
            return new CachePolicy(
                keySensitivity: CachePolicy::SENSITIVITY_IDENTIFYING,
                maxScope:       CachePolicy::SCOPE_PROJECT,
                freshness:      CachePolicy::FRESHNESS_IMMUTABLE,
            );
        }
        return new CachePolicy(
            keySensitivity: CachePolicy::SENSITIVITY_PUBLIC,
            maxScope:       $this->apiKey === '' ? CachePolicy::SCOPE_SYSTEM : CachePolicy::SCOPE_PROJECT,
            freshness:      CachePolicy::FRESHNESS_IMMUTABLE,
        );
    }

    public function sendsIdentifyingData(): bool
    {
        return $this->isIdentifying();
    }

    /**
     * A free-text label the designer sets ("2023", "v2"). Changing it moves the
     * source onto a fresh cache without deleting the old entries.
     */
    public function availableVersions(): array
    {
        $v = trim((string) $this->dataVersion);
        return [$v !== '' ? $v : 'default'];
    }

    /** input: ['path' => already-piped request path] */
    public function normalizeInput(array $input): ?string
    {
        $path = trim((string) ($input['path'] ?? ''));
        return $path; // '' is valid: the source URL itself is the request
    }

    public function fetch(array $inputs, string $version): array
    {
        // Generic APIs have no batch endpoint, so one request per distinct path.
        // CacheManager has already deduplicated inputs by cache key.
        $out = [];
        foreach ($inputs as $key => $input) {
            $result = ($this->send)($this->url, $this->apiKey, (string) ($input['path'] ?? ''));

            if (empty($result['success'])) {
                throw new ProviderUnavailable(($result['requestUrl'] ?? $this->url) . ': '
                    . ($result['error'] ?: 'request failed'));
            }
            if (!is_array($result['bodyJson'] ?? null)) {
                throw new ProviderUnavailable(($result['requestUrl'] ?? $this->url) . ': response was not JSON');
            }
            // Only the decoded body is cached: no headers, which can carry tokens or cookies.
            $out[$key] = $result['bodyJson'];
        }
        return $out;
    }
}
