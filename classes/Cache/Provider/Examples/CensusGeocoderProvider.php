<?php
namespace UKModules\ADIF\Cache\Provider\Examples;

use UKModules\ADIF\Cache\CachePolicy;
use UKModules\ADIF\Cache\Provider\DataProvider;
use UKModules\ADIF\Cache\Provider\HttpClient;
use UKModules\ADIF\Cache\Provider\ProviderUnavailable;

/**
 * RESOLVER example: address -> Census geographies (state/county/tract/block).
 *
 * Identifying input, sent off-server, so:
 *   - key sensitivity: identifying (HMAC'd keys)
 *   - max scope: project (never shared across studies)
 *   - sendsIdentifyingData: true (requires admin + project opt-in)
 *
 * Version = geography vintage, because tract boundaries change each decennial
 * census and a cached tract must say which boundaries it was assigned under.
 */
final class CensusGeocoderProvider implements DataProvider
{
    private const URL = 'https://geocoding.geo.census.gov/geocoder/geographies/addressbatch';
    private const VINTAGES = [
        // version id => [benchmark, vintage] as the geocoder names them
        'census2020' => ['Public_AR_Current', 'Census2020_Current'],
        'acs2023'    => ['Public_AR_Current', 'ACS2023_Current'],
    ];

    public function __construct(private HttpClient $http) {}

    public function id(): string    { return 'census_geocoder'; }
    public function label(): string { return 'U.S. Census Geocoder (address → tract)'; }

    public function cachePolicy(): CachePolicy
    {
        return new CachePolicy(
            keySensitivity: CachePolicy::SENSITIVITY_IDENTIFYING,
            maxScope:       CachePolicy::SCOPE_PROJECT,
            freshness:      CachePolicy::FRESHNESS_IMMUTABLE,
        );
    }

    public function sendsIdentifyingData(): bool { return true; }

    public function availableVersions(): array { return array_keys(self::VINTAGES); }

    /** input: ['street'=>..,'city'=>..,'state'=>..,'zip'=>..] */
    public function normalizeInput(array $input): ?string
    {
        $street = $this->clean($input['street'] ?? '');
        $zip    = substr(preg_replace('/\D/', '', (string) ($input['zip'] ?? '')), 0, 5);
        if ($street === '' || ($zip === '' && trim((string) ($input['city'] ?? '')) === '')) {
            return null;
        }
        return implode('|', [$street, $this->clean($input['city'] ?? ''), strtoupper(trim($input['state'] ?? '')), $zip]);
    }

    public function fetch(array $inputs, string $version): array
    {
        [$benchmark, $vintage] = self::VINTAGES[$version] ?? throw new \InvalidArgumentException("Unknown version $version");

        // Batch endpoint limit is 10,000 rows per file. Ids sent upstream are
        // sequence numbers, not our cache keys, so nothing internal leaks.
        $out = [];
        foreach (array_chunk($inputs, 10000, true) as $chunk) {
            $map = [];
            $csv = fopen('php://temp', 'r+');
            $i = 0;
            foreach ($chunk as $key => $in) {
                $map[++$i] = $key;
                fputcsv($csv, [$i, $in['street'] ?? '', $in['city'] ?? '', $in['state'] ?? '', $in['zip'] ?? '']);
            }
            rewind($csv);
            $body = $this->http->postMultipart(self::URL, [
                'addressFile' => ['filename' => 'addresses.csv', 'contents' => stream_get_contents($csv)],
                'benchmark'   => $benchmark,
                'vintage'     => $vintage,
            ]);
            foreach ($this->parse($body) as $seq => $geo) {
                if (isset($map[$seq])) {
                    $out[$map[$seq]] = $geo;
                }
            }
        }
        return $out;
    }

    /** Response columns: id, input, match, matchtype, matched addr, lon/lat, tigerid, side, state, county, tract, block */
    private function parse(string $body): array
    {
        $out = [];
        foreach (preg_split('/\R/', trim($body)) as $line) {
            $r = str_getcsv($line);
            if (($r[2] ?? '') !== 'Match' || count($r) < 12) {
                continue;
            }
            [$state, $county, $tract, $block] = [$r[8], $r[9], $r[10], $r[11]];
            // Deliberately NOT storing matched address or coordinates: only geography codes.
            $out[(int) $r[0]] = [
                'match_type'   => $r[3],
                'state'        => $state,
                'county'       => $county,
                'tract_geoid'  => $state . $county . $tract,
                'bg_geoid'     => $state . $county . $tract . substr($block, 0, 1),
            ];
        }
        return $out;
    }

    private function clean(string $s): string
    {
        $s = strtoupper(trim(preg_replace('/\s+/', ' ', $s)));
        $s = preg_replace('/[.,#]/', '', $s);
        $abbr = [' STREET' => ' ST', ' AVENUE' => ' AVE', ' ROAD' => ' RD', ' DRIVE' => ' DR',
                 ' LANE' => ' LN', ' BOULEVARD' => ' BLVD', ' COURT' => ' CT'];
        foreach ($abbr as $long => $short) {
            if (str_ends_with($s, $long)) {
                $s = substr($s, 0, -strlen($long)) . $short;
            }
        }
        return $s;
    }
}
