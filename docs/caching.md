# API response caching

ADIF can put a cache in front of configured API sources, so repeated requests
for the same data (two participants in the same census tract, a survey retaken,
a re-import) are answered locally instead of calling the API again.

Caching is opt-in at two levels:

1. An administrator enables API caching for the server (Control Center). While
   it's off, nothing is cached anywhere and every request goes to the API.
2. A project designer ticks which of the project's API sources to cache. Each
   ticked source stores responses as imports request them, and reuses them for
   identical requests.

A source is cached only when both are on. How it's cached (freshness, sharing,
failure behaviour) is then up to the designer, within limits the administrator sets.

## How it fits into the module

```
Import::importMappedData()
  └─ pipeRecordValues()            path with [placeholders] filled from the record
  └─ ApiCache::fetchApiJson()      ← new: replaces the direct dispatchApiRequest() call
        └─ CacheManager::fetch()
              ├─ PolicyResolver    provider ceiling → admin → project → run override
              ├─ memo / TableStorage lookup
              └─ ConfiguredApiProvider::fetch() → Proxy::sendApiRequest()  (only on a miss)
```

Each entry in **api-sources** becomes a `ConfiguredApiProvider`. Its id is a
hash of the URL, so reordering sources in the config dialog doesn't mix up
their caches, and pointing a source at a new URL starts a fresh cache.

The cache key is `source + data version + the fully piped request path`, so two
records whose lookup values produce the same request share one cached response.

```
classes/ApiCache.php                trait mixed into ADIF; builds providers from project settings
classes/Cache/
  CacheManager.php                  fetch(), preload(), purgeRecord(), purgeProject()
  PolicyResolver.php                layers the controls into an EffectivePolicy (+ notices)
  CachePolicy.php                   what a provider declares: sensitivity, max scope, freshness
  FetchMode.php / FailureMode.php   the user-facing choices
  AdifSettingsSource.php            reads those choices from config.json settings
  KeyBuilder.php                    scoped namespaces; HMAC'd keys for identifying requests
  PayloadCipher.php                 AES-256-GCM for identifying values at rest
  Storage/TableStorage.php          adif_api_cache table (created on enable / first use)
  Provider/ConfiguredApiProvider.php  wraps one api-sources entry
  Provider/Examples/                code-defined providers (Census geocoder, ACS) for reference
tests/cache_smoke.php               php tests/cache_smoke.php  (no REDCap needed)
```

## The core rule

| Layer | Who | Controls |
|---|---|---|
| Provider declaration | code / designer | key sensitivity, widest scope, freshness |
| System settings | REDCap admin | caching on/off for the server, max scope |
| Project settings (per source) | project designer | whether to cache the source; when to call the API, max age, failure behaviour, data version, sharing |
| Run override | a user action | mode for one run, e.g. a future "Refresh now" button |

**Caching happens only when every layer allows it. Freshness choices flow down
freely. Privacy choices can only narrow.**
Whenever a choice is overridden, the reason is added to the module log under
`notices` for that import.

## Identifying vs public sources

A source is treated as **identifying** when its schema paths pipe in record
values (`[placeholders]`) and the designer hasn't ticked *"the record values
piped into this source are public codes"*. Sources with no placeholders are
always public.

| | Identifying (e.g. address → geocoder) | Public (e.g. tract GEOID → CDC PLACES) |
|---|---|---|
| Cache key | HMAC of the request path | request path |
| Stored value | AES-256-GCM encrypted | plain JSON |
| Widest sharing | within the project | across projects, if keyless and opted in |

Hashing the key alone isn't enough because responses often echo the input (a
geocoder returns the matched address), so the value is encrypted too.

The secret for both comes from, in order: the `ADIF_CACHE_SECRET` environment
variable, REDCap's `$salt` from `database.php`, or a generated system setting.
The first two keep the secret out of the database, so a database dump alone
can't be used to test guessed addresses against the cache. Changing the secret
turns existing identifying entries into misses; it doesn't cause errors.

## Settings

### Per API source (project configuration dialog)

- **Cache this API's responses** (`cache-enabled`): the project's opt-in for this source. Unticked
  sources are requested from the API every time and nothing is stored. Ignored (with a notice in
  the import log) when caching is off for the server. The settings below apply only to cached sources.
- **When should ADIF call this API?** (`cache-mode`)
  - *Only for data not already stored* (default)
  - *When stored data is older than N days* (`cache-max-age-days`)
  - *Never: use stored data only*. If the source isn't cached, it's requested from the API
    instead, with a notice.
- **If this API is unavailable** (`cache-on-failure`): use the last stored value and note it in the log (default), or leave the fields blank and log an error.
- **Data version** (`data-version`): a free-text label such as `2023`. Changing it makes ADIF fetch fresh data instead of reusing data stored under the old label. Recommended for versioned data, and worth pinning once data collection starts so values don't change mid-study.
- **Piped values are public codes** (`lookups-are-public`): see above.
- **Share stored responses** (`cache-scope`): across records in this project (default), only within the same record, or with other projects (public, keyless sources only).

### Control Center (system)

- `adif-cache-enabled`: enables API caching on this server. Off by default; while it's off,
  nothing is cached, whatever projects have chosen.
- `adif-cache-max-scope`: widest sharing allowed on this server.

ADIF doesn't block outbound requests: a source that isn't cached simply calls its API.

## Behaviour changes from before this branch

- Caching is **opt-in**: nothing is cached until an administrator enables API caching and a project
  ticks *Cache this API's responses* on a source. Until then every import calls the API, as before.
  A ticked source defaults to *cache first*.
- An uncached source is what used to be *Always fetch the latest data*, so that option is gone.
  A source saved with it behaves the same way once ticked: it always calls the API, and stores the
  newest response.
- The earlier `adif-cache-block-network`, `adif-cache-block-identifying` and
  `adif-cache-disallow-live` system settings were removed.
- When an API is down and a stored value exists, the stored value is used and a notice is logged,
  where before the fields were left blank.
- Import log entries gain a `notices` parameter.

## When a project is deleted

REDCap has no hook for project deletion, so a scheduled cleanup
(`ProjectCleanup::removeDeletedProjectData()`) finds projects that ADIF holds data for and that have
been deleted.

**When it runs** is up to the REDCap administrator, in ADIF's system settings (Control Center):

| Setting | Choices | Default (blank) |
|---|---|---|
| `adif-cleanup-frequency` | Weekly, Daily, or Never | Weekly |
| `adif-cleanup-weekday` | Sunday to Saturday (used when weekly) | Thursday |
| `adif-cleanup-hour` | 12 AM to 11 PM, server time | 2 AM |

REDCap fixes a module cron's timing in `config.json`, so the cron, `adif_deleted_project_cleanup`
(`cleanUpDeletedProjects()`), only checks once an hour whether a scheduled run is due
(`projectCleanupDueSince()`) and hasn't happened yet (`adif-cleanup-last-run`). The cleanup starts
within the scheduled hour, or at the next check if REDCap's cron was down then. The first check
after the schedule is set up only notes the time, so the first cleanup waits for the next scheduled
time rather than running whenever the module was enabled. With **Never**, deleted projects' data
stays until an administrator removes it.

What it removes:

- **Deleted** (an administrator can still restore it): its cached API responses go - every
  `project:<pid>` and `record:<pid>:*` entry (`StorageBackend::purgeProjectData()`). They can hold
  identifying values, and a restored project simply fetches again. Its queued imports wait; the
  queue cron skips deleted projects.
- **Permanently deleted** (its row is gone from `redcap_projects`): its uploaded tables' files are
  marked deleted in `redcap_edocs_metadata`, as removing a table does, so REDCap's own cron removes
  them. ADIF's module settings for it (mappings, sources, API keys, the table list) and its module log
  entries (runs, queue) are removed too. Tables are kept until this point, so restoring a deleted
  project restores them.

REDCap may already have removed some of this with the project; each step is a no-op when it has.
Entries shared across projects (`system` scope: public, keyless sources only) belong to no project
and stay. A permanently deleted project's settings may already be gone when the cron runs, so the
files behind each project's tables are also kept in the `adif-table-edocs` system setting. It's
updated whenever a project's tables change, and refreshed from the projects' settings on every run.
A file is only marked deleted while REDCap still records it under that project. Each cleanup writes
one system-level module log entry, `ADIF data removed for a deleted project`, with what was removed.

## Not done yet / next steps

- **Verify against a live REDCap instance.** The tests run against stubs; check the
  `CREATE TABLE` in `TableStorage` is permitted by your framework version, and that
  `branchingLogic` inside `sub_settings` shows/hides the max-age field.
- **"Refresh now" button** on the record or Mapping page, calling
  `fetchApiJson(..., FetchMode::Live)`.
- **Cache admin page** for administrators, across projects. Per project, the Data Sources page's API
  tab already shows each source's cached size and age and can clear a source's responses. The cache
  shares the project's storage allowance with uploaded tables (see `docs/data_sources.md`, "Storage
  and cached responses").
- **Record deletion**: `purgeRecord()` exists but no hook calls it yet. Project-scoped entries
  aren't attributable to a single record. (A deleted project's entries are removed: see "When a
  project is deleted" above.)
- **Provenance in the record**: optionally write source, version and retrieval date
  (`provenance` from `fetchApiJson`) to hidden fields.
- **Bulk preload** for public sources (`CacheManager::preload()` + `BulkLoadable`), so a project
  can run fully offline. The ACS example shows the shape.
- `TableStorage::purge()` returns -1; wire up affected-row counts.
