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

require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';

$pid = $module->getProjectId();

$recordFilter = trim((string) ($_GET['record'] ?? ''));
$statusFilter = in_array((string) ($_GET['status'] ?? ''), ['attention', 'dismissed', 'stale', 'ok'], true) ? (string) $_GET['status'] : '';
// Read once: re-reading $_GET['limit'] after the check would turn a missing parameter (the page as
// opened from the menu) into 0, which importRuns() clamps to a single run
$limitParam = (int) ($_GET['limit'] ?? 50);
$limit = in_array($limitParam, [25, 50, 100, 200], true) ? $limitParam : 50;

$allRuns = $module->importRuns($pid, ['record' => $recordFilter, 'limit' => $limit]);
$attentionCount = count(array_filter($allRuns, fn($run) => $run['outcome'] === 'attention'));

// Retrying writes record data, so it's limited to the users who define what gets imported
$canRetry = $module->canConfigureApiSources($pid);

$runs = $allRuns;
if ($statusFilter !== '') {
    $runs = array_values(array_filter($runs, fn($run) => $run['outcome'] === $statusFilter));
}

// The record lookup doubles as the table's record filter: entering a record shows both its latest
// run per event (below) and the filtered runs list
$recordStatus = $recordFilter !== '' ? $module->importRecordStatus($pid, $recordFilter) : null;

// When the list is empty despite runs having been logged, this tells the two likely causes apart
$diagnostics = empty($allRuns) ? $module->importLogDiagnostics($pid) : null;

// The page's own URL parameters: a GET form replaces the whole query string, so they must be
// echoed back as hidden fields or submitting the filters lands on "a module prefix must be included"
$hiddenParams = [];
foreach (['prefix', 'page', 'pid'] as $name) {
    if (isset($_GET[$name])) {
        $hiddenParams[$name] = (string) $_GET[$name];
    }
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
    <h3>ADIF Imports</h3>
    <p>Every import run the module has logged, newest first, with its outcome: which endpoint requests
        failed and which mapped fields were therefore left empty. A run <strong>needs attention</strong> when a
        request failed with nothing saved from it, so its fields are still waiting on the data; runs answered
        from <strong>stored data</strong> during an API outage are noted but not flagged, since their fields were
        filled as intended. Runs that need attention can be re-run from here for the same record, event,
        instrument and instance &mdash; the retry is logged like any other run.</p>

    <?php if ($attentionCount > 0 && $statusFilter !== 'attention'): ?>
        <div class="adif-alert adif-alert-error" role="alert">
            <strong><?= $attentionCount ?></strong> of the last <?= count($allRuns) ?> import run<?= count($allRuns) === 1 ? '' : 's' ?>
            <?= $attentionCount === 1 ? 'has' : 'have' ?> left mapped fields empty<?= $canRetry
                ? ' - filter by <strong>Needs attention</strong> below to see just those, or use Retry all failed.'
                : '. Filter by <strong>Needs attention</strong> below to see just those.' ?>
        </div>
    <?php endif; ?>

    <form method="get" class="adif-form-row adif-imp-filters">
        <?php foreach ($hiddenParams as $name => $value): ?>
            <input type="hidden" name="<?= adif_imp_h($name) ?>" value="<?= adif_imp_h($value) ?>">
        <?php endforeach; ?>
        <div class="adif-form-field">
            <label for="adif-imp-record">Record (exact name) &mdash; also runs the lookup below</label>
            <input type="text" name="record" id="adif-imp-record" value="<?= adif_imp_h($recordFilter) ?>" placeholder="e.g. 101">
        </div>
        <div class="adif-form-field">
            <label for="adif-imp-status">Outcome</label>
            <select name="status" id="adif-imp-status">
                <option value="">All runs</option>
                <option value="attention" <?= $statusFilter === 'attention' ? 'selected' : '' ?>>Needs attention</option>
                <option value="dismissed" <?= $statusFilter === 'dismissed' ? 'selected' : '' ?>>Dismissed</option>
                <option value="stale" <?= $statusFilter === 'stale' ? 'selected' : '' ?>>From stored data</option>
                <option value="ok" <?= $statusFilter === 'ok' ? 'selected' : '' ?>>OK</option>
            </select>
        </div>
        <div class="adif-form-field">
            <label for="adif-imp-limit">Runs shown</label>
            <select name="limit" id="adif-imp-limit">
                <?php foreach ([25, 50, 100, 200] as $option): ?>
                    <option value="<?= $option ?>" <?= $limit === $option ? 'selected' : '' ?>><?= $option ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-defaultrc btn-sm">Apply</button>
    </form>

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

    <?php if ($canRetry && $attentionCount > 0): ?>
        <div class="adif-imp-toolbar">
            <label class="adif-imp-bypass" for="adif-imp-bypass" title="On (recommended): a retry calls the APIs even for requests the cache could answer, so data stored during an outage isn't reused. Off: stored responses are reused where they exist.">
                <input type="checkbox" id="adif-imp-bypass" checked>
                Skip stored data and call the APIs
            </label>
            <button type="button" id="adif-imp-retry-all" class="btn btn-primaryrc btn-sm"
                    title="Re-runs every run flagged Needs attention in this list, one after another">
                Retry all failed
            </button>
            <span id="adif-imp-retry-progress" class="adif-imp-progress" aria-live="polite"></span>
        </div>
    <?php endif; ?>

    <h4><?= count($runs) ?> run<?= count($runs) === 1 ? '' : 's' ?><?= $statusFilter !== ''
        ? ' (' . adif_imp_h($module->importOutcomeLabel($statusFilter)) . ' only)' : '' ?></h4>

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
        <?php foreach ($runs as $run): ?>
            <details class="adif-imp-run<?= match ($run['outcome']) { 'attention' => ' is-attention', 'dismissed' => ' is-dismissed', 'stale' => ' is-stale', default => '' } ?>"
                     data-run-id="<?= adif_imp_h($run['run_id']) ?>">
                <summary>
                    <span class="adif-imp-when" title="<?= adif_imp_h(date('Y-m-d H:i:s', $run['logged'])) ?>"><?= adif_imp_h(date('Y-m-d H:i', $run['logged'])) ?></span>
                    <span class="adif-imp-user" title="Requested by"><?= adif_imp_h($run['user'] !== '' ? $run['user'] : '?') ?></span>
                    <span class="adif-imp-rec"><?= adif_imp_record_link($run['record'], $pid) ?></span>
                    <span class="adif-imp-event"><?= $run['event_name'] !== null ? adif_imp_h($run['event_name']) : 'Single event' ?></span>
                    <span class="adif-imp-inst"><?= adif_imp_instrument($run) ?></span>
                    <span class="adif-imp-reqs"><?= (int) $run['requests'] ?> request<?= (int) $run['requests'] === 1 ? '' : 's' ?></span>
                    <?= adif_imp_pill($run['outcome'], $module->importOutcomeLabel($run['outcome'])) ?>
                </summary>
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
            </details>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
