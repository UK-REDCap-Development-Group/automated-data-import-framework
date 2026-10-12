<?php
namespace UKModules\ADIF\Cache;

/**
 * Who is asking, and any one-off overrides for this run
 * (e.g. a "Refresh now" button passes mode: Live).
 */
final class FetchContext
{
    public function __construct(
        public readonly int        $projectId,
        public readonly ?string    $recordId = null,
        public readonly ?FetchMode $modeOverride = null,
        public readonly ?int       $now = null,
    ) {}

    public function now(): int
    {
        return $this->now ?? time();
    }
}
