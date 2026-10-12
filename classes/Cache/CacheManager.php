<?php
namespace UKModules\ADIF\Cache;

use UKModules\ADIF\Cache\Provider\BulkLoadable;
use UKModules\ADIF\Cache\Provider\DataProvider;
use UKModules\ADIF\Cache\Provider\ProviderRegistry;
use UKModules\ADIF\Cache\Provider\ProviderUnavailable;
use UKModules\ADIF\Cache\Storage\InMemoryStorage;
use UKModules\ADIF\Cache\Storage\StorageBackend;

/**
 * The only thing that talks to both providers and storage. Providers fetch,
 * storage stores, this class decides — so the privacy rules live in one place.
 */
final class CacheManager
{
    private InMemoryStorage $memo;

    public function __construct(
        private ProviderRegistry $registry,
        private PolicyResolver   $resolver,
        private StorageBackend   $storage,
        private KeyBuilder       $keys,
        private ?PayloadCipher   $cipher = null,
    ) {
        $this->memo = new InMemoryStorage();
    }

    /**
     * @param array<string, array> $inputs inputId => provider input (ids are caller-chosen, e.g. "rec12:event3")
     */
    public function fetch(string $providerId, array $inputs, FetchContext $ctx): FetchReport
    {
        $provider = $this->registry->get($providerId);
        $policy   = $this->resolver->resolve($provider, $ctx);
        $ceiling  = $provider->cachePolicy();
        $now      = $ctx->now();
        $ns       = $this->keys->namespace($policy->scope, $ctx);
        $identifying = $ceiling->keySensitivity === CachePolicy::SENSITIVITY_IDENTIFYING;

        // 1. Normalize and dedupe: many inputs may share one cache key.
        $results = [];
        $idsByKey = [];
        $inputByKey = [];
        foreach ($inputs as $id => $input) {
            $norm = $provider->normalizeInput($input);
            if ($norm === null) {
                $results[$id] = new FetchResult(FetchResult::INVALID, message: 'Input could not be normalized.');
                continue;
            }
            $k = $this->keys->key($providerId, $policy->version, $norm, $ceiling->keySensitivity);
            $idsByKey[$k][] = $id;
            $inputByKey[$k] ??= $input;
        }
        $allKeys = array_keys($idsByKey);

        // 2. Look up cache (memo first, then persistent).
        $cached = [];
        if ($policy->cachingEnabled && $allKeys) {
            $cached = $this->memo->getMany($ns, $allKeys);
            $remaining = array_diff($allKeys, array_keys($cached));
            if ($remaining) {
                $fromStore = $this->storage->getMany($ns, array_values($remaining));
                if ($identifying) {
                    $fromStore = array_filter(array_map(fn($e) => $this->cipherFor()->open($e), $fromStore));
                }
                $this->memo->putMany($ns, $fromStore);
                $cached += $fromStore;
            }
        }

        // 3. Decide what needs the network.
        $isStale = function (CacheEntry $e) use ($policy, $ceiling, $now): bool {
            $limit = match ($policy->mode) {
                FetchMode::RefreshIfStale => $policy->maxAgeSeconds,
                default                   => $ceiling->ttlSeconds, // provider TTL always applies
            };
            return $limit !== null && $e->ageSeconds($now) > $limit;
        };

        $toFetch = [];
        foreach ($allKeys as $k) {
            $hit = $cached[$k] ?? null;
            $need = match ($policy->mode) {
                FetchMode::Offline => false,
                FetchMode::Live    => true,
                default            => $hit === null || $isStale($hit),
            };
            if ($need) {
                $toFetch[$k] = $inputByKey[$k];
            }
        }

        // 4. Fetch (one batched call).
        $fetched = [];
        $failure = null;
        $calls = 0;
        if ($toFetch) {
            $calls = 1;
            try {
                $raw = $provider->fetch($toFetch, $policy->version);
                foreach ($raw as $k => $value) {
                    if (isset($toFetch[$k])) {
                        $fetched[$k] = new CacheEntry($value, $providerId, $policy->version, $now, 'api');
                    }
                }
                if ($policy->cachingEnabled && $fetched) {
                    // Identifying values are encrypted at rest; the in-request memo keeps plaintext.
                    $this->storage->putMany($ns, $identifying
                        ? array_map(fn($e) => $this->cipherFor()->seal($e), $fetched)
                        : $fetched);
                    $this->memo->putMany($ns, $fetched);
                }
            } catch (ProviderUnavailable $e) {
                $failure = $e->getMessage();
            }
        }

        // 5. Assemble per-input results.
        foreach ($idsByKey as $k => $ids) {
            $r = $this->resultFor($k, $cached[$k] ?? null, $fetched, $toFetch, $failure, $policy, $isStale);
            foreach ($ids as $id) {
                $results[$id] = $r;
            }
        }

        return new FetchReport($policy, $results, $calls);
    }

    private function cipherFor(): PayloadCipher
    {
        return $this->cipher
            ?? throw new PolicyViolation('Caching identifying data requires a PayloadCipher.');
    }

    private function resultFor(string $k, ?CacheEntry $hit, array $fetched, array $toFetch, ?string $failure,
                               EffectivePolicy $policy, callable $isStale): FetchResult
    {
        if (isset($fetched[$k])) {
            return new FetchResult(FetchResult::FETCHED, $fetched[$k]);
        }
        $attempted = isset($toFetch[$k]);

        if ($attempted && $failure !== null) {
            if ($hit && $policy->failureMode === FailureMode::UseStale) {
                return new FetchResult(FetchResult::STALE, $hit, "Service unavailable ($failure); using cached value.");
            }
            return new FetchResult(FetchResult::ERROR, message: $failure);
        }
        if ($attempted) {
            // Service answered but had nothing for this input (e.g. address not matched).
            return new FetchResult(FetchResult::MISS, message: 'No result from service.');
        }
        if ($hit) {
            return new FetchResult($isStale($hit) ? FetchResult::STALE : FetchResult::HIT, $hit);
        }
        return new FetchResult(FetchResult::MISS, message: $policy->mode === FetchMode::Offline
            ? 'Not in local data and this source is running offline.'
            : 'No cached value.');
    }

    /**
     * Admin action: preload a reference slice so projects can run offline.
     * Only public-key providers, and always into system scope.
     */
    public function preload(string $providerId, array $slice, string $version): int
    {
        $provider = $this->registry->get($providerId);
        $ceiling  = $provider->cachePolicy();
        if (!$provider instanceof BulkLoadable || !$ceiling->bulkLoadable) {
            throw new \LogicException("Provider '$providerId' does not support bulk loading.");
        }
        if ($ceiling->keySensitivity !== CachePolicy::SENSITIVITY_PUBLIC
            || $ceiling->maxScope !== CachePolicy::SCOPE_SYSTEM) {
            throw new PolicyViolation('Bulk preload is only allowed for public, system-scoped data.');
        }

        $now = time();
        $batch = [];
        $n = 0;
        foreach ($provider->bulkLoad($slice, $version) as $norm => $value) {
            $k = $this->keys->key($providerId, $version, (string) $norm, CachePolicy::SENSITIVITY_PUBLIC);
            $batch[$k] = new CacheEntry($value, $providerId, $version, $now, 'bulk');
            if (count($batch) >= 1000) {
                $this->storage->putMany('system', $batch);
                $n += count($batch);
                $batch = [];
            }
        }
        if ($batch) {
            $this->storage->putMany('system', $batch);
            $n += count($batch);
        }
        return $n;
    }

    /** Hook for redcap_module_record_delete / project deletion. */
    public function purgeRecord(int $projectId, string $recordId): void
    {
        $this->storage->purge("record:$projectId:$recordId");
        $this->memo->purge("record:$projectId:$recordId");
    }

    public function purgeProject(int $projectId, ?string $providerId = null): void
    {
        $this->storage->purge("project:$projectId", $providerId);
        $this->memo->purge("project:$projectId", $providerId);
    }
}
