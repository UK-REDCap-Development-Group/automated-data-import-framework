<?php
/*
 * A page with an interface for connecting REDCap fields to available data fields from your API to be automatically populated by relevant user information.
 */

global $project_id;
/** @var \UKModules\ADIF\ADIF $module */

$instruments = REDCap::getInstrumentNames();
$csrf = $module->getCSRFToken();

// Will use this value later for batching requests if max_input is hit
$maxInputVars = ini_get('max_input_vars') ?: 1000;

require_once "../../redcap_connect.php";

// Now you can safely render your page
require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';

// Data dictionary grouped by instrument: { instrument_key: { field_name: field_info, ... } }
$dictionary = [];
foreach (\REDCap::getDataDictionary($project_id, 'array') as $fieldName => $fieldInfo) {
    $dictionary[$fieldInfo['form_name']][$fieldName] = $fieldInfo;
}

// Every field made available by any configured API source, tagged with the endpoint/grouping
// label from its schema entry (falling back to the source's URL when a field doesn't set one).
// Unlike a single hardcoded API, ADIF sources describe their available fields with a schema
// set once in project configuration, since there's no universal convention across arbitrary
// APIs for "query this to discover what's available" the way one specific API might have.
$apiFields = array_map(function ($apiField) {
    return ['field' => $apiField['field'], 'endpoint' => $apiField['endpoint']];
}, $module->getApiFields());

$projectTitle = \REDCap::getProjectTitle();
?>

<link rel="stylesheet" href="<?= $module->getUrl('css/field_mappings.css') ?>">

<script>
    // Get max_input from php.ini into a js workable variable
    const MAX_INPUT_VARS = <?= (int)$maxInputVars ?>;
    let displayed = []; // tracks displayed instruments

    let mappings = false;

    // Inject PHP constants into JavaScript
    const REDCAP_WEBROOT = <?= json_encode(APP_PATH_WEBROOT) ?>;
    const PROJECT_ID = <?= json_encode($_GET['pid']) ?>;
    const instruments = <?= json_encode($instruments) ?>;
    const dictionary = <?= json_encode($dictionary) ?>;
    const project_title = <?= json_encode($projectTitle) ?>;

    // Every field discovered across all configured API sources. Static for the life of the page
    // load - reconfigure a source's schema and reload to pick up changes.
    const apiFields = <?= json_encode($apiFields) ?>;
</script>

<script>
    // A discovered path can run long - principalInvestigator.contact.lastName -
    // and a select full of them is hard to read. Split one into its segments so
    // the display can be shortened. Brackets are kept whole, since a hand
    // written [staffRole=Ph.D. Advisor] may contain dots of its own.
    function apiPathSegments(path) {
        const segments = [];
        let buffer = '';

        for (let position = 0; position < path.length; position++) {
            const character = path[position];

            if (character === '.') {
                if (buffer !== '') segments.push(buffer);
                buffer = '';
                continue;
            }

            if (character === '[') {
                if (buffer !== '') segments.push(buffer);
                buffer = character;

                position++;
                while (position < path.length && path[position] !== ']') {
                    if (path[position] === '\\' && position + 1 < path.length) {
                        buffer += path[position];
                        position++;
                    }
                    buffer += path[position];
                    position++;
                }

                segments.push(buffer + ']');
                buffer = '';
                continue;
            }

            buffer += character;
        }

        if (buffer !== '') segments.push(buffer);

        return segments;
    }

    // Show the two segments that identify a mapping - which entry of the list,
    // and which field of it - and drop whatever sits between them. The full path
    // stays as the option's value and in its tooltip.
    function apiFieldDisplay(path) {
        const segments = apiPathSegments(path);
        if (segments.length < 2) return path;

        return `${segments[0]} · ${segments[segments.length - 1]}`;
    }

    // Build one <option>, shortened for display and carrying the full path in
    // its value, its tooltip and data-endpoint (which save/load both read).
    function apiFieldOption(obj) {
        const option = document.createElement('option');
        option.value = obj.field;
        option.textContent = apiFieldDisplay(obj.field);
        option.setAttribute('data-endpoint', obj.endpoint);

        // [] takes every value in a list; a role segment such as
        // principalInvestigator takes only that person's, which is what lets
        // the field sync without extra handling.
        const note = obj.field.includes('[]')
            ? '\n\nReads every value returned in this list, joined with "; ". Choose a single value if you need exactly one match.'
            : '';
        option.title = `${obj.endpoint}: ${obj.field}${note}`;

        return option;
    }

    // Append every discovered field, grouped under its endpoint so the endpoint
    // name appears once as a heading rather than on every line. Grouping does
    // not affect select.options, which stays flat for selectedIndex lookups.
    function appendApiFieldOptions(selectEl, fields, onOption) {
        if (!Array.isArray(fields)) return;

        // Keyed rather than run-length grouped, so an endpoint appears once even
        // if the discovered fields ever arrive unsorted.
        const groups = new Map();

        fields.forEach(obj => {
            let group = groups.get(obj.endpoint);
            if (!group) {
                group = document.createElement('optgroup');
                group.label = obj.endpoint;
                selectEl.appendChild(group);
                groups.set(obj.endpoint, group);
            }

            const option = apiFieldOption(obj);
            if (onOption) onOption(option, obj);
            group.appendChild(option);
        });
    }
</script>

<div class="d-flex container" style="flex-direction: column;">
    <!-- Manage and sync buttons -->
    <div class="row selection-btns">
        <div class="col-md-3">
            <a id="manage-forms-btn">
                <div class="center-home-sects">
                    <span><i class="fa fa-plus"></i></span><br>
                    <h5>Manage Forms</h5>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a id="upload-btn" class="center-home-sects">
                <div class="center-home-sects">
                    <span><i class="fas fa-upload"></i></span><br>
                    <h5>Upload Config</h5>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a id="export-btn" class="center-home-sects">
                <div class="center-home-sects">
                    <span><i class="fas fa-file-export"></i></span><br>
                    <h5>Export Config</h5>
                </div>
            </a>
        </div>
    </div>

    <!-- List of forms and their fields -->
    <div id="instruments_list" class="row" style="flex-direction: column;">
        <div id="forms-loader" class="loader-container">
            <div class="loader"></div>
            <p style="margin-top: 15px;">Loading Saved Mappings...</p>
        </div>
    </div>
</div>

<script>
    /* Create the basic modal structure */
    function buildModal() {
        if (document.getElementById('modal-overlay')) {
            return; // Modal already exists
        }

        const modalOverlay = document.createElement('div');
        modalOverlay.className = 'modal-overlay';
        modalOverlay.id = 'comparison-modal';

        const modalBox = document.createElement('div');
        modalBox.className = 'modal-box';

        return {modalOverlay, modalBox};
    }

    // TODO: add a logging call in here to track when forms are added or removed
    // Creates a modal that lets you select which forms you want to sync and ignore the ones you don't want
    function manageForms(instruments, displayed) {
        const built = buildModal();
        if (!built) return; // The modal already exists

        const {modalOverlay, modalBox} = built;

        // Modal header + form list
        let modalContent = `
            <h2>Manage Forms</h2>
            <p>Select which REDCap forms you want to synchronize.</p>
            <div class="modal-form-selection-grid" style="height: 80vh; overflow-y: auto;">
            <table class="dataTable cell-border no-footer">
        `;

        let i = 0;
        Object.entries(instruments).forEach(([key, value]) => {
            const isDisplayed = displayed.includes(key);
            modalContent += `
            <tr class="${i % 2 !== 0 ? 'odd' : 'even'}">
                <td style="width: 100% !important;">
                    <label class="checkbox-option">
                        <input type="checkbox" name="form-select" value="${key}" ${isDisplayed ? "checked" : ""}>
                        ${value}
                    </label>
                </td>
            </tr>
            `;
            i = i + 1; // increment even/odd
        });

        modalContent += `
            </table>
            </div>
            <div class="modal-actions">
                <button id="confirm_btn" class="selectA_btn">Save Changes</button>
                <button id="close_btn" class="close-button">Cancel</button>
            </div>
        `;

        modalBox.innerHTML = modalContent;
        modalOverlay.appendChild(modalBox);
        document.body.appendChild(modalOverlay);

        const closeModal = () => {
            if (modalOverlay && modalOverlay.parentNode) {
                modalOverlay.parentNode.removeChild(modalOverlay);
            }
        };

        // Confirm button logic
        document.getElementById('confirm_btn').addEventListener('click', async () => {
            const selected = Array.from(document.querySelectorAll('input[name="form-select"]:checked'))
                .map(cb => cb.value);

            const toAdd = selected.filter(f => !displayed.includes(f));
            const toRemove = displayed.filter(f => !selected.includes(f));

            // Handle removals with warnings for forms that have mapped data
            for (const formKey of toRemove) {
                const table = document.getElementById(formKey);

                if (table) {
                    const selects = Array.from(table.querySelectorAll('select'));
                    const hasConfiguredData = selects.some(sel => sel.value && sel.value !== "");

                    if (hasConfiguredData) {
                        const confirmRemoval = confirm(
                            `Warning: The form "${instruments[formKey]}" has mapped data. Are you sure you want to remove it?`
                        );
                        if (!confirmRemoval) {
                            continue; // User said no — skip removal entirely
                        }
                    }

                    // Remove from DOM whether or not it had data (user confirmed if needed)
                    table.remove();
                }

                // Remove from displayed regardless of whether table existed in DOM
                const index = displayed.indexOf(formKey);
                if (index !== -1) displayed.splice(index, 1);
            }

            // Handle additions
            toAdd.forEach(selectedForm => {
                displayed.push(selectedForm);
                const table = document.createElement('table');
                table.id = selectedForm;
                table.classList = "dataTable cell-border no-footer";

                const header = document.createElement('thead');
                header.innerHTML = `
            <tr>
                <th style="width: 25%;">${instruments[selectedForm]} Fields</th>
                <th style="width: 40%;">REDCap Field Label</th>
                <th style="width: 35%;">API Field</th>
            </tr>`;
                table.appendChild(header);

                const tbody = document.createElement('tbody');
                table.appendChild(tbody);

                let i = 0;
                Object.keys(dictionary[selectedForm]).forEach(redcapField => {
                    const row = document.createElement('tr');
                    row.className = i % 2 === 0 ? 'even' : 'odd';

                    const selectId = `${selectedForm}_${redcapField}`;
                    row.innerHTML = `
                <td style="width:50%"><label for="${selectId}">${redcapField}</label></td>
                <td>
                    ${dictionary[selectedForm][redcapField].field_label.length > 50
                        ? dictionary[selectedForm][redcapField].field_label.slice(0, 50) + '…'
                        : dictionary[selectedForm][redcapField].field_label}
                </td>
                <td style="width:50%">
                    <select name="${redcapField}" id="${selectId}">
                        <option value="">None</option>
                    </select>
                </td>
            `;

                    const selectEl = row.querySelector('select');
                    appendApiFieldOptions(selectEl, apiFields);

                    tbody.appendChild(row);
                    i++;
                });

                document.getElementById('instruments_list').appendChild(table);
            });

            closeModal(true);
            checkpoint();
        });

        // Close modal on Cancel or background click
        document.getElementById('close_btn').addEventListener('click', (event) => {
            closeModal(false); // close modal but no checkpoint
        });
        modalOverlay.addEventListener('click', (event) => {
            if (event.target === modalOverlay) {
                closeModal(false); // close modal but no checkpoint
            }
        });
    }

    function resolveMappingModal(type, mapping, formName, offenderField, availableOptions) {
        const built = buildModal();
        if (!built) return;

        const {modalOverlay, modalBox} = built;

        // Set dynamic text based on the type
        const isForm = type === 'form';
        const targetName = isForm ? formName : offenderField;
        const parentText = isForm ? "this REDCap project" : `the form <strong>${formName}</strong>`;

        // Build Dropdown
        let dropdownOptions = `<option value="">-- Select a replacement ${type} --</option>`;
        Object.entries(availableOptions).forEach(([key, value]) => {
            let label = typeof value === 'object' && value.field_label ?
                value.field_label.replace(/(<([^>]+)>)/gi, "").substring(0, 50) + "..." : value;
            dropdownOptions += `<option value="${key}">${key} (${label})</option>`;
        });

        // Modal UI
        modalBox.innerHTML = `
        <div style="padding: 10px;">
            <h3 style="color: #d9534f; margin-top: 0;">Missing ${isForm ? 'Form' : 'Field'} Detected: <strong>${targetName}</strong></h3>
            <p>A previously mapped ${type} (<strong>${targetName}</strong>) no longer exists in ${parentText}.</p>

            <div style="margin: 20px 0; padding: 15px; border: 1px solid #ccc; background-color: #f9f9f9; border-radius: 5px;">
                <label style="font-weight: bold; display: block; margin-bottom: 8px;">Option 1: Map to a different ${type}</label>
                <select id="replacement-select" style="width: 100%; padding: 6px;">
                    ${dropdownOptions}
                </select>
            </div>

            <div style="margin-bottom: 20px;">
                <p style="margin-bottom: 5px;"><strong>Option 2: Delete this ${type} mapping</strong></p>
            </div>

            <div class="modal-actions" style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
                <button id="update_btn" class="selectA_btn" style="padding: 6px 12px; cursor: pointer;">Update Mapping</button>
                <button id="delete_btn" style="background-color: #d9534f; color: white; border: 1px solid #d43f3a; padding: 6px 12px; border-radius: 4px; cursor: pointer;">Delete Mapping</button>
                <button id="close_btn" class="close-button" style="padding: 6px 12px; cursor: pointer;">Cancel</button>
            </div>
        </div>
    `;

        modalOverlay.appendChild(modalBox);
        document.body.appendChild(modalOverlay);

        const closeModal = () => {
            if (modalOverlay && modalOverlay.parentNode) modalOverlay.parentNode.removeChild(modalOverlay);
        };


        // Selection for Update
        document.getElementById('update_btn').addEventListener('click', () => {
            const selected = document.getElementById('replacement-select').value;
            if (!selected) return alert(`Please select a replacement ${type}.`);

            if (isForm) {
                mapping[selected] = mapping[formName];
                delete mapping[formName];
            } else {
                mapping[formName][selected] = mapping[formName][offenderField];
                delete mapping[formName][offenderField];
            }

            saveMappingAjax(mapping);
        });

        // Selection for Deletion
        document.getElementById('delete_btn').addEventListener('click', () => {
            if (confirm(`Are you sure you want to delete the mapping for ${targetName}?`)) {
                if (isForm) {
                    delete mapping[formName];
                } else {
                    delete mapping[formName][offenderField];
                }
                mapping = sortMappings(mapping, instruments)
                saveMappingAjax(mapping);
            }
        });

        document.getElementById('close_btn').addEventListener('click', closeModal);


        // Save mappings
        function saveMappingAjax(updatedMapping) {
            $.ajax({
                url: "<?= $module->getUrl('scripts/save_mappings.php') ?>",
                method: "POST",
                data: {
                    pid: <?= json_encode($_GET['pid'] ?? $project_id ?? 0) ?>,
                    redcap_csrf_token: <?= json_encode($csrf) ?>,
                    "mappings": JSON.stringify(updatedMapping),
                    displayed: JSON.stringify(Object.keys(updatedMapping))
                },
                success: () => window.location.reload(),
                error: (xhr, status, error) => console.error("Error:", error)
            });
            closeModal();
        }
    }

    // Compares saved mappings against the forms/fields that currently exist in this project and
    // the fields currently discoverable across configured API sources. The first mismatch found
    // (a form that was deleted, or a field that was renamed/removed) is handed to
    // resolveMappingModal so the user can remap or drop it, rather than letting a stale mapping
    // silently point at nothing. Saving from that modal reloads the page, so validity_check runs
    // again on the fresh state and catches the next issue, if any, one at a time.
    function validity_check(existingMappings, dictionary, instruments) {
        for (const formKey of Object.keys(existingMappings)) {
            if (!instruments[formKey]) {
                resolveMappingModal('form', existingMappings, formKey, null, instruments);
                return;
            }

            const formFields = dictionary[formKey] || {};
            for (const fieldKey of Object.keys(existingMappings[formKey])) {
                if (!formFields[fieldKey]) {
                    resolveMappingModal('field', existingMappings, formKey, fieldKey, formFields);
                    return;
                }
            }
        }
    }

    function rebuild_from_mappings(instruments, dictionary, existingMappings) {
        const container = document.getElementById('instruments_list');
        if (!container) return;

        // This checks the saved mappings to ensure they match up with available forms and fields in the project
        validity_check(existingMappings, dictionary, instruments);

        // Create a fragment to build the tables in memory
        const fragment = document.createDocumentFragment();

        // Only build tables for instruments in the displayed array
        displayed.forEach(instrumentKey => {
            const instrumentLabel = instruments[instrumentKey];
            if (!instrumentLabel) return; // Skip if instrument doesn't exist

            const mappedFields = existingMappings[instrumentKey] || {};
            const dictFields = dictionary[instrumentKey];

            if (!dictFields) return; // skip if no dictionary

            // Create table
            const table = document.createElement('table');
            table.id = instrumentKey;
            table.className = "dataTable cell-border no-footer";

            const header = document.createElement('thead');
            header.innerHTML = `<tr>
                                    <th style="width: 25%;">${instrumentLabel} Fields</th>
                                    <th style="width: 40%;">REDCap Field Label</th>
                                    <th style="width: 35%;">API Field Path</th>
                                 </tr>`;
            table.appendChild(header);

            const tbody = document.createElement('tbody');
            table.appendChild(tbody);

            let i = 0;
            Object.keys(dictFields).forEach(redcapField => {
                const row = document.createElement('tr');
                row.className = i % 2 === 0 ? 'even' : 'odd';

                const selectId = `${instrumentKey}_${redcapField}`;
                row.innerHTML = `
                    <td style="width:45%">
                        <label for="${selectId}">${redcapField}</label>
                    </td>
                        <td>
                            ${
                    dictionary[instrumentKey][redcapField].field_label.length > 50
                        ? dictionary[instrumentKey][redcapField].field_label.slice(0, 50) + '…'
                        : dictionary[instrumentKey][redcapField].field_label
                }
                        </td>
                    <td style="width:40%">
                        <select name="${redcapField}" id="${selectId}">
                            <option value="">None</option>
                        </select>
                    </td>
                `;

                tbody.appendChild(row);

                // Populate select with apiFields
                const selectEl = row.querySelector('select');
                const savedMapping = mappedFields[redcapField] || {};
                appendApiFieldOptions(selectEl, apiFields, (option, obj) => {
                    // Endpoint is included to disambiguate common paths,
                    // while old mappings without an endpoint still load.
                    if (savedMapping.mapping === obj.field
                        && (!savedMapping.endpoint || savedMapping.endpoint === obj.endpoint)) {
                        option.selected = true;
                    }
                });

                fragment.appendChild(table);
                i++;
            });
        });

        // Clear the container (removes the loader) and append all tables
        container.innerHTML = '';
        container.appendChild(fragment);
    }

    // TODO: somehow we want to log changes made, but it runs on every individual change currently. Refactor so that we
    //TODO: cont. ^ benefit from it saving often but that it is only logged by change session.

    // Save a snapshot of configurations whenever you want, currently runs on any change
    function checkpoint() {
        console.log('checkpoint')
        const mapping = {};

        document.querySelectorAll('#instruments_list > table').forEach(table => {
            const instrument = table.id;
            if (!instrument) return;

            const instrumentMapping = {};

            table.querySelectorAll('select').forEach(select => {

                const redcapField = select.name;
                const selectedValue = select.value;

                // Grab the endpoint from the selected option
                const selectedOption = select.options[select.selectedIndex];
                const endpoint = selectedOption ? selectedOption.dataset.endpoint : null;

                console.log(
                    instrument,
                    redcapField,
                    selectedValue,
                    endpoint
                );

                instrumentMapping[redcapField] = {
                    mapping: selectedValue,
                    endpoint: endpoint,
                };
            });

            mapping[instrument] = instrumentMapping;
        });

        mappings = mapping;
        mappings = sortMappings(mappings, instruments);

        // Send to server - include the displayed array
        $.ajax({
            url: "<?= $module->getUrl('scripts/save_mappings.php') ?>",
            method: "POST",
            data: {
                pid: <?= json_encode($_GET['pid'] ?? $project_id ?? 0) ?>,
                redcap_csrf_token: <?= json_encode($csrf) ?>,
                "mappings": JSON.stringify(mappings),
                displayed: JSON.stringify(displayed)  // <-- Save which forms should be displayed
            },
            success: function (result) {
                console.log("Checkpoint saved:", result);
            },
            error: function (xhr, status, error) {
                console.error("Error in checkpoint:", error, xhr.responseText);
            }
        });
    }

    function load_checkpoint() {
        $.ajax({
            url: "<?= $module->getUrl('scripts/load_mappings.php') ?>",
            method: "GET",
            dataType: "json",
            success: function (result) {
                console.log('load checkpoint result', result);
                if (result.status !== 'success') {
                    console.error('Failed to load mappings:', result.error || 'Unknown error');
                    const loader = document.getElementById('forms-loader');
                    if (loader) {
                        loader.innerHTML = '<p style="color: red; text-align: center;"><b>Error:</b> Could not load saved field mappings.</p>';
                    }
                    return;
                }

                if (result.data.length === 0) {
                    let list = document.getElementById('instruments_list');
                    list.innerHTML = `<h2>You currently have not selected any Forms for mapping.</h2>
                                      <h2>Select the "Manage Forms" option above to begin mapping your first Form.</h2>`;
                } else if (mappings.length === 0) {
                    let list = document.getElementById('instruments_list');
                    list.innerHTML = `<h2>You currently have not selected any Forms for mapping.</h2>
                                      <h2>Select the "Manage Forms" option above to begin mapping your first Form.</h2>`;
                } else {
                    mappings = result.data || {};
                    mappings = sortMappings(mappings, instruments);
                    displayed = Object.keys(mappings);

                    rebuild_from_mappings(instruments, dictionary, mappings);
                }
            },
            error: function (xhr, status, error) {
                console.error('Error loading saved mappings:', error, xhr.responseText);
                const loader = document.getElementById('forms-loader');
                if (loader) {
                    loader.innerHTML = '<p style="color: red; text-align: center;"><b>Error:</b> Could not load saved field mappings.</p>';
                }
            }
        });
    }

    // Sorts a mapping prior to save so that it is displayed in the same order as they appear in REDCap.
    function sortMappings(mappings, instruments) {
        const sortedMappings = {};

        // Iterate through the instruments to enforce the correct order
        for (const instrumentKey of Object.keys(instruments)) {
            // If a mapping exists for this instrument, add it to our new object
            if (mappings.hasOwnProperty(instrumentKey)) {
                sortedMappings[instrumentKey] = mappings[instrumentKey];
            }
        }

        return sortedMappings;
    }

    // Load the saved checkpoint when the page is initialized
    document.addEventListener('DOMContentLoaded', () => {
        load_checkpoint();

        document.getElementById('upload-btn').addEventListener('click', async () => {
            // Remove any existing modal first
            const existing = document.querySelector('.modal-overlay');
            if (existing) existing.remove();

            const built = buildModal();
            const {modalOverlay, modalBox} = built;

            let modalContent = `
                <h1 class="warning">WARNING!</h1>
                <h2 class="disclaimer">Uploading a mapping <strong>will overwrite previously saved mappings.</strong></h2>
                <h2 class="disclaimer">We strongly suggest exporting your current mapping first as a backup.</h2>
                <div>
                    <input type="file" id="jsonFile" accept=".json">
                </div>
                <div style="display:flex; justify-content:center; gap:1rem; margin-top:1.5rem;">
                    <button id="confirm_upload" style="background-color:#28a745; color:white; padding:10px 20px; border:none; border-radius:6px;">Save Choices</button>
                    <button id="cancel_upload" style="background-color:#dc3545; color:white; padding:10px 20px; border:none; border-radius:6px;">Cancel</button>
                </div>
            `

            modalBox.innerHTML = modalContent;
            modalOverlay.appendChild(modalBox);
            document.body.appendChild(modalOverlay);

            const closeModal = () => {
                if (modalOverlay && modalOverlay.parentNode) {
                    modalOverlay.parentNode.removeChild(modalOverlay);
                }
            };

            document.getElementById('confirm_upload').addEventListener('click', async () => {
                const fileInput = document.getElementById("jsonFile");

                if (!fileInput || !fileInput.files.length) {
                    alert("Please select a configuration file first.");
                    return;
                }

                const file = fileInput.files[0];

                try {
                    const text = await file.text();
                    const data = JSON.parse(text);
                    rebuild_from_mappings(instruments, dictionary, data);
                    checkpoint(); // save it after
                    closeModal();
                } catch (err) {
                    console.error(err);
                    alert("Invalid JSON file.");
                }
            });

            document.getElementById('cancel_upload').addEventListener('click', () => {
                closeModal();
            });

            modalOverlay.addEventListener('click', (e) => {
                if (e.target === modalOverlay) closeModal();
            });
        });

        document.getElementById('export-btn').addEventListener('click', () => {
            // export a properly formatted config for upload in another project
            const jsonString = JSON.stringify(mappings, null, 2);

            // Create file blob
            const blob = new Blob([jsonString], {type: "application/json"});

            // Create download link
            const url = URL.createObjectURL(blob);
            const a = document.createElement("a");

            a.href = url;
            a.download = `${project_title}_${PROJECT_ID}_mapping_config.json`;
            a.click();

            URL.revokeObjectURL(url);
        });

        document.getElementById('manage-forms-btn').addEventListener('click', () => {
            manageForms(instruments, displayed);
        });

        // When a user changes a field mapping dropdown
        document.addEventListener('change', function (event) {
            if (event.target.matches('#instruments_list select')) {
                checkpoint();
            }
        });
    });

</script>
