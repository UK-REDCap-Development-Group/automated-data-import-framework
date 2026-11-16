# Audit log

ADIF records the user actions that change what it imports or sends to outside services, or that can
overwrite or remove data (`classes/AuditLog.php`). Each one is written to:

- the project's **Logging** page, as `ADIF: <action>`. REDCap records the user and time, and users can't
  edit or remove these entries.
- the **module log**, as `ADIF audit: <action>`, with the same details as parameters.

| Action | Logged as | Details |
| --- | --- | --- |
| Mapping page: a mapping chosen, changed or cleared, a form added or removed | field mappings changed | Forms added/removed; mappings added, changed and removed (`form.field = path (from endpoint)`) |
| Mapping page: Upload Config | mapping configuration uploaded | As above, for everything the file replaced |
| Mapping page: a lookup field chosen or changed | lookup fields changed | Which field now fills each source's `[placeholder]`, and what it was |
| Data Sources: an API source added, saved, deleted or moved | API sources changed | What changed, with mapped fields that will now be skipped |
| REDCap's module configuration dialog, in a project | project settings changed in the module configuration dialog | The same comparison as the Data Sources page, plus the project's other settings |
| Control Center: ADIF's system settings | system settings changed in the module configuration dialog | Module log only |
| Data Sources: Clear cached responses | cached API responses cleared | Source and number removed |
| Data Sources: an uploaded table added, replaced, re-configured or deleted | uploaded table added / file replaced / settings changed / deleted | Table, file, rows, changed settings |
| Import Status: Refresh all records, started or cancelled | refresh of all records requested / cancelled | Records, whether stored data is reused |
| Import Status: Retry all failed, started or cancelled | retry of all failed imports requested / cancelled | Failed imports, record filter, whether stored data is reused |
| Import Status: Import all (Never imported), started or cancelled | import of never-imported records requested / cancelled | Records and imports, record filter |

The bulk actions already had module log entries (`ADIF bulk refresh requested` and so on), so they only
add the Logging page entry.

**Not logged here, on purpose.** Individual imports, whether after a survey or from the Import Status page,
already get one Logging page entry per run (see `ImportLog`), and dismissing an import error is logged by
`ImportStatus`. Page views, connection tests and saves that change nothing aren't logged. The Mapping page
saves after every change, so each entry is one real change.

**What's never logged.** Record values and API keys are never logged. An API key change shows only as
added, replaced or removed, a key in a URL's query string is masked, and a schema change shows only as
"schema changed".

**Changes made in the configuration dialog.** REDCap gives a module no "before" for these, so ADIF keeps
a copy of the settings (`audit-settings-snapshot`, with keys and schemas only as fingerprints). It updates
the copy on every change it logs, and takes the first one when the Data Sources page or the module's
Configure button is shown. A dialog save with no earlier copy is logged without a list of changes.
