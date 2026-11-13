<?php

namespace UKModules\ADIF;

use REDCap;

// Mixed into UKModules\ADIF\ADIF alongside Import and ImportLog. Reads back what ImportLog wrote to
// the module log, so a failed import is visible somewhere other than the raw log: which runs left
// mapped fields empty, which record and event they belong to, and whether they need running again.
// Also re-runs those imports (retryImportRun): the manual option for a run that needs attention.
// Only metadata is handled - the same scrubbed entries logImportRun() wrote (no URLs, no values).
trait ImportStatus
{
    // Every import run's log entries share these (see ImportLog::logImportRun): each endpoint request
    // is logged with the exact message below, and the summary entry's message is the run's
    // description, e.g. "API import after survey completion". A manual retry logs as
    // "API import: manual retry by <user>" (or, from Retry all failed, "API import: retry all failed,
    // requested by <user>") and is picked up here automatically.
    private static $importLogPrefix = 'API import';
    private static $importRequestLogMessage = 'API import: endpoint request';

    // A run flagged "Needs attention" can be dismissed by hand (see dismissImportRun), so a failure
    // nobody intends to fix stops being flagged. Each dismissal, and each undo, is its own module log
    // entry carrying the run's run_id, with who did it and when; the latest one for a run wins. The
    // messages deliberately don't start with $importLogPrefix, so they're never read as part of a run.
    private static $importDismissMessage = 'ADIF import run dismissed';
    private static $importUndismissMessage = 'ADIF import run dismissal undone';
    private static $importDismissReasonLength = 500;

    // How long a retry's lock is held (seconds). A second retry of the same run while the first is
    // still going is rejected, so two clicks can't interleave two imports of the same data.
    private static $importRetryLockSeconds = 60;

    // Columns of a getData() row that say where the row is rather than holding the record's data
    private static $importRowLocationColumns = ['redcap_event_name', 'redcap_repeat_instrument', 'redcap_repeat_instance',
        'redcap_data_access_group', 'redcap_survey_identifier'];

    // The project's import runs, newest first, each put together from the module log entries that
    // share a run_id (one per endpoint request, then a summary - see ImportLog::logImportRun):
    //   run_id, logged (unix time), user, record, event_id, event_name, instrument, repeat_instance,
    //   description, requests (count), api_calls, elapsed_ms, saved, skipped, errors, notices,
    //   not_populated, request_logs (the per-endpoint entries), outcome and explanation (see below),
    //   dismissed (null, or [user, at, reason] when the run's "Needs attention" was dismissed, in
    //   which case outcome is 'dismissed' - see importApplyDismissals)
    // $filters accepts 'record' (one record's runs only) and 'limit' (how many runs, default 50).
    public function importRuns($projectId, $filters = [])
    {
        $limit = max(1, min(500, (int) ($filters['limit'] ?? 50)));
        $record = trim((string) ($filters['record'] ?? ''));

        $runs = [];
        foreach ($this->importRunQuery($projectId, min($limit * 20, 1000)) as $row) {
            if (strpos((string) ($row['message'] ?? ''), self::$importLogPrefix) !== 0) {
                continue;
            }
            $runId = (string) ($row['run_id'] ?? '');
            if ($runId === '') {
                continue;
            }

            $rowRecord = (string) ($row['record'] ?? '');
            if ($record !== '' && $rowRecord !== $record) {
                continue;
            }

            if (!isset($runs[$runId])) {
                $runs[$runId] = [
                    'run_id' => $runId,
                    'logged' => self::importLogTime($row['timestamp'] ?? 0),
                    'user' => (string) ($row['username'] ?? ''),
                    'record' => $rowRecord,
                    'event_id' => $row['event_id'] ?? null,
                    'instrument' => $row['instrument'] ?? null,
                    'repeat_instance' => isset($row['repeat_instance']) ? (int) $row['repeat_instance'] : null,
                    'description' => '',
                    'summary' => null,
                    'request_logs' => [],
                ];
            }

            if (($row['message'] ?? '') === self::$importRequestLogMessage) {
                $runs[$runId]['request_logs'][] = $row;
            } elseif ($runs[$runId]['summary'] === null) {
                $runs[$runId]['summary'] = $row;
                $runs[$runId]['description'] = (string) ($row['message'] ?? '');
            }
        }

        // Newest first, then only as many runs as were asked for
        usort($runs, fn($a, $b) => $b['logged'] <=> $a['logged']);
        $runs = array_slice($runs, 0, $limit);

        foreach ($runs as &$run) {
            // Entries come back oldest first, so put each run's endpoint requests in the order they
            // were logged ('request' is "1 of N", "2 of N", ...)
            usort($run['request_logs'], fn($a, $b) => ((int) ($a['request'] ?? 0)) <=> ((int) ($b['request'] ?? 0)));

            $summary = $run['summary'] ?? [];
            $run += [
                'requests' => (int) ($summary['requests'] ?? count($run['request_logs'])),
                'api_calls' => (int) ($summary['api_calls'] ?? 0),
                'elapsed_ms' => $summary['elapsed_ms'] ?? null,
                'saved' => (string) ($summary['saved'] ?? ''),
                'skipped' => (string) ($summary['skipped'] ?? ''),
                'errors' => (string) ($summary['errors'] ?? ''),
                'notices' => (string) ($summary['notices'] ?? ''),
                'not_populated' => (string) ($summary['not_populated'] ?? ''),
                'event_name' => $this->importEventName($projectId, $run['event_id']),
            ];
            $run['outcome'] = $this->importRunNeedsAttention($summary, $run['request_logs']);
            $run['explanation'] = $this->importRunExplanation($run);
        }
        unset($run);

        return $this->importApplyDismissals($projectId, $runs);
    }

    // Reads the module log's import entries as [message, timestamp, username, record, <parameter>...]
    // arrays, oldest first. An entry's custom parameters (run_id, outcome, fields_saved, ...) live as
    // name/value rows in redcap_external_modules_log_parameters, so they're joined in and assembled
    // by name here - the same way the framework's own log viewer (ExternalModules manager,
    // get-logs.php) reads them. The module's own entries are picked out by joining
    // redcap_external_modules on this module's directory prefix, rather than looking the module's id
    // up first. queryLogs() was tried first, but its pseudo-query dialect proved fragile across
    // versions (no 'from', no '*', and columns coming back empty on 16.1.7), while these tables'
    // shapes are stable and already read directly by REDCap core.
    private function importRunQuery($projectId, $cap)
    {
        // The ids of the newest import entries, capped (the parameter join below turns one entry into
        // several rows, so the cap has to be applied to entries before joining)
        $ids = [];
        foreach ($this->importQueryRows(
            'select l.log_id'
            . ' from redcap_external_modules_log l'
            . ' join redcap_external_modules m on m.external_module_id = l.external_module_id'
            . ' and m.directory_prefix = ?'
            . ' where l.project_id = ? and l.message like ?'
            . ' order by l.log_id desc'
            . ' limit ' . (int) $cap,
            [$this->getPrefix(), (int) $projectId, self::$importLogPrefix . '%']
        ) as $row) {
            $id = (int) ($row['log_id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        if (empty($ids)) {
            return [];
        }

        // One row per (entry, parameter); entries without parameters still come back via the left join
        $rows = $this->importQueryRows(
            'select l.log_id, l.timestamp, u.username, l.record, l.message,'
            . ' p.name as param_name, p.value as param_value'
            . ' from redcap_external_modules_log l'
            . ' left join redcap_user_information u on u.ui_id = l.ui_id'
            . ' left join redcap_external_modules_log_parameters p on p.log_id = l.log_id'
            . ' where l.log_id in (' . implode(',', $ids) . ')'
            . ' order by l.log_id asc',
            []
        );

        // Fold the parameter rows back into one array per entry
        $entries = [];
        foreach ($rows as $row) {
            $logId = (int) ($row['log_id'] ?? 0);
            if ($logId === 0) {
                continue;
            }
            if (!isset($entries[$logId])) {
                $entries[$logId] = [
                    'log_id' => $logId,
                    'timestamp' => $row['timestamp'] ?? '',
                    'username' => (string) ($row['username'] ?? ''),
                    'record' => (string) ($row['record'] ?? ''),
                    'message' => (string) ($row['message'] ?? ''),
                ];
            }
            if (isset($row['param_name']) && $row['param_name'] !== null) {
                $entries[$logId][(string) $row['param_name']] = $row['param_value'];
            }
        }

        return array_values($entries);
    }

    // Runs a query through the framework and returns every row as an associative array. Reads with
    // fetch_assoc() in a loop rather than fetch_all(): fetch_all() only exists when PHP is built with
    // the MySQL native driver, while fetch_assoc() is always there, and a missing method here would
    // silently look like "no rows".
    private function importQueryRows($sql, $parameters)
    {
        $result = $this->importQuery($sql, $parameters);

        $rows = [];
        if (is_object($result) && method_exists($result, 'fetch_assoc')) {
            while (true) {
                $row = $result->fetch_assoc();
                if ($row === null || $row === false) {
                    break;
                }
                $rows[] = $row;
            }
            return $rows;
        }

        if (is_iterable($result)) {
            foreach ($result as $row) {
                $rows[] = (array) $row;
            }
        }
        return $rows;
    }

    // query() with any failure turned into an exception message that says what was being read
    private function importQuery($sql, $parameters)
    {
        try {
            return $this->query($sql, $parameters);
        } catch (\Throwable $e) {
            throw new \Exception('ADIF couldn\'t read its log entries: ' . $e->getMessage());
        }
    }

    // The log's timestamp column has been a unix integer and a formatted string across framework
    // versions; accept either so the run list sorts and formats correctly.
    private static function importLogTime($value)
    {
        if (ctype_digit((string) $value)) {
            return (int) $value;
        }
        $time = strtotime((string) $value);
        return $time !== false ? $time : 0;
    }

    // Why the run list is empty, for the page's empty state: distinguishes "no import entries in the
    // log" from "entries exist but couldn't be grouped into runs". Returns entry counts and one raw
    // sample entry.
    public function importLogDiagnostics($projectId)
    {
        $entries = 0;
        $withRunId = 0;
        $requestEntries = 0;
        $sample = null;

        foreach ($this->importRunQuery($projectId, 1000) as $row) {
            $entries++;
            if (!empty($row['run_id'])) {
                $withRunId++;
            }
            if (($row['message'] ?? '') === self::$importRequestLogMessage) {
                $requestEntries++;
            }
            if ($sample === null) {
                $sample = [
                    'message' => (string) ($row['message'] ?? ''),
                    'timestamp' => (string) ($row['timestamp'] ?? ''),
                    'record' => (string) ($row['record'] ?? ''),
                    'run_id' => (string) ($row['run_id'] ?? ''),
                ];
            }
        }

        return ['entries' => $entries, 'with_run_id' => $withRunId, 'request_entries' => $requestEntries, 'sample' => $sample];
    }

    // The latest run per event for one record: what the Imports page's record lookup shows, and what
    // a "this record's import needs running again" banner would read (see docs/imports.md).
    // Returns [] when nothing has been imported for the record yet, else one run array per event.
    public function importRecordStatus($projectId, $record)
    {
        $latest = [];
        foreach ($this->importRuns($projectId, ['record' => $record, 'limit' => 200]) as $run) {
            // importRuns() is newest first, so the first run seen for an event is its latest
            $key = (string) ($run['event_id'] ?? '');
            $latest[$key] = $latest[$key] ?? $run;
        }
        ksort($latest);

        return array_values($latest);
    }

    // One logged run by its run_id, looked for among the project's most recent 500 runs, or null
    public function importRunFromLog($projectId, $runId)
    {
        foreach ($this->importRuns($projectId, ['limit' => 500]) as $run) {
            if ($run['run_id'] === $runId) {
                return $run;
            }
        }
        return null;
    }

    // The runs "Retry all failed" re-runs: for each import (see importRunUnitKey), its latest run in
    // $runs (newest first, as importRuns() returns them), when that latest run needs attention. A
    // failed run whose import has run again since - a retry, a new survey submission - is left out,
    // as is a dismissed one, so each failing import is retried once, whether or not its run is among
    // those shown on the page. Returns run_ids, newest first.
    public function importRetryTargets($projectId, array $runs)
    {
        $project = new \Project($projectId);
        $seen = [];
        $targets = [];
        foreach ($runs as $run) {
            $key = $this->importRunUnitKey($project, $run);
            if (isset($seen[$key])) {
                continue;   // an older run of an import already seen
            }
            $seen[$key] = true;
            if ($run['outcome'] === 'attention' && trim((string) $run['record']) !== '' && (string) $run['run_id'] !== '') {
                $targets[] = $run['run_id'];
            }
        }

        return $targets;
    }

    // Which import a run belongs to, so a later run can be recognised as importing the same data
    // again: the record, the event, the repeat instance when the event or the instrument repeats
    // (otherwise every run in the event imports the same fields), and the instrument when it repeats.
    private function importRunUnitKey($project, array $run)
    {
        $eventId = is_numeric($run['event_id'] ?? null) ? (int) $run['event_id'] : 0;
        $instrument = (string) ($run['instrument'] ?? '');
        $repeatingForm = $instrument !== '' && $eventId > 0 && $project->isRepeatingForm($eventId, $instrument);
        $repeats = $repeatingForm || ($eventId > 0 && $project->isRepeatingEvent($eventId));

        return json_encode([(string) $run['record'], $eventId, $repeatingForm ? $instrument : '',
            $repeats ? max(1, (int) ($run['repeat_instance'] ?? 1)) : 1]);
    }

    // The imports that have never been tried, so the Imports page can list them and run them. Imports
    // run when a survey is submitted, so records entered before the module was enabled (or before
    // their fields were mapped) never get one. One entry per place an import writes to - a record's
    // event; in a repeating event, each of its instances; and for a mapped repeating instrument, each
    // of its instances - where:
    //   - the record has data there, so the event has happened (events it hasn't reached are left alone)
    //   - every field the current mappings import there is empty
    //   - no logged run has requested any of those fields there (see importAttemptedFields), and no
    //     import for the record's event is waiting in the background queue
    // Each entry: record, event_id, event_name (null in a classic project), instrument (the repeating
    // instrument, or null for the event's other mapped fields), repeat_instance, repeats, fields (the
    // mapped fields there), missing (why lookup values the import needs can't be read from the record
    // yet, as the import would log it - field names, never values) and importable (whether at least
    // one of its endpoint requests has everything it needs).
    // $record checks one record only: the page's record filter, and runFirstImport()'s re-check.
    public function importNeverAttempted($projectId, $record = null)
    {
        $project = new \Project($projectId);
        $shapes = $this->importUnitShapes($projectId, $project);
        if (empty($shapes)) {
            return [];
        }
        $dictionary = REDCap::getDataDictionary($projectId, 'array');

        $places = $this->importPlacesWithData($projectId, $project, $shapes, $dictionary,
            $record === null ? null : [(string) $record]);
        if (empty($places)) {
            return [];
        }

        $attempted = $this->importAttemptedFields($projectId, $project, $dictionary, $record);
        // An import waiting in the background queue (see ImportQueue) is about to run, so its event
        // isn't listed: the queue card shows it instead
        $queued = [];
        foreach ($this->importQueuePending($projectId, $record, 5000) as $item) {
            $queued[$item['record']][(int) $item['event_id']] = true;
        }

        $units = [];
        foreach ($places as $place) {
            $name = $place['record'];
            $shape = $place['shape'];
            $instance = $place['instance'];
            if (!self::importFieldsEmpty($place['row'], $shape['fields'], $dictionary)
                || self::importUnitAttempted($attempted, $name, $shape, $instance)
                || isset($queued[$name][$shape['event_id']])) {
                continue;
            }

            [$missing, $importable] = $this->importUnitLookupCheck($place['rows'], $name, $shape, $instance, $dictionary);
            $units[] = [
                'record' => $name,
                'event_id' => $shape['event_id'],
                'event_name' => $this->importEventName($projectId, $shape['event_id']),
                'instrument' => $shape['instrument'],
                'repeat_instance' => $instance,
                'repeats' => $shape['repeats'],
                'fields' => $shape['fields'],
                'missing' => $missing,
                'importable' => $importable,
            ];
        }

        return $units;
    }

    // Every place an import writes to (one of importUnitShapes()' $shapes, at an instance) that holds
    // data in a record, so the event has happened there: the places importNeverAttempted() picks from,
    // and the ones a bulk refresh re-imports (see ImportRefresh). Reads everything the callers check
    // in one getData() call: the mapped fields, the fields their [placeholders] are looked up from, and
    // the instruments' form status fields, which show which events and instances have data.
    // $records limits it to those records (null: every record). Each place: record, shape, instance,
    // row (its getData() row) and rows (all of the record's rows, for reading lookup values).
    private function importPlacesWithData($projectId, $project, array $shapes, array $dictionary, $records = null)
    {
        if ($records !== null && empty($records)) {
            return [];   // getData() would read every record
        }
        $recordIdField = $project->table_pk;

        $fields = [$recordIdField];
        foreach ($shapes as $shape) {
            array_push($fields, ...$shape['fields'], ...$shape['status_fields']);
            foreach ($shape['lookups'] as $lookups) {
                array_push($fields, ...array_column($lookups, 'field'));
            }
        }
        $statusFields = array_merge(...array_column($shapes, 'status_fields'));
        $fields = array_values(array_unique(array_filter($fields,
            fn($field) => isset($dictionary[$field]) || in_array($field, $statusFields, true))));

        $request = ['project_id' => $projectId, 'return_format' => 'json', 'fields' => $fields];
        if ($records !== null) {
            $request['records'] = array_values(array_map('strval', $records));
        }
        $data = json_decode(REDCap::getData($request), true);
        if (!is_array($data)) {
            throw new \Exception('ADIF couldn\'t read the project\'s records to find where imports write.');
        }

        $rowsByRecord = [];
        foreach ($data as $row) {
            $name = (string) ($row[$recordIdField] ?? '');
            if ($name !== '') {
                $rowsByRecord[$name][] = $row;
            }
        }

        $eventIds = $project->longitudinal ? array_flip($project->getUniqueEventNames()) : [];
        $places = [];
        foreach ($rowsByRecord as $name => $rows) {
            $name = (string) $name;   // numeric record names come back as integer keys
            foreach ($shapes as $shape) {
                foreach ($rows as $row) {
                    $rowEvent = $project->longitudinal
                        ? ($eventIds[$row['redcap_event_name'] ?? ''] ?? null)
                        : (int) $project->firstEventId;
                    if ($rowEvent === null || (int) $rowEvent !== $shape['event_id']) {
                        continue;
                    }

                    // The row this place's data is in: the event's own row, an instance of the repeating
                    // event, or an instance of the repeating instrument
                    $rowInstrument = (string) ($row['redcap_repeat_instrument'] ?? '');
                    $rowInstance = (string) ($row['redcap_repeat_instance'] ?? '');
                    if ($shape['instrument'] !== null) {
                        if ($rowInstrument !== $shape['instrument'] || $rowInstance === '') {
                            continue;
                        }
                    } elseif ($shape['repeats']) {
                        if ($rowInstrument !== '' || $rowInstance === '') {
                            continue;
                        }
                    } elseif ($rowInstance !== '') {
                        continue;
                    }
                    if (!self::importRowHasData($row, $recordIdField)) {
                        continue;
                    }

                    $places[] = [
                        'record' => $name,
                        'shape' => $shape,
                        'instance' => $rowInstance === '' ? 1 : max(1, (int) $rowInstance),
                        'row' => $row,
                        'rows' => $rows,
                    ];
                }
            }
        }

        return $places;
    }

    // Where a project's imports write to, per event: the event's own mapped fields (instrument null;
    // in a repeating event, per instance), and in an event that doesn't repeat, each mapped repeating
    // instrument on its own. Each with the fields importUnitPlan() imports there, the [placeholder]
    // lookups each of its requests needs, the lookup context for instance 1, and the form status
    // fields of the instruments its data can be on.
    private function importUnitShapes($projectId, $project)
    {
        $mappings = $this->getProjectSetting('field-mappings', $projectId) ?: [];
        if (empty($mappings)) {
            return [];
        }

        $shapes = [];
        foreach ($project->eventsForms as $eventId => $forms) {
            $eventId = (int) $eventId;
            $forms = array_values((array) $forms);
            $mappedForms = array_values(array_intersect(array_keys($mappings), $forms));
            if (empty($mappedForms)) {
                continue;
            }
            $eventRepeats = (bool) $project->isRepeatingEvent($eventId);

            $instruments = [null];
            if (!$eventRepeats) {
                foreach ($mappedForms as $form) {
                    if ($project->isRepeatingForm($eventId, $form)) {
                        $instruments[] = $form;
                    }
                }
            }

            foreach ($instruments as $instrument) {
                $plan = $this->importUnitPlan($projectId, $eventId, $instrument, 1);
                $fields = [];
                $lookups = [];
                foreach ($plan['requests'] as $key => $request) {
                    array_push($fields, ...array_column($request['fields'], 'field'));
                    $lookups[$key] = [];
                    if (preg_match_all('/\[([a-z][a-z0-9_]*)\]/', (string) $request['path'], $matches)) {
                        foreach (array_unique($matches[1]) as $placeholder) {
                            $lookups[$key][] = ['placeholder' => $placeholder,
                                'field' => $this->getLookupField($request['source'], $placeholder)];
                        }
                    }
                }
                if (empty($fields)) {
                    continue;
                }

                $shapes[] = [
                    'event_id' => $eventId,
                    'instrument' => $instrument,
                    'repeats' => $instrument !== null || $eventRepeats,
                    'fields' => array_values(array_unique($fields)),
                    'lookups' => $lookups,
                    'context' => $plan['lookup_context'],
                    'status_fields' => array_map(fn($form) => $form . '_complete', $instrument !== null ? [$instrument] : $forms),
                ];
            }
        }

        return $shapes;
    }

    // importPlan() for one of importUnitShapes()' places. For a repeating instrument, only that
    // instrument's fields: the event's other mapped fields are a place of their own, checked and
    // imported separately, so an import of one instance doesn't overwrite them. The "repeating
    // instrument other than the submitted one" lines are dropped for the same reason - those
    // instruments are imported as places of their own, not skipped.
    private function importUnitPlan($projectId, $eventId, $instrument, $instance)
    {
        $plan = $this->importPlan($projectId, $eventId, $instrument, $instance);
        $plan['skipped'] = array_values(array_filter($plan['skipped'],
            fn($line) => !str_ends_with($line, ': repeating instrument other than the submitted one')));
        if ($instrument === null) {
            return $plan;
        }

        $requests = [];
        foreach ($plan['requests'] as $key => $request) {
            $request['fields'] = array_values(array_filter($request['fields'],
                fn($target) => $target['repeatInstrument'] === $instrument));
            if (!empty($request['fields'])) {
                $requests[$key] = $request;
            }
        }
        $plan['requests'] = $requests;

        return $plan;
    }

    // Whether a getData() row holds any of the record's data, as opposed to only saying where it is
    private static function importRowHasData(array $row, $recordIdField)
    {
        foreach ($row as $column => $value) {
            if ($column === $recordIdField || in_array($column, self::$importRowLocationColumns, true)) {
                continue;
            }
            // A checkbox option's column reads "0" when it's unticked
            if (str_contains((string) $column, '___') ? (string) $value === '1' : (string) $value !== '') {
                return true;
            }
        }
        return false;
    }

    // Whether every one of $fields is empty in a getData() row (a checkbox: none of its options ticked)
    private static function importFieldsEmpty(array $row, array $fields, array $dictionary)
    {
        foreach ($fields as $field) {
            if (($dictionary[$field]['field_type'] ?? '') === 'checkbox') {
                foreach ($row as $column => $value) {
                    if (str_starts_with((string) $column, $field . '___') && (string) $value === '1') {
                        return false;
                    }
                }
            } elseif ((string) ($row[$field] ?? '') !== '') {
                return false;
            }
        }
        return true;
    }

    // Whether the module log shows an import having tried any of a place's fields (see importAttemptedFields)
    private static function importUnitAttempted(array $attempted, $record, array $shape, $instance)
    {
        $tried = $attempted[$record] ?? [];
        if (isset($tried['any'][0]) || isset($tried['any'][$shape['event_id']])) {
            return true;
        }
        $instanceKey = $shape['repeats'] ? (string) $instance : '*';
        foreach ($shape['fields'] as $field) {
            if (isset($tried['fields'][$shape['event_id']][$instanceKey][$field])) {
                return true;
            }
        }
        return false;
    }

    // Whether a first import's [placeholder] values can be read from the record yet, by the same
    // rules the import uses (Import::importLookupValue). Returns [missing, importable]: missing lists
    // why values can't be read, in the import's own messages (field and instance names, never
    // values), and importable is whether at least one request has every value it needs. A request
    // without placeholders always does.
    private function importUnitLookupCheck(array $rows, $record, array $shape, $instance, array $dictionary)
    {
        $context = ['instance' => max(1, (int) $instance)] + (array) $shape['context'];
        $missing = [];
        $importable = false;
        foreach ($shape['lookups'] as $lookups) {
            $ready = true;
            foreach ($lookups as $lookup) {
                $field = $lookup['field'];
                if (!isset($dictionary[$field])) {
                    $missing[] = "[{$lookup['placeholder']}] is looked up from the field \"$field\", which isn't in this project."
                        . ' Choose its lookup field on the Mapping page.';
                    $ready = false;
                    continue;
                }
                try {
                    $notices = [];
                    $this->importLookupValue($rows, $field, (string) ($dictionary[$field]['form_name'] ?? ''), $context,
                        $lookup['placeholder'], $record, $notices);
                } catch (\Throwable $e) {
                    $missing[] = $e->getMessage();
                    $ready = false;
                }
            }
            $importable = $importable || $ready;
        }

        return [array_values(array_unique($missing)), $importable];
    }

    // What the module log says imports have tried, by record, for importNeverAttempted():
    //   fields - [event_id => [instance key => [field => true]]]: the fields each logged endpoint request
    //            asked for. The instance key is the run's repeat instance when the field's event or
    //            instrument repeats, else '*' (any run in the event tried the event's own fields).
    //   any    - [event_id => true] for runs that don't say which fields they requested (the import
    //            failed before making a request, or the run was logged without per-request entries),
    //            which count as having tried everything in their event; event_id 0 for runs logged
    //            without one, which count for every event
    // Every import run is read, however old: one attempt is enough to leave a place off the list, and
    // anything it left empty is the run list's to show.
    private function importAttemptedFields($projectId, $project, array $dictionary, $record = null)
    {
        $sql = 'select distinct l.record, l.message, e.value as event_id, r.value as repeat_instance,'
            . ' f.value as fields_requested, n.value as requests'
            . ' from redcap_external_modules_log l'
            . ' join redcap_external_modules m on m.external_module_id = l.external_module_id'
            . ' and m.directory_prefix = ?'
            . ' left join redcap_external_modules_log_parameters e on e.log_id = l.log_id and e.name = \'event_id\''
            . ' left join redcap_external_modules_log_parameters r on r.log_id = l.log_id and r.name = \'repeat_instance\''
            . ' left join redcap_external_modules_log_parameters f on f.log_id = l.log_id and f.name = \'fields_requested\''
            . ' left join redcap_external_modules_log_parameters n on n.log_id = l.log_id and n.name = \'requests\''
            . ' where l.project_id = ? and l.message like ?';
        $parameters = [$this->getPrefix(), (int) $projectId, self::$importLogPrefix . '%'];
        if ($record !== null) {
            $sql .= ' and l.record = ?';
            $parameters[] = (string) $record;
        }

        $attempted = [];
        foreach ($this->importQueryRows($sql, $parameters) as $row) {
            $rowRecord = (string) ($row['record'] ?? '');
            if ($rowRecord === '') {
                continue;
            }
            $eventId = is_numeric($row['event_id'] ?? null) ? (int) $row['event_id'] : 0;

            if (($row['message'] ?? '') !== self::$importRequestLogMessage) {
                // A run's summary entry: only tells us something when the run made no requests
                if ((int) ($row['requests'] ?? 0) === 0) {
                    $attempted[$rowRecord]['any'][$eventId] = true;
                }
                continue;
            }

            $requested = array_filter(array_map('trim', explode(',', (string) ($row['fields_requested'] ?? ''))), 'strlen');
            if (empty($requested)) {
                $attempted[$rowRecord]['any'][$eventId] = true;
                continue;
            }
            $instance = (string) max(1, (int) ($row['repeat_instance'] ?? 1));
            foreach ($requested as $field) {
                $form = (string) ($dictionary[$field]['form_name'] ?? '');
                $repeats = $eventId > 0 && ($project->isRepeatingEvent($eventId)
                    || ($form !== '' && $project->isRepeatingForm($eventId, $form)));
                $attempted[$rowRecord]['fields'][$eventId][$repeats ? $instance : '*'][$field] = true;
            }
        }

        return $attempted;
    }

    // Runs the first import for one entry of importNeverAttempted(), as the logged-in user. The entry
    // is looked up again first, so only an import that is still pending runs: one done since the page
    // loaded (another tab, Import all, a survey submission), or whose fields have been filled since, is
    // refused rather than imported twice. The import reads stored data the way the project's cache
    // settings say, as a survey submission's would, and is logged like one - as "API import: manual
    // import by <user>" - so it leaves the Never imported list and joins the run list, where a failure
    // is flagged and can be retried like any other. Returns:
    //   ['status' => 'success', 'run' => <the new run, as retryImportRun() returns one>]
    //   ['status' => 'not_pending', 'message' => ...]  no longer waiting on a first import
    //   ['status' => 'not_ready', 'message' => ...]    none of its lookup values can be read yet
    //   ['status' => 'locked', 'message' => ...]       the same import was started moments ago
    // $requestedBy is set when the import is part of Import all, run by the cron with nobody logged in
    // (see ImportAll): the run is then described as requested by that user.
    public function runFirstImport($projectId, $record, $eventId, $instrument, $instance, $requestedBy = null)
    {
        $unit = null;
        foreach ($this->importNeverAttempted($projectId, $record) as $candidate) {
            if ($candidate['record'] === (string) $record && $candidate['event_id'] === (int) $eventId
                && $candidate['instrument'] === $instrument && $candidate['repeat_instance'] === (int) $instance) {
                $unit = $candidate;
                break;
            }
        }
        if ($unit === null) {
            return ['status' => 'not_pending', 'message' => 'Not imported: this is no longer waiting on a first import.'
                . ' It has been imported since the page loaded, its mapped fields have values now, or its mappings'
                . ' changed. Reload the page to see where it stands.'];
        }
        if (!$unit['importable']) {
            return ['status' => 'not_ready', 'message' => 'Not imported yet: ' . implode(' ', $unit['missing'])];
        }

        if ($requestedBy === null) {
            $user = $this->getUser();
            $username = $user ? $user->getUsername() : '?';
            $description = "API import: manual import by $username";
        } else {
            $username = '';
            $description = "API import: import all, requested by $requestedBy";
        }
        $lock = 'import-first-' . md5(json_encode([(int) $projectId, $unit['record'], $unit['event_id'],
                $unit['instrument'], $unit['repeat_instance']]));
        if (!$this->importRetryAcquireLock($lock)) {
            return ['status' => 'locked', 'message' => 'This import started less than ' . self::$importRetryLockSeconds
                . ' seconds ago and may still be running. Wait a moment, then reload the page.'];
        }

        try {
            $start = microtime(true);
            try {
                $summary = $this->importMappedData($projectId, $unit['record'], $unit['event_id'], $unit['instrument'],
                    $unit['repeat_instance'], null,
                    $this->importUnitPlan($projectId, $unit['event_id'], $unit['instrument'], $unit['repeat_instance']));
            } catch (\Throwable $e) {
                $summary = ['errors' => [$e->getMessage()]];
            }
            $summary += ['saved' => [], 'skipped' => [], 'errors' => [], 'notices' => [], 'not_populated' => [], 'requests' => []];
            $summary['elapsed_ms'] = round((microtime(true) - $start) * 1000, 1);
            $summary['repeat_instance'] = $unit['repeat_instance'];

            $this->logImportRun($description, $projectId, $unit['record'], $unit['event_id'], $unit['instrument'], $summary);
        } finally {
            $this->importRetryReleaseLock($lock);
        }

        return ['status' => 'success', 'run' => $this->importRunFromSummary($projectId, $description, $username,
            $unit['record'], $unit['event_id'], $unit['instrument'], $unit['repeat_instance'], $summary)];
    }

    // Marks the runs whose "Needs attention" was dismissed (and not since undone): outcome becomes
    // 'dismissed' and 'dismissed' holds who did it, when and why. Only flagged runs can be dismissed,
    // and a run's own entries never change, so a dismissed run would otherwise read 'attention'.
    private function importApplyDismissals($projectId, array $runs)
    {
        $dismissals = $this->importDismissals($projectId, array_column($runs, 'run_id'));
        foreach ($runs as &$run) {
            $run['dismissed'] = null;
            if ($run['outcome'] === 'attention' && isset($dismissals[$run['run_id']])) {
                $run['dismissed'] = $dismissals[$run['run_id']];
                $run['outcome'] = 'dismissed';
            }
        }
        unset($run);

        return $runs;
    }

    // [run_id => [user, at (unix time), reason]] for the given runs' current dismissals: their
    // dismiss and undo entries are read oldest first, so the latest one for each run decides.
    private function importDismissals($projectId, array $runIds)
    {
        $runIds = array_values(array_unique(array_filter(array_map('strval', $runIds), 'strlen')));
        if (empty($runIds)) {
            return [];
        }

        $rows = $this->importQueryRows(
            'select l.log_id, l.timestamp, l.message, u.username, p.value as run_id, r.value as reason'
            . ' from redcap_external_modules_log l'
            . ' join redcap_external_modules m on m.external_module_id = l.external_module_id'
            . ' and m.directory_prefix = ?'
            . ' join redcap_external_modules_log_parameters p on p.log_id = l.log_id and p.name = \'run_id\''
            . ' left join redcap_external_modules_log_parameters r on r.log_id = l.log_id and r.name = \'reason\''
            . ' left join redcap_user_information u on u.ui_id = l.ui_id'
            . ' where l.project_id = ? and l.message in (?, ?)'
            . ' and p.value in (' . implode(',', array_fill(0, count($runIds), '?')) . ')'
            . ' order by l.log_id asc',
            array_merge([$this->getPrefix(), (int) $projectId, self::$importDismissMessage, self::$importUndismissMessage], $runIds)
        );

        $dismissals = [];
        foreach ($rows as $row) {
            $runId = (string) ($row['run_id'] ?? '');
            if (($row['message'] ?? '') === self::$importDismissMessage) {
                $dismissals[$runId] = [
                    'user' => (string) ($row['username'] ?? ''),
                    'at' => self::importLogTime($row['timestamp'] ?? 0),
                    'reason' => (string) ($row['reason'] ?? ''),
                ];
            } else {
                unset($dismissals[$runId]);
            }
        }

        return $dismissals;
    }

    // Dismisses a run's "Needs attention" ($undo false), or undoes that ($undo true), as the
    // logged-in user. Dismissing is for failures nobody intends to fix: the run stops counting as
    // needing attention - on the run list, in the alert, in the record lookup's verdict and for
    // Retry all failed - but nothing about the run or the record's data changes, and it can be
    // undone. Written to the module log (with the reason) and to the project's Logging page.
    // Returns ['status' => 'success', 'run' => <the run as importRuns() now builds it>] or
    // ['status' => 'error', 'message' => ...].
    public function dismissImportRun($projectId, array $run, $undo = false, $reason = '')
    {
        if (!$undo && $run['outcome'] !== 'attention') {
            return ['status' => 'error', 'message' => $run['outcome'] === 'dismissed'
                ? 'This run has already been dismissed.'
                : 'Only runs that need attention can be dismissed.'];
        }
        if ($undo && $run['outcome'] !== 'dismissed') {
            return ['status' => 'error', 'message' => 'This run isn\'t dismissed, so there is nothing to undo.'];
        }

        $reason = trim(preg_replace('/\s+/', ' ', (string) $reason));
        if (mb_strlen($reason) > self::$importDismissReasonLength) {
            $reason = mb_substr($reason, 0, self::$importDismissReasonLength);
        }

        $user = $this->getUser();
        $username = $user ? $user->getUsername() : '';

        $parameters = ['project_id' => $projectId, 'record' => $run['record'], 'run_id' => $run['run_id'],
            'event_id' => $run['event_id'], 'instrument' => $run['instrument'], 'repeat_instance' => $run['repeat_instance']];
        if (!$undo && $reason !== '') {
            $parameters['reason'] = $reason;
        }
        try {
            // Not writeModuleLog(): that swallows failures, and here the user must hear about one
            $this->log($undo ? self::$importUndismissMessage : self::$importDismissMessage,
                array_filter($parameters, fn($value) => $value !== null && $value !== ''));
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => 'The dismissal couldn\'t be saved: ' . $e->getMessage()];
        }

        try {
            REDCap::logEvent($undo ? 'ADIF: import error dismissal undone' : 'ADIF: import error dismissed',
                trim(($run['instrument'] ? "Instrument: {$run['instrument']}\n" : '')
                    . ($reason !== '' && !$undo ? "Reason: $reason\n" : '')
                    . "Details: ADIF module log, run_id {$run['run_id']}"),
                null, $run['record'], $run['event_id'], $projectId);
        } catch (\Throwable $e) {
            error_log("ADIF: couldn't write the dismissal to the project log: " . $e->getMessage());
        }

        if ($undo) {
            $run['outcome'] = 'attention';
            $run['dismissed'] = null;
        } else {
            $run['outcome'] = 'dismissed';
            $run['dismissed'] = ['user' => $username, 'at' => time(), 'reason' => $reason];
        }

        return ['status' => 'success', 'run' => $run];
    }

    // Re-runs an import run that needs attention (see importRunNeedsAttention), as the logged-in
    // user: same record, event, instrument and repeat instance, through importMappedData().
    // $bypassCache passes FetchMode::Live to every request, so a retry after an outage calls the
    // APIs instead of re-reading what the cache stored during it. Returns:
    //   ['status' => 'success', 'run' => <the new run as importRuns() builds it>]
    //   ['status' => 'locked',  'message' => ...] when a retry of this run is already in progress
    //   ['status' => 'not_mapped', 'message' => ..., 'not_mapped' => [field => why]] when the
    //       current mappings wouldn't import any of the fields the run left empty (see
    //       importRetryMappingCheck), so there's nothing a retry could fix - nothing is run or logged
    // Before anything runs, the run is checked against the project's current mappings, and the
    // import then uses exactly the plan that was checked. Fields the run left empty that are no
    // longer mapped are named in the new run's notices.
    // An import that throws is reported inside the run (its errors), as it is for the survey hook.
    // Unlike the survey hook, errors are returned rather than only logged: a user waiting on the
    // button needs to see them.
    // $requestedBy is set when the retry is part of Retry all failed, run by the cron with nobody logged
    // in (see ImportRetryAll): the run is then described as requested by that user.
    public function retryImportRun($projectId, array $run, $bypassCache = true, $requestedBy = null)
    {
        if ($requestedBy === null) {
            $user = $this->getUser();
            $username = $user ? $user->getUsername() : '?';
            $description = "API import: manual retry by $username";
        } else {
            $username = '';
            $description = "API import: retry all failed, requested by $requestedBy";
        }

        $check = $this->importRetryMappingCheck($projectId, $run);
        if ($check['problem'] !== null) {
            return ['status' => 'not_mapped', 'message' => $check['problem'], 'not_mapped' => $check['not_mapped']];
        }

        $lock = 'import-retry-' . $projectId . '-' . $run['run_id'];
        if (!$this->importRetryAcquireLock($lock)) {
            return ['status' => 'locked',
                'message' => 'A retry of this run started less than ' . self::$importRetryLockSeconds
                    . ' seconds ago and may still be running. Wait a moment, then check the run list.'];
        }

        try {
            $start = microtime(true);
            try {
                $summary = $this->importMappedData($projectId, $run['record'], $run['event_id'],
                    $run['instrument'], $run['repeat_instance'] ?? 1,
                    $bypassCache ? \UKModules\ADIF\Cache\FetchMode::Live : null, $check['plan']);
            } catch (\Throwable $e) {
                $summary = ['errors' => [$e->getMessage()]];
            }
            $summary += ['saved' => [], 'skipped' => [], 'errors' => [], 'notices' => [], 'not_populated' => [], 'requests' => []];
            if (!empty($check['not_mapped'])) {
                $summary['notices'][] = 'Left empty by the run being retried, but no longer mapped, so not retried: '
                    . self::importFieldReasons($check['not_mapped']) . '.';
            }
            $summary['elapsed_ms'] = round((microtime(true) - $start) * 1000, 1);
            $summary['repeat_instance'] = $run['repeat_instance'] ?? 1;

            $this->logImportRun($description, $projectId, $run['record'],
                $run['event_id'], $run['instrument'], $summary);
        } finally {
            $this->importRetryReleaseLock($lock);
        }

        return ['status' => 'success', 'run' => $this->importRunFromSummary($projectId, $description,
            $username, $run['record'], $run['event_id'], $run['instrument'], $run['repeat_instance'] ?? 1, $summary)];
    }

    // A run just made (a retry or a first import) in the shape importRuns() gives a logged one, built
    // from its summary through the same outcome rules, so the page can show it the way the run list
    // will once it's reloaded
    private function importRunFromSummary($projectId, $description, $username, $record, $eventId, $instrument,
                                          $repeatInstance, array $summary)
    {
        $requestLogs = [];
        foreach ($summary['requests'] as $request) {
            $requestLogs[] = $this->importRetryRequestLog($request);
        }
        $newRun = [
            'run_id' => null,
            'logged' => time(),
            'user' => $username,
            'record' => (string) $record,
            'event_id' => $eventId,
            'instrument' => $instrument,
            'repeat_instance' => $repeatInstance,
            'description' => $description,
            'summary' => null,
            'request_logs' => $requestLogs,
        ];
        $newRun += [
            'requests' => count($summary['requests']),
            'api_calls' => array_sum(array_map(fn($r) => count($r['calls'] ?? []), $summary['requests'])),
            'elapsed_ms' => $summary['elapsed_ms'],
            'saved' => implode(', ', $summary['saved']),
            'skipped' => implode("\n", $summary['skipped']),
            'errors' => implode("\n", $summary['errors']),
            'notices' => implode("\n", array_unique($summary['notices'])),
            'not_populated' => implode("\n", array_map(fn($f, $e) => "$f: $e", array_keys($summary['not_populated']), $summary['not_populated'])),
            'event_name' => $this->importEventName($projectId, $eventId),
        ];
        // The outcome rules read the logged (string) form of the summary, as the run list does - an
        // empty errors array would otherwise read as "has errors" and flag every retry
        $newRun['outcome'] = $this->importRunNeedsAttention(['errors' => $newRun['errors']], $requestLogs);
        $newRun['explanation'] = $this->importRunExplanation($newRun);

        return $newRun;
    }

    // Checks a logged run against the project's current mappings before it's retried, using the
    // same rules as the import itself (Import::importPlan), so a retry never re-runs an import whose
    // fields have since been unmapped. Returns:
    //   plan        - the plan the retry should run, so it imports exactly what was checked
    //   fields      - the fields the run left empty ([] when the run doesn't say which, e.g. it threw)
    //   still_mapped - those the current mappings would import again
    //   not_mapped  - [field => why] for those they wouldn't
    //   problem     - null when the retry can go ahead, else why it shouldn't: no mappings apply to the
    //                 run's event and instrument any more, or none of its empty fields are still mapped
    public function importRetryMappingCheck($projectId, array $run)
    {
        $plan = $this->importPlan($projectId, $run['event_id'], $run['instrument'], $run['repeat_instance'] ?? 1);

        $mapped = [];
        foreach ($plan['requests'] as $request) {
            foreach ($request['fields'] as $target) {
                $mapped[$target['field']] = true;
            }
        }

        $fields = $this->importRunEmptyFields($run);
        $stillMapped = array_values(array_filter($fields, fn($field) => isset($mapped[$field])));
        $notMapped = [];
        foreach ($fields as $field) {
            if (!isset($mapped[$field])) {
                $notMapped[$field] = $plan['unusable'][$field] ?? 'its mapping was removed, or it is no longer on this event';
            }
        }

        $where = 'record ' . $run['record'] . "'s " . ($run['instrument'] ? "{$run['instrument']} instrument" : 'event');
        // The specific reason first: which of the run's fields are unmapped, and why
        $problem = null;
        if (!empty($fields) && empty($stillMapped)) {
            $problem = 'Not retried: none of the fields this run left empty are mapped any more - '
                . self::importFieldReasons($notMapped) . '. Check the Mapping page.';
        } elseif (empty($mapped)) {
            $problem = "Not retried: no API mappings apply to $where any more, so there is nothing to import."
                . ' Check the Mapping page.';
        }

        return ['plan' => $plan, 'fields' => $fields, 'still_mapped' => $stillMapped,
            'not_mapped' => $notMapped, 'problem' => $problem];
    }

    // The fields a logged run left empty: each failed request's not-populated fields (or, for a
    // request that errored without saying which, every field it requested), plus the summary's
    // not_populated lines ("field: error"), which also cover a failed saveData
    private function importRunEmptyFields(array $run)
    {
        $split = fn($list) => array_filter(array_map('trim', preg_split('/[,\n]/', (string) $list)), 'strlen');

        $fields = [];
        foreach ($run['request_logs'] ?? [] as $request) {
            if (trim((string) ($request['fields_saved'] ?? '')) !== '') {
                continue;
            }
            $notPopulated = $split($request['fields_not_populated'] ?? '');
            if (empty($notPopulated) && ($request['outcome'] ?? '') === 'error') {
                $notPopulated = $split($request['fields_requested'] ?? '');
            }
            array_push($fields, ...$notPopulated);
        }
        foreach (preg_split('/\n/', (string) ($run['not_populated'] ?? '')) as $line) {
            $field = trim(explode(':', $line, 2)[0]);
            if ($field !== '') {
                $fields[] = $field;
            }
        }

        return array_values(array_unique($fields));
    }

    // "field_a (why), field_b (why)"
    private static function importFieldReasons(array $reasons)
    {
        return implode(', ', array_map(fn($field, $why) => "$field ($why)", array_keys($reasons), $reasons));
    }

    // A retry's per-request summary in the shape the run list's detail table reads (the same fields
    // requestLogParameters() logs for a survey-triggered run).
    private function importRetryRequestLog(array $request)
    {
        $calls = $request['calls'] ?? [];   // end() needs a variable: an expression throws an Error on PHP 8
        $call = end($calls) ?: [];

        return [
            'source' => $request['source'] ?? '',
            'path' => $request['path'] ?? '',
            'outcome' => $request['outcome'] ?? 'not_sent',
            'http_status' => isset($call['status_code']) ? trim($call['status_code'] . ' ' . ($call['reason'] ?? '')) : null,
            'response_ms' => $call['elapsed_ms'] ?? null,
            'error' => $request['error'] ?? null,
            'fields_requested' => implode(', ', $request['fields_requested'] ?? []),
            'fields_saved' => implode(', ', $request['fields_saved'] ?? []),
            'fields_skipped' => implode("\n", $request['fields_skipped'] ?? []),
            'fields_not_populated' => implode(', ', $request['fields_not_populated'] ?? []),
            'request' => 0,   // unknown position; the detail table doesn't sort by it
        ];
    }

    // The retry lock: a module-level (system) setting holding the lock's expiry timestamp. Settings
    // are read and written through the framework with per-call latency, but a retry is a rare, short
    // operation, and this works across web nodes without new tables. A crash leaves the lock to
    // expire on its own after $importRetryLockSeconds.
    private function importRetryAcquireLock($name)
    {
        try {
            $expires = $this->getSystemSetting($name);
            if ($expires !== null && (int) $expires > time()) {
                return false;
            }
            $this->setSystemSetting($name, time() + self::$importRetryLockSeconds);
            return true;
        } catch (\Throwable $e) {
            error_log('ADIF: retry lock unavailable: ' . $e->getMessage());
            return true;   // don't block retries because the lock store failed
        }
    }

    private function importRetryReleaseLock($name)
    {
        try {
            $this->setSystemSetting($name, null);
        } catch (\Throwable $e) {
            error_log("ADIF: couldn't clear the retry lock: " . $e->getMessage());
        }
    }

    // Whether a run left mapped fields empty because something failed, per the project's rule:
    //   attention - at least one endpoint request errored (or the final saveData failed) with nothing
    //               saved from it, so the fields it would have filled are still empty: run it again
    //   stale     - every failing request was answered from stored data because the API was down, and
    //               its fields were filled: intended behaviour, nothing to re-run
    //   ok        - everything else, including requests whose fields were skipped for having no value
    //               at their path, which is an answer, not a failure
    public function importRunNeedsAttention(array $summary, array $requestLogs)
    {
        $stale = false;
        foreach ($requestLogs as $request) {
            if (trim((string) ($request['fields_saved'] ?? '')) !== '') {
                continue;   // this request's fields were populated
            }
            if (trim((string) ($request['fields_not_populated'] ?? '')) !== ''
                || ($request['outcome'] ?? '') === 'error') {
                return 'attention';
            }
            if (($request['outcome'] ?? '') === 'stale') {
                $stale = true;
            }
        }
        if (trim((string) ($summary['errors'] ?? '')) !== '') {
            return 'attention';   // e.g. saveData failed after every request succeeded
        }

        return $stale ? 'stale' : 'ok';
    }

    // One line per endpoint request that left its fields empty, for the run's explanation:
    // "source path - HTTP 503 Service Unavailable; left empty: field_a, field_b". Null when the run
    // doesn't need re-running (a stale run's "used stored data" note is added by the page instead).
    private function importRunExplanation(array $run)
    {
        $lines = [];
        foreach ($run['request_logs'] as $request) {
            if (trim((string) ($request['fields_saved'] ?? '')) !== '') {
                continue;
            }
            $notPopulated = trim((string) ($request['fields_not_populated'] ?? ''));
            if (($request['outcome'] ?? '') !== 'error' && $notPopulated === '') {
                continue;
            }
            $endpoint = trim(($request['source'] ?? '') . ' ' . ($request['path'] ?? ''));
            $lines[] = $endpoint . ' - ' . ($request['error'] ?? 'no data') . '; left empty: '
                . ($notPopulated !== '' ? $notPopulated : trim((string) ($request['fields_requested'] ?? '')));
        }
        if (empty($lines) && trim((string) ($run['errors'] ?? '')) !== '') {
            $lines[] = trim($run['errors']);
        }

        return empty($lines) ? null : implode("\n", $lines);
    }

    // An event's unique name for the log's event_id, or null for classic projects and unknown ids.
    // Reads the project itself, so it also works where REDCap::isLongitudinal() doesn't (a cron); the
    // project is loaded once per request, since the run list asks for every run's event.
    public function importEventName($projectId, $eventId)
    {
        if ($eventId === null || $eventId === '' || !is_numeric($eventId)) {
            return null;
        }
        try {
            static $projects = [];
            $projects[(int) $projectId] ??= new \Project((int) $projectId);
            return self::importUniqueEventName($projects[(int) $projectId], $eventId);
        } catch (\Throwable $e) {
            return null;
        }
    }

    // The pill label for a run's outcome, shared by the Imports page and any future banner
    public function importOutcomeLabel($outcome)
    {
        return match ($outcome) {
            'attention' => 'Needs attention',
            'dismissed' => 'Dismissed',
            'stale' => 'From stored data',
            default => 'OK',
        };
    }
}
