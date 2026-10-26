<?php

namespace UKModules\ADIF;

use REDCap;

// Mixed into UKModules\ADIF\ADIF alongside Import. Writes one import run (see importMappedData) to:
//   - the module log: one "API import: endpoint request" entry per endpoint, then a summary entry, all
//     sharing a run_id, with the record, event, instrument and repeat instance on each so they can be
//     filtered by any of them (and so the Imports page can tell instances apart - see ImportStatus)
//   - the project's Logging page: one short entry saying what was imported and what wasn't, for study staff
// Only metadata is logged: status codes, timings, sizes, cache outcomes and field names. Never response
// bodies or headers, and never the piped path of an identifying source (see Import::importRequestLog).
// Logging never throws, so a logging failure can't stop a survey respondent reaching the completion page.
trait ImportLog
{
    // $description is the summary entry's message and the project log's action, e.g.
    // "API import after survey completion". Returns the entries written, as importRunLogEntries()
    // builds them, or [] when the run had nothing to log.
    public function logImportRun($description, $projectId, $record, $eventId, $instrument, array $summary)
    {
        try {
            $runId = bin2hex(random_bytes(6));
        } catch (\Throwable $e) {
            $runId = substr(md5(uniqid('', true)), 0, 12);   // same 12 hex characters the retry action expects
        }

        $entries = $this->importRunLogEntries($runId, $description, $projectId, $record, $eventId, $instrument, $summary);
        if (empty($entries)) {
            return [];
        }
        foreach ($entries as [$message, $parameters]) {
            $this->writeModuleLog($message, $parameters);
        }

        try {
            REDCap::logEvent("ADIF: $description", $this->projectLogText($instrument, $summary, $runId), null,
                $record, $eventId, $projectId);
        } catch (\Throwable $e) {
            error_log("ADIF: couldn't write to the project log for record $record: " . $e->getMessage());
        }

        return $entries;
    }

    // One import run's module log entries as [message, parameters] pairs: one per endpoint request,
    // then the summary. Blank parameters are left out, as writeModuleLog() would. This is exactly what
    // the Imports page reads back (see ImportStatus), so a retry can show its new run from these
    // without re-reading the log.
    // project_id is passed explicitly: log() otherwise takes it from $_GET['pid'], which isn't
    // guaranteed in every context a run can be logged from (survey pages, AJAX, crons), and an entry
    // logged without it is invisible to the Imports page, which reads one project's entries.
    private function importRunLogEntries($runId, $description, $projectId, $record, $eventId, $instrument, array $summary)
    {
        $requests = $summary['requests'] ?? [];
        if (empty($requests) && empty($summary['saved']) && empty($summary['skipped']) && empty($summary['errors'])) {
            return [];
        }

        $context = [
            'run_id' => $runId,
            'project_id' => $projectId,
            'record' => $record,
            'event_id' => $eventId,
            'instrument' => $instrument,
            'repeat_instance' => $summary['repeat_instance'] ?? null,
        ];

        $entries = [];
        $apiCalls = 0;
        foreach (array_values($requests) as $number => $request) {
            $apiCalls += count($request['calls'] ?? []);
            $entries[] = ['API import: endpoint request',
                $context + ['request' => ($number + 1) . ' of ' . count($requests)] + $this->requestLogParameters($request)];
        }

        $notPopulated = [];
        foreach ($summary['not_populated'] ?? [] as $field => $error) {
            $notPopulated[] = "$field: $error";
        }

        $entries[] = [$description, $context + [
            'requests' => count($requests),
            'api_calls' => $apiCalls,
            'elapsed_ms' => $summary['elapsed_ms'] ?? null,
            'saved' => implode(', ', $summary['saved'] ?? []),
            'not_populated' => implode("\n", $notPopulated),
            'skipped' => implode("\n", $summary['skipped'] ?? []),
            'errors' => implode("\n", $summary['errors'] ?? []),
            'notices' => implode("\n", array_unique($summary['notices'] ?? [])),
        ]];

        return array_map(fn($entry) => [$entry[0], self::withoutBlankLogParameters($entry[1])], $entries);
    }

    // One request's log parameters. The response figures are the last HTTP call's; there's at most one
    // per request today, and none when the cache answered.
    private function requestLogParameters(array $request)
    {
        $calls = $request['calls'] ?? [];
        $call = end($calls) ?: [];

        return [
            'source' => $request['source'] ?? '',
            'path' => $request['path'] ?? '',
            'outcome' => $request['outcome'] ?? 'not_sent',
            'api_calls' => count($calls),
            'http_status' => isset($call['status_code']) ? trim($call['status_code'] . ' ' . ($call['reason'] ?? '')) : null,
            'response_ms' => $call['elapsed_ms'] ?? null,
            'response_bytes' => $call['bytes'] ?? null,
            'response_json' => $call ? ($call['json'] ? 'yes' : 'no') : null,
            'elapsed_ms' => $request['elapsed_ms'] ?? null,
            'error' => $request['error'] ?? null,
            // Why the call failed when the request still succeeded from stored data ("stale")
            'response_error' => ($call['error'] ?? null) !== ($request['error'] ?? null) ? ($call['error'] ?? null) : null,
            'fields_requested' => implode(', ', $request['fields_requested'] ?? []),
            'fields_saved' => implode(', ', $request['fields_saved'] ?? []),
            'fields_skipped' => implode("\n", $request['fields_skipped'] ?? []),
            'fields_not_populated' => implode(', ', $request['fields_not_populated'] ?? []),
        ];
    }

    // What study staff see on the Logging page: the outcome per endpoint and which fields were left
    // empty, with a pointer to the module log for the rest. saveData already logs the values saved.
    private function projectLogText($instrument, array $summary, $runId)
    {
        $lines = [];
        if ($instrument) {
            $lines[] = "Instrument: $instrument";
        }
        $lines[] = 'Fields saved: ' . count($summary['saved'] ?? []);

        if (!empty($summary['not_populated'])) {
            $lines[] = 'Not populated due to errors: ' . implode(', ', array_keys($summary['not_populated']));
        }
        if (!empty($summary['skipped'])) {
            $lines[] = 'Skipped: ' . count($summary['skipped']);
        }

        foreach ($summary['requests'] ?? [] as $request) {
            $calls = $request['calls'] ?? [];
            $call = end($calls) ?: [];
            $outcome = match ($request['outcome']) {
                'hit' => 'stored data',
                'stale' => 'stored data (API unavailable)',
                'fetched' => 'HTTP ' . ($call['status_code'] ?? '?') . ', ' . ($call['elapsed_ms'] ?? '?') . ' ms',
                'not_sent' => 'not sent',
                default => $request['outcome'],
            };
            $line = "- {$request['source']}: $outcome";
            if ($request['error'] !== null) {
                $line .= " - {$request['error']}";
            }
            $lines[] = $line;
        }

        if (!empty($summary['notices'])) {
            $lines[] = 'Notices: ' . implode(' ', array_unique($summary['notices']));
        }
        $lines[] = "Details: ADIF module log, run_id $runId";

        return implode("\n", $lines);
    }

    // log() with blank parameters left out, and any failure reported to the PHP error log instead of thrown
    private function writeModuleLog($message, array $parameters)
    {
        $parameters = self::withoutBlankLogParameters($parameters);
        try {
            $this->log($message, $parameters);
        } catch (\Throwable $e) {
            error_log("ADIF: couldn't write \"$message\" to the module log: " . $e->getMessage());
        }
    }

    private static function withoutBlankLogParameters(array $parameters)
    {
        return array_filter($parameters, fn($value) => $value !== null && $value !== '' && $value !== []);
    }
}
