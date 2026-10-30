<?php

namespace UKModules\ADIF;

// Mixed into UKModules\ADIF\ADIF alongside Proxy. Reads and writes the API sources configured in the
// repeatable "api-sources" project setting (see config.json), for the API connections tab of the Data
// Sources page (pages/Data_Sources.php) - a friendlier front end to the same settings REDCap's module
// configuration dialog edits. Nothing new is stored: every value goes into the same sub-setting the
// dialog would write, so either can be used and each sees the other's changes.
//
// How the framework stores a repeatable group: each sub-setting (api, api-key, schema, ...) is a
// project setting holding an array with one entry per source, by position, and the group's own key
// ("api-sources") holds one `true` per source - that's what the dialog counts to know how many
// sources to show. Writes here keep all of those arrays the same length.
//
// A source's position is also its id elsewhere: the Mapping page's lookup-field choices
// ("lookup-fields", keyed by source index) and the cache's per-source settings. Deleting or moving a
// source therefore moves every sub-setting's entry together and re-keys lookup-fields to match, which
// REDCap's dialog doesn't do. (Cached responses are keyed by URL, so they aren't affected.)
trait ApiSources
{
    private static $apiSourcesGroupKey = 'api-sources';

    // The sub-settings of the "api-sources" group, [key => config.json row], in config.json's order.
    // Read from config.json so a sub-setting added there is carried through saves, deletes and moves
    // (and shown on the page) without changes here.
    public function apiSourceSettingConfigs()
    {
        foreach ($this->getConfig()['project-settings'] ?? [] as $setting) {
            if (($setting['key'] ?? null) === self::$apiSourcesGroupKey) {
                $configs = [];
                foreach ($setting['sub_settings'] ?? [] as $sub) {
                    $configs[$sub['key']] = $sub;
                }
                return $configs;
            }
        }
        throw new \Exception('config.json has no "' . self::$apiSourcesGroupKey . '" setting group.');
    }

    // Every configured source, in order, as the Data Sources page shows it:
    //   index, url, has_key (the key itself is never returned), values ([key => value] for every
    //   sub-setting but api-key), field_count, schema_error (null, or why the saved schema can't be
    //   read), mapped ([field => [form, path, endpoint]] for mapped fields that read from it) and
    //   lookups ([placeholder => REDCap field] chosen on the Mapping page)
    // Includes sources with no URL yet (the dialog allows them), which offer nothing until one is set.
    public function getApiSourceConfigs()
    {
        [$arrays, $count] = $this->apiSourceArrays();
        $mapped = $this->apiSourceMappedFields($this->getApiFields());
        $lookups = $this->getProjectSetting('lookup-fields') ?: [];

        $sources = [];
        for ($index = 0; $index < $count; $index++) {
            $values = [];
            foreach ($arrays as $key => $entries) {
                if ($key !== 'api-key') {
                    $values[$key] = $entries[$index] ?? null;
                }
            }
            $url = trim((string) ($values['api'] ?? ''));
            $schema = $values['schema'] ?? null;

            $schemaError = null;
            if (is_string($schema) && trim($schema) !== '') {
                json_decode($schema, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $schemaError = 'The saved schema isn\'t valid JSON (' . json_last_error_msg() . '), so this source offers no fields.';
                }
            }

            $sources[] = [
                'index' => $index,
                'url' => $url,
                'has_key' => trim((string) ($arrays['api-key'][$index] ?? '')) !== '',
                'values' => $values,
                'field_count' => $url === '' ? 0 : count($this->apiFieldsForSource($index, $url, $schema)),
                'schema_error' => $schemaError,
                'mapped' => $mapped[$index] ?? [],
                'lookups' => is_array($lookups[$index] ?? null) ? $lookups[$index] : [],
            ];
        }

        return $sources;
    }

    // Adds a source ($index null) or saves one. $input is the page's form: 's' => [key => value] for
    // the sub-settings, plus 'api_key_action' (keep | replace | clear) and 'api_key'.
    // $expectedUrl is the source's URL when the page was loaded, so a save can't land on a different
    // source if someone else deleted or moved sources in the meantime.
    // A save that would leave mapped fields unable to find their API field (a changed URL, or fields
    // removed from the schema) isn't made unless $confirmBreaking: it returns 'needs_confirm' with the
    // fields instead. Returns ['status' => 'saved' | 'needs_confirm', 'index', 'breaks', 'notices'].
    public function saveApiSource($index, array $input, $expectedUrl = null, $confirmBreaking = false)
    {
        [$arrays, $count] = $this->apiSourceArrays();
        $isNew = $index === null;
        if ($isNew) {
            $index = $count;
        } else {
            $index = $this->checkApiSourceIndex($arrays, $count, $index, $expectedUrl);
        }

        $values = $this->cleanApiSourceInput($input['s'] ?? [], $arrays, $index, $isNew);

        $key = $isNew ? '' : (string) ($arrays['api-key'][$index] ?? '');
        $keyAction = (string) ($input['api_key_action'] ?? 'keep');
        // 'replace-if-entered' is the form for a source without a key: a key typed in is saved
        $typedKey = trim((string) ($input['api_key'] ?? ''));
        if ($keyAction === 'replace' || (($isNew || $keyAction === 'replace-if-entered') && $typedKey !== '')) {
            $key = trim((string) ($input['api_key'] ?? ''));
            if ($key === '') {
                throw new \Exception('Enter the new API key, or choose to keep the current one.');
            }
        } elseif ($keyAction === 'clear') {
            $key = '';
        }
        $values['api-key'] = $key !== '' ? $key : null;

        // What would break: mapped fields that read from this source and couldn't find their API
        // field any more with the new URL and schema
        $breaks = [];
        if (!$isNew) {
            $breaks = $this->apiSourceBreakage($index, $values['api'], $values['schema'] ?? null);
            if (!empty($breaks) && !$confirmBreaking) {
                return ['status' => 'needs_confirm', 'index' => $index, 'breaks' => $breaks, 'notices' => []];
            }
        }

        foreach ($values as $settingKey => $value) {
            $arrays[$settingKey][$index] = $value;
        }
        $this->writeApiSourceArrays($arrays, max($count, $index + 1));

        $this->logApiSourceChange($isNew ? 'added' : 'saved', $values['api']);

        return ['status' => 'saved', 'index' => $index, 'breaks' => $breaks,
            'notices' => $this->apiSourceNotices($index)];
    }

    // Removes a source, moving every later source up one place: each sub-setting's entries and the
    // Mapping page's lookup-field choices together, so they stay with their source.
    // Returns the mapped fields that read from it, which are skipped on import from now on.
    public function deleteApiSource($index, $expectedUrl)
    {
        [$arrays, $count] = $this->apiSourceArrays();
        $index = $this->checkApiSourceIndex($arrays, $count, $index, $expectedUrl);
        $url = trim((string) ($arrays['api'][$index] ?? ''));
        $breaks = $this->apiSourceBreakage($index, '', null);

        foreach ($arrays as $key => $entries) {
            $list = [];
            for ($i = 0; $i < $count; $i++) {
                $list[] = $entries[$i] ?? null;
            }
            array_splice($list, $index, 1);
            $arrays[$key] = $list;
        }
        $this->writeApiSourceArrays($arrays, $count - 1);

        $this->rekeyApiSourceLookups(function ($i) use ($index) {
            return $i < $index ? $i : ($i === $index ? null : $i - 1);
        });

        $this->logApiSourceChange('deleted', $url);

        return ['breaks' => $breaks];
    }

    // Swaps a source with the one above ($direction -1) or below (+1) it, with its settings and
    // lookup-field choices. Where two sources offer the same field path to a mapping saved without an
    // endpoint, the first one wins, so order can matter; the page says so.
    public function moveApiSource($index, $direction, $expectedUrl)
    {
        [$arrays, $count] = $this->apiSourceArrays();
        $index = $this->checkApiSourceIndex($arrays, $count, $index, $expectedUrl);
        $other = $index + ($direction < 0 ? -1 : 1);
        if ($other < 0 || $other >= $count) {
            throw new \Exception('That source is already ' . ($direction < 0 ? 'first.' : 'last.'));
        }

        foreach ($arrays as $key => $entries) {
            $a = $entries[$index] ?? null;
            $arrays[$key][$index] = $entries[$other] ?? null;
            $arrays[$key][$other] = $a;
        }
        $this->writeApiSourceArrays($arrays, $count);

        $this->rekeyApiSourceLookups(function ($i) use ($index, $other) {
            return $i === $index ? $other : ($i === $other ? $index : $i);
        });

        $this->logApiSourceChange('moved', trim((string) ($arrays['api'][$other] ?? '')));

        return $other;
    }

    // Calls a saved source with its saved key, for the page's Test connection: one request to the
    // source URL plus $path, as an import would send it. $path can't contain [placeholders] - the
    // user replaces them with a real value. Returns what the page shows: url (any key in its query
    // string masked), success, status, reason, ms, bytes, is_json, preview (the start of the body,
    // pretty-printed when it's JSON), error.
    public function testApiSource($index, $path, $expectedUrl)
    {
        [$arrays, $count] = $this->apiSourceArrays();
        $index = $this->checkApiSourceIndex($arrays, $count, $index, $expectedUrl);
        $path = trim((string) $path);
        if (preg_match('/\[([a-z][a-z0-9_]*)\]/', $path, $match)) {
            throw new \Exception("Replace [{$match[1]}] with a real value to test, e.g. a record's value for it.");
        }

        $result = $this->dispatchApiRequest($index, $path);

        $preview = null;
        if ($result['bodyJson'] !== null) {
            $preview = json_encode($result['bodyJson'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } elseif ($result['body'] !== null) {
            $preview = (string) $result['body'];
        }
        if ($preview !== null && strlen($preview) > 4000) {
            $preview = substr($preview, 0, 4000) . "\n…";
        }

        return [
            'url' => self::maskApiUrl((string) $result['requestUrl']),
            'success' => $result['success'],
            'status' => $result['statusCode'],
            'reason' => $result['reasonPhrase'],
            'ms' => $result['elapsedMs'],
            'bytes' => $result['byteSize'],
            'is_json' => $result['bodyJson'] !== null,
            'preview' => $preview,
            'error' => $result['error'] !== null ? self::maskApiUrl((string) $result['error']) : null,
        ];
    }

    // [sub-setting key => [index => value]] for every sub-setting, and the number of sources: the
    // group key's count, or more if any sub-setting has entries past it
    private function apiSourceArrays()
    {
        $group = $this->getProjectSetting(self::$apiSourcesGroupKey);
        $count = is_array($group) ? count($group) : 0;

        $arrays = [];
        foreach (array_keys($this->apiSourceSettingConfigs()) as $key) {
            $value = $this->getProjectSetting($key);
            if (!is_array($value)) {
                // A value saved before the setting was made repeatable belongs to the first source
                $value = $value === null ? [] : [$value];
            }
            $arrays[$key] = $value;
            foreach (array_keys($value) as $i) {
                if (is_int($i) || ctype_digit((string) $i)) {
                    $count = max($count, (int) $i + 1);
                }
            }
        }

        return [$arrays, $count];
    }

    // Writes every sub-setting as a list of exactly $count entries, and the group key to match
    private function writeApiSourceArrays(array $arrays, $count)
    {
        foreach ($arrays as $key => $entries) {
            $list = [];
            for ($i = 0; $i < $count; $i++) {
                $list[] = $entries[$i] ?? null;
            }
            $this->setProjectSetting($key, $list);
        }
        $this->setProjectSetting(self::$apiSourcesGroupKey, array_fill(0, $count, true));
    }

    // Moves lookup-fields' numeric (API source) keys with $map(oldIndex) => newIndex, or null to drop;
    // uploaded tables' "table:<id>" keys are left as they are
    private function rekeyApiSourceLookups(callable $map)
    {
        $lookups = $this->getProjectSetting('lookup-fields') ?: [];
        if (!is_array($lookups) || empty($lookups)) {
            return;
        }

        $rekeyed = [];
        foreach ($lookups as $source => $placeholders) {
            if (is_int($source) || ctype_digit((string) $source)) {
                $new = $map((int) $source);
                if ($new !== null) {
                    $rekeyed[(string) $new] = $placeholders;
                }
            } else {
                $rekeyed[$source] = $placeholders;
            }
        }
        $this->setProjectSetting('lookup-fields', $rekeyed);
    }

    // The index as an int, after checking it's a source and still the one the page showed
    private function checkApiSourceIndex(array $arrays, $count, $index, $expectedUrl)
    {
        if (!is_numeric($index) || (int) $index < 0 || (int) $index >= $count) {
            throw new \Exception('That API source no longer exists. Reload the page.');
        }
        $index = (int) $index;
        if ($expectedUrl !== null && trim((string) ($arrays['api'][$index] ?? '')) !== trim((string) $expectedUrl)) {
            throw new \Exception('The API sources were changed elsewhere (in another tab, or REDCap\'s configuration'
                . ' dialog) since this page loaded, so nothing was saved. Reload the page and try again.');
        }
        return $index;
    }

    // The form's sub-setting values, checked and converted to what the dialog itself would save:
    // trimmed text (null when blank), true/false for checkboxes, a listed choice for dropdowns, and
    // valid JSON for the schema. Sub-settings the form doesn't send keep their saved value, so a
    // setting added to config.json later isn't wiped by an older page.
    private function cleanApiSourceInput(array $input, array $arrays, $index, $isNew)
    {
        $values = [];
        foreach ($this->apiSourceSettingConfigs() as $key => $config) {
            if ($key === 'api-key') {
                continue;   // handled separately: it's never sent to the page
            }
            $type = $config['type'] ?? 'text';
            $label = trim(strip_tags($config['name'] ?? $key));

            if ($type === 'checkbox') {
                $values[$key] = !empty($input[$key]) && $input[$key] !== 'false';
                continue;
            }
            if (!array_key_exists($key, $input)) {
                $values[$key] = $isNew ? null : ($arrays[$key][$index] ?? null);
                continue;
            }

            $value = trim((string) $input[$key]);
            if ($value === '') {
                $values[$key] = null;
                continue;
            }

            if ($type === 'dropdown' || $type === 'radio') {
                $choices = array_map('strval', array_column($config['choices'] ?? [], 'value'));
                if (!in_array($value, $choices, true)) {
                    throw new \Exception("\"$value\" isn't one of the choices for \"$label\".");
                }
            } elseif ($type === 'json') {
                $decoded = json_decode($value, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \Exception('The schema isn\'t valid JSON: ' . json_last_error_msg() . '. Nothing was saved.');
                }
                if (!is_array($decoded)) {
                    throw new \Exception('The schema must be a JSON list of fields, or an object with a "fields" list.');
                }
            } elseif (($config['validation'] ?? null) === 'integer') {
                if (!preg_match('/^\d+$/', $value) || (int) $value < 1) {
                    throw new \Exception("\"$label\" must be a whole number of 1 or more.");
                }
            }
            $values[$key] = $value;
        }

        $url = trim((string) ($values['api'] ?? ''));
        if ($url === '') {
            throw new \Exception('Enter the API\'s URL.');
        }
        // Deliberately loose (not FILTER_VALIDATE_URL, which rejects real API addresses with brackets or
        // underscores in them): a scheme, a host, and no spaces
        if (!preg_match('#^https?://[^\s/?\#]+[^\s]*$#i', $url)) {
            throw new \Exception("\"$url\" isn't a web address. It should start with https:// (or http://).");
        }
        $values['api'] = $url;

        if (($values['cache-mode'] ?? null) === 'refresh_if_stale' && empty($values['cache-max-age-days'])) {
            throw new \Exception('Enter how many days stored data is kept before it\'s refreshed, or choose another'
                . ' option for when ADIF should call this API.');
        }

        return $values;
    }

    // [source index => [field => [form, path, endpoint]]]: which mapped fields read from which API
    // source, resolved the way an import resolves them (Import::findApiField)
    private function apiSourceMappedFields(array $apiFields)
    {
        $bySource = [];
        foreach ($this->getProjectSetting('field-mappings') ?: [] as $form => $fields) {
            foreach ((array) $fields as $field => $config) {
                $path = $config['mapping'] ?? '';
                if ($path === '') {
                    continue;
                }
                $apiField = $this->findApiField($apiFields, $path, $config['endpoint'] ?? null);
                if ($apiField !== null && !$this->isDataTableSource($apiField['source'])) {
                    $bySource[(int) $apiField['source']][$field] = ['form' => $form, 'path' => $path,
                        'endpoint' => $config['endpoint'] ?? null];
                }
            }
        }
        return $bySource;
    }

    // [field => path] for the mapped fields reading from source $index that couldn't find their API
    // field any more if the source had $url and $schema instead (a blank $url: if it were removed).
    // A field another source still answers isn't counted.
    private function apiSourceBreakage($index, $url, $schema)
    {
        $current = $this->getApiFields();
        $mapped = $this->apiSourceMappedFields($current)[$index] ?? [];
        if (empty($mapped)) {
            return [];
        }

        $candidate = array_values(array_filter($current, fn($f) => (string) $f['source'] !== (string) $index));
        if (trim((string) $url) !== '') {
            array_push($candidate, ...$this->apiFieldsForSource($index, $url, $schema));
        }

        $breaks = [];
        foreach ($mapped as $field => $mapping) {
            if ($this->findApiField($candidate, $mapping['path'], $mapping['endpoint']) === null) {
                $breaks[$field] = $mapping['path'];
            }
        }
        return $breaks;
    }

    // Things worth knowing after a save that aren't errors
    private function apiSourceNotices($index)
    {
        [$arrays, $count] = $this->apiSourceArrays();
        $url = rtrim(strtolower(trim((string) ($arrays['api'][$index] ?? ''))), '/');
        $notices = [];

        for ($i = 0; $i < $count; $i++) {
            if ($i !== $index && $url !== '' && rtrim(strtolower(trim((string) ($arrays['api'][$i] ?? ''))), '/') === $url) {
                $notices[] = 'Source ' . ($i + 1) . ' has the same URL. The two share stored responses, and the'
                    . ' cache settings of whichever comes first apply to both.';
            }
        }
        if (!empty($arrays['cache-enabled'][$index]) && !$this->getSystemSetting('adif-cache-enabled')) {
            $notices[] = 'Caching is turned off for this server, so this source\'s cache settings have no effect'
                . ' until a REDCap administrator turns it on.';
        }
        if (($arrays['cache-scope'][$index] ?? null) === 'system'
            && (trim((string) ($arrays['api-key'][$index] ?? '')) !== '' || empty($arrays['lookups-are-public'][$index]))) {
            $notices[] = 'Sharing stored responses with other projects only applies to public data from a source'
                . ' without a key; this source\'s responses are shared within this project instead.';
        }

        return $notices;
    }

    // An audit line in the module log. The URL only, with any key in its query string masked; never
    // the API key setting itself.
    private function logApiSourceChange($action, $url)
    {
        try {
            $this->log("API source: $action", ['url' => self::maskApiUrl((string) $url)]);
        } catch (\Throwable $e) {
            error_log("ADIF: couldn't log an API source change: " . $e->getMessage());
        }
    }

    // A URL (or a message quoting one) with any key-like query parameter's value masked
    public static function maskApiUrl($text)
    {
        return preg_replace('/([?&](?:api[_-]?key|apikey|key|token|access_token|\$\$app_token|app_token)=)[^&#\s]*/i',
            '$1[redacted]', (string) $text);
    }
}
