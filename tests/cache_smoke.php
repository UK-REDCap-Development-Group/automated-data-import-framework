<?php
/**
 * Dependency-free smoke tests. Run: php tests/cache_smoke.php
 */
require __DIR__ . '/../classes/Cache/autoload.php';

use UKModules\ADIF\Cache\{ArraySettingsSource, CacheManager, CachePolicy, FetchContext, FetchMode,
    FetchResult, KeyBuilder, PolicyResolver, PolicyViolation};
use UKModules\ADIF\Cache\Provider\{DataProvider, HttpClient, ProviderRegistry, ProviderUnavailable};
use UKModules\ADIF\Cache\Provider\Examples\{AcsProvider, CensusGeocoderProvider};
use UKModules\ADIF\Cache\Storage\InMemoryStorage;

$pass = 0; $fail = 0;
function check(string $name, bool $ok): void {
    global $pass, $fail;
    echo ($ok ? "  ok   " : "  FAIL ") . $name . "\n";
    $ok ? $pass++ : $fail++;
}

/** Fake HTTP that counts calls and can be switched to "down". */
final class FakeHttp implements HttpClient {
    public int $gets = 0; public int $posts = 0; public bool $down = false; public int $income = 50000;
    public function get(string $url, array $query = []): string {
        $this->gets++;
        if ($this->down) throw new ProviderUnavailable('HTTP 503');
        $rows = [['B19013_001E','B17001_002E','B01003_001E','state','county','tract']];
        foreach (['960100','960200','960300'] as $t) $rows[] = [(string)$this->income,'300','-666666666','21','197',$t];
        return json_encode($rows);
    }
    public function postMultipart(string $url, array $multipart): string {
        $this->posts++;
        if ($this->down) throw new ProviderUnavailable('HTTP 503');
        $lines = [];
        foreach (preg_split('/\R/', trim($multipart['addressFile']['contents'])) as $l) {
            $r = str_getcsv($l);
            $lines[] = implode(',', [$r[0], '"x"', 'Match', 'Exact', '"x"', '"-83,37"', '1', 'L', '21', '197', '960100', '1001']);
        }
        return implode("\n", $lines);
    }
}

/** A time-varying provider, to show TTL behaviour (think AirNow / weather). */
final class FakeTtlProvider implements DataProvider {
    public int $calls = 0;
    public function id(): string { return 'fake_aqi'; }
    public function label(): string { return 'Fake AQI'; }
    public function cachePolicy(): CachePolicy {
        return new CachePolicy(CachePolicy::SENSITIVITY_PUBLIC, CachePolicy::SCOPE_SYSTEM, CachePolicy::FRESHNESS_TTL, 3600);
    }
    public function sendsIdentifyingData(): bool { return false; }
    public function availableVersions(): array { return ['current']; }
    public function normalizeInput(array $i): ?string { return $i['zip'] ?? null; }
    public function fetch(array $inputs, string $version): array {
        $this->calls++;
        return array_map(fn() => ['aqi' => 42 + $this->calls], $inputs);
    }
}

function build(array $system, array $project, ?FakeHttp &$http = null, ?InMemoryStorage &$store = null, ?FakeTtlProvider &$ttl = null): CacheManager {
    $http  = new FakeHttp();
    $store = new InMemoryStorage();
    $ttl   = new FakeTtlProvider();
    $reg = new ProviderRegistry();
    $reg->register(new CensusGeocoderProvider($http));
    $reg->register(new AcsProvider($http));
    $reg->register($ttl);
    return new CacheManager($reg, new PolicyResolver(new ArraySettingsSource($system, [7 => $project])),
        $store, new KeyBuilder(str_repeat('k', 32)), new UKModules\ADIF\Cache\PayloadCipher(str_repeat('k', 32)));
}

$ctx  = new FetchContext(projectId: 7, recordId: '101', now: 1_000_000);
$tracts = ['a' => ['tract_geoid' => '21197960100'], 'b' => ['tract_geoid' => '21-197-960200'], 'c' => ['tract_geoid' => '21197960100']];
$addr = ['r1' => ['street' => '123 Main Street', 'city' => 'Stanton', 'state' => 'KY', 'zip' => '40380'],
         'r2' => ['street' => '123 main st.', 'city' => 'stanton', 'state' => 'ky', 'zip' => '40380-1234'],
         'r3' => ['street' => '', 'zip' => '']];

echo "Policy declarations\n";
try { new CachePolicy(CachePolicy::SENSITIVITY_IDENTIFYING, CachePolicy::SCOPE_SYSTEM); check('identifying+system rejected', false); }
catch (PolicyViolation) { check('identifying+system rejected', true); }

echo "Cache-first (default)\n";
$m = build([], [], $http, $store);
$r = $m->fetch('census_acs', $tracts, $ctx);
check('first call fetches once (deduped, one county)', $r->networkCalls === 1 && $http->gets === 1);
check('a and c share a result', $r->results['a']->value() === $r->results['c']->value());
check('ACS sentinel -> null', $r->results['a']->value()['total_population'] === null);
$r = $m->fetch('census_acs', $tracts, $ctx);
check('second call is all hits, no network', $http->gets === 1 && $r->summary() === ['hit' => 3]);
check('ACS cached at system scope', isset($store->dump()['system']));

echo "Live mode (user wants newest data)\n";
$m = build([], ['census_acs' => ['mode' => 'live']], $http, $store);
$m->fetch('census_acs', $tracts, $ctx); $http->income = 60000;
$r = $m->fetch('census_acs', $tracts, $ctx);
check('live always calls API', $http->gets === 2);
check('live overwrites value', $r->results['a']->value()['median_household_income'] === 60000);

echo "Run override (\"Refresh now\" button)\n";
$m = build([], [], $http, $store);
$m->fetch('census_acs', $tracts, $ctx);
$m->fetch('census_acs', $tracts, new FetchContext(7, '101', FetchMode::Live, 1_000_000));
check('override forces a fetch', $http->gets === 2);
$m = build(['census_acs' => ['allowed_modes' => ['offline', 'cache_first']]], [], $http, $store);
try { $m->fetch('census_acs', $tracts, new FetchContext(7, '101', FetchMode::Live)); check('disallowed override refused', false); }
catch (PolicyViolation) { check('disallowed override refused', true); }

echo "Offline mode\n";
$m = build([], ['census_acs' => ['mode' => 'offline']], $http, $store);
$r = $m->fetch('census_acs', $tracts, $ctx);
check('offline never calls network', $http->gets === 0 && $r->summary() === ['miss' => 3]);

echo "Admin can force offline; project can't override it\n";
$m = build(['census_acs' => ['allow_network' => false]], ['census_acs' => ['mode' => 'live']], $http, $store);
$r = $m->fetch('census_acs', $tracts, $ctx);
check('admin network kill switch wins', $http->gets === 0 && $r->policy->mode === FetchMode::Offline && $r->policy->notices);

echo "Service down -> failure mode\n";
$m = build([], [], $http, $store, $ttl);
$m->fetch('census_acs', $tracts, $ctx);
$http->down = true;
$r = $m->fetch('census_acs', $tracts, new FetchContext(7, '101', FetchMode::Live, 1_000_000));
check('use_stale returns cached value flagged stale', $r->results['a']->status === FetchResult::STALE && $r->results['a']->hasValue());
$m = build([], ['census_acs' => ['failure_mode' => 'fail']], $http, $store);
$m->fetch('census_acs', $tracts, $ctx); $http->down = true;
$r = $m->fetch('census_acs', $tracts, new FetchContext(7, '101', FetchMode::Live, 1_000_000));
check('fail mode returns error', $r->results['a']->status === FetchResult::ERROR);

echo "Version pinning\n";
$m = build([], ['census_acs' => ['version' => '2021']], $http, $store);
$r = $m->fetch('census_acs', $tracts, $ctx);
check('pinned vintage used', $r->results['a']->entry->version === '2021');
$m = build([], ['census_acs' => ['version' => '1999']], $http, $store);
$r = $m->fetch('census_acs', $tracts, $ctx);
check('bad pin falls back to latest with notice', $r->policy->version === '2023' && $r->policy->notices);

echo "Identifying egress gate\n";
$m = build([], [], $http, $store);
$r = $m->fetch('census_geocoder', $addr, $ctx);
check('geocoder offline without opt-in', $http->posts === 0 && $r->policy->mode === FetchMode::Offline);
$m = build(['census_geocoder' => ['allow_identifying_egress' => true]], [], $http, $store);
$r = $m->fetch('census_geocoder', $addr, $ctx);
check('admin-only approval is not enough', $http->posts === 0);
$m = build(['census_geocoder' => ['allow_identifying_egress' => true]],
           ['census_geocoder' => ['allow_identifying_egress' => true]], $http, $store);
$r = $m->fetch('census_geocoder', $addr, $ctx);
check('admin+project approval allows fetch', $http->posts === 1 && $r->results['r1']->status === FetchResult::FETCHED);
check('normalization merges r1/r2', $r->results['r1']->value() === $r->results['r2']->value());
check('blank address invalid', $r->results['r3']->status === FetchResult::INVALID);
$dump = json_encode(array_keys($store->dump()['project:7'] ?? []));
check('geocode cached at project scope, not system', isset($store->dump()['project:7']) && !isset($store->dump()['system']));
check('no plaintext address in keys', !str_contains(strtoupper($dump), 'MAIN'));
check('no coordinates stored', !array_key_exists('lat', $r->results['r1']->value()));

echo "Scope can only narrow\n";
$m = build(['census_acs' => ['max_scope' => 'project']], [], $http, $store);
$r = $m->fetch('census_acs', $tracts, $ctx);
check('admin narrows ACS to project scope', $r->policy->scope === 'project');
$m = build([], ['census_geocoder' => ['cache_scope' => 'system']], $http, $store);
$r = $m->fetch('census_geocoder', $addr, $ctx);
check('project cannot widen geocoder to system', $r->policy->scope === 'project');

echo "TTL data and refresh-if-stale\n";
$m = build([], [], $http, $store, $ttl);
$z = ['x' => ['zip' => '40380']];
$m->fetch('fake_aqi', $z, new FetchContext(7, null, null, 1_000_000));
$m->fetch('fake_aqi', $z, new FetchContext(7, null, null, 1_000_000 + 600));
check('within provider TTL -> cached', $ttl->calls === 1);
$m->fetch('fake_aqi', $z, new FetchContext(7, null, null, 1_000_000 + 7200));
check('past provider TTL -> refetched even in cache_first', $ttl->calls === 2);
$m = build([], ['census_acs' => ['mode' => 'refresh_if_stale']], $http, $store);
$r = $m->fetch('census_acs', $tracts, $ctx);
check('refresh_if_stale on immutable data explains itself', $r->policy->mode === FetchMode::CacheFirst && $r->policy->notices);

echo "Purge on record delete\n";
$m = build(['census_geocoder' => ['allow_identifying_egress' => true]],
           ['census_geocoder' => ['allow_identifying_egress' => true, 'cache_scope' => 'record']], $http, $store);
$m->fetch('census_geocoder', $addr, $ctx);
check('record-scoped entry exists', isset($store->dump()['record:7:101']));
$m->purgeRecord(7, '101');
check('purgeRecord removes it', empty($store->dump()['record:7:101']));

// ---------------------------------------------------------------------------
// ADIF integration: configured API sources + settings from config.json
// ---------------------------------------------------------------------------

/** Stands in for the EM module: only the settings getters the adapter uses. */
final class FakeModule {
    public function __construct(public array $system = [], public array $project = []) {}
    public function getSystemSetting($k) { return $this->system[$k] ?? null; }
    public function getProjectSetting($k, $pid = null) { return $this->project[$k] ?? null; }
}

$sent = [];
$sender = function ($url, $key, $path) use (&$sent) {
    $sent[] = $url . $path;
    if (str_contains($path, 'down')) return ['success' => false, 'requestUrl' => $url . $path, 'error' => 'HTTP 503', 'bodyJson' => null];
    return ['success' => true, 'requestUrl' => $url . $path, 'bodyJson' => [['value' => 7, 'path' => $path]]];
};

// Caching is opt-in on the server and per source, so these tests turn both on unless they say otherwise
function adif(array $sys, array $proj, array $providers, &$store): CacheManager {
    $sys  += ['adif-cache-enabled' => true];
    $proj += ['cache-enabled' => [0 => true, 1 => true, 2 => true]];
    $store = new InMemoryStorage();
    $reg = new ProviderRegistry(); $map = [];
    foreach ($providers as $p) { $reg->register($p); $map[$p->id()] = $p->sourceIndex(); }
    return new CacheManager($reg, new PolicyResolver(new UKModules\ADIF\Cache\AdifSettingsSource(new FakeModule($sys, $proj), $map)),
        $store, new KeyBuilder(str_repeat('k', 32)), new UKModules\ADIF\Cache\PayloadCipher(str_repeat('k', 32)));
}

use UKModules\ADIF\Cache\Provider\ConfiguredApiProvider;

echo "ADIF: configured sources\n";
$places  = new ConfiguredApiProvider(0, 'https://data.cdc.gov/resource/', '', $sender, lookupsArePublic: true, dataVersion: '2024');
$geocode = new ConfiguredApiProvider(1, 'https://geo.example.org/', 'secret', $sender);
$static  = new ConfiguredApiProvider(2, 'https://open.example.org/', '', $sender, usesPlaceholders: false);
check('provider id stable regardless of trailing slash/case',
    $places->id() === (new ConfiguredApiProvider(5, 'HTTPS://data.cdc.gov/resource', '', $sender))->id());
check('placeholder source is identifying by default', $geocode->isIdentifying() && $geocode->cachePolicy()->maxScope === 'project');
check('designer can mark lookups public', !$places->isIdentifying() && $places->cachePolicy()->maxScope === 'system');
check('no placeholders -> public', !$static->isIdentifying());

$m = adif([], [], [$places, $geocode, $static], $store);
$c = new FetchContext(7, '101', now: 1_000_000);
$sent = [];
$q = ['r' => ['path' => 'cwsq-ngmh.json?locationid=21197960100']];
$m->fetch($places->id(), $q, $c);
$r = $m->fetch($places->id(), $q, $c);
check('second identical request served from cache', count($sent) === 1 && $r->results['r']->status === 'hit');
check('public source defaults to project scope (cross-project is opt-in)', $r->policy->scope === 'project');
check('data-version label is the cache version', $r->results['r']->entry->version === '2024');

$m = adif([], ['cache-scope' => [0 => 'system']], [$places, $geocode, $static], $store);
check('project can opt public keyless source into system scope', $m->fetch($places->id(), $q, $c)->policy->scope === 'system');
$m = adif(['adif-cache-max-scope' => 'project'], ['cache-scope' => [0 => 'system']], [$places, $geocode, $static], $store);
check('admin max scope caps it', $m->fetch($places->id(), $q, $c)->policy->scope === 'project');

$m = adif([], [], [$places, $geocode, $static], $store); $sent = [];
$m->fetch($geocode->id(), ['r' => ['path' => '?address=123%20Main%20St']], $c);
$keys = json_encode($store->dump());
check('identifying request goes out by default (existing behaviour kept)', count($sent) === 1);
check('identifying request is not stored in plaintext (key or value)', !str_contains($keys, 'Main'));
$r = $m->fetch($geocode->id(), ['r' => ['path' => '?address=123%20Main%20St']], new FetchContext(7, '102', now: 1_000_000));
check('encrypted entry decrypts on a later hit (other record, same project)', $r->results['r']->status === 'hit'
    && $r->results['r']->value()[0]['path'] === '?address=123%20Main%20St');
$m2 = new CacheManager((function () use ($geocode) { $g = new ProviderRegistry(); $g->register($geocode); return $g; })(),
    new PolicyResolver(new UKModules\ADIF\Cache\AdifSettingsSource(
        new FakeModule(['adif-cache-enabled' => true], ['cache-enabled' => [1 => true]]), [$geocode->id() => 1])),
    new InMemoryStorage(), new KeyBuilder(str_repeat('k', 32)));
try { $m2->fetch($geocode->id(), ['r' => ['path' => '?address=1']], $c); check('identifying caching refused without cipher', false); }
catch (PolicyViolation) { check('identifying caching refused without cipher', true); }
echo "ADIF: caching on/off\n";
// Server off: nothing cached even where the project opted in, and the project is told why
$m = adif(['adif-cache-enabled' => false], [], [$places, $geocode, $static], $store); $sent = [];
$m->fetch($places->id(), $q, $c); $r = $m->fetch($places->id(), $q, $c);
check('server caching off: every request goes to the API', count($sent) === 2 && $r->results['r']->status === 'fetched');
check('server caching off: nothing stored', empty($store->dump()));
check('server caching off overrides the project opt-in, with a notice', !$r->policy->cachingEnabled
    && str_contains(implode(' ', $r->policy->notices), 'turned off on this server'));
$m = new CacheManager((function () use ($places) { $g = new ProviderRegistry(); $g->register($places); return $g; })(),
    new PolicyResolver(new UKModules\ADIF\Cache\AdifSettingsSource(new FakeModule([], ['cache-enabled' => [0 => true]]), [$places->id() => 0])),
    $store = new InMemoryStorage(), new KeyBuilder(str_repeat('k', 32)));
$sent = []; $m->fetch($places->id(), $q, $c); $m->fetch($places->id(), $q, $c);
check('server caching is off until an admin enables it', count($sent) === 2 && empty($store->dump()));

// Server on, source not ticked: not cached, and no notice, since that's the project's own choice
$m = adif([], ['cache-enabled' => [0 => false, 1 => true, 2 => true]], [$places, $geocode, $static], $store); $sent = [];
$m->fetch($places->id(), $q, $c); $r = $m->fetch($places->id(), $q, $c);
check('unticked source: every request goes to the API', count($sent) === 2 && empty($store->dump()));
check('unticked source: no notice', !$r->policy->cachingEnabled && !$r->policy->notices);
$r = $m->fetch($geocode->id(), ['r' => ['path' => '?address=1']], $c);
check('other ticked sources in the project still cache', !empty($store->dump()) && $r->policy->cachingEnabled);
$m = adif([], ['cache-enabled' => []], [$places, $geocode, $static], $store); $sent = [];
$m->fetch($places->id(), $q, $c); $m->fetch($places->id(), $q, $c);
check('sources are not cached unless ticked', count($sent) === 2 && empty($store->dump()));

// "Use stored data only" needs a cache to read from
$m = adif(['adif-cache-enabled' => false], ['cache-mode' => [0 => 'offline']], [$places, $geocode, $static], $store); $sent = [];
$r = $m->fetch($places->id(), $q, $c);
check('offline source without a cache is requested from the API, with a notice', count($sent) === 1
    && $r->policy->mode === FetchMode::CacheFirst && count($r->policy->notices) === 2);
$m = adif([], ['cache-mode' => [0 => 'offline']], [$places, $geocode, $static], $store); $sent = [];
$r = $m->fetch($places->id(), $q, $c);
check('offline cached source still uses stored data only', count($sent) === 0 && $r->policy->mode === FetchMode::Offline);

// ADIF doesn't block outbound requests: identifying sources go out
$m = adif([], [], [$places, $geocode, $static], $store); $sent = [];
$r = $m->fetch($geocode->id(), ['r' => ['path' => '?address=1']], $c);
check('identifying source is requested (no admin egress block)', count($sent) === 1 && $r->policy->mode === FetchMode::CacheFirst);

$m = adif([], ['cache-mode' => [0 => 'refresh_if_stale'], 'cache-max-age-days' => [0 => '30']], [$places, $geocode, $static], $store); $sent = [];
$m->fetch($places->id(), $q, new FetchContext(7, '101', now: 1_000_000));
$m->fetch($places->id(), $q, new FetchContext(7, '101', now: 1_000_000 + 10 * 86400));
check('refresh_if_stale: fresh within max age', count($sent) === 1);
$m->fetch($places->id(), $q, new FetchContext(7, '101', now: 1_000_000 + 31 * 86400));
check('refresh_if_stale: refetched after max age', count($sent) === 2);

$m = adif([], [], [$places, $geocode, $static], $store);
$down = ['r' => ['path' => '?down=1']];
$r = $m->fetch($places->id(), $down, $c);
check('service down, nothing cached -> error with message', $r->results['r']->status === 'error' && str_contains($r->results['r']->message, '503'));

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
