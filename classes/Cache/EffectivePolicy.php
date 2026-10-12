<?php
namespace UKModules\ADIF\Cache;

/**
 * The fully resolved behaviour for one provider in one project for one run.
 * Built by PolicyResolver; consumed by CacheManager. Carries the reasons for
 * anything that was clamped so the UI can explain it.
 */
final class EffectivePolicy
{
    /** @param string[] $notices */
    public function __construct(
        public readonly FetchMode   $mode,
        public readonly FailureMode $failureMode,
        public readonly bool        $cachingEnabled,
        public readonly string      $scope,
        public readonly ?int        $maxAgeSeconds,
        public readonly string      $version,
        public readonly array       $notices = [],
    ) {}
}
