# The Imports page

When an import fails, it does so quietly: `ImportLog::logImportRun()` writes the details to the
module log and a short line to the project's Logging page, and nothing else happens. Staff had to
know to go looking. The Imports page (`pages/Imports.php`) reads those same log entries back and
answers the two questions that matter:

1. **Which imports left mapped fields empty?** The runs list shows every run, newest first, with an
   outcome pill and the per-endpoint detail behind it.
2. **Does this record's import need running again?** The record lookup shows the latest run per
   event for one record, with a verdict.
3. **Can it be fixed from here?** Yes: any run flagged *Needs attention* has a retry button (see
   "Re-running an import" below).
4. **Which records have never been imported at all?** The *Never imported* card lists records that
   have data but whose mapped fields are empty and that no import has ever tried - typically records
   entered before the module was enabled - and runs their first import (see "Records never imported").

## How runs are reconstructed

Every import run writes one `API import: endpoint request` entry per endpoint plus one summary
entry, all sharing a `run_id` and logged with an explicit `project_id` (see
`ImportLog::logImportRun`). `ImportStatus` reads them back with two direct queries on
`redcap_external_modules_log` and `redcap_external_modules_log_parameters` (the framework's
`queryLogs()` pseudo-query dialect proved unreliable on 16.1.7):

1. `importRecentRunIds()` picks the newest *runs* - grouped by `run_id`, filtered by record in SQL
   when one is given - so "Runs shown" is a number of runs, and a record's runs are found however
   far back they are.
2. `importRunEntries()` then reads every entry of those runs, so no run is cut in half by a row cap.

`importRunById()` uses step 2 alone; the retry action looks its run up this way.

Only metadata is involved - the entries were written scrubbed (no URLs, no values), so the page can
show them as-is.

## What counts as needing attention

The rule lives in `ImportStatus::importRunNeedsAttention()` and is deliberately narrow, so the page
only flags runs where data is actually missing:

| Outcome | When | Meaning |
|---|---|---|
| **Needs attention** | A request errored (`outcome = error`) or saved nothing while leaving fields not populated - or the run's summary has errors (e.g. the final `saveData` failed) | Those fields are still empty. The import should be run again. |
| **From stored data** | Every failing request was answered from the cache because the API was down (`outcome = stale`) and its fields were filled | Intended behaviour (`cache-on-failure: use_stale`). Noted, not flagged. |
| **OK** | Everything else | Includes requests whose fields were skipped because the API returned no value at a mapped path - that's an answer, not a failure. |

A request that saved its fields never flags anything, whatever happened elsewhere in the run. A run
where one endpoint failed but another saved its fields is flagged, since the first endpoint's fields
are waiting.

## The record lookup

`ImportStatus::importRecordStatus()` returns the **latest** run per event, instrument and repeat
instance for one record, so the verdict always reflects the record's current state: a failed run
followed by a successful re-run shows as OK, while a failed instance #2 stays flagged even after
instance #3 imports cleanly (`repeat_instance` is logged with every run).

## Dismissing an error

Not every failure is worth fixing: an address that will never geocode, a test record, a field
nobody needs any more. A run flagged *Needs attention* can be **dismissed** instead (the **Dismiss**
button beside **Retry this import**, with an optional reason), so it stops being flagged forever.

- **What changes:** the run's outcome reads **Dismissed** (grey). It no longer counts in the
  "left mapped fields empty" alert, in the record lookup's verdict, or for **Retry all failed**, and
  the **Needs attention** filter leaves it out (the **Dismissed** filter lists them). Nothing about
  the run's entries or the record's data changes: the fields are still empty.
- **Undo:** a dismissed run has an **Undo dismiss** button, which flags it again (and brings its
  retry button back).
- **Audit trail:** each dismissal and each undo is its own module log entry
  (`ADIF import run dismissed` / `ADIF import run dismissal undone`) carrying the run's `run_id`,
  the user, the time and the reason, plus a line on the project's Logging page. The latest entry
  for a run decides. These messages deliberately don't start with `API import`, so they are never
  read as part of a run.
- **Who can dismiss:** the same users who can retry (see [permissions.md](permissions.md)); the
  `dismiss-import` AJAX action re-checks this server-side.
- A later run for the same record and event (a new survey submission or a retry) supersedes a
  dismissed one in the record lookup, as it would any other run.

## Re-running an import

The **Retry this import** button on a flagged run (and **Retry all failed** in the toolbar) re-runs
the import for the same record, event, instrument and repeat instance through
`ImportStatus::retryImportRun()`, which calls the existing `Import::importMappedData()`. Nothing
about the import engine is special-cased for retries.

- **Mapping check first:** before anything runs, `ImportStatus::importRetryMappingCheck()` compares
  the fields the run left empty with what the project's *current* mappings would import, using the
  same rules as the import itself (`Import::importPlan()`). If none of those fields is still mapped
  (mapping removed, field moved off the event, path no longer offered by any source, field changed to
  an unwritable type), the retry is refused with the reason per field: nothing is fetched, saved or
  logged, and the button changes to **Mappings changed**. If only some are, the retry goes ahead and
  its notices name the ones that weren't retried. The import then runs exactly the plan that was
  checked. A run that doesn't record which fields it left empty (the import threw) is retried as long
  as any mapping applies to its event and instrument.
- **Who can retry:** users with View & Edit rights on every instrument with ADIF mappings, or Project
  Design rights, since a retry writes record data; a user in a data access group only for their group's
  records. The AJAX action re-checks both server-side. See [permissions.md](permissions.md).
- **Stored data:** by default a retry passes `FetchMode::Live` to the cache, so every request calls
  the API and overwrites what's stored - the right default after an outage. Unchecking **Skip
  stored data and call the APIs** reuses cached responses where they exist (e.g. for a retry of a
  run that failed only at `saveData`).
- **Audit trail:** the retry is logged exactly like a survey-triggered run, as
  `API import: manual retry by <username>`, and appears in the run list like any other run. A
  successful retry also clears the record lookup's verdict.
- **Double-clicks:** a per-run lock (a system setting holding an expiry timestamp) rejects a second
  retry of the same run within 60 seconds.
- **Results:** the action returns the new run, built from the very entries it just logged through
  the same grouping and outcome rules as the run list, so the result matches what the list will show; the page updates the run's pill and shows
  what was saved, what's still not populated, and any errors or notices. Unlike the survey hook,
  errors are returned to the user rather than only logged - nobody is waiting on a completion page.

The retry reads its target (record, event, instrument, instance) from the logged run, so it re-runs
exactly what failed - including for repeating instruments, whose instance number is on the run.

## Retry all failed

**Retry all failed (N)** re-runs every *import* whose latest run needs attention, not every flagged
run. An import is a record's event, plus the repeat instance when the event or instrument repeats,
plus the instrument when it repeats (`ImportStatus::importRunUnitKey()`). A failed run stays flagged
in the list after a later run - a retry, a new survey submission - has imported the same data again,
since a run's own entries never change; Retry all leaves those alone, and dismissed runs too
(`ImportStatus::importRetryTargets()`).

It covers the most recent 500 runs, not only those shown in the list (the button's tooltip says how
many aren't shown), or just the filtered record's when the record filter is set. The button only
appears when there is something to retry.

**It runs in the background, not in the browser** (`classes/ImportRetryAll.php`). The button posts
to the page, which saves a job listing the runs to retry (the project setting `import-retry-all-job`)
and redirects back (`?retryall=<outcome>`); the page is free straight away and can be closed. The
cron `adif_retry_all` (every minute) works through the jobs:

- It isn't held to the bulk refresh's off-peak hours, since a list of failures is wanted soon, but it
  counts toward the server-wide limit on background imports (see [below](#the-server-wide-import-limit)),
  for up to 50 seconds a run. Projects with a job waiting take turns starting.
- Just before each retry the run is checked against the current retry targets: one whose import has
  run again since, was dismissed, or has dropped out of the latest 500 runs is skipped. A run being
  retried by hand at that moment (the per-run lock) or whose fields are no longer mapped is skipped too.
- Each retry goes through `retryImportRun()`, like the per-run button, and is logged as "API import:
  retry all failed, requested by <user>". The **Skip stored data** choice made when queueing applies.
- The toolbar shows the job's progress while it runs (reload the page to update it), with a Cancel
  button, and a summary of the last job for a day afterwards: how many are now fine, still need
  attention, were skipped, or couldn't be retried. Progress is saved after each retry, re-reading the
  job first so a cancel wins. One job per project at a time.
- Who: the same users who can retry (see [permissions.md](permissions.md)); a user in a data access
  group queues only their group's runs. The job itself is logged as "ADIF retry all requested /
  cancelled / done / failed".

## Records never imported

Imports run when a survey is submitted, so records entered before the module was enabled (or before
their fields were mapped) never get one. `ImportStatus::importNeverAttempted()` finds them. It looks
at each place an import writes to: a record's event; each instance of a repeating event; and each
instance of a mapped repeating instrument, which is a place of its own. It lists a place when:

- **the record has data there.** The record has a row for that event or instance with a value in it,
  so the event has happened. Events a record hasn't reached are left alone, as a survey trigger
  would leave them.
- **every field the current mappings import there is empty.** For a checkbox, no option is ticked.
- **no logged run has requested any of those fields there.** This is read from every import run's
  per-request `fields_requested`, however old. A run that made no requests, or that doesn't say which
  fields it requested, counts as having tried everything in its event. One attempt is enough to leave
  a place off the list: if that attempt left fields empty, the run list flags it.

All of this comes from one `REDCap::getData()` call (the mapped fields, their lookup fields and the
instruments' form status fields) and one query on the module log. The record filter narrows both to
one record.

Each place is also checked for the values its `[placeholders]` need, using the import's own rules
(`Import::importLookupValue()`):

| Status | Meaning |
|---|---|
| **Not imported** | Ready: every request has what it needs. |
| **Partly ready** | Some requests can't look their values up yet. Importing fills the rest, and the run is flagged for the fields left empty. |
| **Waiting on data** | No request can look its values up yet (no address entered, say). Not offered for import. |

**Import** (per row) sends `import-unattempted`, which calls `ImportStatus::runFirstImport()`, while
the page waits. **Import all (N)** queues the same imports for a cron instead (see "Import all"
below). Either way, each import goes through `runFirstImport()`:

- **Re-check first:** the place is looked up again for that one record. If it has been imported since
  the page loaded (another tab, Import all, a survey), or its fields have values now, nothing runs
  and the row says so. This also means only places the detection would list can be imported.
- **The import:** `Import::importMappedData()` with the place's plan (`importUnitPlan()`: for a
  repeating instrument, only that instrument's fields). It reads stored data as the project's cache
  settings allow, as a survey submission's import would, rather than bypassing the cache.
- **Audit trail:** logged like any run, as `API import: manual import by <username>` (or, from
  Import all, `API import: import all, requested by <username>`), so it joins the run list. A failure
  there is flagged and can be retried or dismissed like any other.
- **Who:** the same users who can retry, re-checked server-side. Double clicks are refused by the
  same 60-second lock as retries.
- **Not ready:** a place whose lookup values still can't be read is refused (`not_ready`) rather than
  imported, so it can't produce a failed run.
- The table lists the first 200 places; Import all covers every importable one, listed or not.

### Import all

**Import all (N)** works the same way as Retry all failed (`classes/ImportAll.php`):

- **Queued, not run in the page:** the button saves a job, the project setting `import-all-job`, listing
  every importable place at that moment. It's limited to the user's data access group, or to the
  filtered record when the record filter is set. The cron `adif_import_all` (every minute, up to 50
  seconds a run) works through it in the background, so the page can be left. It isn't held to the
  bulk refresh's off-peak hours.
- **Rate-limited:** each import waits for its turn under the server-wide `adif-imports-per-minute`
  limit, shared with the queue, Retry all and the refresh (see "The server-wide import limit"). When
  several projects have a job going, the cron takes turns between them.
- **Re-checked:** each place goes through `runFirstImport()` at its turn. Places imported since the job
  was queued (a survey, the row's own button), whose fields have values now, or whose lookup values are
  still missing are counted as skipped, not imported.
- **Progress and cancelling:** the Never imported card shows the job's progress (imported, needing
  attention, skipped, failed) and a **Cancel** button. It shows the last job's summary for a day after.
  One job runs per project at a time. Starting, cancelling and finishing are written to the module log
  (`ADIF import all requested / cancelled / done`).
- **Who:** the same users who can retry (Edit or above).

## Background imports

A survey submission no longer waits on the APIs. `redcap_survey_complete` only **queues** the
import (`ImportQueue::queueImport()`). The module's cron (`adif_import_queue` in `config.json`,
every 60 seconds) runs it through the same `Import::importMappedData()` and `ImportLog::logImportRun()`
as every other import. Fields usually fill within a minute or two of submission. A queued import
that fails is flagged here like any other run, and retried, dismissed or included in Retry all
failed the same way.

- **Opting out:** a project whose next survey pipes imported values straight away can tick *run each
  import while the survey respondent waits* (`import-immediately`) in its module settings. Imports
  then run in the hook, before the completion page, as they used to.
- **What's queued:** only imports that will call an API (`Import::importPlan()` has requests). An
  import with nothing to request finishes instantly, so it still runs in the hook. If the queue entry
  can't be written, the import also runs in the hook, so nothing is lost.
- **Where the queue lives:** the module log, like the runs. `ADIF import queued` is written per
  submission (record, event, instrument, instance and a `queue_id`). `ADIF import queue started` is
  written before each attempt, and `ADIF import queue done` (with the `run_id` and outcome) once
  it's finished. An import is pending until its done entry exists. Appending to the log is atomic,
  so simultaneous submissions can't overwrite each other's place in the queue. None of these messages
  starts with `API import`, so they're never read as runs.
- **The cron:** works through every enabled project's queue, oldest first, for up to four minutes
  per run, within the server-wide limit on background imports (see
  [below](#the-server-wide-import-limit)); the next run carries on from there. It sets `$_GET['pid']` to each project in turn, as the
  framework documents for crons, since settings are read for the current project. Code on the import
  path reads event names and the record ID field from `\Project` rather than `REDCap::isLongitudinal()`
  / `getRecordIdField()` / `getEventNames()`, which only work on a project's own pages. The API cache
  manager is rebuilt when the project changes, so one project's sources are never used for another.
- **Duplicates:** several entries for the same import (record, event, instrument, instance), such as
  a survey submitted twice before the cron ran, run once and are all closed with that run.
- **Crashes:** an import that stops partway (a PHP fatal error, a killed process) leaves its started
  entry without a done entry. It's tried again on the next run. After three starts it's given up on
  and logged as a failed run (*Needs attention*), which says why, so it can be retried once the cause
  is fixed.
- **On the Imports page:** a *Queued* card lists pending imports with their age, any partial
  attempts, and when the cron last ran (`import-queue-last-run`, a system setting). When the oldest
  has waited more than 10 minutes, the page says REDCap's cron may not be running. **Run queued imports
  now** (`run-import-queue`, the same users who can retry) works through the project's queue in short
  batches without waiting for the cron. The per-import lock it shares with the cron stops the same
  import from running twice at once. Queued imports are left off the *Never imported* list, which
  would otherwise show them in the minute before they run.

## Refresh all records

Re-runs the import for every record, so mapped fields hold what the APIs say now: after a new data
release, say, or a change to the mappings. Each place an import writes to that has data in the record
(an event; each instance of a repeating event or of a mapped repeating instrument, as for *Never
imported*) is imported again, and the values the APIs return replace what's there. A place whose
lookup values aren't in the record (no address yet) is counted as skipped, not run.

- **Requesting:** the *Refresh all records* card on this page; Project Design rights only, since it
  rewrites every record's mapped fields. The page posts to itself and redirects back
  (`?refresh=<outcome>`), so reloading doesn't post again. **Skip stored data and call the APIs** (on
  by default) passes `FetchMode::Live`, as a retry does. A user in a data access group refreshes only
  their group's records. One refresh per project at a time; it can be cancelled from the card, and the
  imports it already ran stay.
- **Running:** never during the request. The cron (`adif_import_refresh`, every minute) does nothing
  outside the off-peak hours in the module's system settings (`adif-refresh-start-hour`,
  `adif-refresh-end-hour`, server time; default 22:00 to 06:00; the same hour for both means any
  time). Inside them it works on one project for up to 50 seconds, within the server-wide limit on
  background imports (see [below](#the-server-wide-import-limit)). Projects with a refresh going take
  turns, one per cron run. It stops at the end of the window and carries on the next night.
- **State:** two project settings. `import-refresh-records` lists the records to work through, taken
  when the refresh was requested (records created later are imported by their own surveys);
  `import-refresh-job` holds the status (`waiting`, `running`, `done`, `cancelled`, `failed`) and
  counts. Progress is saved after every record, re-reading the job first so a cancel from the page
  wins. A cron run cut short loses at most one record's place, which is imported again.
- **Logging:** each import is a run like any other, described as "API import: bulk refresh requested by
  <user>", so failures are flagged and can be retried or dismissed here. The refresh itself is logged
  as "ADIF bulk refresh requested / cancelled / done / failed" (not read as runs). A large refresh
  fills the run list, which shows the latest 500 runs, and the project's Logging page.

## The server-wide import limit

One system setting, `adif-imports-per-minute` (default 20, at most 300), caps how many imports ADIF's
background jobs start a minute across the whole server, together: the survey import queue, Refresh all
records, Retry all failed and Import all (`classes/ImportRateLimit.php`). However many of those crons are running
at once, and for however many projects, their imports are spaced evenly at 60 / limit seconds apart.
Imports a user starts with a single click (one retry, one first import) and imports run while a
respondent waits (`import-immediately`) aren't counted. `adif-refresh-per-minute`, the setting's
earlier name, is still read when the new one is blank.

- **How:** a shared timetable. Before each import a job reserves the next free start time, one interval
  after the last one any process reserved, and sleeps until it. The next free time is the system
  setting `import-next-slot`, read straight from `redcap_external_module_settings` (not a per-request
  cache) and updated under a MySQL named lock (`GET_LOCK('adif-import-slots')`), so two crons can't
  take the same time. Without the lock it carries on, best effort.
- **Deadlines:** a job asks for a time before its own deadline (the end of its cron run's time
  budget). If the next free time is later, it reserves nothing and stops, and its next cron run carries
  on. A reservation more than two minutes ahead (a clock change, a raised limit) is dropped.
- **Sharing:** jobs get times in the order they ask, so a busy refresh and the survey queue interleave
  rather than one waiting for the other to finish. A burst of survey submissions is worked through at
  the limit's pace too, so set it with your busiest survey periods in mind.

## Files

- `classes/ImportStatus.php` - the trait: `importRuns()`, `importRecordStatus()`,
  `importRunNeedsAttention()`, `importRetryMappingCheck()`, `retryImportRun()`, `dismissImportRun()`,
  `importRetryTargets()`, `importNeverAttempted()`, `runFirstImport()`, `importOutcomeLabel()`
- `classes/ImportQueue.php` - the background queue: `queueImport()`, `importQueuePending()`,
  `processImportQueue()` (the cron), `processProjectImportQueue()`
- `classes/ImportRateLimit.php` - the server-wide limit: `importsPerMinute()`, `awaitImportSlot()`
- `classes/ImportRetryAll.php` - Retry all failed: `startRetryAll()`, `cancelRetryAll()`,
  `processRetryAllJobs()` (the cron)
- `classes/ImportAll.php` - Import all under Never imported: `startImportAll()`, `cancelImportAll()`,
  `processImportAllJobs()` (the cron)
- `classes/ImportRefresh.php` - the bulk refresh: `startImportRefresh()`, `cancelImportRefresh()`,
  `importRefreshWindow()`, `processImportRefreshJobs()` (the cron)
- `classes/Import.php` - `importPlan()`, the mapping rules shared by imports and the retry check
- `pages/Imports.php` - the page (linked from the project menu, see `config.json`)
- `js/imports.js` - the retry, dismiss and import buttons' wiring (`module.ajax('retry-import', ...)`,
  `module.ajax('dismiss-import', ...)`, `module.ajax('import-unattempted', ...)`)
- `css/imports.css` - page styles, building on `test_page.css`
- `ADIF.php` - the survey hook that queues imports; the `retry-import`, `dismiss-import`,
  `import-unattempted` and `run-import-queue` AJAX actions and the Imports page's JS bootstrap
