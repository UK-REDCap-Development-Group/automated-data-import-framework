<?php
namespace UKModules\ADIF\Cache\Provider;

final class ProviderRegistry
{
    /** @var array<string, DataProvider> */
    private array $providers = [];

    public function register(DataProvider $provider): void
    {
        $id = $provider->id();
        if (!preg_match('/^[a-z][a-z0-9_]{1,40}$/', $id)) {
            throw new \InvalidArgumentException("Bad provider id '$id'");
        }
        if (isset($this->providers[$id])) {
            throw new \InvalidArgumentException("Provider '$id' already registered");
        }
        // Constructing the policy validates it (e.g. identifying + system scope throws).
        $provider->cachePolicy();
        $this->providers[$id] = $provider;
    }

    public function get(string $id): DataProvider
    {
        return $this->providers[$id] ?? throw new \OutOfBoundsException("Unknown provider '$id'");
    }

    /** @return array<string, DataProvider> */
    public function all(): array
    {
        return $this->providers;
    }
}
