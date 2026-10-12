<?php
namespace UKModules\ADIF\Cache;

/** In-memory settings for tests and CLI use. */
final class ArraySettingsSource implements SettingsSource
{
    /**
     * @param array<string, array<string,mixed>> $system   providerId => [key => value]
     * @param array<int, array<string, array<string,mixed>>> $projects  pid => providerId => [key => value]
     */
    public function __construct(private array $system = [], private array $projects = []) {}

    public function system(string $providerId, string $key, mixed $default = null): mixed
    {
        return $this->system[$providerId][$key] ?? $default;
    }

    public function project(int $projectId, string $providerId, string $key, mixed $default = null): mixed
    {
        return $this->projects[$projectId][$providerId][$key] ?? $default;
    }
}
