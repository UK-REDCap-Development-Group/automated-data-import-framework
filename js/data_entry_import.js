// Asks whether to import after a data entry form was saved (see classes/ImportDataEntry.php).
// window.ADIFDataEntryImport ({ module, offer: { token, record, instrument, fields, filled, background } }) is
// set by ADIF::redcap_every_page_top, once, on the page REDCap shows after the save.
// "Import" goes the same way a survey submission's import does: onto the background queue for the cron
// (the dialog then waits for it and reloads the page once it has run), or, in a project set to import
// immediately, run straight away.
(function () {
    const settings = window.ADIFDataEntryImport;
    if (!settings || !settings.module || !settings.offer) {
        return;
    }
    const offer = settings.offer;
    const POLL_MS = 5000;

    // The dialog's main button. jQuery UI's theme (.ui-state-default) paints over Bootstrap/REDCap button
    // classes such as btn-primaryrc, which left "Import" looking greyed out, so it's styled here with
    // enough specificity to win.
    const PRIMARY = 'adif-de-primary';
    if (!document.getElementById('adif-de-style')) {
        $('<style id="adif-de-style">').text(
            '.ui-dialog .ui-dialog-buttonpane button.' + PRIMARY + ','
            + ' .ui-dialog .ui-dialog-buttonpane button.' + PRIMARY + '.ui-state-default {'
            + ' background: #337ab7 !important; border: 1px solid #2e6da4 !important; color: #fff !important;'
            + ' font-weight: bold; opacity: 1 !important; }'
            + ' .ui-dialog .ui-dialog-buttonpane button.' + PRIMARY + ':hover,'
            + ' .ui-dialog .ui-dialog-buttonpane button.' + PRIMARY + ':focus {'
            + ' background: #286090 !important; border-color: #204d74 !important; color: #fff !important; }'
        ).appendTo('head');
    }
    const WAIT_MS = 5 * 60 * 1000;   // the cron runs about once a minute; past this, stop waiting

    function escapeHtml(value) {
        return $('<div>').text(value === null || value === undefined ? '' : String(value)).html();
    }

    function plural(n, one, many) {
        return n + ' ' + (n === 1 ? one : many);
    }

    function questionHtml() {
        const fields = offer.fields || [];
        const filled = Array.isArray(offer.filled) ? offer.filled : null;
        let html = '<p>You saved <b>' + escapeHtml(offer.instrument) + '</b> for record <b>' + escapeHtml(offer.record)
            + '</b>. ADIF can import ' + plural(fields.length, 'mapped field', 'mapped fields') + ' for this record:</p>'
            + '<p style="font-family:monospace;font-size:12px;max-height:120px;overflow:auto">'
            + fields.map(escapeHtml).join(', ') + '</p>';
        if (filled && filled.length) {
            html += '<p class="text-danger"><b>' + plural(filled.length, 'of these already has a value', 'of these already have values')
                + '</b>, which the import will replace: ' + filled.map(escapeHtml).join(', ') + '.</p>';
        }
        if (offer.background) {
            html += '<p>The import runs in the background with the project\'s other imports, usually within a minute or two.</p>';
        }
        html += '<p>Choose <b>Not now</b> to keep the record as it is. Saving the form again asks again.</p>';
        return html;
    }

    function resultHtml(response) {
        if (!response || response.status !== 'success') {
            return '<p class="text-danger">' + escapeHtml((response && response.message) || 'The import couldn\'t be run.') + '</p>';
        }
        let html = '<p>Imported ' + plural(response.saved, 'field', 'fields') + '.</p>';
        if (response.not_populated && response.not_populated.length) {
            html += '<p class="text-danger">Not imported because of errors: ' + response.not_populated.map(escapeHtml).join(', ')
                + '. See the ADIF Import Status page for details and to retry.</p>';
        } else if (response.errors && response.errors.length) {
            html += '<p class="text-danger">' + response.errors.map(escapeHtml).join('<br>') + '</p>';
        }
        return html;
    }

    function closeButton() {
        return [{ text: 'Close', click: function () { $(this).dialog('close'); } }];
    }

    // The import's outcome. Reloads so the form shows the imported values: saving a form still showing
    // the old values would write them back over the import.
    function showResult($dialog, response) {
        $dialog.html(resultHtml(response));
        const imported = response && response.status === 'success' && response.saved > 0;
        if (!imported) {
            $dialog.dialog('option', 'buttons', closeButton());
            return;
        }
        $dialog.append('<p>Reloading to show the imported values&hellip;</p>');
        $dialog.dialog('option', 'buttons', [{ text: 'Reload page', class: PRIMARY, click: function () { window.location.reload(); } }]);
        setTimeout(function () { window.location.reload(); }, 2000);
    }

    // Waits for a queued import to be run by the cron
    function waitForQueue($dialog, queueId) {
        const started = Date.now();
        $dialog.html('<p>Queued. Waiting for ADIF\'s background imports to run it&hellip;</p>'
            + '<p class="text-muted">If you close this, the import still runs. Reload the page before editing this'
            + ' record again, so the form shows the imported values.</p>');
        $dialog.dialog('option', 'buttons', closeButton());

        function poll() {
            if (!$dialog.closest('body').length) {
                return;   // closed
            }
            settings.module.ajax('data-entry-import-status', { queue_id: queueId })
                .then(function (response) {
                    if (response && response.status === 'pending') {
                        if (Date.now() - started > WAIT_MS) {
                            $dialog.html('<p>The import is still waiting in the queue. It runs when REDCap\'s cron next'
                                + ' gets to it; the ADIF Import Status page shows when it has.</p>'
                                + '<p class="text-danger">Reload the page before editing this record again.</p>');
                            return;
                        }
                        setTimeout(poll, POLL_MS);
                        return;
                    }
                    showResult($dialog, response);
                })
                .catch(function () { setTimeout(poll, POLL_MS); });
        }
        setTimeout(poll, POLL_MS);
    }

    function runImport($dialog) {
        $dialog.html('<p>' + (offer.background ? 'Queueing' : 'Importing') + '&hellip;</p>');
        $dialog.dialog('option', 'buttons', []);
        settings.module.ajax('import-after-data-entry', { token: offer.token })
            .then(function (response) {
                if (response && response.status === 'queued') {
                    waitForQueue($dialog, response.queue_id);
                } else {
                    showResult($dialog, response);
                }
            })
            .catch(function (error) {
                $dialog.html(resultHtml({ status: 'error', message: (error && error.message) || String(error) }));
                $dialog.dialog('option', 'buttons', closeButton());
            });
    }

    $(function () {
        if (!$.fn.dialog) {
            // No jQuery UI on this page: a plain confirm, then the same request
            if (window.confirm('ADIF: import ' + plural((offer.fields || []).length, 'mapped field', 'mapped fields')
                + ' for record ' + offer.record + '? Fields that already have values will be replaced.')) {
                settings.module.ajax('import-after-data-entry', { token: offer.token })
                    .then(function (response) {
                        if (response && response.status === 'queued') {
                            window.alert('Queued: ADIF imports it in the background, usually within a minute or two.'
                                + ' Reload the page before editing this record again.');
                        } else if (response && response.status === 'success' && response.saved > 0) {
                            window.location.reload();
                        } else {
                            window.alert($('<div>').html(resultHtml(response)).text());
                        }
                    });
            }
            return;
        }

        $('<div id="adif-data-entry-import"></div>').html(questionHtml()).dialog({
            title: 'Import from ADIF\'s data sources?',
            modal: true,
            width: 520,
            close: function () { $(this).dialog('destroy').remove(); },
            buttons: [
                { text: 'Not now', click: function () { $(this).dialog('close'); } },
                { text: 'Import', class: PRIMARY, click: function () { runImport($(this)); } }
            ]
        });
    });
})();
