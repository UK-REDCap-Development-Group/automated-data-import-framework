<?php
namespace UKModules\ADIF\Cache;

/**
 * A stored value plus provenance. Provenance travels with the value all the
 * way into the record so studies can report exactly what they used.
 */
final class CacheEntry
{
    public function __construct(
        public readonly array  $value,
        public readonly string $providerId,
        public readonly string $version,
        public readonly int    $retrievedAt,
        public readonly string $origin = 'api', // api | bulk | manual_upload
    ) {}

    public function ageSeconds(int $now): int
    {
        return $now - $this->retrievedAt;
    }

    public function toArray(): array
    {
        return [
            'value'        => $this->value,
            'provider'     => $this->providerId,
            'version'      => $this->version,
            'retrieved_at' => $this->retrievedAt,
            'origin'       => $this->origin,
        ];
    }

    public static function fromArray(array $a): self
    {
        return new self($a['value'], $a['provider'], $a['version'], (int) $a['retrieved_at'], $a['origin'] ?? 'api');
    }
}
