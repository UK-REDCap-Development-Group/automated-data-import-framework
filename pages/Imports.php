<?php
/*
 * The ADIF Imports page: makes failed imports visible and checkable instead of leaving them in the
 * logs. Lists the module log's import runs (see ImportLog::logImportRun) with each one's outcome -
 * which endpoint requests failed and which mapped fields were therefore left empty - and a per-record
 * lookup answering "does this record's import need running again?". Runs that need attention can be
 * re-run from here (see ImportStatus::retryImportRun): the same record, event, instrument and repeat
 * instance, with fresh API calls unless stored data is explicitly allowed.
 */

/** @var \UKModules\ADIF\ADIF $module */

$pid = $module->getProjectId();

// The Refresh all records card's buttons post here (see classes/ImportRefresh.php): handled before the
// page is drawn, then sent back to it, so reloading the page doesn't post again. Only users with
// Project Design rights can start or cancel a refresh, since it rewrites every record's mapped fields.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adif_refresh_action'])) {
    $refreshOutcome = 'denied';
    if ($module->canConfigureApiSources($pid)) {
        try {
            $refreshOutcome = $_POST['adif_refresh_action'] === 'cancel'
                ? $module->cancelImportRefresh($pid)
                : $module->startImportRefresh($pid, !empty($_POST['bypass_cache']))['status'];
        } catch (\Throwable $e) {
            $refreshOutcome = 'error';
            error_log("ADIF: couldn't start or cancel the bulk refresh in project $pid: " . $e->getMessage());
        }
    }
    if (!headers_sent()) {
        header('Location: ' . $module->getUrl('pages/Imports.php') . '&refresh=' . rawurlencode($refreshOutcome)
            . '#adif-imp-activity', true, 303);
        exit;
    }
    $_GET['refresh'] = $refreshOutcome;
}

// Retry all failed posts here too (see classes/ImportRetryAll.php): it queues the retries for the
// cron instead of running them while the page waits, so a long list can't hang the browser. Users who
// can resolve import errors can start or cancel it.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adif_retry_action'])) {
    $retryOutcome = 'denied';
    $retryRecord = trim((string) ($_POST['record'] ?? ''));
    if ($module->canResolveImports($pid)) {
        try {
            $retryOutcome = $_POST['adif_retry_action'] === 'cancel'
                ? $module->cancelRetryAll($pid)
                : $module->startRetryAll($pid, !empty($_POST['bypass_cache']), $retryRecord)['status'];
        } catch (\Throwable $e) {
            $retryOutcome = 'error';
            error_log("ADIF: couldn't start or cancel retry all in project $pid: " . $e->getMessage());
        }
    }
    if (!headers_sent()) {
        header('Location: ' . $module->getUrl('pages/Imports.php') . '&retryall=' . rawurlencode($retryOutcome)
            . ($retryRecord !== '' ? '&record=' . rawurlencode($retryRecord) : '') . '#adif-imp-activity', true, 303);
        exit;
    }
    $_GET['retryall'] = $retryOutcome;
}

// Import all, under Never imported, works the same way (see classes/ImportAll.php): it queues the first
// imports for the cron, which runs them within the same server-wide limit as Retry all. The same users
// can start or cancel it.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adif_import_all_action'])) {
    $importAllOutcome = 'denied';
    $importAllRecord = trim((string) ($_POST['record'] ?? ''));
    if ($module->canResolveImports($pid)) {
        try {
            $importAllOutcome = $_POST['adif_import_all_action'] === 'cancel'
                ? $module->cancelImportAll($pid)
                : $module->startImportAll($pid, $importAllRecord)['status'];
        } catch (\Throwable $e) {
            $importAllOutcome = 'error';
            error_log("ADIF: couldn't start or cancel import all in project $pid: " . $e->getMessage());
        }
    }
    if (!headers_sent()) {
        header('Location: ' . $module->getUrl('pages/Imports.php') . '&importall=' . rawurlencode($importAllOutcome)
            . ($importAllRecord !== '' ? '&record=' . rawurlencode($importAllRecord) : '') . '#adif-imp-activity', true, 303);
        exit;
    }
    $_GET['importall'] = $importAllOutcome;
}

require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';

$module->requireModuleView($pid);

$recordFilter = trim((string) ($_GET['record'] ?? ''));
$statusFilter = in_array((string) ($_GET['status'] ?? ''), ['attention', 'dismissed', 'stale', 'ok'], true) ? (string) $_GET['status'] : '';
// The run table's paging: rows per page ("limit" is the old name of the setting, from bookmarks made
// before the table had pages) and the page number, "p" because the framework's own "page" parameter
// names the module page
$perPageParam = (int) ($_GET['per_page'] ?? $_GET['limit'] ?? 25);
$perPage = in_array($perPageParam, [25, 50, 100], true) ? $perPageParam : 25;
$pageParam = max(1, (int) ($_GET['p'] ?? 1));

// The run table pages through the most recent 500 runs (the filtered record's, when there is one),
// and Retry all failed covers every import among them, not only the runs on the page. A user in a data
// access group sees only their group's records, here and in every list below (see classes/Access.php).
$runListCap = 500;
$recentRuns = $module->onlyVisibleRecords($pid, $module->importRuns($pid, ['limit' => $runListCap]));
$allRuns = $recordFilter === ''
    ? $recentRuns
    : $module->onlyVisibleRecords($pid, $module->importRuns($pid, ['record' => $recordFilter, 'limit' => $runListCap]));
$attentionCount = count(array_filter($allRuns, fn($run) => $run['outcome'] === 'attention'));
$recordVisible = $recordFilter === '' || $module->canSeeRecord($pid, $recordFilter);

// Retrying, dismissing and running imports write record data (or decide it can stay empty), so they
// need View & Edit rights on the mapped instruments, or Project Design rights (see classes/Access.php).
// Read Only users see the same page without those controls.
$canRetry = $module->canResolveImports($pid);

// What Retry all failed re-runs: each import whose latest run needs attention (see
// ImportStatus::importRetryTargets), only the filtered record's when there is one
$retryTargets = [];
if ($canRetry) {
    $recordOfRun = array_column($recentRuns, 'record', 'run_id');
    $retryTargets = array_values(array_filter($module->importRetryTargets($pid, $recentRuns),
        fn($runId) => $recordFilter === '' || (string) ($recordOfRun[$runId] ?? '') === $recordFilter));
}

// Imports waiting in the background queue (see ImportQueue), and when the cron last worked through
// it, so a cron that isn't running shows up here rather than as fields that never fill
$runInBackground = $module->importsRunInBackground($pid);
$queuedImports = [];
$queueError = null;
try {
    $queuedImports = $module->onlyVisibleRecords($pid,
        $module->importQueuePending($pid, $recordFilter !== '' ? $recordFilter : null, 500));
} catch (\Throwable $e) {
    $queueError = $e->getMessage();
}
$queueLastRun = (int) $module->getSystemSetting('import-queue-last-run');
$queueOldest = empty($queuedImports) ? null : min(array_column($queuedImports, 'queued'));
$queueOverdue = $queueOldest !== null && time() - $queueOldest > $module::$importQueueOverdueSeconds;
// The queue card lists this many; Run queued imports now still works through all of them
$queueShownCap = 50;

// Records with data and mapped fields that are empty and have never had an import tried - usually
// entered before the module was enabled, or fields mapped since (see ImportStatus::importNeverAttempted)
$neverImported = [];
$neverImportedError = null;
try {
    $neverImported = $module->onlyVisibleRecords($pid,
        $module->importNeverAttempted($pid, $recordFilter !== '' ? $recordFilter : null));
} catch (\Throwable $e) {
    $neverImportedError = $e->getMessage();
}
// Each place gets a key the Import buttons and Import all share
foreach ($neverImported as $index => &$unit) {
    $unit['key'] = 'u' . $index;
}
unset($unit);
$neverImportable = array_values(array_filter($neverImported, fn($unit) => $unit['importable']));

// The bulk refresh (see classes/ImportRefresh.php): its latest request, the server's off-peak hours, and
// what the last button press did
$refreshJob = $module->importRefreshJob($pid);
$refreshActive = $module::importRefreshActive($refreshJob);
$refreshWindow = $module->importRefreshWindow();
$canRefresh = $module->canConfigureApiSources($pid);
$refreshMessages = [
    'started' => ['ok', 'Refresh requested. It runs in the background during off-peak hours; its progress shows here.'],
    'active' => ['error', 'A refresh is already going. Cancel it first to start another.'],
    'nothing_mapped' => ['error', 'Nothing is mapped yet, so there\'s nothing to refresh. Map fields on the Mapping page first.'],
    'no_records' => ['error', 'There are no records to refresh.'],
    'cancelled' => ['ok', 'Refresh cancelled. The imports it already ran stay.'],
    'not_active' => ['empty', 'There\'s no refresh going to cancel.'],
    'denied' => ['error', 'Only users with Project Design rights can refresh every record\'s imports.'],
    'error' => ['error', 'The refresh couldn\'t be started or cancelled. The server\'s PHP error log has the details.'],
];
$refreshNotice = $refreshMessages[(string) ($_GET['refresh'] ?? '')] ?? null;

// Retry all failed (see classes/ImportRetryAll.php): the latest job, and what the last button press did
$retryJob = $canRetry ? $module->importRetryAllJob($pid) : null;
$retryActive = $module::retryAllActive($retryJob);
$retryMessages = [
    'started' => ['ok', 'Retries queued. They run in the background, usually starting within a minute; progress shows here. You can leave this page.'],
    'active' => ['error', 'Retry all is already going. Cancel it first to start another.'],
    'nothing_to_retry' => ['empty', 'No imports need retrying right now.'],
    'cancelled' => ['ok', 'Retry all cancelled. The retries it already ran stay.'],
    'not_active' => ['empty', 'There\'s no retry all going to cancel.'],
    'denied' => ['error', 'Retrying imports needs View & Edit rights on every instrument with ADIF mappings, or Project Design rights.'],
    'error' => ['error', 'Retry all couldn\'t be started or cancelled. The server\'s PHP error log has the details.'],
];
$retryNotice = $retryMessages[(string) ($_GET['retryall'] ?? '')] ?? null;

// Import all, under Never imported (see classes/ImportAll.php): the latest job, and what the last button
// press did
$importAllJob = $canRetry ? $module->importAllJob($pid) : null;
$importAllActive = $module::importAllActive($importAllJob);
$importAllMessages = [
    'started' => ['ok', 'Imports queued. They run in the background, usually starting within a minute; progress shows here. You can leave this page.'],
    'active' => ['error', 'Import all is already going. Cancel it first to start another.'],
    'nothing_to_import' => ['empty', 'Nothing listed here can be imported right now.'],
    'cancelled' => ['ok', 'Import all cancelled. The imports it already ran stay.'],
    'not_active' => ['empty', 'There\'s no import all going to cancel.'],
    'denied' => ['error', 'Running imports needs View & Edit rights on every instrument with ADIF mappings, or Project Design rights.'],
    'error' => ['error', 'Import all couldn\'t be started or cancelled. The server\'s PHP error log has the details.'],
];
$importAllNotice = $importAllMessages[(string) ($_GET['importall'] ?? '')] ?? null;
$neverRecordCount = count(array_unique(array_column($neverImported, 'record')));
// The table lists this many places; Import all still covers every one
$neverShownCap = 200;

// The table: the runs with the chosen outcome, one page of them at a time. Each outcome's count is
// shown on its filter tab.
$outcomeCounts = array_count_values(array_column($allRuns, 'outcome'));
$runs = $allRuns;
if ($statusFilter !== '') {
    $runs = array_values(array_filter($runs, fn($run) => $run['outcome'] === $statusFilter));
}
$pageCount = max(1, (int) ceil(count($runs) / $perPage));
$currentPage = min($pageParam, $pageCount);
$pageRuns = array_slice($runs, ($currentPage - 1) * $perPage, $perPage);

// Retry all failed's tooltip says how many of its imports aren't on this page
$listedRunIds = array_flip(array_column($pageRuns, 'run_id'));
$hiddenTargets = count(array_filter($retryTargets, fn($runId) => !isset($listedRunIds[$runId])));

// The record lookup doubles as the table's record filter: entering a record shows both its latest
// run per event (below) and the filtered runs list
// (a record outside the user's data access group looks like one with no runs)
$recordStatus = $recordFilter !== '' ? ($recordVisible ? $module->importRecordStatus($pid, $recordFilter) : []) : null;

// When the list is empty despite runs having been logged, this tells the two likely causes apart.
// It quotes a log entry, so only users who see every record and can change the setup get it.
$diagnostics = empty($allRuns) && $module->canConfigureApiSources($pid) && $module->adifVisibleRecords($pid) === null
    ? $module->importLogDiagnostics($pid) : null;

// The page's own URL parameters: a GET form replaces the whole query string, so they must be
// echoed back as hidden fields or submitting the filters lands on "a module prefix must be included"
$hiddenParams = [];
foreach (['prefix', 'page', 'pid'] as $name) {
    if (isset($_GET[$name])) {
        $hiddenParams[$name] = (string) $_GET[$name];
    }
}
// The run table's filters as they stand, for its tab, page and per-page links
$runFilters = ['record' => $recordFilter, 'status' => $statusFilter, 'per_page' => $perPage, 'p' => $currentPage];

// This page's URL with its filters, and $changes made to them ('p' => 3, 'status' => 'ok', ...). Blank
// values and the defaults (page 1, 25 per page) are left out, so the links stay short.
function adif_imp_url($hiddenParams, $filters, $changes = [], $anchor = 'adif-imp-runs')
{
    $query = $hiddenParams;
    foreach (array_merge($filters, $changes) as $name => $value) {
        if ($value === '' || $value === null || ($name === 'p' && (int) $value <= 1) || ($name === 'per_page' && (int) $value === 25)) {
            continue;
        }
        $query[$name] = (string) $value;
    }
    return '?' . http_build_query($query) . ($anchor !== '' ? '#' . $anchor : '');
}

// The page numbers to link: the first and last, and two either side of the current one, with null
// where a gap is skipped ("1 ... 6 7 [8] 9 10 ... 20")
function adif_imp_page_numbers($current, $count)
{
    $numbers = [];
    $previous = 0;
    for ($page = 1; $page <= $count; $page++) {
        if ($page === 1 || $page === $count || abs($page - $current) <= 2) {
            if ($page - $previous > 1) {
                $numbers[] = null;
            }
            $numbers[] = $page;
            $previous = $page;
        }
    }
    return $numbers;
}

function adif_imp_h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES);
}

function adif_imp_pill($outcome, $label)
{
    $class = match ($outcome) {
        'attention' => 'adif-status-error',
        'dismissed' => 'adif-status-none',
        'stale' => 'adif-status-stale',
        'ok' => 'adif-status-ok',
        default => 'adif-status-none',
    };
    return '<span class="adif-status-pill ' . $class . '">' . adif_imp_h($label) . '</span>';
}

function adif_imp_record_link($record, $pid)
{
    if ($record === '') {
        return '&mdash;';
    }
    return '<a href="' . adif_imp_h(APP_PATH_WEBROOT . "DataEntry/record_home.php?pid={$pid}&id=" . urlencode($record))
        . '" title="Open the record\'s home page">' . adif_imp_h($record) . '</a>';
}

// "Dismissed by jdoe on 2026-10-30 10:12 - reason" for a run whose Needs attention was dismissed
function adif_imp_dismissed_note($run)
{
    $dismissed = $run['dismissed'] ?? null;
    if (!$dismissed) {
        return '';
    }
    return 'Dismissed by ' . adif_imp_h($dismissed['user'] !== '' ? $dismissed['user'] : '?')
        . ' on ' . adif_imp_h(date('Y-m-d H:i', $dismissed['at']))
        . ($dismissed['reason'] !== '' ? ' &mdash; ' . adif_imp_h($dismissed['reason']) : '') . '.';
}

// The retry and dismiss controls under a flagged run, or the undo button under a dismissed one.
// js/imports.js builds the same markup when it switches a run between the two.
function adif_imp_actions($run)
{
    $runId = adif_imp_h($run['run_id']);
    if ($run['outcome'] === 'dismissed') {
        return '<div class="adif-imp-retry-row"><button type="button" class="btn btn-defaultrc btn-sm adif-imp-undismiss"'
            . ' data-run-id="' . $runId . '" title="Flags this run as needing attention again">Undo dismiss</button></div>';
    }
    return '<div class="adif-imp-retry-row">'
        . '<button type="button" class="btn btn-primaryrc btn-sm adif-imp-retry" data-run-id="' . $runId . '"'
        . ' title="Re-runs this import for record ' . adif_imp_h($run['record']) . ', same event, instrument and instance">'
        . 'Retry this import</button>'
        . '<span class="adif-imp-dismiss">'
        . '<input type="text" class="form-control form-control-sm adif-imp-dismiss-reason" maxlength="500"'
        . ' placeholder="Reason (optional)" aria-label="Reason for dismissing this run">'
        . '<button type="button" class="btn btn-defaultrc btn-sm adif-imp-dismiss-btn" data-run-id="' . $runId . '"'
        . ' title="Stops flagging this run as needing attention, without changing any data. Can be undone.">Dismiss</button>'
        . '</span></div>';
}

// The instrument and instance column of the Never imported table: a repeating instrument and its
// instance, an instance of a repeating event, or a dash for an event's own fields
function adif_imp_never_where($unit)
{
    if ($unit['instrument'] !== null) {
        return adif_imp_h($unit['instrument']) . ' #' . (int) $unit['repeat_instance'];
    }
    return $unit['repeats'] ? 'Event instance #' . (int) $unit['repeat_instance'] : '&mdash;';
}

// "a, b, c, d, e, f (+3 more)", with the full list as the title
function adif_imp_field_list($fields)
{
    $shown = array_slice($fields, 0, 6);
    $text = implode(', ', $shown) . (count($fields) > count($shown) ? ' (+' . (count($fields) - count($shown)) . ' more)' : '');
    return '<span title="' . adif_imp_h(implode(', ', $fields)) . '">' . adif_imp_h($text) . '</span>';
}

// "just now", "4 min ago", "3 h ago", or the date for anything older than a day
function adif_imp_ago($time)
{
    $seconds = time() - (int) $time;
    if ($seconds < 60) {
        return 'just now';
    }
    if ($seconds < 3600) {
        return floor($seconds / 60) . ' min ago';
    }
    if ($seconds < 86400) {
        return floor($seconds / 3600) . ' h ago';
    }
    return date('Y-m-d H:i', (int) $time);
}

// The data-confirm attribute that makes a form ask first, in the page's confirmation modal (see the
// script at the end): title, message (paragraphs split by blank lines), and optionally confirmLabel,
// cancelLabel, tone ('danger') and checkbox ([name, label, hint, checked], posted as name=1 when ticked)
function adif_imp_confirm_attr(array $confirm)
{
    return ' data-confirm="' . adif_imp_h(json_encode($confirm)) . '"';
}

// A background job's Cancel button: a form posting $field=cancel back to this page (see the top of it),
// confirmed in the modal first
function adif_imp_cancel_form($module, $field, $record, array $confirm)
{
    return '<form method="post" class="adif-imp-job-cancel" action="' . adif_imp_h($module->getUrl('pages/Imports.php')) . '"'
        . adif_imp_confirm_attr($confirm + ['cancelLabel' => 'Keep it running', 'tone' => 'danger']) . '>'
        . '<input type="hidden" name="redcap_csrf_token" value="' . adif_imp_h($module->getCSRFToken()) . '">'
        . '<input type="hidden" name="' . adif_imp_h($field) . '" value="cancel">'
        . '<input type="hidden" name="record" value="' . adif_imp_h($record) . '">'
        . '<button type="submit" class="btn btn-link btn-sm">Cancel</button></form>';
}

// "22:00" for an off-peak hour
function adif_imp_hour($hour)
{
    return sprintf('%02d:00', (int) $hour);
}

function adif_imp_instrument($run)
{
    if ($run['instrument'] === null || $run['instrument'] === '') {
        return '&mdash;';
    }
    return adif_imp_h($run['instrument']) . ($run['repeat_instance'] !== null && $run['repeat_instance'] > 1
        ? ' #' . (int) $run['repeat_instance'] : '');
}
?>

<link rel="stylesheet" href="<?= adif_imp_h($module->getUrl('css/test_page.css')) ?>">
<link rel="stylesheet" href="<?= adif_imp_h($module->getUrl('css/imports.css')) ?>">

<div class="adif-test-page adif-imp-page">
    <header class="adif-imp-header">
        <div class="adif-imp-title">
            <h3>Import status</h3>
            <div class="adif-imp-chips">
                <?php if ($attentionCount > 0): ?>
                    <a class="adif-imp-chip is-alert" href="<?= adif_imp_h(adif_imp_url($hiddenParams, $runFilters, ['status' => 'attention', 'p' => 1])) ?>"
                       title="Runs among the latest <?= count($allRuns) ?> that left mapped fields empty"><?= number_format($attentionCount) ?> need<?= $attentionCount === 1 ? 's' : '' ?> attention</a>
                <?php elseif (!empty($allRuns)): ?>
                    <span class="adif-imp-chip is-ok">No failed imports</span>
                <?php endif; ?>
                <?php if (!empty($neverImported)): ?>
                    <a class="adif-imp-chip" href="#adif-imp-never"><?= number_format(count($neverImported)) ?> never imported</a>
                <?php endif; ?>
                <?php if ($queueError !== null): ?>
                    <span class="adif-imp-chip is-alert" title="<?= adif_imp_h($queueError) ?>">Queue unreadable</span>
                <?php elseif (!empty($queuedImports)): ?>
                    <a class="adif-imp-chip<?= $queueOverdue ? ' is-alert' : ' is-busy' ?>" href="#adif-imp-queue"><?= number_format(count($queuedImports)) ?> queued<?= $queueOverdue ? ' · overdue' : '' ?></a>
                <?php elseif ($runInBackground): ?>
                    <span class="adif-imp-chip is-ok">Queue idle · cron <?= $queueLastRun > 0 ? 'ran ' . adif_imp_h(adif_imp_ago($queueLastRun)) : 'never ran' ?></span>
                <?php else: ?>
                    <span class="adif-imp-chip" title="&quot;Run each import while the survey respondent waits&quot; is ticked in this project's ADIF settings">Imports run while respondents wait</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="adif-imp-tools">
            <form method="get" class="adif-imp-lookup" role="search">
                <?php foreach ($hiddenParams as $name => $value): ?>
                    <input type="hidden" name="<?= adif_imp_h($name) ?>" value="<?= adif_imp_h($value) ?>">
                <?php endforeach; ?>
                <label for="adif-imp-record">Record</label>
                <input type="text" name="record" id="adif-imp-record" value="<?= adif_imp_h($recordFilter) ?>" placeholder="e.g. 101"
                       title="A record's exact name: shows its runs, and its latest import per event">
                <?php if ($statusFilter !== ''): ?>
                    <input type="hidden" name="status" value="<?= adif_imp_h($statusFilter) ?>">
                <?php endif; ?>
                <?php if ($perPage !== 25): ?>
                    <input type="hidden" name="per_page" value="<?= $perPage ?>">
                <?php endif; ?>
                <button type="submit" class="btn btn-defaultrc btn-sm">Look up</button>
                <?php if ($recordFilter !== ''): ?>
                    <a class="btn btn-link btn-sm" href="<?= adif_imp_h(adif_imp_url($hiddenParams, $runFilters, ['record' => '', 'p' => 1], '')) ?>">Clear</a>
                <?php endif; ?>
            </form>

            <?php if ($canRetry || $canRefresh): ?>
                <div class="adif-imp-actions">
                    <button type="button" class="btn btn-primaryrc btn-sm adif-imp-actions-toggle" id="adif-imp-actions-toggle"
                            aria-haspopup="true" aria-expanded="false" aria-controls="adif-imp-actions-menu">
                        Actions <svg width="12" height="12" viewBox="0 0 12 12" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2.5 4.5 6 8l3.5-3.5"></path></svg>
                    </button>
                    <div class="adif-imp-actions-menu" id="adif-imp-actions-menu" hidden>
                        <?php if ($canRetry): ?>
                            <?php if (!$retryActive && !empty($retryTargets)): ?>
                                <?php
                                $retryConfirm = [
                                    'title' => 'Retry ' . count($retryTargets) . ' failed import' . (count($retryTargets) === 1 ? '' : 's') . '?',
                                    'message' => 'Re-runs every import whose latest run needs attention'
                                        . ($recordFilter !== '' ? ' for record ' . $recordFilter : '')
                                        . ($hiddenTargets > 0 ? ", including $hiddenTargets not on this page" : '')
                                        . '. The fields they map are overwritten with the APIs\' current values.'
                                        . "\n\nThey run in the background, starting within a minute or so. You can leave this page;"
                                        . ' progress shows above the run table.',
                                    'confirmLabel' => 'Queue retries',
                                    'checkbox' => ['name' => 'bypass_cache', 'label' => 'Skip stored data and call the APIs', 'checked' => true,
                                        'hint' => 'Recommended after an outage, so data stored while the API was down isn\'t reused.'],
                                ];
                                ?>
                                <form method="post" id="adif-imp-retry-all-form" action="<?= adif_imp_h($module->getUrl('pages/Imports.php')) ?>"<?= adif_imp_confirm_attr($retryConfirm) ?>>
                                    <input type="hidden" name="redcap_csrf_token" value="<?= adif_imp_h($module->getCSRFToken()) ?>">
                                    <input type="hidden" name="adif_retry_action" value="start">
                                    <input type="hidden" name="record" value="<?= adif_imp_h($recordFilter) ?>">
                                    <button type="submit" class="adif-imp-menu-item">
                                        <span class="adif-imp-menu-title">Retry all failed (<?= count($retryTargets) ?>)</span>
                                        <span class="adif-imp-menu-hint">Every import whose latest run needs attention<?= $recordFilter !== '' ? ', for this record' : '' ?>. Runs in the background within a minute.</span>
                                    </button>
                                </form>
                            <?php else: ?>
                                <button type="button" class="adif-imp-menu-item" disabled>
                                    <span class="adif-imp-menu-title">Retry all failed</span>
                                    <span class="adif-imp-menu-hint"><?= $retryActive ? 'Already running; see its progress above the table.' : 'Nothing needs retrying.' ?></span>
                                </button>
                            <?php endif; ?>

                            <?php if (!$importAllActive && !empty($neverImportable)): ?>
                                <?php
                                $importAllConfirm = [
                                    'title' => 'Import ' . count($neverImportable) . ' never-imported place' . (count($neverImportable) === 1 ? '' : 's') . '?',
                                    'message' => 'Runs the first import for every place on the Never imported list that has the values its'
                                        . ' lookups need, in ' . $neverRecordCount . ' record' . ($neverRecordCount === 1 ? '' : 's')
                                        . ($recordFilter !== '' ? ' (record ' . $recordFilter . ' only)' : '')
                                        . '. Each one calls the APIs its mappings use and fills the empty fields.'
                                        . "\n\nThey run in the background, starting within a minute or so. You can leave this page;"
                                        . ' progress shows above the run table.',
                                    'confirmLabel' => 'Queue imports',
                                ];
                                ?>
                                <form method="post" action="<?= adif_imp_h($module->getUrl('pages/Imports.php')) ?>"<?= adif_imp_confirm_attr($importAllConfirm) ?>>
                                    <input type="hidden" name="redcap_csrf_token" value="<?= adif_imp_h($module->getCSRFToken()) ?>">
                                    <input type="hidden" name="adif_import_all_action" value="start">
                                    <input type="hidden" name="record" value="<?= adif_imp_h($recordFilter) ?>">
                                    <button type="submit" class="adif-imp-menu-item">
                                        <span class="adif-imp-menu-title">Import never-imported records (<?= count($neverImportable) ?>)</span>
                                        <span class="adif-imp-menu-hint">First imports for <?= $neverRecordCount ?> record<?= $neverRecordCount === 1 ? '' : 's' ?> with data but no import yet, in the background.</span>
                                    </button>
                                </form>
                            <?php else: ?>
                                <button type="button" class="adif-imp-menu-item" disabled>
                                    <span class="adif-imp-menu-title">Import never-imported records</span>
                                    <span class="adif-imp-menu-hint"><?= $importAllActive ? 'Already running; see its progress above the table.'
                                        : (!empty($neverImported) ? 'None of them has the values its lookups need yet.' : 'Every record with data has been imported.') ?></span>
                                </button>
                            <?php endif; ?>

                            <button type="button" class="adif-imp-menu-item" id="adif-imp-run-queue"<?= empty($queuedImports) ? ' disabled' : '' ?>>
                                <span class="adif-imp-menu-title">Run queued imports now</span>
                                <span class="adif-imp-menu-hint"><?= empty($queuedImports) ? 'Nothing is waiting.'
                                    : number_format(count($queuedImports)) . ' waiting. Runs them from this page instead of waiting for the cron; keep the page open.' ?></span>
                            </button>
                        <?php endif; ?>

                        <?php if ($canRefresh): ?>
                            <div class="adif-imp-menu-divider" role="separator"></div>
                            <button type="button" class="adif-imp-menu-item" id="adif-imp-refresh-open"<?= $refreshActive ? ' disabled' : '' ?>>
                                <span class="adif-imp-menu-title">Refresh all records&hellip;</span>
                                <span class="adif-imp-menu-hint"><?= $refreshActive ? 'Already running; see its progress above the table.'
                                    : 'Re-imports every record during off-peak hours (' . adif_imp_h(adif_imp_hour($refreshWindow['start'])) . '&ndash;'
                                        . adif_imp_h(adif_imp_hour($refreshWindow['end'])) . ').' ?></span>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </header>

    <div class="adif-imp-lede">
        <p>Every import ADIF has run in this project, newest first.<?= !$canRetry
            ? ' You can view this page; retrying, dismissing or running imports needs View &amp; Edit rights on every instrument with ADIF mappings, or Project Design rights.' : '' ?></p>
        <details class="adif-imp-about">
            <summary>How outcomes are decided</summary>
            <p>Every import run the module has logged, newest first, with its outcome: which endpoint requests
        failed and which mapped fields were therefore left empty. A run <strong>needs attention</strong> when a
        request failed with nothing saved from it, so its fields are still waiting on the data; runs answered
        from <strong>stored data</strong> during an API outage are noted but not flagged, since their fields were
        filled as intended. Runs that need attention can be re-run from here for the same record, event,
        instrument and instance &mdash; the retry is logged like any other run.</p>
        </details>
    </div>

    <?php foreach ([$refreshNotice, $retryNotice, $importAllNotice] as $notice): ?>
        <?php if ($notice !== null): ?>
            <div class="adif-alert <?= ['ok' => 'adif-imp-success', 'error' => 'adif-alert-error', 'empty' => 'adif-alert-empty'][$notice[0]] ?>"
                 role="status"><?= adif_imp_h($notice[1]) ?></div>
        <?php endif; ?>
    <?php endforeach; ?>

    <?php
    // The activity strip: each background job that's running, or finished in the last day, as one row.
    // 'detail' is HTML, built here from escaped values.
    $jobRows = [];
    $recentlyOver = fn($job) => $job !== null && (int) ($job['finished_at'] ?? 0) >= time() - 86400;

    if ($queueError === null && !empty($queuedImports)) {
        $jobRows[] = [
            'name' => 'Survey import queue',
            'pill' => $queueOverdue ? ['adif-status-error', 'Overdue'] : ['adif-status-stale', 'Waiting'],
            'percent' => null,
            'detail' => number_format(count($queuedImports)) . ' waiting, the oldest since ' . adif_imp_h(adif_imp_ago($queueOldest))
                . ' · cron ' . ($queueLastRun > 0 ? 'last ran ' . adif_imp_h(adif_imp_ago($queueLastRun)) : 'has never run')
                . ($queueOverdue ? '. REDCap\'s cron may not be running ADIF\'s job (<code>adif_import_queue</code>): ask your REDCap administrator.' : '')
                . ' · <a href="#adif-imp-queue">Show the queue</a>'
                . ' <span id="adif-imp-queue-progress" class="adif-imp-progress" aria-live="polite"></span>',
            'cancel' => '',
        ];
    }

    if ($refreshActive || $recentlyOver($refreshJob)) {
        $total = max(1, (int) $refreshJob['record_count']);
        $done = min($total, (int) $refreshJob['next']);
        $minutesLeft = $refreshActive && $done > 0
            ? (int) ceil(($refreshJob['imports'] / $done) * ($total - $done) / $refreshWindow['per_minute']) : null;
        $jobRows[] = [
            'name' => 'Refresh all records',
            'pill' => match ($refreshJob['status']) {
                'waiting' => ['adif-status-stale', $refreshWindow['open'] ? 'Starting' : 'Starts at ' . adif_imp_hour($refreshWindow['start'])],
                'running' => ['adif-status-stale', $refreshWindow['open'] ? 'Running' : 'Paused until ' . adif_imp_hour($refreshWindow['start'])],
                'done' => ['adif-status-ok', 'Finished'],
                'cancelled' => ['adif-status-none', 'Cancelled'],
                default => ['adif-status-error', 'Stopped'],
            },
            'percent' => $done / $total * 100,
            'detail' => number_format($done) . ' of ' . number_format($total) . ' records · '
                . number_format((int) $refreshJob['imports']) . ' imports'
                . ((int) $refreshJob['attention'] > 0 ? ', <strong class="adif-imp-job-alert">' . number_format((int) $refreshJob['attention']) . ' need attention</strong>' : '')
                . ((int) $refreshJob['skipped'] > 0 ? ', ' . number_format((int) $refreshJob['skipped']) . ' skipped (lookup values missing)' : '')
                . ' · requested by ' . adif_imp_h($refreshJob['requested_by']) . ' ' . adif_imp_h(adif_imp_ago($refreshJob['requested_at']))
                . ($minutesLeft !== null ? ' · roughly ' . ($minutesLeft < 90 ? $minutesLeft . ' min' : round($minutesLeft / 60, 1) . ' h') . ' of off-peak time to go' : '')
                . ($refreshJob['status'] === 'cancelled' ? ' · cancelled by ' . adif_imp_h($refreshJob['cancelled_by'] ?? '?') : '')
                . ($refreshJob['status'] === 'failed' ? ' · ' . adif_imp_h($refreshJob['message'] ?? '') : ''),
            'cancel' => $refreshActive && $canRefresh ? adif_imp_cancel_form($module, 'adif_refresh_action', '', [
                'title' => 'Cancel the refresh?',
                'message' => 'The imports it has already run stay. The records it hasn\'t reached yet are left as they are.',
                'confirmLabel' => 'Cancel refresh',
            ]) : '',
        ];
    }

    if ($canRetry && ($retryActive || $recentlyOver($retryJob))) {
        $retryTotal = max(1, count($retryJob['run_ids']));
        $retryDone = min($retryTotal, (int) $retryJob['next']);
        $jobRows[] = [
            'name' => 'Retry all failed',
            'pill' => match ($retryJob['status']) {
                'waiting' => ['adif-status-stale', 'Queued'],
                'running' => ['adif-status-stale', 'Running'],
                'done' => ['adif-status-ok', 'Finished'],
                'cancelled' => ['adif-status-none', 'Cancelled'],
                default => ['adif-status-error', 'Stopped'],
            },
            'percent' => $retryDone / $retryTotal * 100,
            'detail' => number_format($retryDone) . ' of ' . number_format($retryTotal) . ' runs'
                . ($retryJob['retried'] > 0 ? ' · ' . number_format($retryJob['retried']) . ' now fine' : '')
                . ($retryJob['attention'] > 0 ? ', <strong class="adif-imp-job-alert">' . number_format($retryJob['attention']) . ' still need attention</strong>' : '')
                . ($retryJob['skipped'] > 0 ? ', ' . number_format($retryJob['skipped']) . ' no longer needed a retry' : '')
                . ($retryJob['failed'] > 0 ? ', ' . number_format($retryJob['failed']) . " couldn't be retried" : '')
                . ' · requested by ' . adif_imp_h($retryJob['requested_by']) . ' ' . adif_imp_h(adif_imp_ago($retryJob['requested_at']))
                . ($retryJob['status'] === 'cancelled' ? ' · cancelled by ' . adif_imp_h($retryJob['cancelled_by'] ?? '?') : '')
                . ($retryJob['status'] === 'failed' ? ' · ' . adif_imp_h($retryJob['message'] ?? '') : ''),
            'cancel' => $retryActive ? adif_imp_cancel_form($module, 'adif_retry_action', $recordFilter, [
                'title' => 'Cancel retry all?',
                'message' => 'The retries it has already run stay. The rest keep their Needs attention flag, and can be retried later.',
                'confirmLabel' => 'Cancel retry all',
            ]) : '',
        ];
    }

    if ($canRetry && ($importAllActive || $recentlyOver($importAllJob))) {
        $importAllTotal = max(1, count($importAllJob['places']));
        $importAllDone = min($importAllTotal, (int) $importAllJob['next']);
        $jobRows[] = [
            'name' => 'Import never-imported',
            'pill' => match ($importAllJob['status']) {
                'waiting' => ['adif-status-stale', 'Queued'],
                'running' => ['adif-status-stale', 'Running'],
                'done' => ['adif-status-ok', 'Finished'],
                'cancelled' => ['adif-status-none', 'Cancelled'],
                default => ['adif-status-error', 'Stopped'],
            },
            'percent' => $importAllDone / $importAllTotal * 100,
            'detail' => number_format($importAllDone) . ' of ' . number_format($importAllTotal) . ' places'
                . ($importAllJob['imported'] > 0 ? ' · ' . number_format($importAllJob['imported']) . ' imported' : '')
                . ($importAllJob['attention'] > 0 ? ', <strong class="adif-imp-job-alert">' . number_format($importAllJob['attention']) . ' need attention</strong>' : '')
                . ($importAllJob['skipped'] > 0 ? ', ' . number_format($importAllJob['skipped']) . ' no longer needed or not ready' : '')
                . ($importAllJob['failed'] > 0 ? ', ' . number_format($importAllJob['failed']) . " couldn't be imported" : '')
                . ' · requested by ' . adif_imp_h($importAllJob['requested_by']) . ' ' . adif_imp_h(adif_imp_ago($importAllJob['requested_at']))
                . ($importAllJob['status'] === 'cancelled' ? ' · cancelled by ' . adif_imp_h($importAllJob['cancelled_by'] ?? '?') : '')
                . ($importAllJob['status'] === 'failed' ? ' · ' . adif_imp_h($importAllJob['message'] ?? '') : ''),
            'cancel' => $importAllActive ? adif_imp_cancel_form($module, 'adif_import_all_action', $recordFilter, [
                'title' => 'Cancel the import?',
                'message' => 'The imports it has already run stay. The places it hasn\'t reached yet stay on the Never imported list.',
                'confirmLabel' => 'Cancel import',
            ]) : '',
        ];
    }
    ?>
    <?php if (!empty($jobRows)): ?>
        <section class="adif-imp-activity" id="adif-imp-activity" aria-label="Background jobs">
            <?php foreach ($jobRows as $row): ?>
                <div class="adif-imp-job">
                    <strong class="adif-imp-job-name"><?= adif_imp_h($row['name']) ?></strong>
                    <span class="adif-status-pill <?= $row['pill'][0] ?>"><?= adif_imp_h($row['pill'][1]) ?></span>
                    <?php if ($row['percent'] !== null): ?>
                        <span class="adif-imp-job-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100"
                              aria-valuenow="<?= (int) round($row['percent']) ?>" aria-label="<?= adif_imp_h($row['name']) ?> progress"><span style="width: <?= round($row['percent'], 1) ?>%"></span></span>
                    <?php endif; ?>
                    <span class="adif-imp-job-detail"><?= $row['detail'] ?></span>
                    <?= $row['cancel'] ?>
                </div>
            <?php endforeach; ?>
            <?php if ($refreshActive || $retryActive || $importAllActive): ?>
                <p class="adif-imp-job-note">Background jobs keep running if you leave this page. Reload it to see their progress.</p>
            <?php endif; ?>
        </section>
    <?php else: ?>
        <span id="adif-imp-activity"></span>
    <?php endif; ?>

    <?php if ($recordStatus !== null): ?>
        <section class="adif-dt-card adif-imp-record">
            <h4>Latest import for record <?= adif_imp_h($recordFilter) ?>, by event</h4>
            <?php if (empty($recordStatus)): ?>
                <div class="adif-alert adif-alert-empty">No import has run for this record yet. Runs appear after a
                    survey submission whose instrument has API mappings, or a retry from the run list below.</div>
            <?php else: ?>
                <?php $needsAttention = false; ?>
                <?php $hasStale = false; ?>
                <?php $hasDismissed = false; ?>
                <?php foreach ($recordStatus as $run): ?>
                    <?php $needsAttention = $needsAttention || $run['outcome'] === 'attention'; ?>
                    <?php $hasStale = $hasStale || $run['outcome'] === 'stale'; ?>
                    <?php $hasDismissed = $hasDismissed || $run['outcome'] === 'dismissed'; ?>
                    <div class="adif-imp-record-run<?= $run['outcome'] === 'attention' ? ' is-attention' : '' ?>">
                        <div class="adif-imp-record-head">
                            <?= adif_imp_pill($run['outcome'], $module->importOutcomeLabel($run['outcome'])) ?>
                            <strong><?= $run['event_name'] !== null ? adif_imp_h($run['event_name']) : 'Single event' ?></strong>
                            <span><?= adif_imp_instrument($run) ?></span>
                            <span class="adif-imp-when"><?= adif_imp_h(date('Y-m-d H:i', $run['logged'])) ?>
                                by <?= adif_imp_h($run['user'] !== '' ? $run['user'] : '?') ?></span>
                        </div>
                        <?php if ($run['outcome'] === 'attention'): ?>
                            <p class="adif-imp-explain">Needs running again &mdash; <?= nl2br(adif_imp_h($run['explanation'] ?? '')) ?></p>
                        <?php elseif ($run['outcome'] === 'dismissed'): ?>
                            <p class="adif-imp-explain"><?= adif_imp_dismissed_note($run) ?> Left empty:
                                <?= nl2br(adif_imp_h($run['explanation'] ?? '')) ?></p>
                        <?php elseif ($run['outcome'] === 'stale'): ?>
                            <p class="adif-imp-explain">Fields were filled from data stored on the server because the API was
                                unavailable (intended behaviour). <?= nl2br(adif_imp_h($run['notices'])) ?></p>
                        <?php else: ?>
                            <p class="adif-imp-explain">Fields saved: <?= adif_imp_h($run['saved'] !== '' ? $run['saved'] : 'none') ?>.</p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if ($needsAttention): ?>
                    <div class="adif-alert adif-alert-error" role="alert">This record has imports that need to be run
                        again &mdash; find them in the run list below and use its retry buttons.</div>
                <?php elseif ($hasDismissed): ?>
                    <div class="adif-alert adif-alert-empty">This record's remaining failed imports were dismissed, so
                        nothing is flagged. Their fields are still empty; undo a dismissal in the run list to retry it.</div>
                <?php elseif ($hasStale): ?>
                    <div class="adif-alert adif-alert-empty">The latest imports for this record used stored data. Nothing
                        is waiting, but re-running them once the APIs are back would refresh the values.</div>
                <?php else: ?>
                    <div class="adif-alert adif-imp-success" role="status">The latest imports for this record completed
                        without leaving fields empty.</div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="adif-imp-runs" id="adif-imp-runs">
        <div class="adif-imp-runs-head">
            <h4>Import runs<?= $recordFilter !== '' ? ' for record ' . adif_imp_h($recordFilter) : '' ?></h4>
            <nav class="adif-imp-tabs" aria-label="Show runs by outcome">
                <?php foreach (['' => 'All', 'attention' => 'Needs attention', 'dismissed' => 'Dismissed', 'stale' => 'From stored data', 'ok' => 'OK'] as $key => $label): ?>
                    <?php $tabCount = $key === '' ? count($allRuns) : (int) ($outcomeCounts[$key] ?? 0); ?>
                    <a href="<?= adif_imp_h(adif_imp_url($hiddenParams, $runFilters, ['status' => $key, 'p' => 1])) ?>"
                       class="adif-imp-tab<?= $statusFilter === $key ? ' is-active' : '' ?><?= $key === 'attention' && $tabCount > 0 ? ' is-alert' : '' ?>"
                       <?= $statusFilter === $key ? 'aria-current="true"' : '' ?>>
                        <?= adif_imp_h($label) ?> <span class="adif-imp-tab-count"><?= number_format($tabCount) ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>

    <?php if (empty($runs)): ?>
        <div class="adif-alert adif-alert-empty">
            <?= $statusFilter !== '' || $recordFilter !== '' ? 'No runs match these filters.' : 'No import runs are logged yet.' ?>
        </div>
        <?php if ($diagnostics !== null && $diagnostics['entries'] > 0 && $recordFilter === '' && $statusFilter === ''): ?>
            <div class="adif-alert adif-alert-error">
                <strong>Debug:</strong> this project's module log holds <?= (int) $diagnostics['entries'] ?> import
                entr<?= $diagnostics['entries'] === 1 ? 'y' : 'ies' ?>
                (<?= (int) $diagnostics['with_run_id'] ?> with a run_id, <?= (int) $diagnostics['request_entries'] ?>
                per-request entries), but none could be grouped into runs. The newest entry:
                <code style="white-space: pre-wrap; word-break: break-word;"><?= adif_imp_h(json_encode($diagnostics['sample'])) ?></code>
                &mdash; if its run_id is empty, it was logged before runs had ids; send this JSON along with a bug report.
            </div>
        <?php endif; ?>
        <?php if ($diagnostics !== null && ($diagnostics['without_project'] ?? 0) > 0): ?>
            <div class="adif-alert adif-alert-error">
                <strong>Debug:</strong> <?= (int) $diagnostics['without_project'] ?> import
                entr<?= $diagnostics['without_project'] === 1 ? 'y was' : 'ies were' ?> logged without a project, so
                no project's Imports page can show <?= $diagnostics['without_project'] === 1 ? 'it' : 'them' ?>.
                Runs logged from now on carry their project explicitly.
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="adif-imp-table-wrap">
            <table class="adif-imp-runs-table">
                <thead>
                    <tr>
                        <th class="adif-imp-col-toggle"><span class="adif-sr-only">Details</span></th>
                        <th>When</th>
                        <th>Requested by</th>
                        <th>Record</th>
                        <th>Event</th>
                        <th>Instrument</th>
                        <th class="adif-imp-col-num">Requests</th>
                        <th>Outcome</th>
                    </tr>
                </thead>
                <?php foreach ($pageRuns as $run): ?>
                    <?php $detailId = 'adif-imp-run-' . $run['run_id']; ?>
                    <tbody class="adif-imp-run<?= match ($run['outcome']) { 'attention' => ' is-attention', 'dismissed' => ' is-dismissed', 'stale' => ' is-stale', default => '' } ?>"
                           data-run-id="<?= adif_imp_h($run['run_id']) ?>">
                        <tr class="adif-imp-run-summary">
                            <td class="adif-imp-col-toggle">
                                <button type="button" class="adif-imp-toggle" aria-expanded="false" aria-controls="<?= adif_imp_h($detailId) ?>"
                                        title="Show this run's details"><span aria-hidden="true">&#9656;</span><span class="adif-sr-only">Details</span></button>
                            </td>
                            <td class="adif-imp-when" title="<?= adif_imp_h(date('Y-m-d H:i:s', $run['logged'])) ?>"><?= adif_imp_h(date('Y-m-d H:i', $run['logged'])) ?></td>
                            <td class="adif-imp-user"><?= adif_imp_h($run['user'] !== '' ? $run['user'] : '?') ?></td>
                            <td class="adif-imp-rec"><?= adif_imp_record_link($run['record'], $pid) ?></td>
                            <td><?= $run['event_name'] !== null ? adif_imp_h($run['event_name']) : 'Single event' ?></td>
                            <td><?= adif_imp_instrument($run) ?></td>
                            <td class="adif-imp-col-num"><?= (int) $run['requests'] ?></td>
                            <td><?= adif_imp_pill($run['outcome'], $module->importOutcomeLabel($run['outcome'])) ?></td>
                        </tr>
                        <tr class="adif-imp-run-detail" id="<?= adif_imp_h($detailId) ?>" hidden>
                            <td colspan="8">
                            <p class="adif-imp-desc"><?= adif_imp_h($run['description'] !== '' ? $run['description'] : 'Import run') ?>
                                &middot; run_id <?= adif_imp_h($run['run_id']) ?>
                                &middot; <?= (int) $run['api_calls'] ?> API call<?= (int) $run['api_calls'] === 1 ? '' : 's' ?>
                                &middot; <?= adif_imp_h($run['elapsed_ms'] !== null ? $run['elapsed_ms'] . ' ms' : '?') ?></p>

                            <?php if ($run['outcome'] === 'attention'): ?>
                                <div class="adif-alert adif-alert-error adif-imp-status-note">Needs running again &mdash; <?= nl2br(adif_imp_h($run['explanation'] ?? '')) ?></div>
                            <?php elseif ($run['outcome'] === 'dismissed'): ?>
                                <div class="adif-alert adif-alert-empty adif-imp-status-note"><?= adif_imp_dismissed_note($run) ?>
                                    It no longer counts as needing attention; left empty: <?= nl2br(adif_imp_h($run['explanation'] ?? '')) ?></div>
                            <?php elseif ($run['outcome'] === 'stale'): ?>
                                <div class="adif-alert adif-alert-empty">Answered from stored data during an API outage; its fields
                                    were filled. <?= nl2br(adif_imp_h($run['notices'])) ?></div>
                            <?php endif; ?>

                            <?php if ($canRetry && in_array($run['outcome'], ['attention', 'dismissed'], true)): ?>
                                <?= adif_imp_actions($run) ?>
                            <?php endif; ?>

                            <?php if (!empty($run['request_logs'])): ?>
                                <div class="adif-imp-scroll">
                                    <table class="adif-headers-table adif-imp-requests">
                                        <thead>
                                            <tr>
                                                <th>Source</th>
                                                <th>Path</th>
                                                <th>Outcome</th>
                                                <th>HTTP</th>
                                                <th>ms</th>
                                                <th>Fields saved</th>
                                                <th>Fields skipped</th>
                                                <th>Not populated</th>
                                                <th>Error</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($run['request_logs'] as $request): ?>
                                                <tr>
                                                    <td><?= adif_imp_h($request['source'] ?? '') ?></td>
                                                    <td class="adif-imp-path"><?= adif_imp_h($request['path'] ?? '') ?></td>
                                                    <td><?= adif_imp_h($request['outcome'] ?? '') ?></td>
                                                    <td><?= adif_imp_h($request['http_status'] ?? '') !== '' ? adif_imp_h($request['http_status'] ?? '') : '&mdash;' ?></td>
                                                    <td><?= adif_imp_h($request['response_ms'] ?? '') !== '' ? adif_imp_h($request['response_ms'] ?? '') : '&mdash;' ?></td>
                                                    <td><?= adif_imp_h($request['fields_saved'] ?? '') !== '' ? adif_imp_h($request['fields_saved'] ?? '') : '&mdash;' ?></td>
                                                    <td><?= adif_imp_h($request['fields_skipped'] ?? '') !== '' ? adif_imp_h($request['fields_skipped'] ?? '') : '&mdash;' ?></td>
                                                    <td><?= adif_imp_h($request['fields_not_populated'] ?? '') !== '' ? adif_imp_h($request['fields_not_populated'] ?? '') : '&mdash;' ?></td>
                                                    <td><?= adif_imp_h($request['error'] ?? '') !== '' ? adif_imp_h($request['error'] ?? '') : '&mdash;' ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>

                            <?php if ($run['skipped'] !== ''): ?>
                                <p class="adif-imp-note"><strong>Skipped:</strong> <?= nl2br(adif_imp_h($run['skipped'])) ?></p>
                            <?php endif; ?>
                            <?php if ($run['notices'] !== '' && $run['outcome'] !== 'stale'): ?>
                                <p class="adif-imp-note"><strong>Notices:</strong> <?= nl2br(adif_imp_h($run['notices'])) ?></p>
                            <?php endif; ?>

                            <div class="adif-imp-retry-result"></div>

                                <div class="adif-imp-retry-result"></div>
                            </td>
                        </tr>
                    </tbody>
                <?php endforeach; ?>
            </table>
        </div>

        <?php $firstShown = ($currentPage - 1) * $perPage + 1; ?>
        <div class="adif-imp-pager">
            <span class="adif-imp-pager-count">Showing <?= number_format($firstShown) ?>&ndash;<?= number_format($firstShown + count($pageRuns) - 1) ?>
                of <?= number_format(count($runs)) ?> run<?= count($runs) === 1 ? '' : 's' ?><?= count($allRuns) >= $runListCap
                    ? ' (the latest ' . $runListCap . ' are listed)' : '' ?></span>
            <?php if ($pageCount > 1): ?>
                <nav class="adif-imp-pages" aria-label="Pages of runs">
                    <?php if ($currentPage > 1): ?>
                        <a href="<?= adif_imp_h(adif_imp_url($hiddenParams, $runFilters, ['p' => $currentPage - 1])) ?>" rel="prev">&lsaquo; Newer</a>
                    <?php else: ?>
                        <span class="is-disabled">&lsaquo; Newer</span>
                    <?php endif; ?>
                    <?php foreach (adif_imp_page_numbers($currentPage, $pageCount) as $number): ?>
                        <?php if ($number === null): ?>
                            <span class="adif-imp-pages-gap">&hellip;</span>
                        <?php elseif ($number === $currentPage): ?>
                            <span class="is-current" aria-current="page"><?= $number ?></span>
                        <?php else: ?>
                            <a href="<?= adif_imp_h(adif_imp_url($hiddenParams, $runFilters, ['p' => $number])) ?>"><?= $number ?></a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if ($currentPage < $pageCount): ?>
                        <a href="<?= adif_imp_h(adif_imp_url($hiddenParams, $runFilters, ['p' => $currentPage + 1])) ?>" rel="next">Older &rsaquo;</a>
                    <?php else: ?>
                        <span class="is-disabled">Older &rsaquo;</span>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
            <span class="adif-imp-per-page">Per page:
                <?php foreach ([25, 50, 100] as $option): ?>
                    <?php if ($option === $perPage): ?>
                        <strong aria-current="true"><?= $option ?></strong>
                    <?php else: ?>
                        <a href="<?= adif_imp_h(adif_imp_url($hiddenParams, $runFilters, ['per_page' => $option, 'p' => 1])) ?>"><?= $option ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </span>
        </div>
    <?php endif; ?>
    </section>

    <?php if ($neverImportedError !== null): ?>
        <div class="adif-alert adif-alert-error" role="alert">Couldn't check for records that have never been
            imported: <?= adif_imp_h($neverImportedError) ?></div>
    <?php elseif (!empty($neverImported)): ?>
        <details class="adif-imp-panel adif-imp-never" id="adif-imp-never">
            <summary>
                <span class="adif-imp-panel-title">Never imported</span>
                <span class="adif-imp-tab-count"><?= number_format(count($neverImported)) ?></span>
                <span class="adif-imp-panel-hint">place<?= count($neverImported) === 1 ? '' : 's' ?> in <?= $neverRecordCount ?>
                    record<?= $neverRecordCount === 1 ? '' : 's' ?> with data but no import yet<?= $recordFilter !== ''
                        ? ' (record ' . adif_imp_h($recordFilter) . ' only)' : '' ?></span>
            </summary>
            <p class="adif-imp-explain">These records have data in an event (or instance) with mapped fields that are
                still empty and have never been imported &mdash; usually records entered before ADIF was enabled,
                or fields mapped after a record&rsquo;s first import, which are imported on their own. Imports run when a survey is submitted, so these won't fill on their
                own. Import them one at a time here, or all at once from <strong>Actions</strong>. An import started here
                works like a survey's, and is logged and listed in the run table like any other run.</p>
            <?php if ($canRetry): ?>
                <script type="application/json" id="adif-imp-never-units"><?= json_encode(array_map(fn($unit) => [
                    'key' => $unit['key'],
                    'record' => $unit['record'],
                    'event_id' => $unit['event_id'],
                    'instrument' => $unit['instrument'] ?? '',
                    'instance' => $unit['repeat_instance'],
                ], $neverImportable), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
            <?php else: ?>
                <p class="adif-imp-note">Users with View &amp; Edit rights on the mapped instruments can run these
                    imports from this page.</p>
            <?php endif; ?>
            <?php if (count($neverImportable) < count($neverImported)): ?>
                <p class="adif-imp-note"><?= count($neverImported) - count($neverImportable) ?> can't be imported yet: a
                    value the API lookup needs (an address, say) isn't in the record. They're left out of Import
                    never-imported records; see each one's status.</p>
            <?php endif; ?>

            <?php if (empty($neverImported)): ?>
                <p class="adif-imp-note">Nothing is waiting on a first import now<?= $recordFilter !== '' ? ' for this record' : '' ?>.</p>
            <?php else: ?>
            <div class="adif-imp-scroll">
                <table class="adif-headers-table adif-imp-never-table">
                    <thead>
                        <tr>
                            <th>Record</th>
                            <th>Event</th>
                            <th>Instrument</th>
                            <th>Mapped fields (all empty)</th>
                            <th>Status</th>
                            <?php if ($canRetry): ?><th></th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($neverImported, 0, $neverShownCap) as $unit): ?>
                            <tr data-unit-key="<?= adif_imp_h($unit['key']) ?>" class="<?= $unit['importable'] ? '' : 'is-waiting' ?>">
                                <td><?= adif_imp_record_link($unit['record'], $pid) ?></td>
                                <td><?= $unit['event_name'] !== null ? adif_imp_h($unit['event_name']) : 'Single event' ?></td>
                                <td><?= adif_imp_never_where($unit) ?></td>
                                <td><?= adif_imp_field_list($unit['fields']) ?></td>
                                <td class="adif-imp-never-status">
                                    <?php if (!$unit['importable']): ?>
                                        <span class="adif-status-pill adif-status-none">Waiting on data</span>
                                        <div class="adif-imp-explain"><?= nl2br(adif_imp_h(implode("\n", $unit['missing']))) ?></div>
                                    <?php elseif (!empty($unit['missing'])): ?>
                                        <span class="adif-status-pill adif-status-stale">Partly ready</span>
                                        <div class="adif-imp-explain">Some fields will stay empty for now:
                                            <?= nl2br(adif_imp_h(implode("\n", $unit['missing']))) ?></div>
                                    <?php else: ?>
                                        <span class="adif-status-pill adif-status-none">Not imported</span>
                                    <?php endif; ?>
                                </td>
                                <?php if ($canRetry): ?>
                                    <td class="adif-imp-never-action">
                                        <?php if ($unit['importable']): ?>
                                            <button type="button" class="btn btn-defaultrc btn-sm adif-imp-import-one"
                                                    data-unit-key="<?= adif_imp_h($unit['key']) ?>"
                                                    title="Runs the first import for record <?= adif_imp_h($unit['record']) ?> here">Import</button>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            <?php if (count($neverImported) > $neverShownCap): ?>
                <p class="adif-imp-note">Showing the first <?= $neverShownCap ?> of <?= count($neverImported) ?>.<?= $canRetry
                    ? ' Import never-imported records covers every one that can be imported, listed or not.' : '' ?></p>
            <?php endif; ?>
        </details>
    <?php endif; ?>

    <?php if ($queueError !== null): ?>
        <div class="adif-alert adif-alert-error" role="alert">Couldn't read the background import queue:
            <?= adif_imp_h($queueError) ?></div>
    <?php elseif (!empty($queuedImports)): ?>
        <details class="adif-imp-panel adif-imp-queue" id="adif-imp-queue">
            <summary>
                <span class="adif-imp-panel-title">Queued imports</span>
                <span class="adif-imp-tab-count"><?= number_format(count($queuedImports)) ?></span>
                <span class="adif-imp-panel-hint">waiting to run in the background<?= $recordFilter !== ''
                    ? ' (record ' . adif_imp_h($recordFilter) . ' only)' : '' ?></span>
            </summary>
            <p class="adif-imp-explain">Imports queued after survey submissions, so respondents don't wait on the
                APIs. REDCap's cron runs them about once a minute; the queue last ran
                <strong><?= $queueLastRun > 0 ? adif_imp_h(adif_imp_ago($queueLastRun)) : 'never' ?></strong>.
                Each one then appears in the run table like any other import.</p>
            <div class="adif-imp-scroll">
                <table class="adif-headers-table adif-imp-never-table">
                    <thead>
                        <tr>
                            <th>Record</th>
                            <th>Event</th>
                            <th>Instrument</th>
                            <th>Queued</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($queuedImports, 0, $queueShownCap) as $item): ?>
                            <?php $eventName = $module->importEventName($pid, $item['event_id']); ?>
                            <tr>
                                <td><?= adif_imp_record_link($item['record'], $pid) ?></td>
                                <td><?= $eventName !== null ? adif_imp_h($eventName) : 'Single event' ?></td>
                                <td><?= $item['instrument'] !== null ? adif_imp_h($item['instrument'])
                                        . ($item['repeat_instance'] > 1 ? ' #' . (int) $item['repeat_instance'] : '') : '&mdash;' ?></td>
                                <td title="<?= adif_imp_h(date('Y-m-d H:i:s', $item['queued'])) ?>"><?= adif_imp_h(adif_imp_ago($item['queued'])) ?></td>
                                <td><?= $item['attempts'] > 0
                                        ? '<span class="adif-status-pill adif-status-stale">Stopped partway ' . (int) $item['attempts']
                                            . ' time' . ($item['attempts'] === 1 ? '' : 's') . '</span>'
                                        : '<span class="adif-status-pill adif-status-none">Waiting</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (count($queuedImports) > $queueShownCap): ?>
                <p class="adif-imp-note">Showing the oldest <?= $queueShownCap ?> of <?= count($queuedImports) ?>.</p>
            <?php endif; ?>
        </details>
    <?php endif; ?>

    <?php if ($canRetry || $canRefresh): ?>
        <?php // The confirmation modal every action on the page asks in: filled in by the script below ?>
        <dialog class="adif-imp-dialog" id="adif-imp-confirm" aria-labelledby="adif-imp-confirm-title">
            <form method="dialog">
                <div class="adif-imp-dialog-head">
                    <h4 id="adif-imp-confirm-title"></h4>
                    <button type="submit" value="cancel" class="adif-imp-dialog-close" aria-label="Close">
                        <svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M3 3l8 8M11 3l-8 8"></path></svg>
                    </button>
                </div>
                <div class="adif-imp-confirm-body"></div>
                <label class="adif-imp-dialog-check" hidden>
                    <input type="checkbox" id="adif-imp-confirm-check">
                    <span><span class="adif-imp-confirm-check-label"></span>
                        <small class="adif-imp-confirm-check-hint"></small></span>
                </label>
                <div class="adif-imp-dialog-buttons">
                    <button type="submit" value="cancel" class="btn btn-defaultrc btn-sm adif-imp-confirm-cancel">Cancel</button>
                    <button type="submit" value="ok" class="btn btn-primaryrc btn-sm adif-imp-confirm-ok">Continue</button>
                </div>
            </form>
        </dialog>
    <?php endif; ?>

    <?php if ($canRefresh && !$refreshActive): ?>
        <dialog class="adif-imp-dialog" id="adif-imp-refresh-dialog" aria-labelledby="adif-imp-refresh-title">
            <form method="post" action="<?= adif_imp_h($module->getUrl('pages/Imports.php')) ?>">
                <input type="hidden" name="redcap_csrf_token" value="<?= adif_imp_h($module->getCSRFToken()) ?>">
                <input type="hidden" name="adif_refresh_action" value="start">
                <div class="adif-imp-dialog-head">
                    <h4 id="adif-imp-refresh-title">Refresh all records</h4>
                    <button type="button" class="adif-imp-dialog-close" data-close-dialog aria-label="Close">
                        <svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M3 3l8 8M11 3l-8 8"></path></svg>
                    </button>
                </div>
                <p>Runs the import again for every record<?= $module->adifVisibleRecords($pid) !== null ? ' in your data access group' : '' ?>:
                    each event and instance with data gets its mapped fields filled with what the APIs return now,
                    replacing the values there. Useful after a new data release, or after changing the mappings.</p>
                <dl class="adif-imp-dialog-facts">
                    <div><dt>Runs between</dt><dd><?= adif_imp_h(adif_imp_hour($refreshWindow['start'])) ?>&ndash;<?= adif_imp_h(adif_imp_hour($refreshWindow['end'])) ?></dd></div>
                    <div><dt>Server time now</dt><dd><?= adif_imp_h(date('H:i')) ?></dd></div>
                    <div><dt>Server limit</dt><dd><?= (int) $refreshWindow['per_minute'] ?> imports / min</dd></div>
                    <div><dt>Shared with</dt><dd>every project's background imports</dd></div>
                </dl>
                <label class="adif-imp-dialog-check">
                    <input type="checkbox" name="bypass_cache" value="1" checked>
                    <span>Skip stored data and call the APIs
                        <small>Recommended: the refresh gets current data rather than responses the cache stored.</small></span>
                </label>
                <p class="adif-imp-dialog-note">A large project can take several nights. You can leave the page: progress
                    shows above the run table, where the refresh can be cancelled. Each import is listed like any other run.</p>
                <div class="adif-imp-dialog-buttons">
                    <button type="button" class="btn btn-defaultrc btn-sm" data-close-dialog>Cancel</button>
                    <button type="submit" class="btn btn-primaryrc btn-sm">Start refresh</button>
                </div>
            </form>
        </dialog>
    <?php endif; ?>
</div>

<script>
    // The Actions menu, the confirmation modal every action asks in, the refresh dialog, and the
    // collapsed panels (Never imported, Queued imports) opened by the header's chips. Plain DOM, so they
    // work for every user.
    (function () {
        var toggle = document.getElementById('adif-imp-actions-toggle');
        var menu = document.getElementById('adif-imp-actions-menu');
        function setMenu(open) {
            if (!toggle || !menu) {
                return;
            }
            menu.hidden = !open;
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) {
                var first = menu.querySelector('button:not([disabled])');
                if (first) {
                    first.focus();
                }
            }
        }
        if (toggle && menu) {
            toggle.addEventListener('click', function () {
                setMenu(menu.hidden);
            });
            document.addEventListener('click', function (event) {
                if (!menu.hidden && !event.target.closest('.adif-imp-actions')) {
                    setMenu(false);
                }
            });
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && !menu.hidden) {
                    setMenu(false);
                    toggle.focus();
                }
            });
            // Run queued imports now is handled by js/imports.js; close the menu so its progress shows
            var runQueue = document.getElementById('adif-imp-run-queue');
            if (runQueue) {
                runQueue.addEventListener('click', function () {
                    setMenu(false);
                });
            }
        }

        // ADIFImportsConfirm(options): asks in the confirmation modal and resolves to { confirmed, checked }.
        // options: title, message (paragraphs split by blank lines; plain text), confirmLabel, cancelLabel,
        // tone ('danger' for a red confirm button), checkbox ({ label, hint, checked }). js/imports.js asks
        // through it too. A browser without <dialog> gets the browser's own confirm instead.
        var confirmDialog = document.getElementById('adif-imp-confirm');
        function ask(options) {
            options = options || {};
            if (!confirmDialog || typeof confirmDialog.showModal !== 'function') {
                var ok = window.confirm((options.title ? options.title + '\n\n' : '') + (options.message || ''));
                return Promise.resolve({ confirmed: ok, checked: !!(options.checkbox && options.checkbox.checked) });
            }
            var opener = document.activeElement;
            setMenu(false);

            confirmDialog.querySelector('#adif-imp-confirm-title').textContent = options.title || 'Are you sure?';
            var body = confirmDialog.querySelector('.adif-imp-confirm-body');
            body.textContent = '';
            String(options.message || '').split(/\n\s*\n/).forEach(function (text) {
                if (text.trim() !== '') {
                    var paragraph = document.createElement('p');
                    paragraph.textContent = text.trim();
                    body.appendChild(paragraph);
                }
            });

            var checkLabel = confirmDialog.querySelector('.adif-imp-dialog-check');
            var check = confirmDialog.querySelector('#adif-imp-confirm-check');
            checkLabel.hidden = !options.checkbox;
            if (options.checkbox) {
                checkLabel.querySelector('.adif-imp-confirm-check-label').textContent = options.checkbox.label || '';
                checkLabel.querySelector('.adif-imp-confirm-check-hint').textContent = options.checkbox.hint || '';
                check.checked = !!options.checkbox.checked;
            }

            var okButton = confirmDialog.querySelector('.adif-imp-confirm-ok');
            var cancelButton = confirmDialog.querySelector('.adif-imp-confirm-cancel');
            okButton.textContent = options.confirmLabel || 'Continue';
            okButton.className = 'btn btn-sm adif-imp-confirm-ok ' + (options.tone === 'danger' ? 'btn-danger' : 'btn-primaryrc');
            cancelButton.textContent = options.cancelLabel || 'Cancel';

            return new Promise(function (resolve) {
                confirmDialog.returnValue = '';
                confirmDialog.addEventListener('close', function onClose() {
                    confirmDialog.removeEventListener('close', onClose);
                    // Back to the button that asked, or to the Actions button when that was in the menu
                    var back = opener && opener.offsetParent !== null ? opener : toggle;
                    if (back && typeof back.focus === 'function') {
                        back.focus();
                    }
                    resolve({ confirmed: confirmDialog.returnValue === 'ok', checked: check.checked });
                });
                confirmDialog.showModal();
                // A destructive action starts on its safe choice
                (options.tone === 'danger' ? cancelButton : okButton).focus();
            });
        }
        window.ADIFImportsConfirm = ask;

        // A form with data-confirm (Retry all, Import all, the Cancel buttons) asks first, then posts,
        // with its checkbox's answer added as a hidden field
        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (!form.matches || !form.matches('form[data-confirm]')) {
                return;
            }
            event.preventDefault();
            var options;
            try {
                options = JSON.parse(form.getAttribute('data-confirm'));
            } catch (e) {
                options = {};
            }
            ask(options).then(function (answer) {
                if (!answer.confirmed) {
                    return;
                }
                if (options.checkbox && options.checkbox.name) {
                    var field = form.querySelector('input[type="hidden"][name="' + options.checkbox.name + '"]');
                    if (!field) {
                        field = document.createElement('input');
                        field.type = 'hidden';
                        field.name = options.checkbox.name;
                        form.appendChild(field);
                    }
                    field.value = answer.checked ? '1' : '';
                }
                form.submit();   // not a submit event, so it isn't asked again
            });
        });

        var dialog = document.getElementById('adif-imp-refresh-dialog');
        var opener = document.getElementById('adif-imp-refresh-open');
        if (dialog && opener && typeof dialog.showModal === 'function') {
            opener.addEventListener('click', function () {
                setMenu(false);
                dialog.showModal();
            });
            dialog.querySelectorAll('[data-close-dialog]').forEach(function (button) {
                button.addEventListener('click', function () {
                    dialog.close();
                });
            });
        } else if (opener) {
            opener.disabled = true;   // a browser without <dialog>
        }

        // A link to #adif-imp-never or #adif-imp-queue opens that panel
        function openFromHash() {
            var target = location.hash ? document.getElementById(location.hash.slice(1)) : null;
            if (target && target.tagName === 'DETAILS') {
                target.open = true;
                target.scrollIntoView({ block: 'start' });
            }
        }
        window.addEventListener('hashchange', openFromHash);
        openFromHash();
    })();

    // Opening a run's details: its toggle button, or a click anywhere on its row that isn't a link,
    // button or form control. Works for every user, so it's here rather than in js/imports.js, which
    // only loads for users who can retry.
    (function () {
        function toggle(button) {
            var open = button.getAttribute('aria-expanded') !== 'true';
            var detail = document.getElementById(button.getAttribute('aria-controls'));
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
            button.title = open ? "Hide this run's details" : "Show this run's details";
            button.closest('.adif-imp-run').classList.toggle('is-open', open);
            if (detail) {
                detail.hidden = !open;
            }
        }
        document.addEventListener('click', function (event) {
            var row = event.target.closest('.adif-imp-run-summary');
            if (!row) {
                return;
            }
            var button = event.target.closest('.adif-imp-toggle');
            if (!button && event.target.closest('a, button, input, select, textarea, label')) {
                return;
            }
            toggle(button || row.querySelector('.adif-imp-toggle'));
        });
    })();
</script>
