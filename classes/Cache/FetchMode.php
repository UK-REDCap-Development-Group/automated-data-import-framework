<?php
namespace UKModules\ADIF\Cache;

/**
 * The user-facing freshness choice. Ordered from least to most network use.
 */
enum FetchMode: string
{
    /** Never touch the network. Cache / reference tables only; misses come back empty. */
    case Offline = 'offline';

    /** Use any cached value; call the API only on a miss. (Default.) */
    case CacheFirst = 'cache_first';

    /** Use cached values younger than the max age; refresh older ones. */
    case RefreshIfStale = 'refresh_if_stale';

    /** Always call the API and overwrite the cache. For "I need the newest data". */
    case Live = 'live';

    public function usesNetwork(): bool
    {
        return $this !== self::Offline;
    }

    public function label(): string
    {
        return match ($this) {
            self::Offline        => 'Offline – local data only, never contact external services',
            self::CacheFirst     => 'Cache first – only call the service for data not already stored',
            self::RefreshIfStale => 'Refresh if older than a set age',
            self::Live           => 'Always fetch the latest data from the service',
        };
    }
}
