<?php
namespace UKModules\ADIF\Cache;

/**
 * What a provider declares about itself. These are CEILINGS, not defaults:
 * admin and project settings may tighten them, never loosen them.
 */
final class CachePolicy
{
    public const SENSITIVITY_IDENTIFYING = 'identifying';
    public const SENSITIVITY_PUBLIC      = 'public';

    public const SCOPE_RECORD  = 'record';
    public const SCOPE_PROJECT = 'project';
    public const SCOPE_SYSTEM  = 'system';

    public const FRESHNESS_NONE      = 'none';      // never cache
    public const FRESHNESS_TTL       = 'ttl';       // time-varying data
    public const FRESHNESS_IMMUTABLE = 'immutable'; // versioned data; version is in the key

    private const SCOPE_RANK = [self::SCOPE_RECORD => 0, self::SCOPE_PROJECT => 1, self::SCOPE_SYSTEM => 2];

    public function __construct(
        public readonly string $keySensitivity = self::SENSITIVITY_IDENTIFYING,
        public readonly string $maxScope       = self::SCOPE_RECORD,
        public readonly string $freshness      = self::FRESHNESS_NONE,
        public readonly ?int   $ttlSeconds     = null,
        public readonly bool   $bulkLoadable   = false,
    ) {
        if ($keySensitivity === self::SENSITIVITY_IDENTIFYING && $maxScope === self::SCOPE_SYSTEM) {
            throw new PolicyViolation('Identifying keys may not be cached at system scope.');
        }
        if ($freshness === self::FRESHNESS_TTL && !$ttlSeconds) {
            throw new PolicyViolation('TTL freshness requires ttlSeconds.');
        }
    }

    /** Safe default for providers that declare nothing. */
    public static function strictest(): self
    {
        return new self();
    }

    public static function narrowerScope(string $a, string $b): string
    {
        return self::SCOPE_RANK[$a] <= self::SCOPE_RANK[$b] ? $a : $b;
    }
}
