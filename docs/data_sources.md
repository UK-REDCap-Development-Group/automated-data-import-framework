# The Data Sources page

Everything a project imports from, in one place (`pages/DataSources.php`, formerly the Data Tables
page; `pages/Data_Tables.php` now redirects here). Two tabs:

- **API connections** (`pages/data_sources/apis.php`): add, edit, test, reorder and delete API sources.
- **Uploaded tables** (`pages/data_sources/tables.php`): CSV and other delimited files, JSON files and
  Excel workbooks used as sources (see `classes/DataTables.php`, `classes/DataTableFormats.php` and
  "Uploaded tables" below).

Each tab handles its own form posts. Every form posts `adif_tab`, so a save lands back on its tab.
Only users with Project Design rights can change anything on either tab, the same rule as REDCap's module
configuration. Other users with ADIF access see both tabs view-only: every field disabled, no add, upload,
test, reorder, replace or delete controls, and URLs with any key in them masked. Posts from them are refused
server-side. See [permissions.md](permissions.md).

## Same settings as REDCap's dialog

Nothing new is stored. The API tab reads and writes the `api-sources` repeatable group from
`config.json`, so REDCap's configuration dialog and this page edit the same values, and changes made
in either place show up in the other. The dialog itself now opens with a note linking here
(`js/schema_detect.js`).

How the framework stores the group, and what `classes/ApiSources.php` keeps consistent:

- each sub-setting (`api`, `api-key`, `schema`, `cache-mode`, ...) is a project setting holding an
  array with one entry per source, by position;
- the group key `api-sources` holds one `true` per source, which is what the dialog counts.

Every write keeps all of these the same length. Values are saved the way the dialog saves them:
trimmed text (null when blank), `true`/`false` for checkboxes, a listed choice for dropdowns, and the
schema as a JSON string.

The sub-settings are read from `config.json` itself, so one added there later is carried through
saves, deletes and moves automatically, and shown under **Other settings** with config.json's label
until the page gets a proper field for it.

## Safety

- **API keys** are never sent to the browser. A saved key can be kept, replaced or removed. Test
  results and the module log mask key-like query parameters (`api_key=`, `token=`, ...).
- **Deleting or reordering** a source moves every sub-setting's entry together, *and* re-keys the
  Mapping page's lookup-field choices (`lookup-fields`, keyed by source position), so they stay with
  their source. REDCap's dialog doesn't re-key these, so deleting a source there shifts later
  sources' lookup choices onto the wrong source. Cached responses are keyed by URL, so they're
  unaffected either way.
- **Disconnecting mapped fields**: a save that would leave mapped fields unable to find their API
  field (a changed URL when the schema doesn't name its endpoints, or fields removed from the
  schema) is held back with the list of fields and a **Save anyway** checkbox. Deleting a source
  lists the mapped fields that read from it before confirming.
- **Stale pages**: every action sends the source's URL as the page showed it. If the source at that
  position has changed since (another tab, or the dialog), nothing is saved.
- Changes are logged to the module log as `API source: added | saved | deleted | moved`, with the URL
  only.

## API keys

A saved key is added to every request by `Proxy::sendApiRequest`, where the source's **Where the API
expects the key** setting says (`api-key-location`, with `api-key-name`; see `Proxy::apiKeyPlacement`):

- **Authorization: Bearer header** (the default, and what a source saved before the setting existed uses)
- **A named request header**, e.g. `X-API-Key` (the default name when none is given)
- **A named query parameter**, e.g. `key` (the default name when none is given)

The key is never written into a URL or a schema path, so it stays out of the cache key, the import log and
the Mapping page. What a request reports as sent (`requestUrl`, `requestHeaders`) shows `[redacted]` in its
place, and it's removed from error messages, which quote the URL.

## Responses that are tables

Some APIs answer with a table: a list of rows whose first row names the columns (the Census API's
`[["NAME","B19013_001E",...],["Census Tract 1...","52000",...]]`). With **Responses are tables** ticked
(`response-table`), ADIF turns each later row into a record named by those columns
(`Proxy::apiTableRows`), the same way an uploaded JSON table is read, so a value is mapped as
`[0].B19013_001E` rather than by position (`[1][7]`). Responses are cached as the API sent them and
converted as they're read (`ApiCache::fetchApiJson`), so ticking or unticking the box doesn't need the cache
cleared. **Detect schema** and **Test connection** use the setting too, and a sample response that looks like
a table while the box is unticked says so.

## Test connection

Sends one request to the saved URL plus a path, with the saved key, and shows the status, timing,
size and the start of the response. `[placeholders]` must be replaced with a real value first.

## Uploaded tables

Laid out like the API tab, so the two read the same way:

- **Storage card** at the top: the same card as the API tab (`adif_ds_storage_card()` in
  `pages/DataSources.php`), which both tabs call. Its line ends with the per-file limit, or what to do
  when storage is full.
- **Upload tables**, always shown (uploading is what the tab is for), its limits beside its title.
  The per-file size shown is the limit in force (the lower of ADIF's setting and PHP's upload limits),
  with no explanation of where it comes from: users can't change it.
  Administrators see why in the Control Center's configuration dialog: a read-only
  panel above the data table settings (`adif-php-upload-limits`, a descriptive setting filled in by
  `ADIF::redcap_module_configuration_settings`, REDCap 11+, from `DataTables::phpUploadLimitsHtml()`)
  lists `upload_max_filesize`, `post_max_size`, `max_file_uploads` and `memory_limit`, the largest upload
  a project gets now, in red why it's below ADIF's setting (`DataTables::dataTableUploadLimitReasons()`), and which php.ini file sets each upload limit (noting when
  the web server or a PHP-FPM pool overrides it) and the folder a new .ini file would go in.
  Several files can be chosen at once (`table_files[]`). **Each file becomes its own table** with its own
  lookup column, settings and group of fields on the Mapping page; nothing is merged. The page lists the
  files chosen, checks each one before anything is sent (not a CSV/TSV/TXT, JSON or .xlsx file, an old
  .xls workbook, larger than the per-file limit) and the selection as a whole against PHP's `max_file_uploads` and `post_max_size`, which would
  otherwise drop files or empty the whole post. A selection that can't work disables the button;
  files that would push the project past its storage are flagged as "may not fit". The name field only
  applies to a single file: with several, each table is named after its file (and a name already in
  use is refused for that file alone).
- **On the server** each file is added on its own, in the order chosen (`adif_dt_uploaded_files()`
  normalises `$_FILES`; a form posting `table_file` still works), so one bad file, or storage running out
  partway, doesn't stop the others. The result lists the tables added (linked, and marked **New** on
  their cards) and each file that wasn't, with why. Every added table is written to the audit log.
- **One collapsible card per table**, its header showing the name, rows × columns, the fields it offers,
  how many REDCap fields are mapped to it (`DataTables::dataTableMappedFields()`; the tooltip names them),
  the lookup column and the size. A card opens when it's the only table, the one an action was just taken
  on, or the only one just added. Inside: the file, separator and uploader, the summary, settings,
  preview, test, and replace or delete (the delete confirmation says how many mapped fields it affects).
- With **several tables**, a line above them gives their count, total size and rows, with **Expand all**
  and **Collapse all**.

### File formats

Every table is stored and read as delimited text, so lookups, previews, the Mapping page's fields and
replacing a file work the same whatever was uploaded. JSON and Excel files are converted to CSV once,
on upload (`DataTableFormats`), and the card says what the file was ("Excel workbook, sheet "Tracts",
stored as CSV"). The table records it as `format` (`delimited`, `json` or `xlsx`; tables from before
have none and are `delimited`) and, for a workbook, `sheet`.

- **Delimited text** (.csv, .tsv, .tab, .txt): comma, tab, semicolon or pipe separated, found from the
  header line. Anything that isn't UTF-8 is read as Windows-1252.
- **JSON** (.json, .geojson, .jsonl, .ndjson): a list of objects, one per row; a list of lists whose first
  names the columns (the Census API's form); an object holding such a list under a key (`{"data": [...]}`,
  the longest list is read); a GeoJSON FeatureCollection (each feature's properties, plus its `id`; the
  geometry is left out); or an object of objects keyed by id (the id becomes a `key` column). Nested
  objects become dotted columns (`properties.svi`, mapped as `[0][properties.svi]`); nested lists are
  kept as JSON text; `null` is blank and booleans are `true`/`false`.
- **Excel workbooks** (.xlsx): read with PHP's zip and XML extensions, no library. **Each visible sheet
  becomes its own table**, named "*name* - *sheet*" (just *name* when there's one sheet). A sheet's
  first non-blank row names the columns, from its leftmost to its rightmost filled cell; blank rows are
  skipped. Hidden sheets, and sheets that are empty or have one column, aren't read, and the upload says
  which. Formulas give their saved result, error values (#N/A, #DIV/0!) are blank, dates are
  `YYYY-MM-DD` (with ` HH:MM:SS` when there's a time, 1904 workbooks included), booleans `TRUE`/`FALSE`,
  and numbers are written to 15 significant digits, whole numbers in full (so a GEOID stays
  `21067000100`). Old .xls and password-protected workbooks are refused with a message to save as
  .xlsx or CSV. When replacing a table's file with a workbook, the sheet with the table's sheet name is
  read (or the only sheet).

The per-file limit applies to what's uploaded and also to what's stored once converted, since a
compressed workbook can be several times larger as CSV; a conversion stops as soon as it passes the
limit. A workbook part over 25 times the limit, unzipped, isn't read at all.

## Storage and cached responses

Uploaded tables and cached API responses share one storage allowance per project: the
`adif-table-max-project-mb` system setting, 32 MB by default (`DataTables::projectStorageUsage()`).
Both tabs show it as the same bar (`adif_ds_storage_bar()` in `pages/DataSources.php`), with a segment
for the tables and one for the cache. When it's full, new uploads and replacements are refused
(`checkDataTableProjectLimit()`), and the message suggests deleting a table or clearing cached
responses. Caching carries on regardless, so imports never fail for lack of space. A cached
response's size is the length of what's stored, which for an identifying source is the encrypted
form. Only the project's own entries count. Responses from public, keyless sources shared with other
projects don't count towards it; the API tab notes how much they take.

Each source's cached responses are shown in its own panel on the API connections tab
(`ApiSources::apiCacheUsage()`). The panel gives how many there are, how much space they take and
when they were stored, and its *Cached* badge shows the size. **Clear cached responses** (Design
rights) removes the project's entries for that source through `ApiSources::clearApiSourceCache()`, so
its next imports call the API again and the space goes back to the allowance. The cache keys entries
by the source's URL, so two sources with the same URL share them (the panel says so, and clearing one
clears both). Responses left by a source that's since been deleted, or had its URL changed, are noted
under the storage bar with their own clear button. Each clear is written to the module log with the
source's URL (any key masked) and how many responses were removed.

Viewers without Design rights see the bar and the figures but not the buttons, and the server refuses
a clear from them like any other change.

## When a project is deleted

A project's uploaded tables are files in REDCap's file storage, under the project. When the project
is permanently deleted, ADIF's scheduled cleanup (weekly by default; administrators set when in
ADIF's system settings) marks them deleted, so REDCap's cron removes them. It also removes ADIF's settings for the project, API keys included.
While the project is only marked deleted, the tables are kept, so restoring it restores them. See
"When a project is deleted" in `docs/caching.md` and `classes/ProjectCleanup.php`.

## Files

- `classes/ApiSources.php` - the trait: `getApiSourceConfigs()`, `saveApiSource()`,
  `deleteApiSource()`, `moveApiSource()`, `testApiSource()`
- `classes/Proxy.php` - `apiFieldsForSource()`, the per-source half of `getApiFields()`, used to
  check what a source would offer with unsaved settings
- `pages/DataSources.php`, `pages/data_sources/apis.php`, `pages/data_sources/tables.php`
- `js/data_sources.js` - show/hide dependent fields, the URL-change warning, Detect schema
  (the existing `detect-schema` AJAX action), the unsaved-changes warning
- `css/data_sources.css` - tabs and the API tab, on top of `test_page.css` and `data_tables.css`
