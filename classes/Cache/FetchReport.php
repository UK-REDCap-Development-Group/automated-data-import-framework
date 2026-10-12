<?php
namespace UKModules\ADIF\Cache;

/** Results for a batch, plus the policy actually applied (for logging / UI). */
final class FetchReport
{
    /** @param array<string, FetchResult> $results */
    public function __construct(
        public readonly EffectivePolicy $policy,
        public readonly array $results,
        public readonly int $networkCalls,
    ) {}

    /** @return array<string,int> status => count */
    public function summary(): array
    {
        $out = [];
        foreach ($this->results as $r) {
            $out[$r->status] = ($out[$r->status] ?? 0) + 1;
        }
        ksort($out);
        return $out;
    }
}
