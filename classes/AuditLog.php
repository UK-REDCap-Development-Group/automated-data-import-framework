<?php

namespace UKModules\ADIF;

use REDCap;

// Mixed into UKModules\ADIF\ADIF. An audit trail of the user actions that change what ADIF imports or
// sends to outside services, or that can overwrite or remove data:
//   - field mappings and lookup fields (the Mapping page)
//   - API sources and uploaded tables (the Data Sources page, and REDCap's module configuration dialog,
//     which edits the same API source settings), and clearing cached API responses
//   - the Import Status page's bulk actions: Refresh all records, Retry all failed and Import all
//   - the module's system settings (Control Center), in the module log only
// Each goes to the project's Logging page (REDCap::logEvent), where REDCap records who did it and when,
// where auditors already look and which users can't edit, and to the module log as
// "ADIF audit: <action>" with the same details as parameters. See docs/audit_log.md.
// Not logged here: individual imports (ImportLog already writes an entry per run), page views, and
// saves that change nothing. The Mapping page saves on every change, so only real changes count.
// Only names, paths and settings are logged: never record values, never API keys (only that one was
// added, replaced or removed) and schemas only as "changed".
// Logging never throws: a logging failure can't undo or block the change it describes.
trait AuditLog
{
    private static $auditMessagePrefix = 'ADIF audit: ';
    private static $auditSnapshotSetting = 'audit-settings-snapshot';
    private static $auditSystemSnapshotSetting = 'audit-system-settings-snapshot';
    private static $auditListLimit = 40;     // items listed per detail, then "... and N more"
    private static $auditTextLimit = 6000;   // characters in one Logging page entry
    // Log parameter names the framework keeps for itself
    private static $auditReservedParameters = ['message', 'timestamp', 'username', 'ui_id', 'ip', 'log_id',
        'external_module_id', 'project_id', 'record'];

    // Writes one audit entry. $details is [label => string | string[]], in the order shown; blank ones
    // are left out. $moduleLog false writes only the Logging page entry, for actions the module log
    // already records in its own format (the Import Status page's bulk actions). Without a project
    // (system settings) only the module log is written.
    public function logAuditEvent($action, array $details = [], $projectId = null, $record = null, $moduleLog = true)
    {
        try {
            $lines = [];
            $parameters = [];
            foreach ($details as $label => $value) {
                if (is_array($value)) {
                    $items = array_values(array_filter(array_map(fn($item) => trim((string) $item), $value), 'strlen'));
                    if (empty($items)) {
                        continue;
                    }
                    $more = count($items) - self::$auditListLimit;
                    $items = array_slice($items, 0, self::$auditListLimit);
                    if ($more > 0) {
                        $items[] = "... and $more more";
                    }
                    $lines[] = "$label:";
                    foreach ($items as $item) {
                        $lines[] = "- $item";
                    }
                    $text = implode("\n", $items);
                } else {
                    $text = trim((string) $value);
                    if ($text === '') {
                        continue;
                    }
                    $lines[] = "$label: $text";
                }
                $name = trim(strtolower(preg_replace('/[^A-Za-z0-9]+/', '_', (string) $label)), '_');
                if ($name === '' || in_array($name, self::$auditReservedParameters, true)) {
                    $name = "detail_$name";
                }
                $parameters[$name] = $text;
            }

            if ($moduleLog) {
                $context = [];
                if ($projectId) {
                    $context['project_id'] = $projectId;
                }
                if ($record !== null && $record !== '') {
                    $context['record'] = $record;
                }
                $this->writeModuleLog(self::$auditMessagePrefix . $action, $context + $parameters);
            }

            if ($projectId) {
                $text = implode("\n", $lines);
                if (mb_strlen($text) > self::$auditTextLimit) {
                    $text = mb_substr($text, 0, self::$auditTextLimit) . "\n... (shortened: the full entry is in the ADIF module log)";
                }
                REDCap::logEvent("ADIF: $action", $text, null, $record, null, $projectId);
            }
        } catch (\Throwable $e) {
            error_log("ADIF: couldn't write the audit entry \"$action\": " . $e->getMessage());
        }
    }

    // ---- Mapping page ----

    // Logs what one save of the Mapping page changed (see scripts/save_mappings.php): forms added to or
    // removed from the page, and field mappings added, changed or removed. Removing a form removes its
    // mappings, so those are listed too. The page saves after every change, so a save usually changes
    // one mapping; a save that changes nothing isn't logged. $uploaded: the save came from Upload
    // Config, which replaces every mapping.
    public function logMappingChanges($projectId, $oldMappings, $newMappings, $oldDisplayed, $newDisplayed, $uploaded = false)
    {
        try {
            $old = self::auditMappedFields($oldMappings);
            $new = self::auditMappedFields($newMappings);

            $added = $changed = $removed = [];
            foreach ($new as $key => $label) {
                if (!isset($old[$key])) {
                    $added[] = "$key = $label";
                } elseif ($old[$key] !== $label) {
                    $changed[] = "$key: {$old[$key]} -> $label";
                }
            }
            foreach ($old as $key => $label) {
                if (!isset($new[$key])) {
                    $removed[] = "$key (was $label)";
                }
            }

            $oldForms = self::auditMappingForms($oldMappings, $oldDisplayed);
            $newForms = self::auditMappingForms($newMappings, $newDisplayed);
            $formsAdded = array_values(array_diff($newForms, $oldForms));
            $formsRemoved = array_values(array_diff($oldForms, $newForms));

            if (!$added && !$changed && !$removed && !$formsAdded && !$formsRemoved) {
                return;
            }
            $this->logAuditEvent($uploaded ? 'mapping configuration uploaded' : 'field mappings changed', [
                'Forms added' => $formsAdded,
                'Forms removed' => $formsRemoved,
                'Mappings added' => $added,
                'Mappings changed' => $changed,
                'Mappings removed' => $removed,
            ], $projectId);
        } catch (\Throwable $e) {
            error_log("ADIF: couldn't log a mapping change: " . $e->getMessage());
        }
    }

    // ["form.field" => "path (from endpoint)"] for the fields a saved mapping maps to something
    private static function auditMappedFields($mappings)
    {
        $mapped = [];
        foreach (is_array($mappings) ? $mappings : [] as $form => $fields) {
            foreach (is_array($fields) ? $fields : [] as $field => $config) {
                $path = is_array($config) ? trim((string) ($config['mapping'] ?? '')) : '';
                if ($path === '') {
                    continue;
                }
                $endpoint = trim((string) ($config['endpoint'] ?? ''));
                $mapped["$form.$field"] = $endpoint !== '' ? "$path (from $endpoint)" : $path;
            }
        }
        return $mapped;
    }

    // The forms shown on the Mapping page: those in the saved mappings and the displayed list
    private static function auditMappingForms($mappings, $displayed)
    {
        $forms = array_merge(array_keys(is_array($mappings) ? $mappings : []), is_array($displayed) ? $displayed : []);
        return array_values(array_unique(array_map('strval', array_filter($forms, 'is_scalar'))));
    }

    // Logs changes to the lookup fields (see scripts/save_lookups.php): which REDCap field's value fills
    // each [placeholder], and so which of a record's values each source is sent or looked up by
    public function logLookupFieldChanges($projectId, $oldLookups, $newLookups)
    {
        try {
            $old = self::auditLookups($oldLookups);
            $new = self::auditLookups($newLookups);

            $changes = [];
            foreach ($new as $key => $field) {
                if (($old[$key] ?? null) === $field) {
                    continue;
                }
                $changes[] = $this->auditLookupName($key) . ': '
                    . (isset($old[$key]) ? "field {$old[$key]} -> $field" : "field $field");
            }
            foreach ($old as $key => $field) {
                if (!isset($new[$key])) {
                    $changes[] = $this->auditLookupName($key) . ": no field chosen any more (was $field)";
                }
            }
            if (!$changes) {
                return;
            }
            $this->logAuditEvent('lookup fields changed', [
                'Changes' => $changes,
                'Note' => 'A lookup field\'s value is sent with each request to an API source, or looked up in an uploaded table.',
            ], $projectId);
        } catch (\Throwable $e) {
            error_log("ADIF: couldn't log a lookup field change: " . $e->getMessage());
        }
    }

    // ["<source>|<placeholder>" => field]
    private static function auditLookups($lookups)
    {
        $flat = [];
        foreach (is_array($lookups) ? $lookups : [] as $source => $placeholders) {
            foreach (is_array($placeholders) ? $placeholders : [] as $placeholder => $field) {
                if (is_string($field) && $field !== '') {
                    $flat["$source|$placeholder"] = $field;
                }
            }
        }
        return $flat;
    }

    // "API source 2 (https://...) [placeholder]" or "uploaded table "Name" [placeholder]"
    private function auditLookupName($key)
    {
        [$source, $placeholder] = explode('|', $key, 2) + [1 => ''];
        $name = "source $source";
        try {
            if (ctype_digit((string) $source)) {
                [$arrays] = $this->apiSourceArrays();
                $name = 'API source ' . ((int) $source + 1)
                    . ' (' . self::auditShown(self::maskApiUrl((string) ($arrays['api'][(int) $source] ?? ''))) . ')';
            } elseif (str_starts_with((string) $source, 'table:')) {
                $id = substr((string) $source, 6);
                $name = 'uploaded table "' . ($this->getDataTables()[$id]['name'] ?? $id) . '"';
            }
        } catch (\Throwable $e) {
            // the plain name will do
        }
        return "$name [$placeholder]";
    }

    // ---- API sources and the module configuration dialog ----

    // The project's module settings as they are now, for telling what a save changed: each API source's
    // settings, and the project's other settings from config.json. API keys and schemas are kept only as
    // fingerprints, and URLs with any key in their query string masked (plus a fingerprint of the full
    // URL, which also tells sources apart when they're reordered or removed).
    public function auditSettingsSnapshot()
    {
        $configs = $this->apiSourceSettingConfigs();
        [$arrays, $count] = $this->apiSourceArrays();

        $sources = [];
        for ($i = 0; $i < $count; $i++) {
            $url = trim((string) ($arrays['api'][$i] ?? ''));
            $source = ['#url' => self::auditFingerprint($url)];
            foreach ($configs as $key => $config) {
                $source[$key] = $key === 'api'
                    ? self::maskApiUrl($url)
                    : self::auditSettingValue($arrays[$key][$i] ?? null, $config);
            }
            $sources[] = $source;
        }

        $settings = [];
        foreach ($this->getConfig()['project-settings'] ?? [] as $config) {
            $key = $config['key'] ?? '';
            if ($key === '' || $key === self::$apiSourcesGroupKey || ($config['type'] ?? '') === 'descriptive') {
                continue;
            }
            $settings[$key] = self::auditSettingValue($this->getProjectSetting($key), $config);
        }

        return ['sources' => $sources, 'settings' => $settings];
    }

    // Takes a snapshot when there's none yet, so a later save in the module configuration dialog has
    // something to be compared with: called where the dialog is opened from (see
    // ADIF::redcap_module_configure_button_display) and on the Data Sources page.
    public function ensureAuditSettingsSnapshot($projectId = null)
    {
        try {
            if ($projectId) {
                if ($this->getProjectSetting(self::$auditSnapshotSetting, $projectId) === null) {
                    $this->setProjectSetting(self::$auditSnapshotSetting, $this->auditSettingsSnapshot(), $projectId);
                }
            } elseif ($this->getSystemSetting(self::$auditSystemSnapshotSetting) === null) {
                $this->setSystemSetting(self::$auditSystemSnapshotSetting, $this->auditSystemSettingsSnapshot());
            }
        } catch (\Throwable $e) {
            error_log("ADIF: couldn't record the module settings for the audit log: " . $e->getMessage());
        }
    }

    // Logs a change to the API sources made on the Data Sources page. $before is auditSettingsSnapshot()
    // from before the change; $details are added to the entry. Nothing is logged when nothing changed.
    private function logApiSourceSave(array $before, array $details = [])
    {
        try {
            $after = $this->auditSettingsSnapshot();
            $this->setProjectSetting(self::$auditSnapshotSetting, $after);
            $changes = $this->auditSettingsChanges($before, $after);
            if ($changes) {
                $this->logAuditEvent('API sources changed', ['Changes' => $changes] + $details, $this->getProjectId());
            }
        } catch (\Throwable $e) {
            error_log("ADIF: couldn't log an API source change: " . $e->getMessage());
        }
    }

    // After a save in REDCap's module configuration dialog (see ADIF::redcap_module_save_configuration),
    // which edits the same API source settings as the Data Sources page, so changes there are logged
    // the same way: compared with the last snapshot. $projectId null: the system settings, logged to the
    // module log only.
    public function logConfigurationDialogSave($projectId)
    {
        try {
            if ($projectId) {
                $before = $this->getProjectSetting(self::$auditSnapshotSetting, $projectId);
                $after = $this->auditSettingsSnapshot();
                $this->setProjectSetting(self::$auditSnapshotSetting, $after, $projectId);
                $where = 'project';
            } else {
                $before = $this->getSystemSetting(self::$auditSystemSnapshotSetting);
                $after = $this->auditSystemSettingsSnapshot();
                $this->setSystemSetting(self::$auditSystemSnapshotSetting, $after);
                $where = 'system';
            }

            if (!is_array($before)) {
                $this->logAuditEvent("$where settings saved in the module configuration dialog", [
                    'Changes' => 'Not listed: ADIF had no earlier copy of the settings to compare with.',
                ], $projectId ?: null);
                return;
            }
            $changes = $this->auditSettingsChanges($before, $after);
            if ($changes) {
                $this->logAuditEvent("$where settings changed in the module configuration dialog",
                    ['Changes' => $changes], $projectId ?: null);
            }
        } catch (\Throwable $e) {
            error_log("ADIF: couldn't log a module configuration save: " . $e->getMessage());
        }
    }

    // The system settings from config.json, in the shape auditSettingsSnapshot() returns
    private function auditSystemSettingsSnapshot()
    {
        $settings = [];
        foreach ($this->getConfig()['system-settings'] ?? [] as $config) {
            $key = $config['key'] ?? '';
            if ($key !== '' && ($config['type'] ?? '') !== 'descriptive') {
                $settings[$key] = self::auditSettingValue($this->getSystemSetting($key), $config);
            }
        }
        return ['sources' => [], 'settings' => $settings];
    }

    // What changed between two snapshots, as lines for the log. Sources are matched by URL rather than by
    // position, so removing or reordering one reads as that, not as every later source changing.
    private function auditSettingsChanges(array $before, array $after)
    {
        $old = array_values($before['sources'] ?? []);
        $new = array_values($after['sources'] ?? []);

        // Pairs [old index => new index]: the same URL first, in order; then a source left unmatched at
        // the same position as an unmatched one, which is a source whose URL was changed
        $pairs = [];
        $unmatched = array_keys($new);
        foreach ($old as $i => $source) {
            foreach ($unmatched as $n => $j) {
                if (($new[$j]['#url'] ?? null) === ($source['#url'] ?? '')) {
                    $pairs[$i] = $j;
                    unset($unmatched[$n]);
                    break;
                }
            }
        }
        foreach (array_keys($old) as $i) {
            if (!isset($pairs[$i]) && ($n = array_search($i, $unmatched, true)) !== false) {
                $pairs[$i] = $i;
                unset($unmatched[$n]);
            }
        }
        ksort($pairs);

        $changes = [];
        foreach ($old as $i => $source) {
            if (!isset($pairs[$i])) {
                $changes[] = 'API source removed: ' . self::auditShown($source['api'] ?? '');
            }
        }
        foreach ($unmatched as $j) {
            $changes[] = 'API source added: ' . self::auditShown($new[$j]['api'] ?? '')
                . (($new[$j]['api-key'] ?? '') !== '' ? ', with an API key' : '');
        }
        $labels = ['api' => 'URL', 'api-key' => 'API key', 'schema' => 'schema', 'schema-probe' => 'schema endpoint'];
        foreach ($pairs as $i => $j) {
            foreach (self::auditValueChanges($old[$i], $new[$j], $labels) as $change) {
                $changes[] = 'API source ' . self::auditShown($new[$j]['api'] ?? '') . ": $change";
            }
        }
        // Order matters where two sources offer the same field (the first one wins), so a reorder is noted
        $order = array_values($pairs);
        $sorted = $order;
        sort($sorted);
        if ($order !== $sorted) {
            $list = [];
            foreach ($new as $j => $source) {
                $list[] = ($j + 1) . '. ' . self::auditShown($source['api'] ?? '');
            }
            $changes[] = 'API sources reordered, now: ' . implode(', ', $list);
        }

        foreach (self::auditValueChanges($before['settings'] ?? [], $after['settings'] ?? []) as $change) {
            $changes[] = "Setting $change";
        }

        return $changes;
    }

    // "label: old -> new" for each value that differs between two [key => value] lists. Fingerprinted
    // values (API keys, schemas) only say they were added, changed or removed.
    private static function auditValueChanges(array $old, array $new, array $labels = [])
    {
        $changes = [];
        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $key) {
            $a = (string) ($old[$key] ?? '');
            $b = (string) ($new[$key] ?? '');
            if ($a === $b) {
                continue;
            }
            if ($key === '#url') {
                // Shown through 'api' unless only a masked key in the URL changed
                if (($old['api'] ?? '') === ($new['api'] ?? '')) {
                    $changes[] = 'the key in its URL changed';
                }
                continue;
            }
            $label = $labels[$key] ?? $key;
            if ($key === 'api') {
                $changes[] = 'URL changed (was ' . self::auditShown($a) . ')';
            } elseif (str_starts_with($a, 'sha256:') || str_starts_with($b, 'sha256:')) {
                $changes[] = $label . ($a === '' ? ' added' : ($b === '' ? ' removed' : ($key === 'api-key' ? ' replaced' : ' changed')));
            } else {
                $changes[] = "$label: " . self::auditShown($a) . ' -> ' . self::auditShown($b);
            }
        }
        return $changes;
    }

    // A setting's value as a snapshot keeps it: secrets and long JSON as a fingerprint, checkboxes as on/off
    private static function auditSettingValue($value, array $config)
    {
        $type = $config['type'] ?? '';
        if ($type === 'checkbox') {
            return !empty($value) ? 'on' : 'off';
        }
        if ($value === null || $value === '' || $value === []) {
            return '';
        }
        if (in_array($type, ['password', 'json', 'textarea', 'rich-text'], true)) {
            return self::auditFingerprint($value);
        }
        return is_scalar($value) ? (string) $value : json_encode($value);
    }

    // A short hash that tells two values apart without keeping either. Only ever compared, never logged.
    private static function auditFingerprint($value)
    {
        if ($value === null || $value === '') {
            return '';
        }
        return 'sha256:' . substr(hash('sha256', is_string($value) ? $value : json_encode($value)), 0, 16);
    }

    private static function auditShown($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '(blank)';
        }
        return mb_strlen($value) > 120 ? mb_substr($value, 0, 117) . '...' : $value;
    }

    // [field => path] from apiSourceBreakage(), as "field (path)" lines
    private static function auditBrokenFields(array $breaks)
    {
        $lines = [];
        foreach ($breaks as $field => $path) {
            $lines[] = "$field ($path)";
        }
        return $lines;
    }

    // ---- Uploaded tables ----

    // Logs a change to an uploaded table made on the Data Sources page (see pages/data_sources/tables.php).
    // $before is the table as it was (null for an upload), $after as it is now.
    public function logDataTableChange($action, $before, array $after)
    {
        $name = (string) ($after['name'] ?? '');
        $projectId = $this->getProjectId();
        switch ($action) {
            case 'upload':
                $this->logAuditEvent('uploaded table added', [
                    'Table' => $name,
                    'File' => $after['filename'] ?? '',
                    'Rows' => (string) ($after['row_count'] ?? ''),
                ], $projectId);
                break;

            case 'replace':
                $this->logAuditEvent('uploaded table file replaced', [
                    'Table' => $name,
                    'File' => (isset($before['filename']) ? "{$before['filename']} -> " : '') . ($after['filename'] ?? ''),
                    'Rows' => (isset($before['row_count']) ? "{$before['row_count']} -> " : '') . ($after['row_count'] ?? ''),
                    'Effect' => 'Imports read the new file from now on.',
                ], $projectId);
                break;

            case 'settings':
                $pick = fn($table) => [
                    'key_column' => (string) ($table['key_column'] ?? ''),
                    'placeholder' => (string) ($table['placeholder'] ?? ''),
                    'layout' => (string) ($table['layout'] ?? ''),
                    'measure_column' => (string) ($table['measure_column'] ?? ''),
                    'loose_match' => !empty($table['loose_match']) ? 'on' : 'off',
                ];
                $changes = self::auditValueChanges($pick($before ?? []), $pick($after), [
                    'key_column' => 'rows looked up by column', 'placeholder' => 'placeholder',
                    'layout' => 'layout', 'measure_column' => 'rows told apart by column', 'loose_match' => 'loose matching',
                ]);
                if ($changes) {
                    $this->logAuditEvent('uploaded table settings changed', ['Table' => $name, 'Changes' => $changes], $projectId);
                }
                break;

            case 'delete':
                $this->logAuditEvent('uploaded table deleted', [
                    'Table' => $name,
                    'File' => $after['filename'] ?? '',
                    'Effect' => 'Fields mapped from it are skipped on import until they\'re mapped to another source, '
                        . 'and its lookup field choices were removed.',
                ], $projectId);
                break;
        }
    }
}
