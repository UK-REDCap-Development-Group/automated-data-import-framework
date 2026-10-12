<?php
namespace UKModules\ADIF\Cache\Provider;

use UKModules\ADIF\Cache\CachePolicy;

/**
 * A pluggable data source. Providers describe themselves and fetch;
 * they never touch cache storage directly.
 */
interface DataProvider
{
    /** Stable machine id, used in settings keys and cache namespaces. e.g. "census_acs". */
    public function id(): string;

    public function label(): string;

    /** Caching ceiling. See CachePolicy. */
    public function cachePolicy(): CachePolicy;

    /**
     * True if fetch() sends identifying input (addresses, coordinates, DOB...)
     * to a service outside this server. Gated behind an explicit opt-in.
     */
    public function sendsIdentifyingData(): bool;

    /**
     * Versions the user may pin to, newest first. e.g. ['2023','2022'] for ACS
     * vintages. Return ['current'] for unversioned/time-varying sources.
     */
    public function availableVersions(): array;

    /**
     * Canonical form of one input, used for the cache key. Normalize so
     * "123 Main St." and "123 MAIN STREET" collide. Return null if the input
     * is unusable (blank address etc.).
     */
    public function normalizeInput(array $input): ?string;

    /**
     * Fetch a batch. Batch-first: implementations should make as few calls as
     * the upstream allows. Return [inputId => value]; omit ids with no result.
     *
     * @param array<string, array> $inputs inputId => input
     * @return array<string, array>
     * @throws ProviderUnavailable on network/service failure
     */
    public function fetch(array $inputs, string $version): array;
}
