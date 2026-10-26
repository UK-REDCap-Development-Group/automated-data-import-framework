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

    /** @var array[] metadata for every HTTP call made through the cache this request; see apiCallMetadata() */
    private array $apiCallTrace = [];

    // Fetches the decoded JSON for one (source, already-piped path) request through the cache.
    // Returns ['json' => array|null, 'status' => FetchResult::*, 'message' => ?string,
    //          'provenance' => ?array, 'notices' => string[],
    //          'calls' => apiCallMetadata()[] for the requests actually sent (none when answered from the cache),
    //          'identifying' => whether the piped path carries identifying values, so must be kept out of logs]
    // 'message' can contain the request URL; use the calls' 'error' for anything that's logged.
    public function fetchApiJson($projectId, $record, $sourceIndex, $apiPath, ?FetchMode $modeOverride = null)
    {
        // Uploaded tables are local files: nothing to cache, and their "table:<id>" source isn't an index
        if ($this->isDataTableSource($sourceIndex)) {
            $response = $this->dispatchApiRequest($sourceIndex, $apiPath);
            $call = self::apiCallMetadata($response);
            return [
                'json'        => $call['error'] === null ? $response['bodyJson'] : null,
                'status'      => $call['error'] === null ? FetchResult::FETCHED : FetchResult::ERROR,
                'message'     => $call['error'],
                'provenance'  => null,
                'notices'     => [],
                'calls'       => [$call],
                // Tables are always looked up by a piped record value and have no "public codes" setting,
                // so that value is treated as identifying
                'identifying' => true,
            ];
        }

        $this->apiCacheManager($projectId);
        $provider = $this->apiCacheProviders[(int) $sourceIndex] ?? null;
        if ($provider === null) {
            throw new \Exception("No API source is configured at index \"$sourceIndex\".");
        }

        $traceStart = count($this->apiCallTrace);
        try {
            $report = $this->apiCacheManager->fetch(
                $provider->id(),
                ['req' => ['path' => (string) $apiPath]],
                new FetchContext((int) $projectId, $record === null ? null : (string) $record, $modeOverride)
            );
        } catch (PolicyViolation $e) {
            return ['json' => null, 'status' => FetchResult::ERROR, 'message' => $e->getMessage(),
                    'provenance' => null, 'notices' => [], 'calls' => array_slice($this->apiCallTrace, $traceStart),
                    'identifying' => $provider->isIdentifying()];
        }
        $calls = array_slice($this->apiCallTrace, $traceStart);

        $result = $report->results['req'];
        $notices = $report->policy->notices;
        if ($result->status === FetchResult::STALE && $result->message) {
            // The cache's own message includes the request URL, which can carry an address
            $failed = end($calls) ?: [];
            $notices[] = 'API unavailable (' . ($failed['error'] ?? 'request failed') . '); used the value stored on '
                . date('Y-m-d', $result->entry->retrievedAt) . '.';
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
            'calls'      => $calls,
            'identifying' => $provider->isIdentifying(),
        ];
    }

    // What's safe to log about one sendApiRequest()/queryDataTable() result: no URL, headers or body,
    // since the URL can carry a piped address or a key and headers carry the Authorization token.
    // 'error' is a short description built from the status or the cURL error, never the raw exception
    // message (Guzzle's includes the URL and an excerpt of the response).
    private static function apiCallMetadata(array $response): array
    {
        $isJson = is_array($response['bodyJson'] ?? null);
        $isTable = !preg_match('#^https?://#i', (string) ($response['requestUrl'] ?? ''));

        $error = null;
        if (empty($response['success'])) {
            if ($isTable) {
                // A table's errors name its columns, not the value looked up
                $error = 'table query failed: ' . ($response['error'] ?: 'unknown error');
            } elseif (!empty($response['statusCode'])) {
                $error = trim("HTTP {$response['statusCode']} " . ($response['reasonPhrase'] ?? ''));
            } elseif (preg_match('/cURL error \d+:[^(]*/', (string) $response['error'], $match)) {
                $error = trim($match[0]);
            } else {
                $error = 'request failed';
            }
        } elseif (!$isJson) {
            $error = 'response was not JSON';
        }

        return [
            'method'      => $response['requestMethod'] ?? 'GET',
            'status_code' => $response['statusCode'] ?? null,
            'reason'      => $response['reasonPhrase'] ?? null,
            'elapsed_ms'  => $response['elapsedMs'] ?? null,
            'bytes'       => $response['byteSize'] ?? null,
            'json'        => $isJson,
            'error'       => $error,
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
        // Every request the cache actually sends goes through here, so it's where calls are traced
        $send     = function ($url, $key, $path) {
            $response = $this->sendApiRequest($url, $key, $path);
            $this->apiCallTrace[] = self::apiCallMetadata($response);
            return $response;
        };

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
