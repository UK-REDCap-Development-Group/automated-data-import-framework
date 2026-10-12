<?php
namespace UKModules\ADIF\Cache\Provider\Examples;

use UKModules\ADIF\Cache\CachePolicy;
use UKModules\ADIF\Cache\Provider\BulkLoadable;
use UKModules\ADIF\Cache\Provider\DataProvider;
use UKModules\ADIF\Cache\Provider\HttpClient;

/**
 * ENRICHER example: tract GEOID -> ACS 5-year estimates.
 *
 * Public key, versioned data, so it's cached system-wide forever (per vintage)
 * and can be bulk-preloaded a state at a time for fully offline projects.
 */
final class AcsProvider implements DataProvider, BulkLoadable
{
    /** @param array<string,string> $variables ACS variable => friendly name */
    public function __construct(
        private HttpClient $http,
        private array $variables = [
            'B19013_001E' => 'median_household_income',
            'B17001_002E' => 'pop_below_poverty',
            'B01003_001E' => 'total_population',
        ],
        private ?string $apiKey = null,
        private array $vintages = ['2023', '2022', '2021'],
    ) {}

    public function id(): string    { return 'census_acs'; }
    public function label(): string { return 'American Community Survey 5-year (tract)'; }

    public function cachePolicy(): CachePolicy
    {
        return new CachePolicy(
            keySensitivity: CachePolicy::SENSITIVITY_PUBLIC,
            maxScope:       CachePolicy::SCOPE_SYSTEM,
            freshness:      CachePolicy::FRESHNESS_IMMUTABLE,
            bulkLoadable:   true,
        );
    }

    public function sendsIdentifyingData(): bool { return false; }

    public function availableVersions(): array { return $this->vintages; }

    /** input: ['tract_geoid' => '21197960100'] */
    public function normalizeInput(array $input): ?string
    {
        $g = preg_replace('/\D/', '', (string) ($input['tract_geoid'] ?? ''));
        return strlen($g) === 11 ? $g : null;
    }

    public function fetch(array $inputs, string $version): array
    {
        // Group by county so we make one call per county rather than per tract.
        $byCounty = [];
        foreach ($inputs as $key => $in) {
            $g = $this->normalizeInput($in);
            $byCounty[substr($g, 0, 5)][$g][] = $key;
        }
        $out = [];
        foreach ($byCounty as $sc => $tracts) {
            $rows = $this->query($version, 'tract:*', 'state:' . substr($sc, 0, 2) . ' county:' . substr($sc, 2, 3));
            foreach ($rows as $geoid => $values) {
                foreach ($tracts[$geoid] ?? [] as $key) {
                    $out[$key] = $values;
                }
            }
        }
        return $out;
    }

    public function bulkLoad(array $slice, string $version): iterable
    {
        $state = $slice['state'] ?? throw new \InvalidArgumentException('slice needs state FIPS');
        yield from $this->query($version, 'tract:*', "state:$state");
    }

    /** @return array<string, array> geoid => [friendly => value] */
    private function query(string $year, string $for, string $in): array
    {
        $params = ['get' => implode(',', array_keys($this->variables)), 'for' => $for, 'in' => $in];
        if ($this->apiKey) {
            $params['key'] = $this->apiKey;
        }
        $json = json_decode($this->http->get("https://api.census.gov/data/$year/acs/acs5", $params), true);
        $head = array_shift($json);
        $out = [];
        foreach ($json as $row) {
            $r = array_combine($head, $row);
            $geoid = $r['state'] . $r['county'] . $r['tract'];
            $vals = [];
            foreach ($this->variables as $var => $name) {
                $v = $r[$var] ?? null;
                // ACS uses large negative sentinels (e.g. -666666666) for "not available".
                $vals[$name] = ($v === null || (float) $v < -1e8) ? null : $v + 0;
            }
            $out[$geoid] = $vals;
        }
        return $out;
    }
}
