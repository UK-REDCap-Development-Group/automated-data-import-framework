<?php
namespace UKModules\ADIF\Cache\Provider;

/**
 * Optional: providers that can download a whole reference table at once
 * (all tracts in a state, a CSV release of SVI...). Lets an admin preload
 * so projects can run in Offline mode.
 */
interface BulkLoadable
{
    /**
     * Yield [normalizedKey => value] for an entire slice of the dataset.
     *
     * @param array $slice provider-specific, e.g. ['state' => '21']
     * @return iterable<string, array>
     */
    public function bulkLoad(array $slice, string $version): iterable;
}
