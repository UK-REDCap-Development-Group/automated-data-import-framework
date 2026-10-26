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

## Re-running an import

The **Retry this import** button on a flagged run (and **Retry all failed** in the toolbar) re-runs
the import for the same record, event, instrument and repeat instance through
`ImportStatus::retryImportRun()`, which calls the existing `Import::importMappedData()`. Nothing
about the import engine is special-cased for retries.

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
  `importRunNeedsAttention()`, `retryImportRun()`, `importOutcomeLabel()`
- `pages/Imports.php` - the page (linked from the project menu, see `config.json`)
- `js/imports.js` - the retry buttons' wiring (`module.ajax('retry-import', ...)`)
- `css/imports.css` - page styles, building on `test_page.css`
- `ADIF.php` - the `retry-import` AJAX action and the Imports page's JS bootstrap
