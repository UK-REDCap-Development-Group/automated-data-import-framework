<?php

namespace UKModules\ADIF;

use REDCap;

// Mixed into UKModules\ADIF\ADIF alongside Proxy. Takes the saved "field-mappings" for a project,
// calls each API endpoint those mappings point at, and saves the mapped values into the record.
trait Import
{
    // Field types that can't (or shouldn't) be written through saveData
    private static $unwritableFieldTypes = ['descriptive', 'calc', 'file'];

    // Pulls every mapped API value for $record and saves it. Mappings for instruments that aren't
    // designated in $eventId are skipped, as are repeating instruments other than $instrument, since
    // there's no way to know which of their instances the data belongs in (see importPlan()).
    // $modeOverride (a Cache\FetchMode, or null) is passed to the cache for every request, so a
    // manual retry can force fresh API calls instead of stored data (see ImportStatus::retryImportRun).
    // $plan is importPlan()'s result when the caller has already worked it out (the retry checks it
    // against the run it's retrying first); null works it out here from the current mappings.
    // Returns a summary of what was saved and what was skipped, which the caller logs (see ImportLog):
    //   saved / skipped / errors / notices - lists of fields or messages
    //   not_populated - [field => error] for mapped fields left empty because their request or the save failed
    //   requests      - one entry per endpoint request: source, path, outcome, the HTTP calls' metadata
    //                   and which fields it saved, skipped or couldn't populate (see importRequestLog())
    public function importMappedData($projectId, $record, $eventId, $instrument = null, $repeatInstance = 1, $modeOverride = null, $plan = null)
    {
        $summary = ['saved' => [], 'skipped' => [], 'errors' => [], 'notices' => [], 'not_populated' => [], 'requests' => []];

        $plan = $plan ?? $this->importPlan($projectId, $eventId, $instrument, $repeatInstance);
        $summary['skipped'] = $plan['skipped'];
        $requests = $plan['requests'];
        if (empty($requests)) {
            return $summary;
        }

        // Read from the project itself rather than REDCap::getRecordIdField() and friends, which only
        // work on a project's own pages: queued imports run from a cron (see ImportQueue)
        $dictionary = REDCap::getDataDictionary($projectId, 'array');
        $project = new \Project($projectId);
        $recordIdField = $project->table_pk;
        $eventName = self::importUniqueEventName($project, $eventId);

        // Rows keyed by repeat instrument + instance, since saveData takes one row per instance
        $rows = [];
        foreach ($requests as $request) {
            $start = microtime(true);
            $log = $this->importRequestLog($request);
            // An error leaves every field this request would have filled empty
            $fail = function ($error) use (&$summary, &$log, $request) {
                $log['error'] = $error;
                foreach ($request['fields'] as $target) {
                    $log['fields_not_populated'][] = $target['field'];
                    $summary['not_populated'][$target['field']] = $error;
                }
                $summary['errors'][] = "{$log['source']} {$log['path']}: $error";
            };

            $apiPath = $request['path'];
            $pipedValues = [];
            $pipeNotices = [];
            try {
                $apiPath = $this->pipeRecordValues($projectId, $record, $eventId, $request['path'], $request['source'],
                    $dictionary, $pipedValues, $plan['lookup_context'] ?? null, $pipeNotices);
                array_push($summary['notices'], ...$pipeNotices);
                // Goes through the cache (see ApiCache): when the server and the project both cache this
                // source it may be answered locally; otherwise it's sent to the API.
                $fetched = $this->fetchApiJson($projectId, $record, $request['source'], $apiPath, $modeOverride);
            } catch (\Throwable $e) {
                $fail($this->withoutLookupValues($e->getMessage(), $apiPath, $request['path'], $pipedValues));
                $log['elapsed_ms'] = round((microtime(true) - $start) * 1000, 1);
                $summary['requests'][] = $log;
                continue;
            }

            // Identifying values (an address) stay out of the log: it keeps the [placeholder] path instead
            if (empty($fetched['identifying'])) {
                $log['path'] = $apiPath;
            }
            $log['outcome'] = $fetched['status'];
            $log['calls'] = $fetched['calls'];
            array_push($summary['notices'], ...$fetched['notices']);

            if ($fetched['json'] === null) {
                $lastCall = end($fetched['calls']) ?: [];
                $fail(($lastCall['error'] ?? null)
                    ?: $this->withoutLookupValues($fetched['message'] ?: 'no data', $apiPath, $request['path'], $pipedValues));
                $log['elapsed_ms'] = round((microtime(true) - $start) * 1000, 1);
                $summary['requests'][] = $log;
                continue;
            }

            foreach ($request['fields'] as $target) {
                $field = $target['field'];
                $value = $this->resolveApiPath($fetched['json'], $target['path']);
                if ($value === null || $value === '') {
                    $summary['skipped'][] = "$field: no value at \"{$target['path']}\"";
                    $log['fields_skipped'][] = "$field: no value at \"{$target['path']}\"";
                    continue;
                }

                $values = $this->toRedcapValues($dictionary[$field], $value);
                if ($values === null) {
                    // Logged without the value itself, since API responses can echo identifying input
                    $summary['skipped'][] = "$field: the API's value doesn't match any of the field's choices";
                    $log['fields_skipped'][] = "$field: the API's value doesn't match any of the field's choices";
                    continue;
                }

                $rowKey = $target['repeatInstrument'] . '|' . $target['repeatInstance'];
                if (!isset($rows[$rowKey])) {
                    $rows[$rowKey] = [$recordIdField => $record];
                    if ($eventName !== null) {
                        $rows[$rowKey]['redcap_event_name'] = $eventName;
                    }
                    if ($target['repeatInstance'] !== null) {
                        $rows[$rowKey]['redcap_repeat_instrument'] = $target['repeatInstrument'];
                        $rows[$rowKey]['redcap_repeat_instance'] = $target['repeatInstance'];
                    }
                }
                $rows[$rowKey] += $values;
                $summary['saved'][] = $field;
                $log['fields_saved'][] = $field;
            }

            $log['elapsed_ms'] = round((microtime(true) - $start) * 1000, 1);
            $summary['requests'][] = $log;
        }

        if (!empty($rows)) {
            $saveResult = REDCap::saveData($projectId, 'json', json_encode(array_values($rows)), 'normal');
            if (!empty($saveResult['errors'])) {
                $error = 'saveData: ' . implode('; ', (array) $saveResult['errors']);
                $summary['errors'][] = $error;
                // saveData is all or nothing, so none of the fields fetched for it were populated
                foreach ($summary['saved'] as $field) {
                    $summary['not_populated'][$field] = $error;
                }
                foreach ($summary['requests'] as &$requestLog) {
                    array_push($requestLog['fields_not_populated'], ...$requestLog['fields_saved']);
                    $requestLog['fields_saved'] = [];
                }
                unset($requestLog);
                $summary['saved'] = [];
            }
        }

        return $summary;
    }

    // What an import into $eventId (from $instrument, instance $repeatInstance) would request under
    // the project's current mappings, by the rules importMappedData() applies:
    //   requests  - one per distinct (source, path) pair, no matter how many fields read from it:
    //               [source, path, fields => [[field, path, repeatInstrument, repeatInstance], ...]]
    //   skipped   - "name: reason" lines for mappings that can't be imported, as the summary reports them
    //   unusable  - [field => reason] for mapped fields that can't be imported, by field
    //   lookup_context - where the import is happening, for reading [placeholder] values from the
    //               right event and instance (see importLookupContext() and pipeRecordValues())
    // Only reads settings and metadata - nothing is fetched or saved - so the retry can check a run
    // against it before re-running anything (see ImportStatus::importRetryMappingCheck).
    public function importPlan($projectId, $eventId, $instrument = null, $repeatInstance = 1)
    {
        $plan = ['requests' => [], 'skipped' => [], 'unusable' => [], 'lookup_context' => null];

        $mappings = $this->getProjectSetting('field-mappings', $projectId) ?: [];
        if (empty($mappings)) {
            return $plan;
        }

        $project = new \Project($projectId);
        $dictionary = REDCap::getDataDictionary($projectId, 'array');
        $apiFields = $this->getApiFields();
        $plan['lookup_context'] = $this->importLookupContext($project, $eventId, $instrument, $repeatInstance);

        foreach ($mappings as $form => $fields) {
            if (!isset($project->eventsForms[$eventId]) || !in_array($form, $project->eventsForms[$eventId])) {
                continue;
            }

            $repeatInstrument = '';
            if ($project->isRepeatingForm($eventId, $form)) {
                if ($form !== $instrument) {
                    $plan['skipped'][] = "$form: repeating instrument other than the submitted one";
                    foreach (array_keys((array) $fields) as $field) {
                        $plan['unusable'][$field] = "its instrument ($form) repeats, and isn't the one this import is for";
                    }
                    continue;
                }
                $repeatInstrument = $form;
            }
            $isRepeating = $repeatInstrument !== '' || $project->isRepeatingEvent($eventId);

            foreach ((array) $fields as $field => $config) {
                $path = $config['mapping'] ?? '';
                if ($path === '' || !isset($dictionary[$field])) {
                    continue;
                }
                if (in_array($dictionary[$field]['field_type'], self::$unwritableFieldTypes)) {
                    $reason = "{$dictionary[$field]['field_type']} fields can't be imported into";
                    $plan['skipped'][] = "$field: $reason";
                    $plan['unusable'][$field] = $reason;
                    continue;
                }

                $apiField = $this->findApiField($apiFields, $path, $config['endpoint'] ?? null);
                if ($apiField === null) {
                    $reason = "\"$path\" is no longer offered by any configured API source";
                    $plan['skipped'][] = "$field: $reason";
                    $plan['unusable'][$field] = $reason;
                    continue;
                }

                $key = $apiField['source'] . '|' . $apiField['path'];
                $plan['requests'][$key]['source'] = $apiField['source'];
                $plan['requests'][$key]['path'] = $apiField['path'];
                $plan['requests'][$key]['fields'][] = [
                    'field' => $field,
                    'path' => $path,
                    'repeatInstrument' => $repeatInstrument,
                    'repeatInstance' => $isRepeating ? (int) $repeatInstance : null,
                ];
            }
        }

        return $plan;
    }

    // The log entry for one endpoint request, filled in as the request is made. 'path' starts as the
    // schema's path with its [placeholders], and is replaced with the piped path only for public sources.
    private function importRequestLog($request)
    {
        return [
            'source' => $this->importSourceLabel($request['source']),
            'path' => $request['path'],
            'outcome' => 'not_sent',   // or FetchResult::* (hit, fetched, stale, miss, error)
            'calls' => [],
            'error' => null,
            'elapsed_ms' => null,
            'fields_requested' => array_column($request['fields'], 'field'),
            'fields_saved' => [],
            'fields_skipped' => [],
            'fields_not_populated' => [],
        ];
    }

    // An API source's base URL (with any key in its query string masked), or a table's name
    private function importSourceLabel($source)
    {
        try {
            if ($this->isDataTableSource($source)) {
                return $this->dataTableEndpoint($this->getDataTable($this->dataTableIdFromSource($source)));
            }
            return preg_replace('/([?&](?:api[_-]?key|apikey|key|token|access_token|\$\$app_token|app_token)=)[^&#]*/i',
                '$1[redacted]', $this->getApiSource($source)['url']);
        } catch (\Throwable $e) {
            return "source $source";
        }
    }

    // Takes the record's piped lookup values back out of a message before it's logged, in case it
    // quotes the request URL: the piped path becomes its [placeholder] form, and stray values are masked.
    private function withoutLookupValues($message, $pipedPath, $templatePath, $values)
    {
        $message = (string) $message;
        if ($pipedPath !== $templatePath && $pipedPath !== '') {
            $message = str_replace($pipedPath, $templatePath, $message);
        }
        // Longest first, so a value inside another isn't masked piecemeal; very short values
        // (a state code) would mask unrelated text, and aren't identifying on their own.
        usort($values, fn($a, $b) => strlen($b) <=> strlen($a));
        foreach ($values as $value) {
            if (strlen((string) $value) >= 4) {
                $message = str_replace([rawurlencode($value), urlencode($value), $value], '[redacted]', $message);
            }
        }
        return $message;
    }

    // Finds the schema entry a saved mapping points at. Mappings saved before endpoints were
    // recorded only carry the path, so fall back to the first source offering it.
    private function findApiField($apiFields, $path, $endpoint)
    {
        $fallback = null;
        foreach ($apiFields as $apiField) {
            if ($apiField['field'] !== $path) {
                continue;
            }
            if ($endpoint === null || $endpoint === '' || $apiField['endpoint'] === $endpoint) {
                return $apiField;
            }
            $fallback = $fallback ?? $apiField;
        }

        return ($endpoint === null || $endpoint === '') ? $fallback : null;
    }

    // The REDCap field chosen on the Mapping page to supply a source's [placeholder] (see
    // scripts/save_lookups.php). Without a choice, a field with the placeholder's own name is used.
    public function getLookupField($sourceIndex, $placeholder)
    {
        $lookups = $this->getProjectSetting('lookup-fields') ?: [];
        $field = $lookups[$sourceIndex][$placeholder] ?? '';

        return $field !== '' ? $field : $placeholder;
    }

    // Where an import is happening, so a [placeholder]'s value is read from the right row of the
    // record (see importLookupValue()): the event's unique name (null in a classic project), the
    // instrument and instance being imported, the forms designated in the event, which of them are
    // repeating instruments there, and whether the event itself repeats.
    private function importLookupContext($project, $eventId, $instrument, $repeatInstance)
    {
        $forms = array_values($project->eventsForms[$eventId] ?? []);
        return [
            'event_name' => self::importUniqueEventName($project, $eventId),
            'instrument' => (string) $instrument,
            'instance' => max(1, (int) $repeatInstance),
            'event_forms' => $forms,
            'repeating_forms' => array_values(array_filter($forms, fn($form) => $project->isRepeatingForm($eventId, $form))),
            'event_repeats' => (bool) $project->isRepeatingEvent($eventId),
        ];
    }

    // An event's unique name, or null in a classic project (whose export rows carry no event name).
    // Works from a cron as well as a project page, unlike REDCap::isLongitudinal()/getEventNames().
    private static function importUniqueEventName($project, $eventId)
    {
        if (!$project->longitudinal) {
            return null;
        }
        $names = $project->getUniqueEventNames();
        return is_array($names) ? ($names[(int) $eventId] ?? null) : null;
    }

    // Replaces [placeholder]s in an endpoint path with this record's values, e.g. "/people/[email]"
    // becomes "/people/jane%40example.com". Each placeholder is read from its lookup field, in the row
    // importLookupValue() picks for where the import is happening ($context, from importPlan()).
    // $values receives [placeholder => value], so callers can keep the values out of what they log,
    // and $notices any note worth logging about which row a value came from.
    private function pipeRecordValues($projectId, $record, $eventId, $apiPath, $sourceIndex, $dictionary, &$values = [],
                                      $context = null, &$notices = [])
    {
        if (!preg_match_all('/\[([a-z][a-z0-9_]*)\]/', $apiPath, $matches)) {
            return $apiPath;
        }

        $fieldFor = [];
        foreach (array_unique($matches[1]) as $placeholder) {
            $field = $this->getLookupField($sourceIndex, $placeholder);
            if (!isset($dictionary[$field])) {
                throw new \Exception("[$placeholder] is looked up from the field \"$field\", which isn't in this project."
                    . " Choose its lookup field on the Mapping page.");
            }
            $fieldFor[$placeholder] = $field;
        }

        // Every event and instance: a lookup value such as an address is often collected in another
        // event (e.g. baseline) than the one being imported into
        $data = json_decode(REDCap::getData([
            'project_id' => $projectId,
            'return_format' => 'json',
            'records' => [$record],
            'fields' => array_values(array_unique($fieldFor)),
        ]), true) ?: [];

        $context = $context ?? $this->importLookupContext(new \Project($projectId), $eventId, null, 1);

        $values = [];
        foreach ($fieldFor as $placeholder => $field) {
            $values[$placeholder] = $this->importLookupValue($data, $field, (string) ($dictionary[$field]['form_name'] ?? ''),
                $context, $placeholder, $record, $notices);
        }

        return preg_replace_callback('/\[([a-z][a-z0-9_]*)\]/', function ($match) use ($values) {
            return rawurlencode($values[$match[1]]);
        }, $apiPath);
    }

    // Picks a lookup field's value from the record's rows (getData() returns one per event, and one
    // per instance of a repeating instrument or event), for an import in $context:
    //   1. The lookup field is on the instrument being imported, and that instrument repeats: only the
    //      same instance is used. Another instance's value would be another address.
    //   2. The event being imported into repeats: only the same instance of the event is used.
    //   3. Otherwise the event's own (non-repeating) value, then another event's - e.g. an address
    //      collected at baseline - as before repeating instruments were handled.
    //   4. Failing those, the field is on a repeating instrument or event other than the one being
    //      imported, so there's no matching instance: the most recent instance (highest number) is
    //      used, and a notice says which.
    // Throws when there's no value to use. Messages name fields and instances, never values.
    private function importLookupValue(array $rows, $field, $form, array $context, $placeholder, $record, array &$notices)
    {
        $where = $field === $placeholder ? "[$field]" : "[$placeholder] (field $field)";
        $sameEvent = fn($row) => $context['event_name'] === null || ($row['redcap_event_name'] ?? null) === $context['event_name'];
        $kind = function ($row) {
            if ((string) ($row['redcap_repeat_instance'] ?? '') === '') {
                return 'plain';
            }
            return (string) ($row['redcap_repeat_instrument'] ?? '') !== '' ? 'instrument' : 'event';
        };
        $filled = array_values(array_filter($rows, fn($row) => isset($row[$field]) && (string) $row[$field] !== ''));
        $instance = $context['instance'];
        $formHere = in_array($form, $context['event_forms'], true);

        if ($formHere && in_array($form, $context['repeating_forms'], true) && $form === $context['instrument']) {
            foreach ($filled as $row) {
                if ($sameEvent($row) && $kind($row) === 'instrument' && $row['redcap_repeat_instrument'] === $form
                    && (int) $row['redcap_repeat_instance'] === $instance) {
                    return (string) $row[$field];
                }
            }
            throw new \Exception("Record $record has no value for $where in instance $instance of $form, which the"
                . ' endpoint needs.');
        }

        if ($formHere && $context['event_repeats'] && !in_array($form, $context['repeating_forms'], true)) {
            foreach ($filled as $row) {
                if ($sameEvent($row) && $kind($row) === 'event' && (int) $row['redcap_repeat_instance'] === $instance) {
                    return (string) $row[$field];
                }
            }
            throw new \Exception("Record $record has no value for $where in instance $instance of this event, which the"
                . ' endpoint needs.');
        }

        // The event's own value first, then other events', in the order REDCap returns them
        foreach ([true, false] as $inThisEvent) {
            foreach ($filled as $row) {
                if ($kind($row) === 'plain' && $sameEvent($row) === $inThisEvent) {
                    return (string) $row[$field];
                }
            }
        }

        // Only on a repeating instrument or event other than the one being imported: the latest instance
        $latest = null;
        foreach ($filled as $row) {
            if ($kind($row) !== 'plain' && ($latest === null || (int) $row['redcap_repeat_instance'] >= (int) $latest['redcap_repeat_instance'])) {
                $latest = $row;
            }
        }
        if ($latest !== null) {
            $from = $kind($latest) === 'instrument' ? $latest['redcap_repeat_instrument'] : 'its repeating event';
            if (!empty($latest['redcap_event_name']) && $latest['redcap_event_name'] !== $context['event_name']) {
                $from .= " in {$latest['redcap_event_name']}";
            }
            $notices[] = "$where was read from instance {$latest['redcap_repeat_instance']} of $from, the most recent,"
                . ' since it\'s on a repeating instrument or event other than the one being imported.';
            return (string) $latest[$field];
        }

        throw new \Exception("Record $record has no value for $where, which the endpoint needs.");
    }

    // Splits a mapping path into segments, matching apiPathSegments() on the Mapping page:
    // dots separate segments, and brackets are kept whole (a backslash escapes the next character).
    private function apiPathSegments($path)
    {
        $segments = [];
        $buffer = '';
        $length = strlen($path);

        for ($position = 0; $position < $length; $position++) {
            $character = $path[$position];

            if ($character === '.') {
                if ($buffer !== '') $segments[] = $buffer;
                $buffer = '';
                continue;
            }

            if ($character === '[') {
                if ($buffer !== '') $segments[] = $buffer;
                $buffer = $character;

                $position++;
                while ($position < $length && $path[$position] !== ']') {
                    if ($path[$position] === '\\' && $position + 1 < $length) {
                        $buffer .= $path[$position];
                        $position++;
                    }
                    $buffer .= $path[$position];
                    $position++;
                }

                $segments[] = $buffer . ']';
                $buffer = '';
                continue;
            }

            $buffer .= $character;
        }

        if ($buffer !== '') $segments[] = $buffer;

        return $segments;
    }

    // Walks a decoded API response along a mapping path and returns the value found there.
    //   name          - a key of the current object
    //   [0]           - an entry of a list by position
    //   []            - every entry of a list
    //   [key=value]   - the entries of a list whose key equals value
    // When a path matches several values they're joined with "; ", as the Mapping page promises.
    public function resolveApiPath($data, $path)
    {
        $nodes = [$data];

        foreach ($this->apiPathSegments($path) as $segment) {
            $next = [];
            $isBracket = $segment[0] === '[';
            $inner = $isBracket ? stripslashes(substr($segment, 1, -1)) : $segment;

            foreach ($nodes as $node) {
                if (!is_array($node)) {
                    continue;
                }

                if ($isBracket && $inner === '') {
                    if (array_is_list($node)) {
                        array_push($next, ...$node);
                    }
                } elseif ($isBracket && strpos($inner, '=') !== false) {
                    [$key, $expected] = explode('=', $inner, 2);
                    foreach (array_is_list($node) ? $node : [$node] as $entry) {
                        if (is_array($entry) && isset($entry[$key]) && (string) $entry[$key] === $expected) {
                            $next[] = $entry;
                        }
                    }
                } elseif (array_key_exists($inner, $node)) {
                    $next[] = $node[$inner];
                }
            }

            $nodes = $next;
        }

        $values = [];
        foreach ($nodes as $node) {
            if (is_bool($node)) {
                $values[] = $node ? '1' : '0';
            } elseif (is_scalar($node) && (string) $node !== '') {
                $values[] = (string) $node;
            }
        }

        return empty($values) ? null : implode('; ', $values);
    }

    // Converts an API value into what saveData expects for this field. Multiple-choice fields accept
    // either a choice's code or its label (case-insensitive); checkboxes take a "; " separated list.
    // Returns [field => value, ...], or null when a choice field's value matches none of its choices.
    private function toRedcapValues($fieldInfo, $value)
    {
        $field = $fieldInfo['field_name'];
        $type = $fieldInfo['field_type'];

        if ($type === 'yesno') {
            $choices = ['1' => 'Yes', '0' => 'No'];
        } elseif ($type === 'truefalse') {
            $choices = ['1' => 'True', '0' => 'False'];
        } elseif (in_array($type, ['radio', 'dropdown', 'checkbox'])) {
            $choices = $this->parseChoices($fieldInfo['select_choices_or_calculations']);
        } else {
            return [$field => $value];
        }

        $findCode = function ($candidate) use ($choices) {
            $candidate = trim($candidate);
            foreach ($choices as $code => $label) {
                if ((string) $code === $candidate || strcasecmp(strip_tags($label), $candidate) === 0) {
                    return (string) $code;
                }
            }
            return null;
        };

        if ($type !== 'checkbox') {
            $code = $findCode($value);
            return $code === null ? null : [$field => $code];
        }

        $values = [];
        foreach (explode(';', $value) as $candidate) {
            $code = $findCode($candidate);
            if ($code === null) {
                return null;
            }
            $values[$field . '___' . $code] = '1';
        }

        return $values;
    }

    // Parses a data dictionary choice string ("1, Yes | 2, No") into [code => label]
    private function parseChoices($choiceString)
    {
        $choices = [];
        foreach (explode('|', (string) $choiceString) as $choice) {
            $parts = explode(',', $choice, 2);
            if (count($parts) === 2) {
                $choices[trim($parts[0])] = trim($parts[1]);
            }
        }

        return $choices;
    }
}
