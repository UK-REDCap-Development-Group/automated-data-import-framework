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
// Each also carries its source and the [placeholder]s its endpoint path sends to the API to find
// a record's data, so picking it can ask which REDCap field holds those values.
$apiFields = array_map(function ($apiField) {
    preg_match_all('/\[([a-z][a-z0-9_]*)\]/', $apiField['path'], $matches);
    return [
        'field' => $apiField['field'],
        'endpoint' => $apiField['endpoint'],
        'source' => $apiField['source'],
        'lookups' => array_values(array_unique($matches[1])),
    ];
}, $module->getApiFields());

// The REDCap field chosen to supply each source's placeholders (see scripts/save_lookups.php)
$savedLookups = $module->getProjectSetting('lookup-fields') ?: [];

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

    // { "<source>": { "<placeholder>": "<field>" } }
    let savedLookups = Object.assign({}, <?= json_encode((object) $savedLookups) ?>);
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
        option.setAttribute('data-source', obj.source);
        if (obj.lookups && obj.lookups.length) {
            option.setAttribute('data-lookups', obj.lookups.join(','));
        }
        option.title = apiFieldTitle(obj);

        return option;
    }

    // [] takes every value in a list; a role segment such as
    // principalInvestigator takes only that person's, which is what lets
    // the field sync without extra handling.
    function apiFieldTitle(obj) {
        const note = obj.field.includes('[]')
            ? '\n\nReads every value returned in this list, joined with "; ". Choose a single value if you need exactly one match.'
            : '';
        return `${obj.endpoint}: ${obj.field}${note}`;
    }

    // ---- API field picker ----
    // Each mapping row keeps a hidden <select name> as the record of its choice, since checkpoint(),
    // the lookup prompts and Manage Forms all read a mapping from one. It only ever holds "None"
    // and the chosen field. Choosing happens in one shared, searchable popover instead of a native
    // dropdown that repeats every field of every source on every row, which stopped being usable
    // once a few sources and uploaded tables were configured.

    // The most results drawn at once. Search narrows past this; drawing thousands of rows per
    // keystroke is what we're getting away from.
    const PICKER_RESULT_LIMIT = 150;

    // Words that say nothing about which field is meant, in labels or in paths (data_value)
    const PICKER_STOP_WORDS = new Set(['the', 'and', 'for', 'with', 'from', 'of', 'in', 'an', 'among', 'data', 'value']);

    // Static markup only - nothing from a schema or the dictionary goes through innerHTML
    const PICKER_ICONS = {
        search: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg>',
        chevron: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m6 9 6 6 6-6"></path></svg>',
        api: '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M7 18h10a4 4 0 0 0 .5-7.97A6 6 0 0 0 6.1 9.5 4.25 4.25 0 0 0 7 18z"></path></svg>',
        table: '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><rect x="3" y="4" width="18" height="16" rx="2"></rect><path d="M3 10h18M9 4v16"></path></svg>',
    };

    function pickerWords(text) {
        return String(text || '').replace(/<[^>]*>/g, ' ').toLowerCase()
            .split(/[^a-z0-9]+/)
            .filter(word => word.length > 1 && !PICKER_STOP_WORDS.has(word));
    }

    // Identifies a field across sources, since two endpoints can offer the same path
    function pickerKey(endpoint, field) {
        return `${endpoint}\u0000${field}`;
    }

    // Every API field, with what search and suggestions need worked out once
    const pickerFields = apiFields.map(obj => ({
        obj,
        key: pickerKey(obj.endpoint, obj.field),
        display: apiFieldDisplay(obj.field),
        isTable: String(obj.source).startsWith('table:'),
        haystack: `${obj.field} ${obj.endpoint}`.toLowerCase(),
        words: new Set(pickerWords(obj.field)),
    }));

    // Endpoints in the order their fields arrive, for grouping and the filter chips
    const pickerEndpoints = [...new Set(apiFields.map(obj => obj.endpoint))];

    // How many fields each word appears in, so a word every field of a source shares (data_value)
    // counts for little and a distinctive one (depression) for a lot
    const pickerWordCounts = new Map();
    pickerFields.forEach(f => f.words.forEach(word => {
        pickerWordCounts.set(word, (pickerWordCounts.get(word) || 0) + 1);
    }));

    // Words most of a form's fields share - the tract_ in tract_depression_pct, tract_obesity_pct...
    // A naming convention, not a clue to which API field is meant. Worked out once per form.
    const pickerFormWords = new Map();
    function pickerCommonFormWords(formKey) {
        if (pickerFormWords.has(formKey)) return pickerFormWords.get(formKey);

        const names = Object.keys(dictionary[formKey] || {});
        const counts = new Map();
        names.forEach(name => new Set(pickerWords(name)).forEach(word => {
            counts.set(word, (counts.get(word) || 0) + 1);
        }));
        const common = new Set(names.length >= 3
            ? [...counts].filter(([, count]) => count / names.length >= 0.5).map(([word]) => word)
            : []);

        pickerFormWords.set(formKey, common);
        return common;
    }

    // Up to three fields whose paths share distinctive words with a REDCap field's name and label:
    // tract_depression_pct, "Depression among adults (%)" -> [measureid=DEPRESSION].data_value.
    // Ties go to the shorter path, which tends to be the main value rather than a qualifier of it.
    function pickerSuggestions(redcapField, formKey) {
        const info = (dictionary[formKey] || {})[redcapField] || {};
        const common = pickerCommonFormWords(formKey);
        const wanted = [...new Set([...pickerWords(redcapField), ...pickerWords(info.field_label)])]
            .filter(word => !common.has(word));
        const total = pickerFields.length;
        if (!wanted.length || !total) return [];

        // Enough that one distinctive shared word counts and a loosely common one doesn't, scaled
        // down for a source small enough that no word can be very rare
        const threshold = Math.min(2, Math.log(total) / 2);
        const scored = [];
        pickerFields.forEach(f => {
            let score = 0;
            f.words.forEach(word => {
                const count = pickerWordCounts.get(word);
                if (count / total >= 0.5) return;
                // Whole words, or one starting the other: "lower" in a label finds low_confidence_limit
                const shorter = (a, b) => a.length >= 3 && b.startsWith(a);
                if (wanted.some(w => w === word || shorter(w, word) || shorter(word, w))) {
                    score += Math.log(total / count);
                }
            });
            if (score >= threshold) scored.push({f, score});
        });

        return scored
            .sort((a, b) => b.score - a.score || a.f.obj.field.length - b.f.obj.field.length)
            .slice(0, 3)
            .map(entry => entry.f);
    }

    // Puts a field (or nothing) into a row's hidden select, the way a choice in the old dropdown did
    function setPickerValue(select, obj) {
        select.textContent = '';
        const none = document.createElement('option');
        none.value = '';
        none.textContent = 'None';
        select.appendChild(none);

        if (obj) {
            const option = apiFieldOption(obj);
            option.selected = true;
            select.appendChild(option);
        } else {
            none.selected = true;
        }
    }

    function selectedPickerKey(select) {
        const option = select.options[select.selectedIndex];
        return option && option.value ? pickerKey(option.dataset.endpoint, option.value) : '';
    }

    function pickerBadge(field) {
        const badge = document.createElement('span');
        badge.className = 'adif-picker-badge';
        badge.title = field.obj.endpoint;
        badge.insertAdjacentHTML('beforeend', field.isTable ? PICKER_ICONS.table : PICKER_ICONS.api);
        const text = document.createElement('span');
        text.className = 'adif-picker-badge-text';
        text.textContent = field.obj.endpoint;
        badge.appendChild(text);
        return badge;
    }

    // Appends text with each search term wrapped in <mark>, built from text nodes so a path or
    // endpoint name is never read as markup
    function appendHighlighted(parent, text, tokens) {
        const lower = text.toLowerCase();
        const ranges = [];
        tokens.forEach(token => {
            let from = 0;
            let at;
            while ((at = lower.indexOf(token, from)) !== -1) {
                ranges.push([at, at + token.length]);
                from = at + token.length;
            }
        });
        ranges.sort((a, b) => a[0] - b[0]);

        let position = 0;
        ranges.forEach(([start, end]) => {
            if (end <= position) return;
            start = Math.max(start, position);
            if (start > position) parent.append(text.slice(position, start));
            const mark = document.createElement('mark');
            mark.textContent = text.slice(start, end);
            parent.appendChild(mark);
            position = end;
        });
        if (position < text.length) parent.append(text.slice(position));
    }

    // The button that stands in for a row's dropdown: where the value comes from, and which field
    function renderPickerTrigger(trigger, select) {
        trigger.textContent = '';
        const key = selectedPickerKey(select);
        const field = key ? pickerFields.find(f => f.key === key) : null;

        if (!field) {
            trigger.classList.add('is-empty');
            trigger.insertAdjacentHTML('beforeend', PICKER_ICONS.search);
            const text = document.createElement('span');
            text.className = 'adif-picker-value';
            text.textContent = 'Search fields…';
            trigger.appendChild(text);
            trigger.title = '';
            trigger.setAttribute('aria-label', `API field for ${select.name}: not mapped`);
            return;
        }

        trigger.classList.remove('is-empty');
        trigger.appendChild(pickerBadge(field));
        const value = document.createElement('span');
        value.className = 'adif-picker-value';
        value.textContent = field.display;
        trigger.appendChild(value);
        trigger.insertAdjacentHTML('beforeend', PICKER_ICONS.chevron);
        trigger.title = apiFieldTitle(field.obj);
        trigger.setAttribute('aria-label', `API field for ${select.name}: ${field.obj.endpoint}, ${field.obj.field}`);
    }

    // Turns a row's <select name> into the picker. savedMapping is { mapping, endpoint } as saved;
    // endpoint is included to disambiguate common paths, while old mappings without one still load.
    function attachApiFieldPicker(select, formKey, savedMapping) {
        // The last match, as the old dropdown's selected option was
        const saved = savedMapping && savedMapping.mapping
            ? apiFields.filter(obj => obj.field === savedMapping.mapping
                && (!savedMapping.endpoint || savedMapping.endpoint === obj.endpoint)).pop()
            : null;
        setPickerValue(select, saved || null);
        select.hidden = true;
        select.dataset.form = formKey;

        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'adif-picker-trigger';
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');
        // The row's <label for> now points at the button
        if (select.id) {
            trigger.id = select.id;
            select.id = `${select.id}__value`;
        }
        select.after(trigger);
        renderPickerTrigger(trigger, select);

        trigger.addEventListener('click', () => openApiFieldPicker(select, trigger));
        trigger.addEventListener('keydown', event => {
            // Arrow down opens it; typing a letter opens it already searching for that letter
            const typed = event.key.length === 1 && event.key !== ' '
                && !event.ctrlKey && !event.metaKey && !event.altKey;
            if (event.key === 'ArrowDown' || typed) {
                event.preventDefault();
                openApiFieldPicker(select, trigger, typed ? event.key : '');
            }
        });
    }

    let picker = null;

    function buildPicker() {
        if (picker) return picker;

        const el = document.createElement('div');
        el.className = 'adif-picker';
        el.setAttribute('role', 'dialog');
        el.setAttribute('aria-label', 'Choose an API field');
        el.hidden = true;

        const search = document.createElement('label');
        search.className = 'adif-picker-search';
        search.insertAdjacentHTML('beforeend', PICKER_ICONS.search);
        const input = document.createElement('input');
        input.type = 'text';
        input.autocomplete = 'off';
        input.spellcheck = false;
        input.placeholder = `Search ${pickerFields.length.toLocaleString()} fields`;
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-expanded', 'true');
        input.setAttribute('aria-controls', 'adif-picker-list');
        search.appendChild(input);

        const chips = document.createElement('div');
        chips.className = 'adif-picker-chips';
        chips.setAttribute('role', 'group');
        chips.setAttribute('aria-label', 'Filter by source');

        const list = document.createElement('div');
        list.className = 'adif-picker-list';
        list.id = 'adif-picker-list';
        list.setAttribute('role', 'listbox');
        list.setAttribute('aria-label', 'API fields');

        const lookup = document.createElement('div');
        lookup.className = 'adif-picker-lookup';
        lookup.hidden = true;

        const footer = document.createElement('div');
        footer.className = 'adif-picker-footer';
        const keys = document.createElement('span');
        keys.textContent = '↑ ↓ to move · Enter to choose · Esc to close';
        const count = document.createElement('span');
        footer.append(keys, count);

        el.append(search, chips, list, lookup, footer);
        document.body.appendChild(el);

        picker = {el, input, chips, list, lookup, count, select: null, trigger: null,
            endpoint: null, suggestions: [], items: [], active: -1};

        input.addEventListener('input', () => renderPicker(false));
        input.addEventListener('keydown', event => {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                if (!picker.items.length) return;
                const step = event.key === 'ArrowDown' ? 1 : -1;
                const next = Math.min(picker.items.length - 1, Math.max(0, picker.active + step));
                setPickerActive(next, true);
            } else if (event.key === 'Enter') {
                event.preventDefault();
                choosePickerItem(picker.active);
            } else if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                closeApiFieldPicker(true);
            } else if (event.key === 'Tab') {
                closeApiFieldPicker(false);
            }
        });

        return picker;
    }

    function openApiFieldPicker(select, trigger, initialQuery = '') {
        const p = buildPicker();
        if (p.trigger === trigger) {
            closeApiFieldPicker(true);
            return;
        }
        closeApiFieldPicker(false);

        p.select = select;
        p.trigger = trigger;
        p.endpoint = null;
        p.suggestions = pickerSuggestions(select.name, select.dataset.form);
        p.input.value = initialQuery;
        p.input.setAttribute('aria-label', `Search API fields for ${select.name}`);
        trigger.setAttribute('aria-expanded', 'true');
        trigger.classList.add('is-open');
        p.el.hidden = false;

        renderPicker(true);
        positionPicker();
        p.input.focus();
    }

    function closeApiFieldPicker(returnFocus) {
        const p = picker;
        if (!p || !p.trigger) return;

        p.el.hidden = true;
        p.trigger.setAttribute('aria-expanded', 'false');
        p.trigger.classList.remove('is-open');
        if (returnFocus && p.trigger.isConnected) p.trigger.focus();
        p.trigger = null;
        p.select = null;
    }

    function renderPicker(onOpen) {
        const p = picker;
        const query = p.input.value.trim().toLowerCase();
        const tokens = query.split(/\s+/).filter(Boolean);
        const matching = tokens.length
            ? pickerFields.filter(f => tokens.every(token => f.haystack.includes(token)))
            : pickerFields;

        // Source chips, with how many fields in each match the search. Not worth showing for one.
        p.chips.textContent = '';
        p.chips.hidden = pickerEndpoints.length < 2;
        if (!p.chips.hidden) {
            const counts = new Map();
            matching.forEach(f => counts.set(f.obj.endpoint, (counts.get(f.obj.endpoint) || 0) + 1));

            const chip = (label, endpoint, count) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'adif-picker-chip';
                button.textContent = `${label} · ${count.toLocaleString()}`;
                button.title = label;
                button.setAttribute('aria-pressed', p.endpoint === endpoint ? 'true' : 'false');
                if (count === 0 && p.endpoint !== endpoint) button.classList.add('is-empty');
                // Keep focus in the search box while clicking
                button.addEventListener('mousedown', event => event.preventDefault());
                button.addEventListener('click', () => {
                    p.endpoint = p.endpoint === endpoint ? null : endpoint;
                    renderPicker(false);
                });
                p.chips.appendChild(button);
            };
            chip('All sources', null, matching.length);
            pickerEndpoints.forEach(endpoint => chip(endpoint, endpoint, counts.get(endpoint) || 0));
        }

        const shown = p.endpoint ? matching.filter(f => f.obj.endpoint === p.endpoint) : matching;
        const shownSet = new Set(shown);
        const currentKey = selectedPickerKey(p.select);

        p.list.textContent = '';
        p.items = [];

        const group = (label, count) => {
            const wrapper = document.createElement('div');
            wrapper.setAttribute('role', 'group');
            const heading = document.createElement('div');
            heading.className = 'adif-picker-group-label';
            heading.id = `adif-picker-group-${p.list.children.length}`;
            heading.textContent = count === undefined ? label : `${label} · ${count.toLocaleString()}`;
            wrapper.setAttribute('aria-labelledby', heading.id);
            wrapper.appendChild(heading);
            p.list.appendChild(wrapper);
            return wrapper;
        };

        const addOption = (field, parent, withBadge) => {
            const index = p.items.length;
            const option = document.createElement('div');
            option.id = `adif-picker-option-${index}`;
            option.className = 'adif-picker-option';
            option.setAttribute('role', 'option');

            const main = document.createElement('span');
            main.className = 'adif-picker-option-main';
            option.appendChild(main);

            if (!field) {
                option.classList.add('is-clear');
                option.setAttribute('aria-selected', 'false');
                main.textContent = 'No mapping';
            } else {
                const isCurrent = field.key === currentKey;
                option.setAttribute('aria-selected', isCurrent ? 'true' : 'false');
                option.title = apiFieldTitle(field.obj);
                if (withBadge) main.appendChild(pickerBadge(field));
                const display = document.createElement('span');
                display.className = 'adif-picker-option-display';
                appendHighlighted(display, field.display, tokens);
                main.appendChild(display);
                if (isCurrent) {
                    const current = document.createElement('span');
                    current.className = 'adif-picker-current';
                    current.textContent = 'Current';
                    main.appendChild(current);
                }

                // The full path, which the shortened display leaves out the middle of
                if (field.display !== field.obj.field) {
                    const path = document.createElement('span');
                    path.className = 'adif-picker-option-path';
                    appendHighlighted(path, field.obj.field, tokens);
                    option.appendChild(path);
                }
            }

            // mousedown would move focus out of the search box and close the picker
            option.addEventListener('mousedown', event => event.preventDefault());
            option.addEventListener('click', () => choosePickerItem(index));
            option.addEventListener('mousemove', () => {
                if (p.active !== index) setPickerActive(index, false);
            });

            p.items.push(field);
            parent.appendChild(option);
        };

        if (!tokens.length && currentKey) {
            addOption(null, p.list, false);
        }

        const suggested = p.suggestions.filter(f => shownSet.has(f));
        if (suggested.length) {
            const suggestedGroup = group(`Suggested for ${p.select.name}`);
            suggested.forEach(f => addOption(f, suggestedGroup, true));
        }

        // Everything else grouped under its endpoint, so the endpoint name appears once as a heading
        const suggestedSet = new Set(suggested);
        const byEndpoint = new Map();
        shown.forEach(f => {
            if (suggestedSet.has(f)) return;
            if (!byEndpoint.has(f.obj.endpoint)) byEndpoint.set(f.obj.endpoint, []);
            byEndpoint.get(f.obj.endpoint).push(f);
        });

        let drawn = suggested.length;
        for (const [endpoint, fields] of byEndpoint) {
            if (drawn >= PICKER_RESULT_LIMIT) break;
            const endpointGroup = group(endpoint, fields.length);
            for (const f of fields) {
                if (drawn >= PICKER_RESULT_LIMIT) break;
                addOption(f, endpointGroup, false);
                drawn++;
            }
        }

        if (!shown.length) {
            const empty = document.createElement('div');
            empty.className = 'adif-picker-empty';
            empty.textContent = tokens.length
                ? `No fields match “${p.input.value.trim()}”${p.endpoint ? ` in ${p.endpoint}` : ''}.`
                : 'No fields are available from this source.';
            p.list.appendChild(empty);
        }

        if (drawn < shown.length) {
            p.count.textContent = `Showing ${drawn.toLocaleString()} of ${shown.length.toLocaleString()} — type to narrow`;
        } else if (tokens.length || p.endpoint) {
            p.count.textContent = `${shown.length.toLocaleString()} of ${pickerFields.length.toLocaleString()} fields`;
        } else {
            p.count.textContent = `${pickerFields.length.toLocaleString()} fields`;
        }

        // Start on the current mapping when opening, else the best suggestion; while typing,
        // on the first result. "No mapping" is only ever chosen on purpose.
        const firstField = p.items.findIndex(f => f !== null);
        let active = firstField;
        if (onOpen) {
            const current = p.items.findIndex(f => f && f.key === currentKey);
            if (current !== -1) active = current;
        }
        setPickerActive(active === -1 ? (p.items.length ? 0 : -1) : active, onOpen);
        if (!onOpen) p.list.scrollTop = 0;
    }

    function setPickerActive(index, scroll) {
        const p = picker;
        p.list.querySelectorAll('.adif-picker-option.is-active').forEach(el => el.classList.remove('is-active'));
        p.active = index;

        const option = index >= 0 ? document.getElementById(`adif-picker-option-${index}`) : null;
        if (option) {
            option.classList.add('is-active');
            p.input.setAttribute('aria-activedescendant', option.id);
            if (scroll) option.scrollIntoView({block: 'nearest'});
        } else {
            p.input.removeAttribute('aria-activedescendant');
        }
        renderPickerLookup(index >= 0 ? p.items[index] : null);
    }

    // Under the results: what the highlighted field's endpoint sends to find a record, and
    // where that value will come from
    function renderPickerLookup(field) {
        const p = picker;
        p.lookup.textContent = '';
        const placeholders = field ? field.obj.lookups || [] : [];
        p.lookup.hidden = !placeholders.length;
        if (!placeholders.length) return;

        p.lookup.append('Finds records by ');
        placeholders.forEach((placeholder, i) => {
            if (i > 0) p.lookup.append(i === placeholders.length - 1 ? ' and ' : ', ');
            const code = document.createElement('code');
            code.textContent = `[${placeholder}]`;
            p.lookup.appendChild(code);
            const from = lookupFieldFor(field.obj.source, placeholder);
            p.lookup.append(from ? ` from ${from}` : ' (you’ll be asked which field holds it)');
        });
        p.lookup.append('.');
    }

    function choosePickerItem(index) {
        const p = picker;
        if (!p || !p.select || index < 0 || index >= p.items.length) return;

        const field = p.items[index];
        const select = p.select;
        const trigger = p.trigger;
        const before = selectedPickerKey(select);

        setPickerValue(select, field ? field.obj : null);
        renderPickerTrigger(trigger, select);
        closeApiFieldPicker(true);

        // The page's change handler saves, and asks for a lookup field when one is missing
        if (selectedPickerKey(select) !== before) {
            select.dispatchEvent(new Event('change', {bubbles: true}));
        }
    }

    // Under the trigger, right edges lined up since the API field column is the last one; above
    // it when there isn't room below
    function positionPicker() {
        const p = picker;
        if (!p || !p.trigger) return;

        const margin = 8;
        const rect = p.trigger.getBoundingClientRect();
        const width = Math.min(Math.max(rect.width, 520), window.innerWidth - margin * 2);
        let left = Math.min(rect.right - width, window.innerWidth - margin - width);
        left = Math.max(margin, left);

        const below = window.innerHeight - rect.bottom - margin;
        const above = rect.top - margin;
        const openUp = below < 280 && above > below;
        p.el.style.width = `${width}px`;
        p.el.style.left = `${left + window.scrollX}px`;
        p.el.style.maxHeight = `${Math.max(220, Math.min(480, (openUp ? above : below) - 6))}px`;
        p.el.style.top = openUp
            ? `${rect.top + window.scrollY - 6 - p.el.offsetHeight}px`
            : `${rect.bottom + window.scrollY + 6}px`;
    }

    document.addEventListener('mousedown', event => {
        if (!picker || !picker.trigger) return;
        if (picker.el.contains(event.target) || picker.trigger.contains(event.target)) return;
        closeApiFieldPicker(false);
    });
    window.addEventListener('resize', positionPicker);
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
                    const selects = Array.from(table.querySelectorAll('select[name]'));
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

                    attachApiFieldPicker(row.querySelector('select'), selectedForm, null);

                    tbody.appendChild(row);
                    i++;
                });

                document.getElementById('instruments_list').appendChild(table);
            });

            refreshAllLookupStatuses();
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

                attachApiFieldPicker(row.querySelector('select'), instrumentKey, mappedFields[redcapField] || null);

                fragment.appendChild(table);
                i++;
            });
        });

        // Clear the container (removes the loader) and append all tables
        container.innerHTML = '';
        container.appendChild(fragment);
        refreshAllLookupStatuses();
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

            // select[name]: the mapping dropdowns, not a lookup prompt's
            table.querySelectorAll('select[name]').forEach(select => {

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

    // Fields that can hold a lookup value. Descriptive, file and checkbox fields have no single
    // value to send, and calc fields are left in since a computed ID is a reasonable thing to look up by.
    function lookupFieldOptions() {
        const unusable = ['descriptive', 'file', 'checkbox'];
        const groups = [];
        Object.keys(instruments).forEach(form => {
            const fields = Object.entries(dictionary[form] || {})
                .filter(([, info]) => !unusable.includes(info.field_type))
                .map(([name, info]) => ({name, label: info.field_label.replace(/(<([^>]+)>)/gi, '')}));
            if (fields.length) groups.push({label: instruments[form], fields});
        });
        return groups;
    }

    function fieldExists(name) {
        return Object.values(dictionary).some(fields => Object.prototype.hasOwnProperty.call(fields, name));
    }

    // ---- Lookup values ----
    // An endpoint path's [placeholder]s are sent to its API to find the record's data, filled from a
    // REDCap field. Picking an API field whose endpoint needs one with no field behind it opens a
    // prompt under that row. "Not now" leaves a chip on the row that reopens it, and a row whose
    // lookups are all set says where each value comes from, with a way to change it.

    // The field a source's placeholder is read from: the saved choice, else a field with the
    // placeholder's own name, as Import::getLookupField does. '' when neither is in the project.
    function lookupFieldFor(source, placeholder) {
        const saved = (savedLookups[source] || {})[placeholder] || '';
        const field = saved !== '' ? saved : placeholder;
        return fieldExists(field) ? field : '';
    }

    // The field most likely to hold a placeholder's value: the same name, then a name containing it
    // or contained in it (address -> home_address, census_tract_geoid -> tract_geoid), then the
    // most shared words. '' when nothing is close.
    function suggestLookupField(placeholder) {
        const words = placeholder.split('_');
        let best = '';
        let bestScore = 0;
        lookupFieldOptions().forEach(group => group.fields.forEach(({name}) => {
            let score;
            if (name === placeholder) score = 100;
            else if (name.includes(placeholder)) score = 80;
            else if (placeholder.includes(name)) score = 70;
            else score = 10 * name.split('_').filter(word => word.length > 2 && words.includes(word)).length;
            if (score > bestScore) {
                best = name;
                bestScore = score;
            }
        }));
        return best;
    }

    // The selected API field's source, endpoint and placeholders, or null when it has none
    function selectedLookups(select) {
        const option = select.options[select.selectedIndex];
        if (!option || !option.dataset.lookups) return null;
        return {
            source: option.dataset.source,
            endpoint: option.dataset.endpoint,
            placeholders: option.dataset.lookups.split(','),
        };
    }

    function missingLookups(lookups) {
        return lookups ? lookups.placeholders.filter(p => lookupFieldFor(lookups.source, p) === '') : [];
    }

    // The line under a mapping's dropdown: nothing without lookups, a chip while one isn't set, or
    // where each value is looked up from
    function refreshLookupStatus(select) {
        const cell = select.parentElement;
        let status = cell.querySelector('.lookup-status');
        if (!status) {
            status = document.createElement('div');
            status.className = 'lookup-status';
            cell.appendChild(status);
        }
        status.textContent = '';

        const lookups = selectedLookups(select);
        if (!lookups) return;

        const button = document.createElement('button');
        button.type = 'button';
        button.addEventListener('click', () => openLookupPrompt(select));

        const missing = missingLookups(lookups);
        if (missing.length) {
            button.className = 'lookup-chip';
            button.textContent = `Needs ${missing.map(p => `[${p}]`).join(', ')} · Set lookup field`;
            status.appendChild(button);
        } else {
            const text = document.createElement('span');
            text.textContent = 'Looks up ' + lookups.placeholders
                .map(p => `[${p}] from ${lookupFieldFor(lookups.source, p)}`).join(', ');
            button.className = 'lookup-change';
            button.textContent = 'Change';
            status.append(text, ' · ', button);
        }
    }

    function refreshAllLookupStatuses() {
        document.querySelectorAll('#instruments_list select[name]').forEach(refreshLookupStatus);
    }

    // How many other mapped fields read this source's placeholder, since a choice covers them too
    function countOtherMappings(source, placeholder, except) {
        let count = 0;
        document.querySelectorAll('#instruments_list select[name]').forEach(other => {
            const lookups = other === except ? null : selectedLookups(other);
            if (lookups && lookups.source === source && lookups.placeholders.includes(placeholder)) count++;
        });
        return count;
    }

    function closeLookupPrompts() {
        document.querySelectorAll('#instruments_list .lookup-prompt-row').forEach(row => row.remove());
    }

    // The prompt, in a full-width row under the mapping. One field choice per placeholder the
    // endpoint uses; ones already set show their current field, so the same prompt changes them.
    function openLookupPrompt(select) {
        closeLookupPrompts();
        const lookups = selectedLookups(select);
        if (!lookups) return;

        const mappingRow = select.closest('tr');
        const promptRow = document.createElement('tr');
        promptRow.className = 'lookup-prompt-row';
        const cell = document.createElement('td');
        cell.colSpan = mappingRow.children.length;
        promptRow.appendChild(cell);

        const box = document.createElement('div');
        box.className = 'lookup-prompt';
        box.setAttribute('role', 'group');

        const placeholderList = lookups.placeholders.map(p => `[${p}]`).join(' and ');
        const title = document.createElement('h4');
        title.textContent = `${lookups.endpoint} finds records by ${placeholderList}`;
        box.setAttribute('aria-label', title.textContent);

        const others = Math.max(...lookups.placeholders.map(p => countOtherMappings(lookups.source, p, select)));
        const intro = document.createElement('p');
        intro.textContent = `Which REDCap field holds ${lookups.placeholders.length > 1 ? 'each one' : 'it'}? `
            + 'This applies to every field mapped from this API source'
            + (others > 0 ? `, including ${others} other mapped field${others === 1 ? '' : 's'}.` : '.');
        box.append(title, intro);

        const choices = document.createElement('div');
        choices.className = 'lookup-prompt-choices';
        const groups = lookupFieldOptions();
        const pickers = lookups.placeholders.map(placeholder => {
            const current = lookupFieldFor(lookups.source, placeholder);
            const suggested = current === '' ? suggestLookupField(placeholder) : '';

            const label = document.createElement('label');
            label.textContent = `REDCap field for [${placeholder}]`;
            const picker = document.createElement('select');
            picker.className = 'lookup-prompt-select';
            picker.dataset.placeholder = placeholder;

            const blank = document.createElement('option');
            blank.value = '';
            blank.textContent = '-- Choose a field --';
            picker.appendChild(blank);
            groups.forEach(group => {
                const optgroup = document.createElement('optgroup');
                optgroup.label = group.label;
                group.fields.forEach(field => {
                    const option = document.createElement('option');
                    option.value = field.name;
                    option.textContent = (field.label ? `${field.name} (${field.label.slice(0, 40)})` : field.name)
                        + (field.name === suggested ? ' · suggested' : '');
                    optgroup.appendChild(option);
                });
                picker.appendChild(optgroup);
            });
            picker.value = current || suggested;

            label.appendChild(picker);
            choices.appendChild(label);
            return picker;
        });

        const use = document.createElement('button');
        use.type = 'button';
        use.className = 'selectA_btn';
        use.textContent = pickers.length > 1 ? 'Use these fields' : 'Use this field';
        const later = document.createElement('button');
        later.type = 'button';
        later.className = 'close-button';
        later.textContent = 'Not now';
        choices.append(use, later);
        box.appendChild(choices);
        cell.appendChild(box);
        mappingRow.after(promptRow);

        use.addEventListener('click', () => {
            if (pickers.some(picker => picker.value === '')) {
                alert('Choose a field for each lookup value, or select "Not now".');
                return;
            }
            savedLookups[lookups.source] = Object.assign({}, savedLookups[lookups.source] || {});
            pickers.forEach(picker => {
                savedLookups[lookups.source][picker.dataset.placeholder] = picker.value;
            });
            saveLookupFields();
            closeLookupPrompts();
            refreshAllLookupStatuses();
        });
        later.addEventListener('click', closeLookupPrompts);
        pickers[0].focus();
    }

    // Saves every source's choices, as { "<source>": { "<placeholder>": "<field>" } }
    function saveLookupFields() {
        $.ajax({
            url: "<?= $module->getUrl('scripts/save_lookups.php') ?>",
            method: "POST",
            dataType: "json",
            data: {
                pid: <?= json_encode($_GET['pid'] ?? $project_id ?? 0) ?>,
                redcap_csrf_token: <?= json_encode($csrf) ?>,
                lookups: JSON.stringify(savedLookups)
            },
            success: result => { savedLookups = Object.assign({}, result.lookups || {}); },
            error: (xhr, status, error) => {
                console.error('Error saving lookup fields:', error, xhr.responseText);
                alert('The lookup field choice could not be saved. Please try again.');
            }
        });
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
            // select[name]: the mapping dropdowns, not a lookup prompt's
            if (event.target.matches('#instruments_list select[name]')) {
                checkpoint();
                refreshLookupStatus(event.target);
                if (missingLookups(selectedLookups(event.target)).length) {
                    openLookupPrompt(event.target);
                } else if (event.target.closest('tr').nextElementSibling?.classList.contains('lookup-prompt-row')) {
                    closeLookupPrompts();
                }
            }
        });
    });

</script>
