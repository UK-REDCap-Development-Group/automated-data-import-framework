# The Data Sources page

Everything a project imports from, in one place (`pages/Data_Sources.php`, formerly the Data Tables
page; `pages/Data_Tables.php` now redirects here). Two tabs:

- **API connections** (`pages/data_sources/apis.php`): add, edit, test, reorder and delete API sources.
- **Uploaded tables** (`pages/data_sources/tables.php`): the old Data Tables page, unchanged apart
  from its wiring (see `classes/DataTables.php`).

Each tab handles its own form posts. Every form posts `adif_tab`, so a save lands back on its tab.
Only users with Project Design rights see either tab, the same rule as REDCap's module configuration.

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

## Test connection

Sends one request to the saved URL plus a path, with the saved key, and shows the status, timing,
size and the start of the response. `[placeholders]` must be replaced with a real value first.

## Files

- `classes/ApiSources.php` - the trait: `getApiSourceConfigs()`, `saveApiSource()`,
  `deleteApiSource()`, `moveApiSource()`, `testApiSource()`
- `classes/Proxy.php` - `apiFieldsForSource()`, the per-source half of `getApiFields()`, used to
  check what a source would offer with unsaved settings
- `pages/Data_Sources.php`, `pages/data_sources/apis.php`, `pages/data_sources/tables.php`
- `js/data_sources.js` - show/hide dependent fields, the URL-change warning, Detect schema
  (the existing `detect-schema` AJAX action), the unsaved-changes warning
- `css/data_sources.css` - tabs and the API tab, on top of `test_page.css` and `data_tables.css`
