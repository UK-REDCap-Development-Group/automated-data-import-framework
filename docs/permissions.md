# Who can do what

ADIF reads each user's REDCap user rights in the project (`classes/Access.php`). There are three
levels, and each includes everything the one below it allows.

| Level | REDCap rights | ADIF pages |
| --- | --- | --- |
| **Design** | Project Design and Setup, or a super user | Change API sources, uploaded tables, field mappings and the module's settings. Send requests from the API Test page. Start or cancel a refresh of every record's imports. Everything below. |
| **Edit** | Data viewing rights of **View & Edit** on every instrument that has ADIF mappings (on any instrument, while nothing is mapped yet) | Resolve errors on the Import Status page: retry and dismiss failed runs, run imports that never ran, run the queue. Data Sources, Mapping and API Test are view-only. |
| **Read** | **Read Only** (or better) on at least one instrument | See every ADIF page; change nothing. |
| *None* | No data viewing rights on any instrument | No ADIF menu links, and the pages refuse to load. |

Edit needs View & Edit on *every* mapped instrument because an import writes the mapped fields on all
of the event's mapped instruments, not just the one that was submitted. A user who can edit only some
of them gets Read.

**Data access groups.** A user in a data access group sees only their group's records on the Import
Status page (run list, record lookup, queue and *Never imported*), and can only retry, dismiss or run
imports for those records. **Run queued imports now** runs only their group's queued imports. This
applies at every level, Design included. The Import Status page's debug notes, which quote a log entry,
are shown only to Design users who aren't in a group.

## Where it's enforced

- **Menu links and page access:** `redcap_module_link_check_display` returns no link to users with no
  access, and the framework also refuses those pages' URLs to them. Each page also calls
  `requireModuleView()` after its header. The API Test link appears only while the project's
  **Show the API Test page in the project menu?** setting is ticked.
- **Import Status actions** (`retry-import`, `dismiss-import`, `import-unattempted`,
  `run-import-queue` in `ADIF::redcap_module_ajax`): Edit or above, and a record in the user's group.
  `js/imports.js`, which draws the buttons, is loaded only for those users.
- **Refresh all records** (`pages/Imports.php`, see `classes/ImportRefresh.php`): Design only; a user
  in a data access group refreshes only their group's records.
- **Data Sources:** the tabs process posts only for Design users; others get the forms disabled.
  Saved API keys are never sent to the browser at any level.
- **Mapping:** `scripts/load_mappings.php` needs Read; `scripts/save_mappings.php` and
  `scripts/save_lookups.php` need Design (`ADIF::requireMappingAccess`). The page itself disables the
  pickers and hides Manage Forms and Upload Config for non-Design users; Export Config stays.
- **Module settings dialog:** REDCap's own rule (Project Design rights). `detect-schema` needs Design.

Every check runs on each request, so a change to someone's user rights applies on their next click.
