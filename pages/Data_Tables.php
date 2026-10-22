<?php
/*
 * Upload CSV and other delimited files to use as data sources. Each table's columns are offered on
 * the Mapping page alongside the API sources' fields, and imports read the row matching the record.
 * See classes/DataTables.php.
 */

/** @var \UKModules\ADIF\ADIF $module */

// Now you can safely render your page
require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';

$pid = $module->getProjectId();
$canManage = $module->canConfigureApiSources($pid);

$error = null;
$success = null;
$notices = [];
$focusTable = null;   // the table an action was taken on, scrolled to and opened after the page loads
$testResult = null;   // ['table' => id, 'value' => ..., 'result' => queryDataTable() result]

if ($canManage && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['adif_action'] ?? '');
    $tableId = (string) ($_POST['table_id'] ?? '');

    try {
        switch ($action) {
            case 'upload':
                $outcome = $module->addDataTable($_FILES['table_file'] ?? null, $_POST['table_name'] ?? '');
                $success = "Added \"{$outcome['table']['name']}\" with " . number_format($outcome['table']['row_count'])
                    . ' rows. Check which column its rows are looked up by below, then map its fields on the Mapping page.';
                break;

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
            $module->log("Data table: $action", ['table' => $outcome['table']['name'], 'file' => $outcome['table']['filename']]);
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
        $focusTable = $tableId !== '' ? $tableId : null;
    }
}

$tables = $module->getDataTables();
$lookups = $module->getProjectSetting('lookup-fields') ?: [];
$csrf = $module->getCSRFToken();
// Storage: each file is capped, and so is the total across the project's tables (system settings)
$fileLimitBytes = $module->maxDataTableUploadBytes();
$projectLimitBytes = $module->dataTableProjectLimitBytes();
$storedBytes = $module->dataTableStoredBytes();
$spaceLeftBytes = $module->dataTableSpaceLeftBytes();
$uploadFull = $spaceLeftBytes === 0;
$delimiterNames = [',' => 'comma', "\t" => 'tab', ';' => 'semicolon', '|' => 'pipe'];

function adif_h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES);
}

function adif_bytes($bytes)
{
    return $bytes >= 1048576 || $bytes == 0 ? round($bytes / 1048576, 1) . ' MB' : max(1, round($bytes / 1024)) . ' KB';
}

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

<link rel="stylesheet" href="<?= $module->getUrl('css/test_page.css') ?>">
<link rel="stylesheet" href="<?= $module->getUrl('css/data_tables.css') ?>">

<div class="adif-test-page adif-dt-page">
    <h3>ADIF Data Tables</h3>
    <p>Upload a CSV or other delimited file to use it as a data source, the same way as an API. Choose the column
        that identifies each row (such as a census tract GEOID), and its columns are offered on the
        <a href="<?= adif_h($module->getUrl('pages/Mapping.php')) ?>">Mapping</a> page next to your API sources' fields.
        When a record is imported, the row matching the record's value is read into the mapped fields.</p>

    <?php if (!$canManage): ?>
        <div class="adif-alert adif-alert-empty">Only users with Project Design rights can manage data tables.</div>
    <?php else: ?>

    <?php if ($error): ?>
        <div class="adif-alert adif-alert-error" role="alert"><?= adif_h($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="adif-alert adif-dt-success" role="status"><?= adif_h($success) ?></div>
    <?php endif; ?>
    <?php foreach ($notices as $notice): ?>
        <div class="adif-alert adif-alert-empty"><?= adif_h($notice) ?></div>
    <?php endforeach; ?>

    <form method="post" enctype="multipart/form-data" class="adif-dt-card adif-dt-upload">
        <input type="hidden" name="redcap_csrf_token" value="<?= adif_h($csrf) ?>">
        <input type="hidden" name="adif_action" value="upload">
        <h4>Upload a table</h4>
        <div class="adif-form-row">
            <div class="adif-form-field">
                <label for="adif-dt-file">File (.csv, .tsv or .txt, up to <?= adif_h(adif_bytes(min($fileLimitBytes, $spaceLeftBytes))) ?>)</label>
                <input type="file" name="table_file" id="adif-dt-file" accept=".csv,.tsv,.tab,.txt,text/csv,text/tab-separated-values" required<?= $uploadFull ? ' disabled' : '' ?>>
            </div>
            <div class="adif-form-field">
                <label for="adif-dt-name">Name (optional; defaults to the file name)</label>
                <input type="text" name="table_name" id="adif-dt-name" maxlength="100" placeholder="e.g. CDC SVI 2022 - Kentucky tracts">
            </div>
            <button type="submit" class="btn btn-primaryrc"<?= $uploadFull ? ' disabled' : '' ?>>Upload</button>
        </div>
        <?php $usedShare = $projectLimitBytes > 0 ? min(1, $storedBytes / $projectLimitBytes) : 1; ?>
        <div class="adif-dt-usage<?= $usedShare >= 0.9 ? ' is-near-full' : '' ?>">
            <div class="adif-dt-usage-bar" aria-hidden="true"><span style="width: <?= round($usedShare * 100, 1) ?>%"></span></div>
            <p>
                <?php if ($uploadFull): ?>
                    This project's tables use all <?= adif_h(adif_bytes($projectLimitBytes)) ?> of the storage it's allowed
                    (<?= adif_h(adif_bytes($storedBytes)) ?> in use). Delete or replace a table that's no longer needed to
                    upload another, or ask a REDCap administrator to raise the limit.
                <?php else: ?>
                    This project's tables use <?= adif_h(adif_bytes($storedBytes)) ?> of the
                    <?= adif_h(adif_bytes($projectLimitBytes)) ?> they're allowed, leaving <?= adif_h(adif_bytes($spaceLeftBytes)) ?>.
                    Each file can be up to <?= adif_h(adif_bytes($fileLimitBytes)) ?>.
                <?php endif; ?>
            </p>
        </div>
        <p class="adif-dt-hint">The first row must name the columns. Comma, tab, semicolon and pipe separated files are
            all read. For an Excel workbook, save the sheet as CSV first. The name can't be changed later, since
            mapped fields are matched by it.</p>
    </form>

    <?php if (empty($tables)): ?>
        <div class="adif-alert adif-alert-empty">No tables have been uploaded to this project yet.</div>
    <?php endif; ?>

    <?php foreach ($tables as $table): ?>
        <?php
        $id = $table['id'];
        $source = $module->dataTableSource($id);
        $fieldCount = count($module->dataTableFields($table));
        $lookupField = $module->getLookupField($source, $table['placeholder']);
        $lookupChosen = isset($lookups[$source][$table['placeholder']]);
        $isFocus = $focusTable === $id;
        try {
            $preview = $module->dataTablePreview($table, 10);
            $previewError = null;
        } catch (\Throwable $e) {
            $preview = [];
            $previewError = $e->getMessage();
        }
        ?>
        <section class="adif-dt-card" id="adif-table-<?= adif_h($id) ?>">
            <h4><?= adif_h($table['name']) ?></h4>
            <div class="adif-dt-meta">
                <span><?= adif_h($table['filename']) ?></span>
                <span><?= number_format($table['row_count']) ?> rows</span>
                <span><?= count($table['columns']) ?> columns</span>
                <span><?= adif_h($delimiterNames[$table['delimiter']] ?? 'custom') ?> separated</span>
                <span><?= adif_h(adif_bytes($table['bytes'])) ?></span>
                <span>Uploaded <?= adif_h($table['uploaded_at']) ?><?= $table['uploaded_by'] !== '' ? ' by ' . adif_h($table['uploaded_by']) : '' ?></span>
            </div>

            <p class="adif-dt-summary">
                Offers <strong><?= $fieldCount ?> field<?= $fieldCount === 1 ? '' : 's' ?></strong> on the Mapping page,
                under <strong><?= adif_h($module->dataTableEndpoint($table)) ?></strong>. Rows are found with
                <code><?= adif_h($module->dataTablePath($table)) ?></code>, where
                <code>[<?= adif_h($table['placeholder']) ?>]</code> is read from
                <?php if ($lookupChosen): ?>
                    the REDCap field <strong><?= adif_h($lookupField) ?></strong>.
                <?php else: ?>
                    a REDCap field named <strong><?= adif_h($lookupField) ?></strong> unless another is chosen on the
                    Mapping page.
                <?php endif; ?>
            </p>

            <form method="post" class="adif-dt-settings">
                <input type="hidden" name="redcap_csrf_token" value="<?= adif_h($csrf) ?>">
                <input type="hidden" name="adif_action" value="settings">
                <input type="hidden" name="table_id" value="<?= adif_h($id) ?>">
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
                <button type="submit" class="btn btn-primaryrc btn-sm">Save settings</button>
            </form>

            <details class="adif-dt-details" <?= ($isFocus && $testResult === null && $error === null) ? 'open' : '' ?>>
                <summary>Preview the first <?= count($preview) ?> rows</summary>
                <?php if ($previewError): ?>
                    <div class="adif-alert adif-alert-error"><?= adif_h($previewError) ?></div>
                <?php else: ?>
                    <?= adif_rows_table($table['columns'], $preview, $table['key_column']) ?>
                <?php endif; ?>
            </details>

            <details class="adif-dt-details" <?= ($testResult !== null && $testResult['table'] === $id) ? 'open' : '' ?>>
                <summary>Test a lookup</summary>
                <form method="post" class="adif-form-row">
                    <input type="hidden" name="redcap_csrf_token" value="<?= adif_h($csrf) ?>">
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
                <form method="post" enctype="multipart/form-data" class="adif-form-row">
                    <input type="hidden" name="redcap_csrf_token" value="<?= adif_h($csrf) ?>">
                    <input type="hidden" name="adif_action" value="replace">
                    <input type="hidden" name="table_id" value="<?= adif_h($id) ?>">
                    <div class="adif-form-field">
                        <label for="adif-replace-<?= adif_h($id) ?>">Upload a new version (keeps the name, settings and
                            mappings; up to <?= adif_h(adif_bytes(min($fileLimitBytes, $module->dataTableSpaceLeftBytes($id)))) ?>)</label>
                        <input type="file" name="table_file" id="adif-replace-<?= adif_h($id) ?>" accept=".csv,.tsv,.tab,.txt,text/csv,text/tab-separated-values" required>
                    </div>
                    <button type="submit" class="btn btn-defaultrc btn-sm">Replace file</button>
                </form>
                <form method="post" class="adif-dt-delete"
                      onsubmit="return confirm(<?= adif_h(json_encode('Delete the table "' . $table['name'] . '"? Fields mapped from it will be skipped on import.')) ?>);">
                    <input type="hidden" name="redcap_csrf_token" value="<?= adif_h($csrf) ?>">
                    <input type="hidden" name="adif_action" value="delete">
                    <input type="hidden" name="table_id" value="<?= adif_h($id) ?>">
                    <button type="submit" class="btn btn-danger btn-sm">Delete table</button>
                </form>
            </details>
        </section>
    <?php endforeach; ?>

    <?php endif; ?>
</div>

<script>
    (function () {
        // Refreshing after an upload or save shouldn't send it again
        if (window.history.replaceState) {
            window.history.replaceState(null, '', window.location.href);
        }

        var focus = <?= json_encode($focusTable !== null ? 'adif-table-' . $focusTable : null) ?>;
        var card = focus && document.getElementById(focus);
        if (card) {
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
    })();
</script>
