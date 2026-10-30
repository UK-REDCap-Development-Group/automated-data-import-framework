<?php
/*
 * The ADIF Data Sources page: everything a project imports from, in one place.
 *   - API connections: the API sources from the "api-sources" project setting (see
 *     classes/ApiSources.php) - the same settings REDCap's module configuration dialog edits, with a
 *     friendlier form, schema detection, a connection test, and safe deletes and reordering.
 *   - Uploaded tables: CSV and other delimited files used as sources (see classes/DataTables.php).
 * Each tab lives in pages/data_sources/ and handles its own form posts; every form says which tab
 * it belongs to (adif_tab), so the page comes back on that tab after a save.
 * Formerly the Data Tables page (pages/Data_Tables.php, which now redirects here).
 */

/** @var \UKModules\ADIF\ADIF $module */

require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';

const ADIF_DATA_SOURCES_PAGE = true;

$pid = $module->getProjectId();
$canManage = $module->canConfigureApiSources($pid);

$tabs = ['apis' => 'API connections', 'tables' => 'Uploaded tables'];
$tab = (string) ($_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['adif_tab'] ?? '') : ($_GET['tab'] ?? ''));
if (!isset($tabs[$tab])) {
    $tab = 'apis';
}

function adif_h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES);
}

function adif_bytes($bytes)
{
    return $bytes >= 1048576 || $bytes == 0 ? round($bytes / 1048576, 1) . ' MB' : max(1, round($bytes / 1024)) . ' KB';
}

// This page's URL on a tab, for the tab links and as every form's action
function adif_ds_url($module, $tab)
{
    return $module->getUrl('pages/Data_Sources.php') . '&tab=' . rawurlencode($tab);
}

// The hidden fields every form on the page posts: the CSRF token and which tab it belongs to
function adif_ds_form_fields($csrf, $tab)
{
    return '<input type="hidden" name="redcap_csrf_token" value="' . adif_h($csrf) . '">'
        . '<input type="hidden" name="adif_tab" value="' . adif_h($tab) . '">';
}

$csrf = $module->getCSRFToken();

// The tab is rendered first, since it handles its own form post: the counts in the tab links
// below then include what was just added or deleted
$panel = '';
if ($canManage) {
    ob_start();
    require __DIR__ . '/data_sources/' . $tab . '.php';
    $panel = ob_get_clean();
}
$sourceCount = count($module->getApiSources());
$tableCount = count($module->getDataTables());
?>

<link rel="stylesheet" href="<?= adif_h($module->getUrl('css/test_page.css')) ?>">
<link rel="stylesheet" href="<?= adif_h($module->getUrl('css/data_tables.css')) ?>">
<link rel="stylesheet" href="<?= adif_h($module->getUrl('css/data_sources.css')) ?>">

<div class="adif-test-page adif-dt-page adif-ds-page">
    <h3>ADIF Data Sources</h3>
    <p>Everything this project can import from: web APIs, and tables uploaded as files. The fields each source
        offers appear on the <a href="<?= adif_h($module->getUrl('pages/Mapping.php')) ?>">Mapping</a> page, where
        they're matched to REDCap fields.</p>

    <nav class="adif-ds-tabs" role="tablist" aria-label="Kinds of data source">
        <?php foreach ($tabs as $key => $label): ?>
            <a href="<?= adif_h(adif_ds_url($module, $key)) ?>" role="tab" aria-selected="<?= $key === $tab ? 'true' : 'false' ?>"
               class="adif-ds-tab<?= $key === $tab ? ' is-active' : '' ?>">
                <?= adif_h($label) ?>
                <span class="adif-ds-tab-count"><?= $key === 'apis' ? $sourceCount : $tableCount ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="adif-ds-panel" role="tabpanel">
        <?php if (!$canManage): ?>
            <div class="adif-alert adif-alert-empty">Only users with Project Design rights can manage data sources.</div>
        <?php else: ?>
            <?= $panel ?>
        <?php endif; ?>
    </div>
</div>

<script>
    // Refreshing after a save shouldn't send it again, and the address shows the tab
    try {
        window.history.replaceState(null, '', <?= json_encode(adif_ds_url($module, $tab)) ?>);
    } catch (e) {
        // A URL REDCap builds for another origin (e.g. behind a proxy) can't be set; nothing depends on it
    }
</script>
