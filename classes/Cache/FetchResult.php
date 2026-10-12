<?php
namespace UKModules\ADIF\Cache;

final class FetchResult
{
    public const HIT     = 'hit';      // served from cache, within freshness rules
    public const FETCHED = 'fetched';  // fresh from the service
    public const STALE   = 'stale';    // cached, older than allowed (offline, or service failed)
    public const MISS    = 'miss';     // nothing cached and not allowed / not able to fetch
    public const INVALID = 'invalid';  // input unusable (blank address...)
    public const ERROR   = 'error';    // service failed and failure mode is Fail

    public function __construct(
        public readonly string      $status,
        public readonly ?CacheEntry $entry = null,
        public readonly ?string     $message = null,
    ) {}

    public function value(): ?array
    {
        return $this->entry?->value;
    }

    public function hasValue(): bool
    {
        return $this->entry !== null;
    }
}
