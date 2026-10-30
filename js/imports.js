// The Imports page's retry buttons (pages/Imports.php). Sends 'retry-import' (see ADIF's
// redcap_module_ajax and ImportStatus::retryImportRun) for one run, or sequentially for every run
// flagged "Needs attention", and rewrites each run's pill and detail area with the result.
// window.ADIFImports ({ prefix, module }) is set by ADIF::redcap_every_page_top.
(function () {
    const settings = window.ADIFImports;
    if (!settings || !settings.module) {
        return;
    }

    const LABELS = { attention: 'Needs attention', dismissed: 'Dismissed', stale: 'From stored data', ok: 'OK' };
    const PILL_CLASS = { attention: 'adif-status-error', dismissed: 'adif-status-none', stale: 'adif-status-stale', ok: 'adif-status-ok' };
    const RUN_CLASS = { attention: 'is-attention', dismissed: 'is-dismissed', stale: 'is-stale' };

    function escapeHtml(value) {
        return $('<div>').text(value === null || value === undefined ? '' : String(value)).html();
    }

    function multiline(value) {
        return escapeHtml(value).replace(/\n/g, '<br>');
    }

    function bypassCache() {
        return $('#adif-imp-bypass').is(':checked');
    }

    // Brings a run's pill and accordion colouring up to date with its outcome
    function setOutcome($details, outcome) {
        $details.find('summary .adif-status-pill')
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

    // The area inside a run's <details> where the retry's outcome is shown
    function resultPanel($details) {
        let $panel = $details.find('.adif-imp-retry-result').first();
        if (!$panel.length) {
            $panel = $('<div class="adif-imp-retry-result"></div>');
            $details.append($panel);
        }
        return $panel;
    }

    function renderResult($details, response) {
        const $panel = resultPanel($details);

        // The run's fields aren't mapped any more (see ImportStatus::importRetryMappingCheck): nothing
        // was run, and retrying again won't change that until the mappings do
        if (response && response.status === 'not_mapped') {
            $panel.html('<div class="adif-alert adif-alert-empty">' + multiline(response.message) + '</div>');
            $details.find('.adif-imp-retry').prop('disabled', true).text('Mappings changed');
            return;
        }

        if (!response || response.status !== 'success') {
            $panel.html('<div class="adif-alert adif-alert-error">'
                + multiline((response && response.message) || 'The retry failed.') + '</div>');
            return;
        }

        const run = response.run || {};
        const outcome = run.outcome || 'ok';
        const alertClass = outcome === 'attention' ? 'adif-alert-error'
            : (outcome === 'stale' ? 'adif-alert-empty' : 'adif-imp-success');

        let html = '<div class="adif-alert ' + alertClass + '">'
            + '<strong>Retry finished:</strong> ' + escapeHtml(LABELS[outcome])
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

        // Bring the run's own pill up to date, and retire its buttons once nothing is waiting
        setOutcome($details, outcome);
        if (outcome !== 'attention') {
            $details.find('.adif-imp-retry').prop('disabled', true).text('Retried');
            $details.find('.adif-imp-dismiss').remove();
        }
    }

    function retryRun(runId, $details, $button) {
        const originalText = $button.text();
        $button.prop('disabled', true).text('Retrying…');

        return settings.module.ajax('retry-import', { run_id: runId, bypass_cache: bypassCache() })
            .then(function (response) {
                renderResult($details, response || { status: 'error', message: 'No response from the server.' });
                return response;
            })
            .catch(function (error) {
                renderResult($details, { status: 'error', message: (error && error.message) || String(error) });
                return null;
            })
            .finally(function () {
                // Only undo "Retrying…": renderResult may have retired the button ("Retried")
                if ($button.text() === 'Retrying…') {
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
        if (!confirm("Re-run this import? The fields it maps will be overwritten with the API's current values.")) {
            return;
        }
        retryRun(runId, $button.closest('details'), $button);
    });

    // Dismissing: the run stops counting as needing attention (see ImportStatus::dismissImportRun).
    // Nothing about the record's data changes, and it can be undone.
    $(document).on('click', '.adif-imp-dismiss-btn', function () {
        const $button = $(this);
        const runId = $button.attr('data-run-id');
        const $details = $button.closest('details');
        const reason = String($details.find('.adif-imp-dismiss-reason').val() || '').trim();
        if (!runId || $button.prop('disabled')) {
            return;
        }
        if (!confirm('Dismiss this run? It will stop being flagged as needing attention. Its fields stay empty, '
                + 'and you can undo this later.')) {
            return;
        }

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
    });

    $(document).on('click', '.adif-imp-undismiss', function () {
        const $button = $(this);
        const runId = $button.attr('data-run-id');
        const $details = $button.closest('details');
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

    $(document).on('click', '#adif-imp-retry-all', function () {
        const $button = $(this);
        const $buttons = $('.adif-imp-retry').filter(function () { return !this.disabled; });
        if (!$buttons.length) {
            return;
        }
        if (!confirm('Re-run ' + $buttons.length + ' failed import' + ($buttons.length === 1 ? '' : 's')
                + "? The fields they map will be overwritten with the APIs' current values.")) {
            return;
        }

        $button.prop('disabled', true);
        const $progress = $('#adif-imp-retry-progress');
        const total = $buttons.length;
        let done = 0;
        let stillWaiting = 0;
        let notMapped = 0;

        const next = function () {
            if (done >= total) {
                $button.prop('disabled', false);
                $progress.text((done - notMapped) + ' retried'
                    + (notMapped ? ', ' + notMapped + ' skipped because their fields are no longer mapped' : '')
                    + (stillWaiting ? ', ' + stillWaiting + ' still need attention' : (notMapped ? '' : ' - nothing left waiting')) + '.');
                return;
            }
            $progress.text('Retrying ' + (done + 1) + ' of ' + total + '…');
            const $retryButton = $buttons.eq(done);
            retryRun($retryButton.attr('data-run-id'), $retryButton.closest('details'), $retryButton)
                .then(function (response) {
                    if (response && response.status === 'not_mapped') {
                        notMapped++;
                    } else if (!response || !response.run || response.run.outcome === 'attention') {
                        stillWaiting++;
                    }
                    done++;
                    next();
                });
        };
        next();
    });
})();
