// The Imports page's buttons (pages/Imports.php). Sends 'retry-import' (see ADIF's
// redcap_module_ajax and ImportStatus::retryImportRun) for one run, and rewrites its pill and detail
// area with the result. (Retry all failed is queued for a cron instead - see classes/ImportRetryAll.php.)
// Also runs the first import for one record listed under "Never imported" ('import-unattempted', see
// ImportStatus::runFirstImport; Import all is queued for a cron instead - see classes/ImportAll.php),
// and the background import queue on demand ('run-import-queue', see ImportQueue).
// window.ADIFImports ({ prefix, module }) is set by ADIF::redcap_every_page_top.
(function () {
    const settings = window.ADIFImports;
    if (!settings || !settings.module) {
        return;
    }

    const LABELS = { attention: 'Needs attention', dismissed: 'Dismissed', stale: 'From stored data', ok: 'OK', fixed: 'Fixed later', replaced: 'Stale' };
    const PILL_CLASS = { attention: 'adif-status-error', dismissed: 'adif-status-none', stale: 'adif-status-stale', ok: 'adif-status-ok', fixed: 'adif-status-ok', replaced: 'adif-status-none' };
    const RUN_CLASS = { attention: 'is-attention', dismissed: 'is-dismissed', stale: 'is-stale' };

    function escapeHtml(value) {
        return $('<div>').text(value === null || value === undefined ? '' : String(value)).html();
    }

    function multiline(value) {
        return escapeHtml(value).replace(/\n/g, '<br>');
    }

    // Asks in the page's confirmation modal (window.ADIFImportsConfirm, from pages/Imports.php), which
    // resolves to { confirmed, checked }; the browser's confirm only if the page didn't provide it
    function ask(options) {
        if (typeof window.ADIFImportsConfirm === 'function') {
            return window.ADIFImportsConfirm(options);
        }
        return Promise.resolve({
            confirmed: window.confirm(options.title + '\n\n' + options.message),
            checked: !!(options.checkbox && options.checkbox.checked)
        });
    }

    // A retry's "Skip stored data" choice, asked with each retry and remembered for the next one on the page
    let skipStoredData = true;

    // Each run in the table is a <tbody class="adif-imp-run" data-run-id> holding its summary row and its
    // details row (pages/Imports.php); $details below is that <tbody>.

    // Brings a run's pill and row colouring up to date with its outcome
    function setOutcome($details, outcome) {
        $details.find('.adif-imp-run-summary .adif-status-pill')
            .removeClass('adif-status-error adif-status-stale adif-status-ok adif-status-none')
            .addClass(PILL_CLASS[outcome] || 'adif-status-none')
            .text(LABELS[outcome] || outcome);
        $details.removeClass('is-attention is-dismissed is-stale');
        if (RUN_CLASS[outcome]) {
            $details.addClass(RUN_CLASS[outcome]);
        }
    }

    // A run's controls, matching adif_imp_actions() in pages/Imports.php: retry and dismiss for a
    // flagged run, undo for a dismissed one
    function actionsHtml(runId, outcome) {
        const id = escapeHtml(runId);
        if (outcome === 'dismissed') {
            return '<div class="adif-imp-retry-row"><button type="button" class="btn btn-defaultrc btn-sm adif-imp-undismiss"'
                + ' data-run-id="' + id + '" title="Flags this run as needing attention again">Undo dismiss</button></div>';
        }
        return '<div class="adif-imp-retry-row">'
            + '<button type="button" class="btn btn-primaryrc btn-sm adif-imp-retry" data-run-id="' + id + '">Retry this import</button>'
            + '<span class="adif-imp-dismiss">'
            + '<input type="text" class="form-control form-control-sm adif-imp-dismiss-reason" maxlength="500"'
            + ' placeholder="Reason (optional)" aria-label="Reason for dismissing this run">'
            + '<button type="button" class="btn btn-defaultrc btn-sm adif-imp-dismiss-btn" data-run-id="' + id + '"'
            + ' title="Stops flagging this run as needing attention, without changing any data. Can be undone.">Dismiss</button>'
            + '</span></div>';
    }

    function replaceActions($details, runId, outcome) {
        const $row = $details.find('.adif-imp-retry-row').first();
        const html = actionsHtml(runId, outcome);
        if ($row.length) {
            $row.replaceWith(html);
        } else {
            $details.find('.adif-imp-retry-result').first().before(html);
        }
    }

    // The area in a run's details row where the retry's outcome is shown
    function resultPanel($details) {
        let $panel = $details.find('.adif-imp-retry-result').first();
        if (!$panel.length) {
            $panel = $('<div class="adif-imp-retry-result"></div>');
            $details.find('.adif-imp-run-detail > td').first().append($panel);
        }
        return $panel;
    }

    function renderResult($details, response, reimport) {
        const $panel = resultPanel($details);
        const what = reimport ? 'Re-import' : 'Retry';

        // The run's fields aren't mapped any more (see ImportStatus::importRetryMappingCheck): nothing
        // was run, and retrying again won't change that until the mappings do
        if (response && response.status === 'not_mapped') {
            $panel.html('<div class="adif-alert adif-alert-empty">' + multiline(response.message) + '</div>');
            $details.find('.adif-imp-retry').prop('disabled', true).text('Mappings changed');
            return;
        }

        if (!response || response.status !== 'success') {
            $panel.html('<div class="adif-alert adif-alert-error">'
                + multiline((response && response.message) || 'The ' + what.toLowerCase() + ' failed.') + '</div>');
            return;
        }

        const run = response.run || {};
        const outcome = run.outcome || 'ok';
        const alertClass = outcome === 'attention' ? 'adif-alert-error'
            : (outcome === 'stale' ? 'adif-alert-empty' : 'adif-imp-success');

        let html = '<div class="adif-alert ' + alertClass + '">'
            + '<strong>' + what + ' finished:</strong> ' + escapeHtml(LABELS[outcome])
            + ' &middot; fields saved: ' + (run.saved ? multiline(run.saved) : 'none');
        if (run.not_populated) {
            html += '<br><strong>Not populated:</strong> ' + multiline(run.not_populated);
        }
        if (run.errors) {
            html += '<br><strong>Errors:</strong> ' + multiline(run.errors);
        }
        if (run.notices) {
            html += '<br><strong>Notices:</strong> ' + multiline(run.notices);
        }
        html += '</div>';
        $panel.html(html);

        // A re-import is a new run: the one it re-ran keeps its own outcome, and the button stays for
        // another go. The new run is in the list once the page is reloaded.
        if (reimport) {
            return;
        }

        // Bring the run's own pill up to date, and retire its buttons once nothing is waiting
        setOutcome($details, outcome);
        if (outcome !== 'attention') {
            $details.find('.adif-imp-retry').prop('disabled', true).text('Retried');
            $details.find('.adif-imp-dismiss').remove();
        }
    }

    function retryRun(runId, $details, $button, bypassCache, reimport) {
        const originalText = $button.text();
        const busyText = reimport ? 'Re-importing…' : 'Retrying…';
        $button.prop('disabled', true).text(busyText);

        return settings.module.ajax('retry-import', { run_id: runId, bypass_cache: bypassCache, reimport: !!reimport })
            .then(function (response) {
                renderResult($details, response || { status: 'error', message: 'No response from the server.' }, reimport);
                return response;
            })
            .catch(function (error) {
                renderResult($details, { status: 'error', message: (error && error.message) || String(error) }, reimport);
                return null;
            })
            .finally(function () {
                // Only undo the busy text: renderResult may have retired the button ("Retried")
                if ($button.text() === busyText) {
                    $button.prop('disabled', false).text(originalText);
                }
            });
    }

    $(document).on('click', '.adif-imp-retry', function () {
        const $button = $(this);
        const runId = $button.attr('data-run-id');
        if (!runId || $button.prop('disabled')) {
            return;
        }
        const $details = $button.closest('.adif-imp-run');
        const record = $.trim($details.find('.adif-imp-rec').first().text());
        ask({
            title: 'Retry this import?',
            message: 'Re-runs the import' + (record ? ' for record ' + record : '') + ', for the same event, instrument and'
                + " instance. The fields it maps are overwritten with the API's current values.",
            confirmLabel: 'Retry import',
            checkbox: {
                label: 'Skip stored data and call the APIs',
                hint: "Recommended after an outage, so data stored while the API was down isn't reused.",
                checked: skipStoredData
            }
        }).then(function (answer) {
            if (answer.confirmed) {
                skipStoredData = answer.checked;
                retryRun(runId, $details, $button, answer.checked);
            }
        });
    });

    // Re-import: a run that finished fine, imported again (same request as a retry; see retryImportRun's
    // $reimport). For when a source changed, such as a replaced uploaded table.
    $(document).on('click', '.adif-imp-reimport', function () {
        const $button = $(this);
        const runId = $button.attr('data-run-id');
        if (!runId || $button.prop('disabled')) {
            return;
        }
        const $details = $button.closest('.adif-imp-run');
        const record = $.trim($details.find('.adif-imp-rec').first().text());
        ask({
            title: 'Re-import this record?',
            message: 'Imports' + (record ? ' record ' + record : ' this record') + ' again, for the same event, instrument and'
                + ' instance. Every field it maps is overwritten with what the sources return now, including'
                + ' values that are already filled in.',
            confirmLabel: 'Re-import',
            checkbox: {
                label: 'Skip stored data and call the APIs',
                hint: 'Uploaded tables are always read fresh. This only affects API sources.',
                checked: skipStoredData
            }
        }).then(function (answer) {
            if (answer.confirmed) {
                skipStoredData = answer.checked;
                retryRun(runId, $details, $button, answer.checked, true);
            }
        });
    });

    // Dismissing: the run stops counting as needing attention (see ImportStatus::dismissImportRun).
    // Nothing about the record's data changes, and it can be undone.
    $(document).on('click', '.adif-imp-dismiss-btn', function () {
        const $button = $(this);
        const runId = $button.attr('data-run-id');
        const $details = $button.closest('.adif-imp-run');
        const reason = String($details.find('.adif-imp-dismiss-reason').val() || '').trim();
        if (!runId || $button.prop('disabled')) {
            return;
        }
        ask({
            title: 'Dismiss this run?',
            message: 'It stops being flagged as needing attention, and Retry all failed leaves it alone. Its fields'
                + ' stay empty, and nothing about the record changes. You can undo this later.'
                + (reason ? '\n\nReason: ' + reason : ''),
            confirmLabel: 'Dismiss run'
        }).then(function (answer) {
            if (answer.confirmed) {
                dismissRun(runId, $details, reason);
            }
        });
    });

    function dismissRun(runId, $details, reason) {
        $details.find('.adif-imp-retry-row button').prop('disabled', true);
        settings.module.ajax('dismiss-import', { run_id: runId, reason: reason })
            .then(function (response) {
                if (!response || response.status !== 'success') {
                    throw new Error((response && response.message) || 'The run couldn\'t be dismissed.');
                }
                const dismissed = (response.run && response.run.dismissed) || {};
                setOutcome($details, 'dismissed');
                $details.find('.adif-imp-status-note')
                    .removeClass('adif-alert-error').addClass('adif-alert-empty')
                    .html('Dismissed by ' + escapeHtml(dismissed.user || 'you') + ' just now'
                        + (dismissed.reason ? ' &mdash; ' + escapeHtml(dismissed.reason) : '')
                        + '. It no longer counts as needing attention.');
                replaceActions($details, runId, 'dismissed');
                resultPanel($details).empty();
            })
            .catch(function (error) {
                $details.find('.adif-imp-retry-row button').prop('disabled', false);
                resultPanel($details).html('<div class="adif-alert adif-alert-error">'
                    + multiline((error && error.message) || String(error)) + '</div>');
            });
    }

    $(document).on('click', '.adif-imp-undismiss', function () {
        const $button = $(this);
        const runId = $button.attr('data-run-id');
        const $details = $button.closest('.adif-imp-run');
        if (!runId || $button.prop('disabled')) {
            return;
        }

        $button.prop('disabled', true);
        settings.module.ajax('dismiss-import', { run_id: runId, undo: true })
            .then(function (response) {
                if (!response || response.status !== 'success') {
                    throw new Error((response && response.message) || 'The dismissal couldn\'t be undone.');
                }
                setOutcome($details, 'attention');
                $details.find('.adif-imp-status-note')
                    .removeClass('adif-alert-empty').addClass('adif-alert-error')
                    .html('Dismissal undone &mdash; this run needs attention again.'
                        + (response.run && response.run.explanation ? '<br>' + multiline(response.run.explanation) : ''));
                replaceActions($details, runId, 'attention');
                resultPanel($details).empty();
            })
            .catch(function (error) {
                $button.prop('disabled', false);
                resultPanel($details).html('<div class="adif-alert adif-alert-error">'
                    + multiline((error && error.message) || String(error)) + '</div>');
            });
    });

    // JSON the page embeds for the buttons below (the Never imported places, see pages/Imports.php)
    function readJson(id) {
        try {
            return JSON.parse($('#' + id).text() || '[]');
        } catch (e) {
            return [];
        }
    }

    // Retry all failed isn't wired here: it's a plain form that queues the retries for the cron (see
    // classes/ImportRetryAll.php), so a long list can't hold the browser up. It asks in the same modal,
    // through its data-confirm attribute.

    // "Never imported" (pages/Imports.php): the first import for one place, from its row's button, sent
    // as 'import-unattempted' (see ImportStatus::runFirstImport)
    // Read when first needed, not when this file loads: it loads at the top of the page (from
    // ADIF::redcap_every_page_top), before the page's content - and this JSON - exists
    let neverUnits = null;
    function neverUnit(key) {
        if (neverUnits === null) {
            neverUnits = {};
            readJson('adif-imp-never-units').forEach(function (unit) {
                neverUnits[unit.key] = unit;
            });
        }
        return neverUnits[key];
    }

    // Shows a first import's outcome in its row, when the row is on the page
    function renderFirstImport(unitKey, response) {
        const $row = $('#adif-imp-never tr[data-unit-key="' + unitKey + '"]');
        if (!$row.length) {
            return;
        }
        const $status = $row.find('.adif-imp-never-status');
        const $button = $row.find('.adif-imp-import-one');
        $status.find('.adif-imp-never-message').remove();

        if (response && response.status === 'success') {
            const run = response.run || {};
            const outcome = run.outcome || 'ok';
            let html = '<span class="adif-status-pill ' + (PILL_CLASS[outcome] || 'adif-status-none') + '">'
                + escapeHtml(LABELS[outcome] || outcome) + '</span>'
                + '<div class="adif-imp-explain">Fields saved: ' + (run.saved ? escapeHtml(run.saved) : 'none') + '</div>';
            if (outcome === 'attention' && run.explanation) {
                html += '<div class="adif-imp-explain">' + multiline(run.explanation)
                    + '<br>Reload the page to retry it from the run list.</div>';
            }
            $status.html(html);
            $button.remove();
            $row.addClass(outcome === 'attention' ? 'is-attention' : 'is-done');
            return;
        }

        const notPending = response && response.status === 'not_pending';
        $status.append('<div class="adif-alert ' + (notPending ? 'adif-alert-empty' : 'adif-alert-error')
            + ' adif-imp-never-message">' + multiline((response && response.message) || 'The import failed.') + '</div>');
        if (notPending) {
            $button.prop('disabled', true).text('Not pending');
        } else {
            $button.prop('disabled', false).text('Import');
        }
    }

    function runFirstImport(unit) {
        $('#adif-imp-never tr[data-unit-key="' + unit.key + '"] .adif-imp-import-one').prop('disabled', true).text('Importing…');

        return settings.module.ajax('import-unattempted', {
            record: unit.record,
            event_id: unit.event_id,
            instrument: unit.instrument || '',
            instance: unit.instance
        })
            .then(function (response) {
                return response || { status: 'error', message: 'No response from the server.' };
            })
            .catch(function (error) {
                return { status: 'error', message: (error && error.message) || String(error) };
            })
            .then(function (response) {
                renderFirstImport(unit.key, response);
                return response;
            });
    }

    $(document).on('click', '.adif-imp-import-one', function () {
        const unit = neverUnit($(this).attr('data-unit-key'));
        if (!unit || $(this).prop('disabled')) {
            return;
        }
        ask({
            title: 'Import record ' + unit.record + '?',
            message: 'Runs its first import now, while you wait. Its empty mapped fields are filled from the APIs, and'
                + ' the run is listed in the run table like any other.',
            confirmLabel: 'Import'
        }).then(function (answer) {
            if (answer.confirmed) {
                runFirstImport(unit);
            }
        });
    });

    // "Run queued imports now" (pages/Imports.php): works through the background queue without
    // waiting for the cron ('run-import-queue', see ImportQueue::processProjectImportQueue). Each
    // request runs a short batch; it's sent again while imports remain and the last batch made progress.
    $(document).on('click', '#adif-imp-run-queue', function () {
        const $button = $(this);
        ask({
            title: 'Run the queued imports now?',
            message: 'Runs them from this page instead of waiting for the cron, a few at a time. Each one calls the APIs'
                + " its mappings use and fills the record's fields.\n\nKeep this page open until it finishes; progress"
                + ' shows in the activity strip.',
            confirmLabel: 'Run them now'
        }).then(function (answer) {
            if (answer.confirmed) {
                runQueueNow($button);
            }
        });
    });

    function runQueueNow($button) {
        const $progress = $('#adif-imp-queue-progress');
        $button.prop('disabled', true);
        const totals = { ran: 0, attention: 0, abandoned: 0 };
        const summary = function (remaining, busy) {
            const parts = [(totals.ran + totals.attention) + ' run'];
            if (totals.attention) {
                parts.push(totals.attention + ' need attention');
            }
            if (totals.abandoned) {
                parts.push(totals.abandoned + ' given up on after stopping partway');
            }
            if (busy) {
                parts.push(busy + ' being run by the cron right now');
            }
            if (remaining) {
                parts.push(remaining + ' still waiting');
            }
            return parts.join(', ');
        };

        const step = function () {
            $progress.text('Running queued imports… ' + summary(0, 0) + ' so far.');
            settings.module.ajax('run-import-queue', {})
                .then(function (response) {
                    if (!response || response.status !== 'success') {
                        throw new Error((response && response.message) || 'The queued imports couldn\'t be run.');
                    }
                    totals.ran += response.ran || 0;
                    totals.attention += response.attention || 0;
                    totals.abandoned += response.abandoned || 0;
                    const progressed = (response.ran || 0) + (response.attention || 0) + (response.abandoned || 0);
                    if (response.remaining > 0 && progressed > 0) {
                        step();
                        return;
                    }
                    $button.prop('disabled', false);
                    $progress.text(summary(response.remaining || 0, response.busy || 0) + '. Reload the page to see the new runs.');
                })
                .catch(function (error) {
                    $button.prop('disabled', false);
                    $progress.text(summary(0, 0) + '. Stopped: ' + ((error && error.message) || String(error)));
                });
        };
        step();
    }
})();
