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

    /**
     * What one project has stored, by provider: its project-scoped entries and all its record-scoped
     * ones, for the Data Sources page.
     * @return array<string, array{entries: int, bytes: int, oldest: int, newest: int}>
     */
    public function usageByProvider(int $projectId): array;

    /**
     * The same for entries shared across projects ("system" scope), limited to the given providers.
     * @param string[] $providerIds
     * @return array<string, array{entries: int, bytes: int, oldest: int, newest: int}>
     */
    public function sharedUsageByProvider(array $providerIds): array;

    /**
     * Remove one provider's entries for one project (project- and record-scoped; shared entries stay).
     * @return int how many entries were removed
     */
    public function purgeProjectProvider(int $projectId, string $providerId): int;
}
