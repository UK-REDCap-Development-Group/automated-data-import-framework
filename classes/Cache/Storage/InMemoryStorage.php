<?php
namespace UKModules\ADIF\Cache\Storage;

/**
 * Per-request memoization layer, and the backend used in tests.
 */
final class InMemoryStorage implements StorageBackend
{
    /** @var array<string, array<string, \UKModules\ADIF\Cache\CacheEntry>> */
    private array $data = [];

    public function getMany(string $namespace, array $keys): array
    {
        return array_intersect_key($this->data[$namespace] ?? [], array_flip($keys));
    }

    public function putMany(string $namespace, array $entries): void
    {
        foreach ($entries as $k => $e) {
            $this->data[$namespace][$k] = $e;
        }
    }

    public function purge(string $namespace, ?string $providerId = null): int
    {
        $before = count($this->data[$namespace] ?? []);
        if ($providerId === null) {
            unset($this->data[$namespace]);
            return $before;
        }
        $this->data[$namespace] = array_filter(
            $this->data[$namespace] ?? [],
            fn($k) => !str_starts_with($k, $providerId . '|'),
            ARRAY_FILTER_USE_KEY
        );
        return $before - count($this->data[$namespace]);
    }

    public function purgeProjectData(int $projectId): int
    {
        $removed = 0;
        foreach (array_keys($this->data) as $namespace) {
            if ($namespace === "project:$projectId" || str_starts_with($namespace, "record:$projectId:")) {
                $removed += count($this->data[$namespace]);
                unset($this->data[$namespace]);
            }
        }
        return $removed;
    }

    public function projectIds(): array
    {
        $ids = [];
        foreach (array_keys($this->data) as $namespace) {
            if (preg_match('/^(?:project|record):(\d+)(?::|$)/', $namespace, $m)) {
                $ids[(int) $m[1]] = true;
            }
        }
        return array_keys($ids);
    }

    public function usageByProvider(int $projectId): array
    {
        return $this->usage(fn($ns) => $ns === "project:$projectId" || str_starts_with($ns, "record:$projectId:"));
    }

    public function sharedUsageByProvider(array $providerIds): array
    {
        return array_intersect_key($this->usage(fn($ns) => $ns === 'system'), array_flip($providerIds));
    }

    public function purgeProjectProvider(int $projectId, string $providerId): int
    {
        $removed = 0;
        foreach ($this->data as $ns => $entries) {
            if ($ns !== "project:$projectId" && !str_starts_with($ns, "record:$projectId:")) {
                continue;
            }
            foreach ($entries as $key => $entry) {
                if ($entry->providerId === $providerId) {
                    unset($this->data[$ns][$key]);
                    $removed++;
                }
            }
        }
        return $removed;
    }

    private function usage(callable $nsMatches): array
    {
        $out = [];
        foreach ($this->data as $ns => $entries) {
            if (!$nsMatches($ns)) {
                continue;
            }
            foreach ($entries as $entry) {
                $u = $out[$entry->providerId] ?? ['entries' => 0, 'bytes' => 0, 'oldest' => PHP_INT_MAX, 'newest' => 0];
                $u['entries']++;
                $u['bytes'] += strlen(json_encode($entry->toArray()));
                $u['oldest'] = min($u['oldest'], $entry->retrievedAt);
                $u['newest'] = max($u['newest'], $entry->retrievedAt);
                $out[$entry->providerId] = $u;
            }
        }
        return $out;
    }

    /** Test helper. */
    public function dump(): array
    {
        return $this->data;
    }
}
