// The Data Sources page's API connections tab (pages/data_sources/apis.php): shows and hides the
// parts of each source's form that depend on other answers, warns before a URL change that could
// disconnect mapped fields, fills the schema in with "Detect schema" (the same 'detect-schema' action
// as the button in REDCap's configuration dialog, see js/schema_detect.js), and warns before leaving
// with unsaved changes. Everything here is a convenience: the forms post and save without it.
// window.ADIFDataSources ({ module }) is set by ADIF::redcap_every_page_top on every module page,
// so this returns straight away on pages without the API connections tab.
(function () {
    const settings = window.ADIFDataSources;
    if (!settings || !document.querySelector('.adif-ds-source-form')) {
        return;
    }

    // The bits of a form that follow other answers
    function sync(form) {
        const $form = $(form);

        const keyAction = $form.find('input[name="api_key_action"]:checked').val();
        $form.find('.adif-ds-key-input').prop('hidden', keyAction !== 'replace');

        const cacheOn = $form.find('.adif-ds-cache-toggle').is(':checked');
        $form.find('.adif-ds-cache-options').prop('hidden', !cacheOn);

        const mode = $form.find('select[name="s[cache-mode]"]').val();
        $form.find('.adif-ds-max-age').prop('hidden', mode !== 'refresh_if_stale');

        const original = String($form.attr('data-original-url') || '');
        const url = String($form.find('.adif-ds-url').val() || '').trim();
        $form.find('.adif-ds-url-warning').prop('hidden', original === '' || url === original);
    }

    $('.adif-ds-source-form').each(function () { sync(this); });
    $(document).on('change input', '.adif-ds-source-form :input', function () {
        sync(this.form);
        $(this.form).attr('data-dirty', '1');
    });

    // Leaving with edits that haven't been saved
    let submitting = false;
    $(document).on('submit', 'form', function () { submitting = true; });
    window.addEventListener('beforeunload', function (event) {
        if (!submitting && document.querySelector('.adif-ds-source-form[data-dirty="1"]')) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    $(document).on('click', '.adif-ds-detect', function () {
        const $button = $(this);
        const $form = $button.closest('form');
        const $schema = $form.find('.adif-ds-schema');
        const $status = $form.find('.adif-ds-detect-status');
        const show = (message, kind) => $status.removeClass('is-error is-ok').addClass(kind ? 'is-' + kind : '').text(message);

        const url = String($form.find('.adif-ds-url').val() || '').trim();
        if (url === '') {
            show('Enter the URL first.', 'error');
            return;
        }
        if (String($schema.val() || '').trim() !== ''
                && !confirm('Replace the schema already entered for this source with the one read from the API?')) {
            return;
        }

        // A typed key is used as is. Otherwise, for a saved source keeping its key, the server uses the
        // saved one (only while the URL still matches what's saved at that position).
        const payload = {
            url: url,
            probePath: String($form.find('.adif-ds-probe').val() || '').trim(),
            apiKey: $form.find('.adif-ds-key-input, input[name="api_key"]').filter(function () { return !this.hidden; }).val() || ''
        };
        const index = $form.attr('data-index');
        const keyAction = $form.find('input[name="api_key_action"]:checked').val();
        if (index !== 'new' && (keyAction === undefined || keyAction === 'keep')) {
            payload.instance = Number(index);
        }

        $button.prop('disabled', true);
        show('Checking the API for a schema…');
        settings.module.ajax('detect-schema', payload)
            .then(function (response) {
                if (!response || response.status !== 'success') {
                    throw new Error((response && response.message) || 'No schema was returned.');
                }
                $schema.val(JSON.stringify(response.schema, null, 4)).trigger('change');
                show(response.message + ' Save the source to keep it.', 'ok');
            })
            .catch(function (error) {
                show((error && error.message) || String(error), 'error');
            })
            .finally(function () {
                $button.prop('disabled', false);
            });
    });
})();
