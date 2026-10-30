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
- **Who can dismiss:** the same users who can retry (Project Design rights); the
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
- **Who can retry:** users with Project Design rights (the same rule as configuring the module's
  sources), since a retry writes record data. The AJAX action re-checks this server-side.
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

## Files

- `classes/ImportStatus.php` - the trait: `importRuns()`, `importRecordStatus()`,
  `importRunNeedsAttention()`, `importRetryMappingCheck()`, `retryImportRun()`, `dismissImportRun()`,
  `importOutcomeLabel()`
- `classes/Import.php` - `importPlan()`, the mapping rules shared by imports and the retry check
- `pages/Imports.php` - the page (linked from the project menu, see `config.json`)
- `js/imports.js` - the retry and dismiss buttons' wiring (`module.ajax('retry-import', ...)`,
  `module.ajax('dismiss-import', ...)`)
- `css/imports.css` - page styles, building on `test_page.css`
- `ADIF.php` - the `retry-import` and `dismiss-import` AJAX actions and the Imports page's JS bootstrap
