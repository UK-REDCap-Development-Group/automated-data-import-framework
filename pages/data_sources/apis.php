<?php
/*
 * The API connections tab of the Data Sources page (pages/Data_Sources.php): add, edit, test, reorder
 * and delete the project's API sources. Every value is saved to the same "api-sources" sub-settings
 * REDCap's module configuration dialog edits (see classes/ApiSources.php), so the two stay in step.
 * The API key is never sent to the browser: a saved key can be kept, replaced or removed.
 */

/** @var \UKModules\ADIF\ADIF $module */

if (!defined('ADIF_DATA_SOURCES_PAGE')) {
    exit;   // only ever included by pages/Data_Sources.php, which checks the user's rights first
}

$error = null;
$success = null;
$notices = [];
$focus = null;            // the source an action was taken on ('new' for the add form), opened and scrolled to
$posted = [];             // [index|'new' => the form as posted], to re-show it after an error or a warning
$confirmBreaks = [];      // [index => [field => path]] mapped fields a save would disconnect
$testResults = [];        // [index => ApiSources::testApiSource() result]
$testPaths = [];          // [index => the path last tested], to keep it in the test form

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['adif_action'] ?? '');
    $rawIndex = (string) ($_POST['source_index'] ?? '');
    $index = $rawIndex === 'new' ? null : $rawIndex;
    $expectedUrl = isset($_POST['source_url']) ? (string) $_POST['source_url'] : null;
    $focus = $rawIndex !== '' ? $rawIndex : null;
    if ($action === 'api_test') {
        $testPaths[$rawIndex] = (string) ($_POST['test_path'] ?? '');   // kept even when the test fails
    }

    try {
        switch ($action) {
            case 'api_save':
                $outcome = $module->saveApiSource($index, $_POST, $expectedUrl, !empty($_POST['confirm_breaking']));
                if ($outcome['status'] === 'needs_confirm') {
                    $posted[$rawIndex] = $_POST;
                    $confirmBreaks[$outcome['index']] = $outcome['breaks'];
                    break;
                }
                $focus = (string) $outcome['index'];
                $success = $index === null
                    ? 'Added the API source. Its fields are now offered on the Mapping page.'
                    : 'Saved the API source.';
                if (!empty($outcome['breaks'])) {
                    $success .= ' ' . count($outcome['breaks']) . ' mapped field(s) can no longer find their API field and'
                        . ' will be skipped on import until they\'re mapped again: ' . implode(', ', array_keys($outcome['breaks'])) . '.';
                }
                $notices = $outcome['notices'];
                break;

            case 'api_delete':
                $outcome = $module->deleteApiSource($index, $expectedUrl);
                $focus = null;
                $success = 'Deleted the API source. Later sources moved up one place, with their settings and lookup choices.';
                if (!empty($outcome['breaks'])) {
                    $success .= ' ' . count($outcome['breaks']) . ' mapped field(s) read from it and will be skipped on import'
                        . ' until they\'re mapped to another source: ' . implode(', ', array_keys($outcome['breaks'])) . '.';
                }
                break;

            case 'api_move':
                $focus = (string) $module->moveApiSource($index, (int) ($_POST['direction'] ?? 0), $expectedUrl);
                $success = 'Moved the API source.';
                break;

            case 'api_test':
                $testResults[(int) $index] = $module->testApiSource($index, $_POST['test_path'] ?? '', $expectedUrl);
                break;

            default:
                throw new \Exception('Unknown action.');
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
        if ($action === 'api_save') {
            $posted[$rawIndex] = $_POST;   // keep what was typed, e.g. a long pasted schema
        }
    }
}

$sources = $module->getApiSourceConfigs();
$configs = $module->apiSourceSettingConfigs();
$cachingOn = (bool) $module->getSystemSetting('adif-cache-enabled');
$tablesUrl = adif_ds_url($module, 'tables');

// The sub-settings this tab lays out itself; any other sub-setting in config.json's "api-sources"
// group is shown under "Other settings" with config.json's own label, so it can still be edited here
$knownKeys = ['api', 'api-key', 'schema-probe', 'schema', 'cache-enabled', 'cache-mode', 'cache-max-age-days',
    'cache-on-failure', 'cache-scope', 'data-version', 'lookups-are-public'];

// A sub-setting's value for the form: as posted when the form is being shown again, else as saved
function adif_api_value($source, $form, $key)
{
    if ($form !== null) {
        $value = $form['s'][$key] ?? null;
        return $value === null ? null : (string) $value;
    }
    $value = $source['values'][$key] ?? null;
    if (is_array($value)) {
        $value = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    return $value === null ? null : (string) $value;
}

function adif_api_checked($source, $form, $key)
{
    if ($form !== null) {
        return !empty($form['s'][$key]);
    }
    $value = $source['values'][$key] ?? null;
    return $value === true || $value === 'true' || $value === '1' || $value === 1;
}

// A dropdown from config.json's choices for $key (whose first choice is the default, as in the dialog)
function adif_api_select($configs, $key, $id, $value)
{
    $html = '<select name="s[' . adif_h($key) . ']" id="' . adif_h($id) . '">';
    $choices = $configs[$key]['choices'] ?? [];
    foreach ($choices as $i => $choice) {
        $selected = $value === null ? $i === 0 : (string) $choice['value'] === $value;
        $html .= '<option value="' . adif_h($choice['value']) . '"' . ($selected ? ' selected' : '') . '>'
            . adif_h(strip_tags($choice['name'])) . '</option>';
    }
    return $html . '</select>';
}

// A sub-setting this tab doesn't know, drawn from its config.json row
function adif_api_generic_input($config, $key, $id, $source, $form)
{
    $type = $config['type'] ?? 'text';
    $label = '<label for="' . adif_h($id) . '">' . strip_tags($config['name'] ?? $key, '<b><i><code><strong><em>') . '</label>';
    if ($type === 'checkbox') {
        return '<label class="adif-dt-check"><input type="checkbox" name="s[' . adif_h($key) . ']" value="1" id="' . adif_h($id) . '"'
            . (adif_api_checked($source, $form, $key) ? ' checked' : '') . '> ' . strip_tags($config['name'] ?? $key, '<b><i><code><strong><em>') . '</label>';
    }
    $value = adif_api_value($source, $form, $key);
    if ($type === 'dropdown' || $type === 'radio') {
        $html = '<select name="s[' . adif_h($key) . ']" id="' . adif_h($id) . '"><option value=""></option>';
        foreach ($config['choices'] ?? [] as $choice) {
            $html .= '<option value="' . adif_h($choice['value']) . '"' . ((string) $choice['value'] === $value ? ' selected' : '')
                . '>' . adif_h(strip_tags($choice['name'])) . '</option>';
        }
        return '<div class="adif-form-field">' . $label . $html . '</select></div>';
    }
    if ($type === 'json' || $type === 'textarea') {
        return '<div class="adif-form-field adif-ds-wide">' . $label . '<textarea name="s[' . adif_h($key) . ']" id="' . adif_h($id)
            . '" rows="4">' . adif_h($value) . '</textarea></div>';
    }
    return '<div class="adif-form-field">' . $label . '<input type="text" name="s[' . adif_h($key) . ']" id="' . adif_h($id)
        . '" value="' . adif_h($value) . '"></div>';
}

// "Default (first choice)" text for a dropdown's hint
function adif_api_choice_name($configs, $key, $value)
{
    foreach ($configs[$key]['choices'] ?? [] as $choice) {
        if ((string) $choice['value'] === (string) $value) {
            return strip_tags($choice['name']);
        }
    }
    return (string) $value;
}

// The form for one source ($source null: the add form). $form is the posted form being shown again.
function adif_api_form($module, $configs, $knownKeys, $csrf, $source, $form, $breaks, $cachingOn)
{
    $isNew = $source === null;
    $idx = $isNew ? 'new' : (string) $source['index'];
    $p = 'adif-src-' . $idx . '-';
    $mappedCount = $isNew ? 0 : count($source['mapped']);
    $keyAction = $form !== null ? (string) ($form['api_key_action'] ?? 'keep') : 'keep';
    $cacheOn = adif_api_checked($source, $form, 'cache-enabled');
    $mode = adif_api_value($source, $form, 'cache-mode');
    ob_start();
    ?>
    <form method="post" action="<?= adif_h(adif_ds_url($module, 'apis')) ?>" class="adif-ds-source-form" data-index="<?= adif_h($idx) ?>"
          data-mapped="<?= $mappedCount ?>" data-original-url="<?= adif_h($isNew ? '' : $source['url']) ?>">
        <?= adif_ds_form_fields($csrf, 'apis') ?>
        <input type="hidden" name="adif_action" value="api_save">
        <input type="hidden" name="source_index" value="<?= adif_h($idx) ?>">
        <?php if (!$isNew): ?>
            <input type="hidden" name="source_url" value="<?= adif_h($source['url']) ?>">
        <?php endif; ?>

        <?php if (!empty($breaks)): ?>
            <div class="adif-alert adif-alert-error" role="alert">
                <strong>Saving this would disconnect <?= count($breaks) ?> mapped field<?= count($breaks) === 1 ? '' : 's' ?>.</strong>
                With the new URL or schema they can't find the API field they're mapped to, so imports would skip them
                until they're mapped again:
                <ul class="adif-ds-list">
                    <?php foreach ($breaks as $field => $path): ?>
                        <li><code><?= adif_h($field) ?></code> &larr; <code><?= adif_h($path) ?></code></li>
                    <?php endforeach; ?>
                </ul>
                <label class="adif-dt-check"><input type="checkbox" name="confirm_breaking" value="1"> Save anyway</label>
            </div>
        <?php endif; ?>

        <fieldset class="adif-ds-section">
            <legend>Connection</legend>
            <div class="adif-form-field adif-ds-wide">
                <label for="<?= $p ?>url">URL</label>
                <input type="text" inputmode="url" name="s[api]" id="<?= $p ?>url" required class="adif-ds-url"
                       value="<?= adif_h(adif_api_value($source, $form, 'api')) ?>"
                       placeholder="https://data.cdc.gov/resource/cwsq-ngmh.json">
                <p class="adif-dt-hint">The API's base address. Each field's path is added to the end of it.</p>
                <?php if ($mappedCount > 0): ?>
                    <p class="adif-ds-url-warning" hidden>Changing the URL can disconnect the <?= $mappedCount ?> mapped
                        field<?= $mappedCount === 1 ? '' : 's' ?> that read from this source. You'll be asked to confirm
                        before anything is disconnected.</p>
                <?php endif; ?>
            </div>

            <div class="adif-form-field adif-ds-wide adif-ds-key">
                <span class="adif-ds-label">API key</span>
                <?php if (!$isNew && $source['has_key']): ?>
                    <div class="adif-ds-key-choice">
                        <label class="adif-dt-check"><input type="radio" name="api_key_action" value="keep" <?= $keyAction === 'keep' ? 'checked' : '' ?>> Keep the saved key</label>
                        <label class="adif-dt-check"><input type="radio" name="api_key_action" value="replace" <?= $keyAction === 'replace' ? 'checked' : '' ?>> Replace it</label>
                        <label class="adif-dt-check"><input type="radio" name="api_key_action" value="clear" <?= $keyAction === 'clear' ? 'checked' : '' ?>> Remove it</label>
                    </div>
                    <input type="password" name="api_key" id="<?= $p ?>key" autocomplete="new-password" class="adif-ds-key-input"
                           aria-label="New API key" placeholder="New API key"<?= $keyAction === 'replace' ? '' : ' hidden' ?>>
                    <?php if ($form !== null && $keyAction === 'replace'): ?>
                        <p class="adif-dt-hint">Enter the new key again: keys aren't sent back to the browser.</p>
                    <?php endif; ?>
                <?php else: ?>
                    <input type="hidden" name="api_key_action" value="replace-if-entered">
                    <input type="password" name="api_key" id="<?= $p ?>key" autocomplete="new-password" aria-label="API key"
                           placeholder="Only if this API needs one">
                <?php endif; ?>
                <p class="adif-dt-hint">Sent as <code>Authorization: Bearer &lt;key&gt;</code>. It's stored with the
                    project's module settings and never shown again once saved.</p>
            </div>
        </fieldset>

        <fieldset class="adif-ds-section">
            <legend>Fields offered</legend>
            <div class="adif-form-field adif-ds-wide">
                <label for="<?= $p ?>probe">Endpoint to check for a schema <span class="adif-ds-optional">(optional)</span></label>
                <input type="text" name="s[schema-probe]" id="<?= $p ?>probe" class="adif-ds-probe"
                       value="<?= adif_h(adif_api_value($source, $form, 'schema-probe')) ?>"
                       placeholder="e.g. ?locationid=[census_tract_geoid]">
                <p class="adif-dt-hint">Added to the URL the way a field's path is. It can use <code>[field_name]</code>
                    placeholders, which are filled from the record at import time.</p>
            </div>
            <div class="adif-form-field adif-ds-wide">
                <div class="adif-ds-schema-head">
                    <label for="<?= $p ?>schema">Schema</label>
                    <button type="button" class="btn btn-defaultrc btn-xs adif-ds-detect"
                            title="Read the fields this API offers from the API itself, and fill them in below">Detect schema</button>
                </div>
                <textarea name="s[schema]" id="<?= $p ?>schema" rows="8" class="adif-ds-schema" spellcheck="false"><?= adif_h(adif_api_value($source, $form, 'schema')) ?></textarea>
                <div class="adif-ds-detect-status" aria-live="polite"></div>
                <p class="adif-dt-hint">The fields this source offers on the Mapping page, as JSON: a list of paths, or
                    entries like <code>{"field": "data.value", "endpoint": "Tracts", "path": "?geoid=[geoid]"}</code>.
                    <strong>Detect schema</strong> reads it from an OpenAPI document, a Socrata dataset, or a sample response.</p>
            </div>
        </fieldset>

        <fieldset class="adif-ds-section">
            <legend>Caching</legend>
            <?php if (!$cachingOn): ?>
                <p class="adif-dt-hint">Caching is turned off for this server, so these settings have no effect until a
                    REDCap administrator turns it on. They can still be set now.</p>
            <?php endif; ?>
            <label class="adif-dt-check">
                <input type="checkbox" name="s[cache-enabled]" value="1" class="adif-ds-cache-toggle" <?= $cacheOn ? 'checked' : '' ?>>
                Cache this API's responses: each response is stored as imports request it, and identical requests reuse it
                instead of calling the API again
            </label>
            <div class="adif-ds-cache-options"<?= $cacheOn ? '' : ' hidden' ?>>
                <div class="adif-form-row">
                    <div class="adif-form-field">
                        <label for="<?= $p ?>mode">When should ADIF call this API?</label>
                        <?= adif_api_select($configs, 'cache-mode', $p . 'mode', $mode) ?>
                    </div>
                    <div class="adif-form-field adif-ds-max-age"<?= $mode === 'refresh_if_stale' ? '' : ' hidden' ?>>
                        <label for="<?= $p ?>age">Refresh stored data older than (days)</label>
                        <input type="number" min="1" step="1" name="s[cache-max-age-days]" id="<?= $p ?>age"
                               value="<?= adif_h(adif_api_value($source, $form, 'cache-max-age-days')) ?>">
                    </div>
                    <div class="adif-form-field">
                        <label for="<?= $p ?>failure">If this API is unavailable</label>
                        <?= adif_api_select($configs, 'cache-on-failure', $p . 'failure', adif_api_value($source, $form, 'cache-on-failure')) ?>
                    </div>
                    <div class="adif-form-field">
                        <label for="<?= $p ?>scope">Share stored responses</label>
                        <?= adif_api_select($configs, 'cache-scope', $p . 'scope', adif_api_value($source, $form, 'cache-scope')) ?>
                    </div>
                    <div class="adif-form-field">
                        <label for="<?= $p ?>version">Data version <span class="adif-ds-optional">(optional)</span></label>
                        <input type="text" name="s[data-version]" id="<?= $p ?>version" placeholder="e.g. 2023"
                               value="<?= adif_h(adif_api_value($source, $form, 'data-version')) ?>">
                    </div>
                </div>
                <p class="adif-dt-hint">Changing the data version makes ADIF fetch fresh data instead of reusing what was
                    stored under the old one &mdash; useful for versioned data such as ACS releases.</p>
            </div>
            <label class="adif-dt-check">
                <input type="checkbox" name="s[lookups-are-public]" value="1" <?= adif_api_checked($source, $form, 'lookups-are-public') ? 'checked' : '' ?>>
                The record values piped into this source's <code>[placeholders]</code> are public codes (such as census
                tract IDs), not identifying information like addresses
            </label>
            <p class="adif-dt-hint">Leave this unchecked if unsure: identifying requests are only cached within this
                project, under one-way hashed keys.</p>
        </fieldset>

        <?php $otherKeys = array_diff(array_keys($configs), $knownKeys); ?>
        <?php if (!empty($otherKeys)): ?>
            <fieldset class="adif-ds-section">
                <legend>Other settings</legend>
                <div class="adif-form-row">
                    <?php foreach ($otherKeys as $key): ?>
                        <?= adif_api_generic_input($configs[$key], $key, $p . 'x-' . $key, $source, $form) ?>
                    <?php endforeach; ?>
                </div>
            </fieldset>
        <?php endif; ?>

        <div class="adif-ds-actions">
            <button type="submit" class="btn btn-primaryrc btn-sm"><?= $isNew ? 'Add source' : 'Save' ?></button>
        </div>
    </form>
    <?php
    return ob_get_clean();
}
?>

<p>Each API source is a web address ADIF calls during imports. Its <strong>schema</strong> lists the fields it
    offers, which are matched to REDCap fields on the Mapping page. These are the same settings as in REDCap's
    module configuration dialog; changes made in either place show up in the other.</p>

<?php if ($error): ?>
    <div class="adif-alert adif-alert-error" role="alert"><?= adif_h($error) ?></div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="adif-alert adif-dt-success" role="status"><?= adif_h($success) ?></div>
<?php endif; ?>
<?php foreach ($notices as $notice): ?>
    <div class="adif-alert adif-alert-empty"><?= adif_h($notice) ?></div>
<?php endforeach; ?>

<details class="adif-dt-card adif-ds-source adif-ds-new" id="adif-source-new" <?= empty($sources) || $focus === 'new' ? 'open' : '' ?>>
    <summary><h4>Add an API source</h4></summary>
    <?= adif_api_form($module, $configs, $knownKeys, $csrf, null, $posted['new'] ?? null, [], $cachingOn) ?>
</details>

<?php if (empty($sources)): ?>
    <div class="adif-alert adif-alert-empty">No API sources are configured yet. To use a CSV file instead, upload it on
        the <a href="<?= adif_h($tablesUrl) ?>">Uploaded tables</a> tab.</div>
<?php endif; ?>

<?php foreach ($sources as $position => $source): ?>
    <?php
    $idx = (string) $source['index'];
    $form = $posted[$idx] ?? null;
    $isFocus = $focus === $idx;
    $mapped = $source['mapped'];
    $test = $testResults[$source['index']] ?? null;
    $cached = !empty($source['values']['cache-enabled']) && $source['values']['cache-enabled'] !== 'false';
    $probe = (string) ($source['values']['schema-probe'] ?? '');
    $testPath = $testPaths[$idx] ?? (preg_match('/\[[a-z][a-z0-9_]*\]/', $probe) ? '' : $probe);
    ?>
    <details class="adif-dt-card adif-ds-source" id="adif-source-<?= adif_h($idx) ?>" <?= $isFocus || isset($confirmBreaks[$source['index']]) ? 'open' : '' ?>>
        <summary>
            <span class="adif-ds-position"><?= $position + 1 ?></span>
            <h4 class="adif-ds-source-url"><?= $source['url'] !== '' ? adif_h(\UKModules\ADIF\ADIF::maskApiUrl($source['url'])) : '<em>No URL yet</em>' ?></h4>
            <span class="adif-ds-badges">
                <?php if ($source['schema_error']): ?>
                    <span class="adif-status-pill adif-status-error">Schema invalid</span>
                <?php else: ?>
                    <span class="adif-status-pill adif-status-none"><?= (int) $source['field_count'] ?> field<?= $source['field_count'] === 1 ? '' : 's' ?></span>
                <?php endif; ?>
                <span class="adif-status-pill <?= count($mapped) > 0 ? 'adif-status-ok' : 'adif-status-none' ?>"><?= count($mapped) ?> mapped</span>
                <?php if ($source['has_key']): ?><span class="adif-status-pill adif-status-none">Key saved</span><?php endif; ?>
                <?php if ($cached): ?><span class="adif-status-pill adif-status-stale">Cached</span><?php endif; ?>
            </span>
        </summary>

        <?php if ($source['schema_error']): ?>
            <div class="adif-alert adif-alert-error"><?= adif_h($source['schema_error']) ?></div>
        <?php endif; ?>

        <?php if (!empty($mapped) || !empty($source['lookups'])): ?>
            <div class="adif-ds-usage">
                <?php if (!empty($mapped)): ?>
                    <p><strong>Used by <?= count($mapped) ?> mapped field<?= count($mapped) === 1 ? '' : 's' ?>:</strong>
                        <?= adif_h(implode(', ', array_keys(array_slice($mapped, 0, 20, true)))) ?><?= count($mapped) > 20 ? ', …' : '' ?></p>
                <?php endif; ?>
                <?php if (!empty($source['lookups'])): ?>
                    <p><strong>Placeholders:</strong>
                        <?php $first = true; foreach ($source['lookups'] as $placeholder => $field): ?><?= $first ? '' : ', ' ?><code>[<?= adif_h($placeholder) ?>]</code> from <?= adif_h($field) ?><?php $first = false; endforeach; ?>
                        (chosen on the Mapping page)</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?= adif_api_form($module, $configs, $knownKeys, $csrf, $source, $form, $confirmBreaks[$source['index']] ?? [], $cachingOn) ?>

        <details class="adif-dt-details" <?= $test !== null ? 'open' : '' ?>>
            <summary>Test connection</summary>
            <form method="post" action="<?= adif_h(adif_ds_url($module, 'apis')) ?>" class="adif-form-row">
                <?= adif_ds_form_fields($csrf, 'apis') ?>
                <input type="hidden" name="adif_action" value="api_test">
                <input type="hidden" name="source_index" value="<?= adif_h($idx) ?>">
                <input type="hidden" name="source_url" value="<?= adif_h($source['url']) ?>">
                <div class="adif-form-field adif-ds-wide">
                    <label for="adif-src-<?= adif_h($idx) ?>-test">Path to request <span class="adif-ds-optional">(added to the URL; blank requests the URL itself)</span></label>
                    <input type="text" name="test_path" id="adif-src-<?= adif_h($idx) ?>-test" value="<?= adif_h($testPath) ?>"
                           placeholder="e.g. ?locationid=21067000100">
                </div>
                <button type="submit" class="btn btn-defaultrc btn-sm"<?= $source['url'] === '' ? ' disabled' : '' ?>>Send request</button>
            </form>
            <p class="adif-dt-hint">Uses the saved URL and key, so save any changes first. Replace a
                <code>[placeholder]</code> with a real value, such as a census tract ID.</p>
            <?php if ($test !== null): ?>
                <div class="adif-alert <?= $test['success'] ? 'adif-dt-success' : 'adif-alert-error' ?>">
                    <strong><?= $test['status'] !== null ? 'HTTP ' . adif_h($test['status'] . ' ' . $test['reason']) : 'No response' ?></strong>
                    &middot; <?= adif_h($test['ms']) ?> ms
                    <?php if ($test['bytes'] !== null): ?>&middot; <?= adif_h(adif_bytes($test['bytes'])) ?><?php endif; ?>
                    &middot; <?= $test['is_json'] ? 'JSON' : 'not JSON' ?>
                    <br><code class="adif-ds-break"><?= adif_h($test['url']) ?></code>
                    <?php if ($test['error']): ?><br><?= adif_h($test['error']) ?><?php endif; ?>
                </div>
                <?php if ($test['preview'] !== null && $test['preview'] !== ''): ?>
                    <pre class="adif-ds-preview"><?= adif_h($test['preview']) ?></pre>
                <?php endif; ?>
            <?php endif; ?>
        </details>

        <details class="adif-dt-details">
            <summary>Reorder or delete</summary>
            <div class="adif-ds-manage">
                <?php foreach ([-1 => 'Move up', 1 => 'Move down'] as $direction => $label): ?>
                    <?php $disabled = ($direction < 0 && $position === 0) || ($direction > 0 && $position === count($sources) - 1); ?>
                    <form method="post" action="<?= adif_h(adif_ds_url($module, 'apis')) ?>">
                        <?= adif_ds_form_fields($csrf, 'apis') ?>
                        <input type="hidden" name="adif_action" value="api_move">
                        <input type="hidden" name="source_index" value="<?= adif_h($idx) ?>">
                        <input type="hidden" name="source_url" value="<?= adif_h($source['url']) ?>">
                        <input type="hidden" name="direction" value="<?= $direction ?>">
                        <button type="submit" class="btn btn-defaultrc btn-sm"<?= $disabled ? ' disabled' : '' ?>><?= $label ?></button>
                    </form>
                <?php endforeach; ?>
                <?php
                $deleteMessage = 'Delete source ' . ($position + 1) . ($source['url'] !== '' ? ' (' . $source['url'] . ')' : '') . '?';
                if (!empty($mapped)) {
                    $deleteMessage .= "\n\n" . count($mapped) . ' mapped field(s) read from it and will be skipped on import until'
                        . ' they\'re mapped to another source: ' . implode(', ', array_slice(array_keys($mapped), 0, 10))
                        . (count($mapped) > 10 ? ', …' : '') . '.';
                }
                $deleteMessage .= "\n\nLater sources move up one place, keeping their settings and lookup choices.";
                ?>
                <form method="post" action="<?= adif_h(adif_ds_url($module, 'apis')) ?>" class="adif-dt-delete"
                      onsubmit="return confirm(<?= adif_h(json_encode($deleteMessage)) ?>);">
                    <?= adif_ds_form_fields($csrf, 'apis') ?>
                    <input type="hidden" name="adif_action" value="api_delete">
                    <input type="hidden" name="source_index" value="<?= adif_h($idx) ?>">
                    <input type="hidden" name="source_url" value="<?= adif_h($source['url']) ?>">
                    <button type="submit" class="btn btn-danger btn-sm">Delete source</button>
                </form>
            </div>
            <p class="adif-dt-hint">Order only matters when two sources offer the same field path to a mapping saved
                without an endpoint: the first one is used.</p>
        </details>
    </details>
<?php endforeach; ?>

<script>
    (function () {
        var focus = <?= json_encode($focus !== null ? 'adif-source-' . $focus : null) ?>;
        var card = focus && document.getElementById(focus);
        if (card) {
            card.open = true;
            card.scrollIntoView({block: 'start'});
        }
    })();
</script>
