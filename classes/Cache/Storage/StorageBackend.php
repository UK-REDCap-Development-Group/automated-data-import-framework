<?php
namespace UKModules\ADIF\Cache\Storage;

use UKModules\ADIF\Cache\CacheEntry;

interface StorageBackend
{
    /**
     * @param string[] $keys
     * @return array<string, CacheEntry> found entries only
     */
    public function getMany(string $namespace, array $keys): array;

    /** @param array<string, CacheEntry> $entries */
    public function putMany(string $namespace, array $entries): void;

    /** Remove everything for a provider in a namespace (admin "purge" / record deletion). */
    public function purge(string $namespace, ?string $providerId = null): int;
}
