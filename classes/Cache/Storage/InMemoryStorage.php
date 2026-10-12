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

    /** Test helper. */
    public function dump(): array
    {
        return $this->data;
    }
}
