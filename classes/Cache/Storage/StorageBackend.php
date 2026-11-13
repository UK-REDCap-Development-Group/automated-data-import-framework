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

    /**
     * Remove everything stored for one project - its project-scoped entries and every record-scoped
     * one - when the project is deleted. Entries shared across projects ("system") stay.
     * @return int how many entries were removed
     */
    public function purgeProjectData(int $projectId): int;

    /** @return int[] the projects that have project- or record-scoped entries */
    public function projectIds(): array;
}
