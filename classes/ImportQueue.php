<?php

namespace UKModules\ADIF;

// Mixed into UKModules\ADIF\ADIF alongside Import, ImportLog and ImportStatus. Takes imports off
// the survey respondent's path: when a survey is submitted, redcap_survey_complete only queues the
// import (queueImport), and the module's cron (processImportQueue, see "crons" in config.json) runs
// it within a minute or so through the same importMappedData() and logImportRun() as every other
// import, so a slow or unavailable API never holds up the completion page. A queued import that
// fails is flagged on the Imports page like any other run, and retried from there.
//
// The queue lives in the module log, like the runs themselves - appending an entry is atomic, so
// surveys submitted at the same moment can't overwrite each other's place in it:
//   "ADIF import queued"        one per survey submission: record, event_id, instrument,
//                               repeat_instance and a queue_id
//   "ADIF import queue started" written before each attempt, so an import that dies partway (a PHP
//                               fatal error, a killed process) is noticed; after $importQueueMaxAttempts
//                               such starts it's given up on and logged as a failed run
//   "ADIF import queue done"    the queue_id is finished with: the run_id it ran as, and outcome
//                               ('ran', or 'abandoned')
// An import is pending while its queued entry has no done entry. None of these messages start with
// "API import", so the run list and the Never imported check never read them as runs.
trait ImportQueue
{
    private static $importQueuedMessage = 'ADIF import queued';
    private static $importQueueStartedMessage = 'ADIF import queue started';
    private static $importQueueDoneMessage = 'ADIF import queue done';

    // The summary's description for a queued run, as the run list shows it
    private static $importQueuedDescription = 'API import after survey completion (in the background)';

    // A queued import that has started this many times without finishing is given up on
    private static $importQueueMaxAttempts = 3;

    // How long one cron run keeps taking imports off the queue (seconds). The next run carries on
    // where it stopped; this keeps one run well inside the cron's max run time (config.json).
    private static $importQueueCronSeconds = 240;

    // The Imports page warns when an import has been waiting longer than this (seconds): the cron
    // runs about once a minute, so a longer wait usually means REDCap's cron isn't running
    public static $importQueueOverdueSeconds = 600;

    // Whether this project runs imports in the background (the default) or while the respondent
    // waits ("import-immediately", for projects whose next survey pipes imported values right away)
    public function importsRunInBackground($projectId)
    {
        return !$this->getProjectSetting('import-immediately', $projectId);
    }

    // Puts an import on the queue. Throws when the entry can't be written, so the caller can run the
    // import straight away instead of losing it.
    public function queueImport($projectId, $record, $eventId, $instrument, $repeatInstance)
    {
        try {
            $queueId = bin2hex(random_bytes(6));
        } catch (\Throwable $e) {
            $queueId = substr(md5(uniqid('', true)), 0, 12);
        }

        $this->log(self::$importQueuedMessage, array_filter([
            'project_id' => $projectId,
            'record' => $record,
            'event_id' => $eventId,
            'instrument' => $instrument,
            'repeat_instance' => $repeatInstance,
            'queue_id' => $queueId,
        ], fn($value) => $value !== null && $value !== ''));

        return $queueId;
    }

    // The project's pending imports, oldest first: [queue_id, log_id, queued (unix time), record,
    // event_id, instrument, repeat_instance, attempts]. $record limits them to one record.
    public function importQueuePending($projectId, $record = null, $limit = 500)
    {
        $sql = 'select q.log_id, q.timestamp, q.record, qi.value as queue_id,'
            . ' e.value as event_id, i.value as instrument, r.value as repeat_instance'
            . ' from redcap_external_modules_log q'
            . ' join redcap_external_modules m on m.external_module_id = q.external_module_id'
            . ' and m.directory_prefix = ?'
            . ' join redcap_external_modules_log_parameters qi on qi.log_id = q.log_id and qi.name = \'queue_id\''
            . ' left join redcap_external_modules_log_parameters e on e.log_id = q.log_id and e.name = \'event_id\''
            . ' left join redcap_external_modules_log_parameters i on i.log_id = q.log_id and i.name = \'instrument\''
            . ' left join redcap_external_modules_log_parameters r on r.log_id = q.log_id and r.name = \'repeat_instance\''
            . ' where q.project_id = ? and q.message = ?'
            . ($record !== null ? ' and q.record = ?' : '')
            . ' and not exists (select 1 from redcap_external_modules_log d'
            . ' join redcap_external_modules_log_parameters dq on dq.log_id = d.log_id and dq.name = \'queue_id\''
            . ' where d.external_module_id = q.external_module_id and d.project_id = q.project_id'
            . ' and d.message = ? and dq.value = qi.value)'
            . ' order by q.log_id asc'
            . ' limit ' . max(1, (int) $limit);
        $parameters = array_merge([$this->getPrefix(), (int) $projectId, self::$importQueuedMessage],
            $record !== null ? [(string) $record] : [], [self::$importQueueDoneMessage]);

        $pending = [];
        foreach ($this->importQueryRows($sql, $parameters) as $row) {
            $pending[(string) $row['queue_id']] = [
                'queue_id' => (string) $row['queue_id'],
                'log_id' => (int) $row['log_id'],
                'queued' => self::importLogTime($row['timestamp'] ?? 0),
                'record' => (string) ($row['record'] ?? ''),
                'event_id' => is_numeric($row['event_id'] ?? null) ? (int) $row['event_id'] : null,
                'instrument' => ($row['instrument'] ?? '') !== '' ? (string) $row['instrument'] : null,
                'repeat_instance' => max(1, (int) ($row['repeat_instance'] ?? 1)),
                'attempts' => 0,
            ];
        }
        if (empty($pending)) {
            return [];
        }

        // How many times each has been started without finishing
        $ids = array_keys($pending);
        foreach ($this->importQueryRows(
            'select p.value as queue_id, count(*) as attempts'
            . ' from redcap_external_modules_log l'
            . ' join redcap_external_modules m on m.external_module_id = l.external_module_id'
            . ' and m.directory_prefix = ?'
            . ' join redcap_external_modules_log_parameters p on p.log_id = l.log_id and p.name = \'queue_id\''
            . ' where l.project_id = ? and l.message = ?'
            . ' and p.value in (' . implode(',', array_fill(0, count($ids), '?')) . ')'
            . ' group by p.value',
            array_merge([$this->getPrefix(), (int) $projectId, self::$importQueueStartedMessage], $ids)
        ) as $row) {
            if (isset($pending[(string) $row['queue_id']])) {
                $pending[(string) $row['queue_id']]['attempts'] = (int) $row['attempts'];
            }
        }

        return array_values($pending);
    }

    // The cron (see "crons" in config.json): works through the queue of every project the module is
    // enabled in, oldest first, for up to $importQueueCronSeconds. Settings are read for the project
    // in $_GET['pid'], as the framework documents for crons, so it's set for each project in turn.
    public function processImportQueue($cronInfo = [])
    {
        $deadline = time() + self::$importQueueCronSeconds;
        $hadPid = array_key_exists('pid', $_GET);
        $originalPid = $_GET['pid'] ?? null;
        $totals = ['ran' => 0, 'attention' => 0, 'abandoned' => 0, 'remaining' => 0];

        try {
            $projectIds = $this->getProjectsWithModuleEnabled();
            // A deleted project's imports wait: it may be restored, and if it's deleted for good its
            // queue goes with its module log (see ProjectCleanup)
            try {
                $status = $this->projectDeletionStatus($projectIds);
            } catch (\Throwable $e) {
                $status = [];
            }
            foreach ($projectIds as $projectId) {
                if (time() >= $deadline) {
                    break;
                }
                if (($status[(int) $projectId] ?? 'active') !== 'active') {
                    continue;
                }
                $_GET['pid'] = $projectId;
                try {
                    $result = $this->processProjectImportQueue($projectId, $deadline);
                    foreach ($totals as $key => $value) {
                        $totals[$key] = $value + ($result[$key] ?? 0);
                    }
                } catch (\Throwable $e) {
                    error_log("ADIF: the import queue for project $projectId couldn't be processed: " . $e->getMessage());
                }
            }
        } finally {
            if ($hadPid) {
                $_GET['pid'] = $originalPid;
            } else {
                unset($_GET['pid']);
            }
            try {
                $this->setSystemSetting('import-queue-last-run', time());
            } catch (\Throwable $e) {
                error_log("ADIF: couldn't record the import queue's last run: " . $e->getMessage());
            }
        }

        return 'ADIF import queue: ' . ($totals['ran'] + $totals['attention']) . " import(s) run ({$totals['attention']} "
            . "needing attention), {$totals['abandoned']} given up on, {$totals['remaining']} still waiting.";
    }

    // Runs one project's pending imports, oldest first, until $deadline or $limit imports. Several
    // queue entries for the same import (a survey submitted twice before the cron ran) run once, as
    // one import, and all of them are marked done with its run. Also called by the Imports page's
    // "Run queued imports now" button. Returns counts: ran, attention (ran but left fields empty),
    // abandoned, busy (being run elsewhere right now), remaining (still pending afterwards).
    public function processProjectImportQueue($projectId, $deadline, $limit = 200)
    {
        $counts = ['ran' => 0, 'attention' => 0, 'abandoned' => 0, 'busy' => 0, 'remaining' => 0];

        $groups = [];
        foreach ($this->importQueuePending($projectId, null, 1000) as $item) {
            $groups[self::importQueueKey($item)][] = $item;
        }

        $handled = 0;
        foreach ($groups as $key => $items) {
            if (time() >= $deadline || $handled >= $limit) {
                break;
            }
            $handled++;

            // The cron doesn't overlap itself, but the page's button can run alongside it
            $lock = 'import-queue-' . md5((int) $projectId . '|' . $key);
            if (!$this->importRetryAcquireLock($lock)) {
                $counts['busy']++;
                continue;
            }
            try {
                $outcome = $this->runQueuedImport($projectId, $items);
                $counts[$outcome]++;
            } finally {
                $this->importRetryReleaseLock($lock);
            }
        }

        $counts['remaining'] = count($this->importQueuePending($projectId, null, 1000));
        return $counts;
    }

    // Runs one import from the queue (every entry in $items is the same import) and marks its entries
    // done. Returns 'ran', 'attention' or 'abandoned'.
    private function runQueuedImport($projectId, array $items)
    {
        $item = $items[0];
        $queueIds = array_column($items, 'queue_id');
        $attempts = max(array_column($items, 'attempts'));

        if ($attempts >= self::$importQueueMaxAttempts) {
            // It stopped partway every time: give up, and say so as a failed run, so it's flagged on the
            // Imports page and can be retried there once the cause is fixed
            $summary = ['saved' => [], 'skipped' => [], 'notices' => [], 'not_populated' => [], 'requests' => [],
                'errors' => ["This queued import stopped before finishing $attempts times (a PHP fatal error or"
                    . ' a time limit, most likely - see the server\'s PHP error log), so ADIF stopped trying.'
                    . ' Retry it once the cause is fixed.'],
                'repeat_instance' => $item['repeat_instance']];
            $entries = $this->logImportRun(self::$importQueuedDescription, $projectId, $item['record'],
                $item['event_id'], $item['instrument'], $summary);
            $this->markImportQueueDone($projectId, $item['record'], $queueIds, $entries, 'abandoned');
            return 'abandoned';
        }

        foreach ($queueIds as $queueId) {
            $this->writeModuleLog(self::$importQueueStartedMessage,
                ['project_id' => $projectId, 'record' => $item['record'], 'queue_id' => $queueId]);
        }

        $start = microtime(true);
        try {
            $summary = $this->importMappedData($projectId, $item['record'], $item['event_id'], $item['instrument'],
                $item['repeat_instance']);
        } catch (\Throwable $e) {
            $summary = ['saved' => [], 'skipped' => [], 'errors' => [$e->getMessage()], 'requests' => []];
        }
        $summary['elapsed_ms'] = round((microtime(true) - $start) * 1000, 1);
        $summary['repeat_instance'] = $item['repeat_instance'];

        $entries = $this->logImportRun(self::$importQueuedDescription, $projectId, $item['record'],
            $item['event_id'], $item['instrument'], $summary);
        $this->markImportQueueDone($projectId, $item['record'], $queueIds, $entries, 'ran');

        // The same rule the run list applies to the logged entries
        $requestLogs = array_map(fn($entry) => $entry[1], array_slice($entries, 0, -1));
        $logged = end($entries) ?: [null, []];
        return $this->importRunNeedsAttention(['errors' => $logged[1]['errors'] ?? ''], $requestLogs) === 'attention'
            ? 'attention' : 'ran';
    }

    // Closes queue entries. Written even when the run itself couldn't be logged (no run_id then),
    // so a logging failure can't make the cron run the same import over and over.
    private function markImportQueueDone($projectId, $record, array $queueIds, array $entries, $outcome)
    {
        $runId = $entries[0][1]['run_id'] ?? null;
        foreach ($queueIds as $queueId) {
            $this->writeModuleLog(self::$importQueueDoneMessage, ['project_id' => $projectId, 'record' => $record,
                'queue_id' => $queueId, 'run_id' => $runId, 'outcome' => $outcome]);
        }
    }

    // Queue entries that are the same import: record, event, instrument and instance
    private static function importQueueKey(array $item)
    {
        return json_encode([$item['record'], $item['event_id'], $item['instrument'], $item['repeat_instance']]);
    }
}
