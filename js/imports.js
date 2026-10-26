// The Imports page's retry buttons (pages/Imports.php). Sends 'retry-import' (see ADIF's
// redcap_module_ajax and ImportStatus::retryImportRun) for one run, or sequentially for every run
// flagged "Needs attention", and rewrites each run's pill and detail area with the result.
// window.ADIFImports ({ prefix, module }) is set by ADIF::redcap_every_page_top.
(function () {
    const settings = window.ADIFImports;
    if (!settings || !settings.module) {
        return;
    }

    const LABELS = { attention: 'Needs attention', stale: 'From stored data', ok: 'OK' };
    const PILL_CLASS = { attention: 'adif-status-error', stale: 'adif-status-stale', ok: 'adif-status-ok' };

    function escapeHtml(value) {
        return $('<div>').text(value === null || value === undefined ? '' : String(value)).html();
    }

    function multiline(value) {
        return escapeHtml(value).replace(/\n/g, '<br>');
    }

    function bypassCache() {
        return $('#adif-imp-bypass').is(':checked');
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

        // Bring the run's own pill up to date, and retire its button once nothing is waiting
        $details.find('summary .adif-status-pill')
            .removeClass('adif-status-error adif-status-stale adif-status-ok')
            .addClass(PILL_CLASS[outcome])
            .text(LABELS[outcome]);
        if (outcome !== 'attention') {
            $details.find('.adif-imp-retry').prop('disabled', true).text('Retried');
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
                $button.prop('disabled', false).text(originalText);
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

        const next = function () {
            if (done >= total) {
                $button.prop('disabled', false);
                $progress.text(done + ' retried'
                    + (stillWaiting ? ', ' + stillWaiting + ' still need attention' : ' - nothing left waiting') + '.');
                return;
            }
            $progress.text('Retrying ' + (done + 1) + ' of ' + total + '…');
            const $retryButton = $buttons.eq(done);
            retryRun($retryButton.attr('data-run-id'), $retryButton.closest('details'), $retryButton)
                .then(function (response) {
                    if (!response || !response.run || response.run.outcome === 'attention') {
                        stillWaiting++;
                    }
                    done++;
                    next();
                });
        };
        next();
    });
})();
