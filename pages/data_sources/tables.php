<?php
/*
 * The Uploaded tables tab of the Data Sources page (pages/DataSources.php): upload CSVs or other
 * delimited files, JSON files or Excel workbooks to use as data sources. Each file (each sheet, for a
 * workbook) becomes its own table, whose columns are offered on
 * the Mapping page alongside the API sources' fields, and imports read the row matching the record.
 * See classes/DataTables.php. Formerly the whole of the Data Tables page.
 *
 * Laid out like the API connections tab: the shared Storage card, the Upload card (always shown, since
 * uploading is what the tab is for; several files can be chosen at once, and are checked against the
 * size limits before anything is sent), then one collapsible card per table whose header says what it holds. With several tables, a line above them
 * gives their count and total size, and opens or closes them all.
 */

/** @var \UKModules\ADIF\ADIF $module */

if (!defined('ADIF_DATA_SOURCES_PAGE')) {
    exit;   // only ever included by pages/DataSources.php, which checks the user's rights first
}

// $canManage (set by pages/DataSources.php): whether this user can change tables. Without it the tab is
// view-only: each table's details, settings (disabled) and preview, but no upload, test, replace or delete.

// The files posted by the upload form, as a list of single-file $_FILES entries. The form posts
// table_files[] (several at once); table_file, a single file, is a form from before that.
function adif_dt_uploaded_files()
{
    $files = $_FILES['table_files'] ?? null;
    if (is_array($files) && is_array($files['name'] ?? null)) {
        $list = [];
        foreach ($files['name'] as $i => $name) {
            if ((int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $list[] = ['name' => $name, 'type' => $files['type'][$i] ?? '', 'tmp_name' => $files['tmp_name'][$i] ?? '',
                'error' => (int) $files['error'][$i], 'size' => (int) ($files['size'][$i] ?? 0)];
        }
        return $list;
    }
    $single = $_FILES['table_file'] ?? null;
    return is_array($single) && (int) ($single['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE ? [$single] : [];
}

$error = null;
$success = null;
$notices = [];
$focusTable = null;      // the table an action was taken on, scrolled to and opened after the page loads
$testResult = null;      // ['table' => id, 'value' => ..., 'result' => queryDataTable() result]
$addedTables = [];       // tables an upload just added: [id => name]
$uploadFailures = [];    // files an upload couldn't add: [[filename, why], ...]

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$canManage) {
    $error = 'Only users with Project Design rights can change data sources.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['adif_action'] ?? '') === 'upload') {
    // Each file is added on its own, in the order chosen, so one bad file (or the project running out of
    // storage partway) doesn't stop the others. A name typed in the form only applies to a single file;
    // with several, each table is named after its file.
    $uploads = adif_dt_uploaded_files();
    if (empty($uploads)) {
        $error = 'Choose a file to upload.';
    }
    $name = count($uploads) === 1 ? (string) ($_POST['table_name'] ?? '') : '';
    foreach ($uploads as $upload) {
        $filename = basename((string) ($upload['name'] ?? 'file'));
        try {
            // One table, or one per sheet of an Excel workbook
            $outcome = $module->addDataTables($upload, $name);
            foreach ($outcome['tables'] as $table) {
                $addedTables[$table['id']] = $table['name'];
                $module->logDataTableChange('upload', null, $table);
            }
            foreach ($outcome['notices'] as $notice) {
                $notices[] = (count($uploads) > 1 ? "$filename: " : '') . $notice;
            }
        } catch (\Throwable $e) {
            $uploadFailures[] = [$filename, $e->getMessage()];
        }
    }
    if (count($addedTables) === 1 && empty($uploadFailures)) {
        $table = $module->getDataTable(array_key_first($addedTables));
        $success = "Added \"{$table['name']}\" with " . number_format($table['row_count'])
            . ' rows. Check which column its rows are looked up by below, then map its fields on the Mapping page.';
        $focusTable = $table['id'];
    } elseif (!empty($addedTables)) {
        $success = 'Added ' . count($addedTables) . ' table' . (count($addedTables) === 1 ? '' : 's')
            . ', marked New below. Check which column each one\'s rows are looked up by, then map their fields on the'
            . ' Mapping page.';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['adif_action'] ?? '');
    $tableId = (string) ($_POST['table_id'] ?? '');
    $tableBefore = null;   // for the audit log (see AuditLog::logDataTableChange)

    try {
        if (in_array($action, ['settings', 'replace'], true)) {
            $tableBefore = $module->getDataTable($tableId);
        }
        switch ($action) {
            case 'settings':
                $outcome = $module->updateDataTableSettings($tableId, $_POST);
                $success = "Saved the settings for \"{$outcome['table']['name']}\".";
                break;

            case 'replace':
                $outcome = $module->replaceDataTableFile($tableId, $_FILES['table_file'] ?? null);
                $success = "Replaced the file for \"{$outcome['table']['name']}\"; it now has "
                    . number_format($outcome['table']['row_count']) . ' rows.';
                break;

            case 'delete':
                $outcome = $module->deleteDataTable($tableId);
                $success = "Deleted \"{$outcome['table']['name']}\". Fields mapped from it will be skipped on import "
                    . 'until they\'re mapped to another source.';
                break;

            case 'test':
                $table = $module->getDataTable($tableId);
                $value = (string) ($_POST['test_value'] ?? '');
                $path = str_replace('[' . $table['placeholder'] . ']', rawurlencode($value), $module->dataTablePath($table));
                $testResult = ['table' => $tableId, 'value' => $value, 'result' => $module->queryDataTable($module->dataTableSource($tableId), $path)];
                $outcome = ['table' => $table, 'notices' => []];
                break;

            default:
                throw new \Exception('Unknown action.');
        }

        $notices = $outcome['notices'];
        $focusTable = $action === 'delete' ? null : $outcome['table']['id'];
        if ($action !== 'test') {
            $module->logDataTableChange($action, $tableBefore, $outcome['table']);
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
        $focusTable = $tableId !== '' ? $tableId : null;
    }
}

$tables = $module->getDataTables();
$lookups = $module->getProjectSetting('lookup-fields') ?: [];
$mappedByTable = $module->dataTableMappedFields();
// Storage: each file is capped, and so is the total across the project's tables and cached API
// responses (system settings; see adif_ds_storage_bar)
// The limit in force: ADIF's per-file setting or PHP's upload limits, whichever is lower. Only the size
// is shown here; why it's lower than the setting is explained to administrators in the module's
// configuration dialog (DataTables::phpUploadLimitsHtml).
$fileLimitBytes = $module->maxDataTableUploadBytes();
$spaceLeftBytes = $module->dataTableSpaceLeftBytes();
$uploadFull = $spaceLeftBytes === 0;
$storage = $module->projectStorageUsage();
// What PHP accepts in one upload, for checking the chosen files before they're sent: how many files,
// and how many bytes in all (a post over that arrives empty)
$iniBytes = function ($value) {
    $value = trim((string) $value);
    $number = (float) $value;
    switch (strtolower(substr($value, -1))) {
        case 'g': $number *= 1024;
        case 'm': $number *= 1024;
        case 'k': $number *= 1024;
    }
    return (int) $number;
};
$maxFiles = max(1, (int) (ini_get('max_file_uploads') ?: 20));
$maxPostBytes = $iniBytes(ini_get('post_max_size'));
// The extensions the upload accepts, for the file inputs and the check before sending (see
// DataTables::readDataTableUpload, which has the final say)
$acceptExtensions = '.csv,.tsv,.tab,.txt,.json,.geojson,.jsonl,.ndjson,.xlsx';
$acceptTypes = 'text/csv,text/tab-separated-values,application/json,application/geo+json,'
    . 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

// A small HTML table of rows, columns in file order
function adif_rows_table($columns, $rows, $highlight = null)
{
    $html = '<div class="adif-dt-scroll"><table class="adif-dt-rows"><thead><tr>';
    foreach ($columns as $column) {
        $html .= '<th' . ($column === $highlight ? ' class="adif-dt-key"' : '') . '>' . adif_h($column) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr>';
        foreach ($columns as $column) {
            $html .= '<td' . ($column === $highlight ? ' class="adif-dt-key"' : '') . '>' . adif_h($row[$column] ?? '') . '</td>';
        }
        $html .= '</tr>';
    }
    return $html . '</tbody></table></div>';
}
?>

    <p>Upload CSVs or other delimited files, JSON files or Excel workbooks to use them as data sources, the same way as
        an API. Each file becomes its own table (each sheet, for a workbook): choose the column that identifies each row (such as a census tract GEOID), and its columns are
        offered on the <a href="<?= adif_h($module->getUrl('pages/Mapping.php')) ?>">Mapping</a> page next to your API
        sources' fields. When a record is imported, the row matching the record's value is read into the mapped fields.</p>

    <?php if ($error): ?>
        <div class="adif-alert adif-alert-error" role="alert"><?= adif_h($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="adif-alert adif-dt-success" role="status"><?= adif_h($success) ?>
            <?php if (count($addedTables) > 1 || (!empty($addedTables) && !empty($uploadFailures))): ?>
                <ul class="adif-dt-results">
                    <?php foreach ($addedTables as $id => $tableName): ?>
                        <li><a href="#adif-table-<?= adif_h($id) ?>"><?= adif_h($tableName) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($uploadFailures)): ?>
        <div class="adif-alert adif-alert-error" role="alert">
            <?= count($uploadFailures) === 1 ? 'This file wasn\'t added:' : count($uploadFailures) . ' files weren\'t added:' ?>
            <ul class="adif-dt-results">
                <?php foreach ($uploadFailures as [$filename, $why]): ?>
                    <li><strong><?= adif_h($filename) ?></strong>: <?= adif_h($why) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    <?php foreach ($notices as $notice): ?>
        <div class="adif-alert adif-alert-empty"><?= adif_h($notice) ?></div>
    <?php endforeach; ?>

    <?= adif_ds_storage_card($storage, $uploadFull
        ? 'No more tables can be uploaded: delete or replace a table that\'s no longer needed, clear cached responses on the'
            . ' <a href="' . adif_h(adif_ds_url($module, 'apis')) . '">API connections</a> tab, or ask a REDCap administrator'
            . ' to raise the limit.'
        : 'Each table\'s size is shown on its card; a file can be up to ' . adif_h(adif_bytes($fileLimitBytes)) . '.') ?>

    <?php if ($canManage): ?>
        <section class="adif-dt-card adif-dt-upload" id="adif-table-upload" aria-labelledby="adif-table-upload-title">
            <div class="adif-dt-upload-head">
                <h4 id="adif-table-upload-title">Upload tables</h4>
                <span class="adif-ds-badges">
                    <span class="adif-status-pill adif-status-none" title="Also .tab, .geojson, .jsonl and .ndjson">.csv, .tsv, .txt, .json or .xlsx</span>
                    <span class="adif-status-pill adif-status-none">up to <?= adif_h(adif_bytes($fileLimitBytes)) ?> each</span>
                    <?php if ($uploadFull): ?>
                        <span class="adif-status-pill adif-status-error">Storage full</span>
                    <?php endif; ?>
                </span>
            </div>
            <form method="post" action="<?= adif_h(adif_ds_url($module, 'tables')) ?>" enctype="multipart/form-data"
                  class="adif-dt-upload-form" id="adif-dt-upload-form"
                  data-max-files="<?= $maxFiles ?>" data-max-file-bytes="<?= (int) $fileLimitBytes ?>"
                  data-max-post-bytes="<?= (int) $maxPostBytes ?>" data-space-left="<?= (int) $spaceLeftBytes ?>">
                <?= adif_ds_form_fields($csrf, 'tables') ?>
                <input type="hidden" name="adif_action" value="upload">
                <div class="adif-form-row">
                    <div class="adif-form-field">
                        <label for="adif-dt-files">Files</label>
                        <input type="file" name="table_files[]" id="adif-dt-files" multiple
                               accept="<?= adif_h($acceptExtensions . ',' . $acceptTypes) ?>" required<?= $uploadFull ? ' disabled' : '' ?>>
                    </div>
                    <div class="adif-form-field" id="adif-dt-name-field">
                        <label for="adif-dt-name">Name (optional; defaults to the file name)</label>
                        <input type="text" name="table_name" id="adif-dt-name" maxlength="100" placeholder="e.g. CDC SVI 2022 - Kentucky tracts">
                    </div>
                </div>
                <ul class="adif-dt-chosen" id="adif-dt-chosen" hidden></ul>
                <p class="adif-dt-chosen-summary" id="adif-dt-chosen-summary" aria-live="polite" hidden></p>
                <div class="adif-dt-upload-actions">
                    <button type="submit" class="btn btn-primaryrc btn-sm" id="adif-dt-upload-button"<?= $uploadFull ? ' disabled' : '' ?>>Upload</button>
                    <span class="adif-dt-hint">Choose one file or several (up to <?= $maxFiles ?> at a time). Each becomes its own
                        table, with its own lookup column and settings.</span>
                </div>
                <p class="adif-dt-hint">The first row must name the columns. Comma, tab, semicolon and pipe separated files are
                    all read. In an Excel workbook (.xlsx), each visible sheet becomes its own table, named
                    &ldquo;<em>name</em> - <em>sheet</em>&rdquo;, and its first non-blank row names the columns. A JSON file
                    can be a list of objects (one per row), a list of lists whose first names the columns (as the Census API
                    returns), or a GeoJSON FeatureCollection. JSON and workbooks are stored as CSV. A table's name can't be
                    changed later, since mapped fields are matched by it; with several files, each table is named after its
                    file.</p>
            </form>
        </section>
    <?php endif; ?>

    <?php if (empty($tables)): ?>
        <div class="adif-alert adif-alert-empty">No tables have been uploaded to this project yet.</div>
    <?php elseif (count($tables) > 1): ?>
        <div class="adif-dt-list-head">
            <span><?= count($tables) ?> tables &middot; <?= adif_h(adif_bytes(array_sum(array_column($tables, 'bytes')))) ?>
                &middot; <?= number_format(array_sum(array_column($tables, 'row_count'))) ?> rows in all</span>
            <span>
                <button type="button" class="btn btn-link btn-sm" data-adif-tables="open">Expand all</button>
                <button type="button" class="btn btn-link btn-sm" data-adif-tables="close">Collapse all</button>
            </span>
        </div>
    <?php endif; ?>

    <?php foreach ($tables as $table): ?>
        <?php
        $id = $table['id'];
        $source = $module->dataTableSource($id);
        $fieldCount = count($module->dataTableFields($table));
        $mappedFields = $mappedByTable[$id] ?? [];
        $lookupField = $module->getLookupField($source, $table['placeholder']);
        $lookupChosen = isset($lookups[$source][$table['placeholder']]);
        $isFocus = $focusTable === $id;
        $isNew = isset($addedTables[$id]);
        // Open: the table an action was just taken on, the only table, or the only one just added
        $isOpen = $isFocus || count($tables) === 1 || ($isNew && count($addedTables) === 1)
            || ($testResult !== null && $testResult['table'] === $id);
        try {
            $preview = $module->dataTablePreview($table, 10);
            $previewError = null;
        } catch (\Throwable $e) {
            $preview = [];
            $previewError = $e->getMessage();
        }
        ?>
        <details class="adif-dt-card adif-ds-source adif-ds-table" id="adif-table-<?= adif_h($id) ?>" <?= $isOpen ? 'open' : '' ?>>
            <summary>
                <h4 class="adif-ds-table-name"><?= adif_h($table['name']) ?></h4>
                <span class="adif-ds-badges">
                    <?php if ($isNew): ?>
                        <span class="adif-status-pill adif-status-ok">New</span>
                    <?php endif; ?>
                    <span class="adif-status-pill adif-status-none"><?= number_format($table['row_count']) ?> rows &times; <?= count($table['columns']) ?> columns</span>
                    <span class="adif-status-pill adif-status-none"><?= $fieldCount ?> field<?= $fieldCount === 1 ? '' : 's' ?></span>
                    <span class="adif-status-pill <?= count($mappedFields) > 0 ? 'adif-status-ok' : 'adif-status-none' ?>"
                          title="<?= adif_h(empty($mappedFields) ? 'No REDCap field is mapped to this table yet' : 'Mapped: ' . implode(', ', array_keys($mappedFields))) ?>"><?= count($mappedFields) ?> mapped</span>
                    <span class="adif-status-pill adif-status-none" title="Rows are looked up by this column">by <?= adif_h($table['key_column']) ?></span>
                    <span class="adif-status-pill adif-status-none"><?= adif_h(adif_bytes($table['bytes'])) ?></span>
                </span>
            </summary>

            <div class="adif-dt-meta">
                <span><?= adif_h($table['filename']) ?></span>
                <span><?= adif_h($module->dataTableFormatLabel($table)) ?></span>
                <span>Uploaded <?= adif_h($table['uploaded_at']) ?><?= $table['uploaded_by'] !== '' ? ' by ' . adif_h($table['uploaded_by']) : '' ?></span>
            </div>

            <p class="adif-dt-summary">
                Offers <strong><?= $fieldCount ?> field<?= $fieldCount === 1 ? '' : 's' ?></strong> on the Mapping page,
                under <strong><?= adif_h($module->dataTableEndpoint($table)) ?></strong><?= !empty($mappedFields)
                    ? ', ' . count($mappedFields) . ' of them mapped (' . adif_h(implode(', ', array_slice(array_keys($mappedFields), 0, 12)))
                        . (count($mappedFields) > 12 ? ', …' : '') . ')' : '' ?>. Rows are found with
                <code><?= adif_h($module->dataTablePath($table)) ?></code>, where
                <code>[<?= adif_h($table['placeholder']) ?>]</code> is read from
                <?php if ($lookupChosen): ?>
                    the REDCap field <strong><?= adif_h($lookupField) ?></strong>.
                <?php else: ?>
                    a REDCap field named <strong><?= adif_h($lookupField) ?></strong> unless another is chosen on the
                    Mapping page.
                <?php endif; ?>
            </p>

            <?php if ($canManage): ?>
            <form method="post" action="<?= adif_h(adif_ds_url($module, 'tables')) ?>" class="adif-dt-settings">
                <?= adif_ds_form_fields($csrf, 'tables') ?>
                <input type="hidden" name="adif_action" value="settings">
                <input type="hidden" name="table_id" value="<?= adif_h($id) ?>">
            <?php else: ?>
            <form class="adif-dt-settings adif-ds-readonly" onsubmit="return false;">
                <fieldset disabled class="adif-ds-readonly-fields">
            <?php endif; ?>
                <div class="adif-form-row">
                    <div class="adif-form-field">
                        <label for="adif-key-<?= adif_h($id) ?>">Look rows up by column</label>
                        <select name="key_column" id="adif-key-<?= adif_h($id) ?>">
                            <?php foreach ($table['columns'] as $column): ?>
                                <option value="<?= adif_h($column) ?>" <?= $column === $table['key_column'] ? 'selected' : '' ?>><?= adif_h($column) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="adif-form-field">
                        <label for="adif-placeholder-<?= adif_h($id) ?>">Placeholder for the record's value</label>
                        <input type="text" name="placeholder" id="adif-placeholder-<?= adif_h($id) ?>"
                               value="<?= adif_h($table['placeholder']) ?>" pattern="[a-z][a-z0-9_]*"
                               title="Lowercase letters, numbers and underscores, starting with a letter">
                    </div>
                </div>
                <label class="adif-dt-check">
                    <input type="checkbox" name="loose_match" value="1" <?= !empty($table['loose_match']) ? 'checked' : '' ?>>
                    Match loosely, ignoring letter case, surrounding spaces and leading zeros (so <code>01001020100</code>
                    finds <code>1001020100</code>, as Excel often saves it)
                </label>
                <fieldset class="adif-dt-layout">
                    <legend>Rows per value</legend>
                    <label class="adif-dt-check">
                        <input type="radio" name="layout" value="wide" <?= $table['layout'] !== 'long' ? 'checked' : '' ?>>
                        One row each: every column is a field, read as <code>[0].column</code>
                    </label>
                    <label class="adif-dt-check">
                        <input type="radio" name="layout" value="long" <?= $table['layout'] === 'long' ? 'checked' : '' ?>>
                        Several rows each, one per value of
                        <select name="measure_column" aria-label="Column that tells a value's rows apart">
                            <option value="">-- choose a column --</option>
                            <?php foreach ($table['columns'] as $column): ?>
                                <option value="<?= adif_h($column) ?>" <?= $column === $table['measure_column'] ? 'selected' : '' ?>><?= adif_h($column) ?></option>
                            <?php endforeach; ?>
                        </select>
                        : fields are read as <code>[column=value].other_column</code>, like CDC PLACES' measures
                    </label>
                    <?php if ($table['layout'] === 'long'): ?>
                        <p class="adif-dt-hint"><?= count($table['measure_values']) ?> values of
                            <?= adif_h($table['measure_column']) ?>: <?= adif_h(implode(', ', array_slice($table['measure_values'], 0, 12))) ?><?= count($table['measure_values']) > 12 ? ', …' : '' ?></p>
                    <?php endif; ?>
                </fieldset>
                <?php if ($canManage): ?>
                    <button type="submit" class="btn btn-primaryrc btn-sm">Save settings</button>
                <?php else: ?>
                </fieldset>
                <?php endif; ?>
            </form>

            <details class="adif-dt-details" <?= ($isFocus && $testResult === null && $error === null) ? 'open' : '' ?>>
                <summary>Preview the first <?= count($preview) ?> rows</summary>
                <?php if ($previewError): ?>
                    <div class="adif-alert adif-alert-error"><?= adif_h($previewError) ?></div>
                <?php else: ?>
                    <?= adif_rows_table($table['columns'], $preview, $table['key_column']) ?>
                <?php endif; ?>
            </details>

            <?php if ($canManage): ?>
            <details class="adif-dt-details" <?= ($testResult !== null && $testResult['table'] === $id) ? 'open' : '' ?>>
                <summary>Test a lookup</summary>
                <form method="post" action="<?= adif_h(adif_ds_url($module, 'tables')) ?>" class="adif-form-row">
                    <?= adif_ds_form_fields($csrf, 'tables') ?>
                    <input type="hidden" name="adif_action" value="test">
                    <input type="hidden" name="table_id" value="<?= adif_h($id) ?>">
                    <div class="adif-form-field">
                        <label for="adif-test-<?= adif_h($id) ?>">Value of <?= adif_h($table['key_column']) ?> to find</label>
                        <input type="text" name="test_value" id="adif-test-<?= adif_h($id) ?>" required
                               value="<?= adif_h($testResult !== null && $testResult['table'] === $id ? $testResult['value'] : ($preview[0][$table['key_column']] ?? '')) ?>">
                    </div>
                    <button type="submit" class="btn btn-defaultrc btn-sm">Find rows</button>
                </form>
                <?php if ($testResult !== null && $testResult['table'] === $id): ?>
                    <?php $result = $testResult['result']; ?>
                    <?php if (!$result['success']): ?>
                        <div class="adif-alert adif-alert-error"><?= adif_h($result['error']) ?></div>
                    <?php elseif (empty($result['bodyJson'])): ?>
                        <div class="adif-alert adif-alert-empty">No rows have that value, so an import would leave this
                            table's mapped fields blank. (<?= adif_h($result['elapsedMs']) ?> ms)</div>
                    <?php else: ?>
                        <p class="adif-dt-hint"><?= count($result['bodyJson']) ?> matching row(s) in <?= adif_h($result['elapsedMs']) ?> ms.
                            The API Test page shows the same result as JSON.</p>
                        <?= adif_rows_table($table['columns'], array_slice($result['bodyJson'], 0, 25), $table['key_column']) ?>
                    <?php endif; ?>
                <?php endif; ?>
            </details>

            <details class="adif-dt-details">
                <summary>Replace or delete</summary>
                <form method="post" action="<?= adif_h(adif_ds_url($module, 'tables')) ?>" enctype="multipart/form-data" class="adif-form-row">
                    <?= adif_ds_form_fields($csrf, 'tables') ?>
                    <input type="hidden" name="adif_action" value="replace">
                    <input type="hidden" name="table_id" value="<?= adif_h($id) ?>">
                    <div class="adif-form-field">
                        <label for="adif-replace-<?= adif_h($id) ?>">Upload a new version (keeps the name, settings and
                            mappings; up to <?= adif_h(adif_bytes(min($fileLimitBytes, $module->dataTableSpaceLeftBytes($id)))) ?><?=
                            ($table['format'] ?? '') === 'xlsx' && ($table['sheet'] ?? '') !== ''
                                ? '; from a workbook, the sheet named "' . adif_h($table['sheet']) . '" is read' : '' ?>)</label>
                        <input type="file" name="table_file" id="adif-replace-<?= adif_h($id) ?>" accept="<?= adif_h($acceptExtensions . ',' . $acceptTypes) ?>" required>
                    </div>
                    <button type="submit" class="btn btn-defaultrc btn-sm">Replace file</button>
                </form>
                <form method="post" action="<?= adif_h(adif_ds_url($module, 'tables')) ?>" class="adif-dt-delete"
                      onsubmit="return confirm(<?= adif_h(json_encode('Delete the table "' . $table['name'] . '"?' . (!empty($mappedFields)
                          ? ' ' . count($mappedFields) . ' mapped field(s) read from it and will be skipped on import.'
                          : ' Fields mapped from it will be skipped on import.'))) ?>);">
                    <?= adif_ds_form_fields($csrf, 'tables') ?>
                    <input type="hidden" name="adif_action" value="delete">
                    <input type="hidden" name="table_id" value="<?= adif_h($id) ?>">
                    <button type="submit" class="btn btn-danger btn-sm">Delete table</button>
                </form>
            </details>
            <?php endif; ?>
        </details>
    <?php endforeach; ?>

<script>
    (function () {
        var focus = <?= json_encode($focusTable !== null ? 'adif-table-' . $focusTable
            : (!empty($addedTables) ? 'adif-table-' . array_key_first($addedTables) : null)) ?>;
        var card = focus && document.getElementById(focus);
        if (card) {
            card.open = true;
            card.scrollIntoView({block: 'start'});
        }

        // Picking a column for "several rows each" selects that layout
        document.querySelectorAll('.adif-dt-layout select[name="measure_column"]').forEach(function (select) {
            select.addEventListener('change', function () {
                if (select.value) {
                    select.closest('label').querySelector('input[type="radio"]').checked = true;
                }
            });
        });

        // Expand all / Collapse all, above the tables when there are several
        document.querySelectorAll('[data-adif-tables]').forEach(function (button) {
            button.addEventListener('click', function () {
                var open = button.getAttribute('data-adif-tables') === 'open';
                document.querySelectorAll('.adif-ds-table').forEach(function (details) {
                    details.open = open;
                });
            });
        });

        // The upload form: lists the files chosen, each checked against the limits the server will apply,
        // so a selection that can't work is caught here rather than after a long upload. With several
        // files, the name field goes (each table is named after its file).
        var form = document.getElementById('adif-dt-upload-form');
        if (!form) {
            return;
        }
        var input = document.getElementById('adif-dt-files');
        var list = document.getElementById('adif-dt-chosen');
        var summary = document.getElementById('adif-dt-chosen-summary');
        var nameField = document.getElementById('adif-dt-name-field');
        var button = document.getElementById('adif-dt-upload-button');
        var maxFiles = parseInt(form.getAttribute('data-max-files'), 10) || 20;
        var maxFileBytes = parseInt(form.getAttribute('data-max-file-bytes'), 10) || 0;
        var maxPostBytes = parseInt(form.getAttribute('data-max-post-bytes'), 10) || 0;
        var spaceLeft = parseInt(form.getAttribute('data-space-left'), 10) || 0;
        var extensions = <?= json_encode(array_map(fn($e) => ltrim($e, '.'), explode(',', $acceptExtensions))) ?>;

        function size(bytes) {
            return bytes >= 1048576 ? (Math.round(bytes / 104857.6) / 10) + ' MB' : Math.max(1, Math.round(bytes / 1024)) + ' KB';
        }

        function check() {
            var files = Array.prototype.slice.call(input.files || []);
            list.textContent = '';
            list.hidden = files.length === 0;
            summary.hidden = files.length === 0;
            summary.classList.remove('is-error');
            nameField.hidden = files.length > 1;
            if (files.length > 1) {
                document.getElementById('adif-dt-name').value = '';
            }

            var total = 0;
            var usable = 0;
            var tooLarge = 0;
            var blocking = null;
            files.forEach(function (file) {
                var extension = (file.name.split('.').pop() || '').toLowerCase();
                var problem = extension === 'xls' ? 'Old .xls workbook: save it as .xlsx or CSV'
                    : (extensions.indexOf(extension) === -1 ? 'Not a CSV, JSON or Excel (.xlsx) file'
                    : (maxFileBytes && file.size > maxFileBytes ? 'Larger than ' + size(maxFileBytes) : ''));
                total += file.size;
                if (maxFileBytes && file.size > maxFileBytes) {
                    tooLarge++;
                }
                if (!problem) {
                    usable++;
                }

                var item = document.createElement('li');
                var name = document.createElement('span');
                name.className = 'adif-dt-chosen-name';
                name.textContent = file.name;
                var bytes = document.createElement('span');
                bytes.className = 'adif-dt-chosen-size';
                bytes.textContent = size(file.size);
                var status = document.createElement('span');
                status.className = 'adif-status-pill ' + (problem ? 'adif-status-error' : 'adif-status-none');
                status.textContent = problem || 'Ready';
                item.append(name, bytes, status);
                list.appendChild(item);
            });

            // A single file over the limit is told so; "together" is only for several files, whose total
            // the server takes in one upload (a post over post_max_size arrives empty, so it's stopped here)
            if (files.length > maxFiles) {
                blocking = 'The server takes up to ' + maxFiles + ' files at a time; choose ' + (files.length - maxFiles) + ' fewer.';
            } else if (files.length === 1 && tooLarge === 1) {
                blocking = 'This file is ' + size(total) + ', more than the ' + size(maxFileBytes) + ' a file can be.';
            } else if (maxPostBytes && total > maxPostBytes) {
                blocking = 'These ' + files.length + ' files are ' + size(total) + ' together, more than the server takes in one'
                    + ' upload (' + size(maxPostBytes) + '). ' + (tooLarge > 0
                        ? 'Remove the ' + (tooLarge === 1 ? 'one' : tooLarge) + ' marked too large, or upload the rest in smaller groups.'
                        : 'Upload them in smaller groups.');
            } else if (files.length > 0 && usable === 0) {
                blocking = files.length === 1 ? 'This file can\'t be uploaded.' : 'None of these files can be uploaded.';
            }

            if (blocking) {
                summary.textContent = blocking;
                summary.classList.add('is-error');
            } else if (files.length > 0) {
                var text = files.length === 1 ? '1 file, ' + size(total) + '.'
                    : files.length + ' files, ' + size(total) + ' in all. Each becomes its own table, named after its file.';
                if (usable < files.length) {
                    text += ' The ' + (files.length - usable) + ' marked in red will be skipped.';
                }
                if (total > spaceLeft) {
                    text += ' The project has ' + size(spaceLeft) + ' of storage left, so the last files may not fit; they\'re'
                        + ' added in the order listed.';
                }
                summary.textContent = text;
            }
            button.disabled = !!blocking;
            button.textContent = usable > 1 ? 'Upload ' + usable + ' files' : 'Upload';
        }

        input.addEventListener('change', check);
    })();
</script>
