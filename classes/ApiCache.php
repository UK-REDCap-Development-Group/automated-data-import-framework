<?php

namespace UKModules\ADIF;

use UKModules\ADIF\Cache\AdifSettingsSource;
use UKModules\ADIF\Cache\CacheManager;
use UKModules\ADIF\Cache\FetchContext;
use UKModules\ADIF\Cache\FetchMode;
use UKModules\ADIF\Cache\FetchResult;
use UKModules\ADIF\Cache\KeyBuilder;
use UKModules\ADIF\Cache\PayloadCipher;
use UKModules\ADIF\Cache\PolicyResolver;
use UKModules\ADIF\Cache\PolicyViolation;
use UKModules\ADIF\Cache\Provider\ConfiguredApiProvider;
use UKModules\ADIF\Cache\Provider\ProviderRegistry;
use UKModules\ADIF\Cache\Storage\TableStorage;

require_once __DIR__ . '/Cache/autoload.php';

// Mixed into UKModules\ADIF\ADIF alongside Proxy and Import. Puts the cache layer in front of
// dispatchApiRequest(): each configured API source becomes a ConfiguredApiProvider, and the
// project's per-source cache settings decide whether a request is served locally or sent out.
// See docs/caching.md for the design.
trait ApiCache
{
    private ?CacheManager $apiCacheManager = null;
    /** @var array<int, ConfiguredApiProvider> sourceIndex => provider */
    private array $apiCacheProviders = [];

    // Fetches the decoded JSON for one (source, already-piped path) request through the cache.
    // Returns ['json' => array|null, 'status' => FetchResult::*, 'message' => ?string,
    //          'provenance' => ?array, 'notices' => string[]]
    public function fetchApiJson($projectId, $record, $sourceIndex, $apiPath, ?FetchMode $modeOverride = null)
    {
        $this->apiCacheManager($projectId);
        $provider = $this->apiCacheProviders[(int) $sourceIndex] ?? null;
        if ($provider === null) {
            throw new \Exception("No API source is configured at index \"$sourceIndex\".");
        }

        try {
            $report = $this->apiCacheManager->fetch(
                $provider->id(),
                ['req' => ['path' => (string) $apiPath]],
                new FetchContext((int) $projectId, $record === null ? null : (string) $record, $modeOverride)
            );
        } catch (PolicyViolation $e) {
            return ['json' => null, 'status' => FetchResult::ERROR, 'message' => $e->getMessage(),
                    'provenance' => null, 'notices' => []];
        }

        $result = $report->results['req'];
        $notices = $report->policy->notices;
        if ($result->status === FetchResult::STALE && $result->message) {
            $notices[] = $result->message;
        }

        return [
            'json'       => $result->value(),
            'status'     => $result->status,
            'message'    => $result->message,
            'provenance' => $result->entry ? [
                'version'      => $result->entry->version,
                'retrieved_at' => date('Y-m-d H:i:s', $result->entry->retrievedAt),
                'from_cache'   => $result->status !== FetchResult::FETCHED,
            ] : null,
            'notices'    => $notices,
        ];
    }

    // Built once per request. Providers are rebuilt from the project's current settings each
    // request, so config changes take effect immediately.
    private function apiCacheManager($projectId)
    {
        if ($this->apiCacheManager !== null) {
            return $this->apiCacheManager;
        }

        // Sources whose schema paths pipe in record values ([placeholders]) are treated as
        // identifying unless the designer says the piped values are public codes.
        $placeholderSources = [];
        foreach ($this->getApiFields() as $field) {
            if (preg_match('/\[[a-z][a-z0-9_]*\]/', (string) $field['path'])) {
                $placeholderSources[$field['source']] = true;
            }
        }

        $public   = $this->getProjectSetting('lookups-are-public', $projectId) ?: [];
        $versions = $this->getProjectSetting('data-version', $projectId) ?: [];
        $send     = fn($url, $key, $path) => $this->sendApiRequest($url, $key, $path);

        $registry = new ProviderRegistry();
        $idToIndex = [];
        foreach ($this->getApiSources() as $source) {
            $index = $source['index'];
            $full  = $this->getApiSource($index);
            $provider = new ConfiguredApiProvider(
                sourceIndex:      $index,
                url:              $full['url'],
                apiKey:           $full['apiKey'],
                send:             $send,
                lookupsArePublic: !empty($public[$index]),
                usesPlaceholders: isset($placeholderSources[$index]),
                dataVersion:      $versions[$index] ?? null,
            );
            // Two sources with the same URL share one provider id; the first one's settings win.
            if (!isset($idToIndex[$provider->id()])) {
                $registry->register($provider);
                $idToIndex[$provider->id()] = $index;
            }
            $this->apiCacheProviders[$index] = $registry->get($provider->id());
        }

        $secret = $this->apiCacheSecret();
        $this->apiCacheManager = new CacheManager(
            $registry,
            new PolicyResolver(new AdifSettingsSource($this, $idToIndex)),
            $this->apiCacheStorage(),
            new KeyBuilder($secret),
            new PayloadCipher($secret)
        );
        return $this->apiCacheManager;
    }

    private function apiCacheStorage()
    {
        $storage = new TableStorage(fn($sql, $params) => $this->query($sql, $params));
        // Created on first use as well as on enable, so instances that enabled the module before
        // this table existed pick it up without a re-enable.
        if ((int) $this->getSystemSetting('adif-cache-schema-version') < TableStorage::SCHEMA_VERSION) {
            $storage->ensureTable();
            $this->setSystemSetting('adif-cache-schema-version', TableStorage::SCHEMA_VERSION);
        }
        return $storage;
    }

    // Secret for hashing identifying cache keys and encrypting their values. Preferred sources keep it out of the database, so a
    // database dump alone can't be used to test guessed addresses against the cached keys.
    private function apiCacheSecret()
    {
        $env = getenv('ADIF_CACHE_SECRET');
        if (is_string($env) && strlen($env) >= 32) {
            return $env;
        }
        // REDCap's database.php defines $salt, which lives on disk rather than in the database
        if (!empty($GLOBALS['salt'])) {
            return hash('sha256', 'adif-api-cache|' . $GLOBALS['salt']);
        }
        // Last resort: a generated secret stored as a system setting (weaker: same database)
        $stored = $this->getSystemSetting('adif-cache-generated-secret');
        if (!$stored) {
            $stored = bin2hex(random_bytes(32));
            $this->setSystemSetting('adif-cache-generated-secret', $stored);
        }
        return $stored;
    }

    function redcap_module_system_enable($version)
    {
        $this->apiCacheStorage();
    }
}
