// Adds a "Detect schema" button beside each API source's schema box in ADIF's configuration dialog,
// and a note at the top of the dialog pointing to the Data Sources page, which edits the same settings.
// The module reads the schema from the API itself (classes/SchemaDetection.php) and the box is filled
// in, so it's saved with the dialog exactly like a schema typed in by hand.
// window.ADIFSchemaDetect ({ prefix, module, dataSourcesUrl }) is set by ADIF::redcap_every_page_top.
(function () {
    const settings = window.ADIFSchemaDetect;
    if (!settings || !settings.module) {
        return;
    }

    const MODAL = '#external-modules-configure-modal';
    // The dialog names each repeated instance's inputs <key>____<index>, e.g. api____0, schema____0
    const INSTANCE_SYMBOL = '____';

    function isAdifDialog() {
        return $(MODAL).data('module') === settings.prefix;
    }

    function settingValue(key, suffix) {
        const input = $(MODAL).find(`[name="${key}${suffix}"]`);
        return input.length ? String(input.val() || '').trim() : '';
    }

    // A note pointing to the Data Sources page. Both save the same settings, so either can be used.
    function addPointer() {
        const body = $(MODAL).find('.modal-body').first();
        if (!settings.dataSourcesUrl || !body.length || body.find('.adif-ds-pointer').length) {
            return;
        }
        const link = $('<a></a>').attr('href', settings.dataSourcesUrl).text('Data Sources page');
        $('<div class="alert alert-info adif-ds-pointer" style="margin-bottom: 12px;"></div>')
            .append('<strong>Tip:</strong> API sources are easier to set up on the ADIF ', link,
                ', with schema detection, a connection test and safe reordering. It saves to these same settings,'
                + ' so changes made in either place show up in the other.')
            .prependTo(body);
    }

    function addButtons() {
        if (!isAdifDialog()) {
            // The dialog element is shared by every module's configuration, so don't leave the note behind
            $(MODAL).find('.adif-ds-pointer').remove();
            return;
        }
        addPointer();

        $(MODAL).find(`textarea[name^="schema${INSTANCE_SYMBOL}"]`).each(function () {
            const cell = $(this).closest('td');
            if (cell.find('.adif-detect-schema').length) {
                return;
            }

            // A <button>, not an <input>: when instances are renumbered the dialog gives every input
            // in the row the setting's name, and an input after the textarea would be saved in its place.
            const button = $('<button type="button" class="btn btn-xs btn-defaultrc adif-detect-schema" style="margin: 0 0 4px 4px;"></button>')
                .text('Detect schema')
                .attr('title', 'Read this source\'s schema from the API and fill it in below');
            const status = $('<div class="adif-detect-schema-status" style="font-size: 12px; margin-top: 4px; white-space: pre-line;"></div>');

            $(this).before(button).after(status);
        });
    }

    $(document).on('click', '.adif-detect-schema', function () {
        const button = $(this);
        const cell = button.closest('td');
        const textarea = cell.find(`textarea[name^="schema${INSTANCE_SYMBOL}"]`);
        const status = cell.find('.adif-detect-schema-status');
        const suffix = textarea.attr('name').slice('schema'.length);

        const showStatus = (message, color) => status.css('color', color || '').text(message);

        const url = settingValue('api', suffix);
        if (url === '') {
            showStatus('Enter this source\'s URL first.', '#C00000');
            return;
        }
        if (String(textarea.val() || '').trim() !== ''
            && !confirm('Replace the schema already entered for this source with the one read from the API?')) {
            return;
        }

        button.prop('disabled', true);
        showStatus('Checking the API for a schema…');

        settings.module.ajax('detect-schema', {
            url: url,
            probePath: settingValue('schema-probe', suffix),
            apiKey: settingValue('api-key', suffix),
            instance: Number(suffix.split(INSTANCE_SYMBOL).pop())
        }).then(function (response) {
            if (!response || response.status !== 'success') {
                throw new Error((response && response.message) || 'No schema was returned.');
            }
            textarea.val(JSON.stringify(response.schema, null, 4)).trigger('change');
            showStatus(response.message + ' Save the configuration to keep it.', 'green');
        }).catch(function (error) {
            showStatus((error && error.message) || String(error), '#C00000');
        }).finally(function () {
            button.prop('disabled', false);
        });
    });

    // The dialog's rows are rebuilt each time it opens and whenever an instance is added, so watch for them
    $(function () {
        const modal = document.querySelector(MODAL);
        if (modal) {
            new MutationObserver(addButtons).observe(modal, { childList: true, subtree: true });
        }
    });
})();
