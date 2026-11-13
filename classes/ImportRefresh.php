<?php

namespace UKModules\ADIF;

use REDCap;

// Mixed into UKModules\ADIF\ADIF alongside Import, ImportLog, ImportStatus and ImportQueue. A bulk
// refresh re-runs the import for every place a project's imports write to (see
// ImportStatus::importPlacesWithData) in every record, so mapped fields are filled again with what the
// APIs say now - after a data release, say, or a change to the mappings.
//
// It's requested on the Import Status page (startImportRefresh) and run by the module's cron
// (processImportRefreshJobs, "adif_import_refresh" in config.json), never during the request:
//   - only during the off-peak hours set in the module's system settings (server time)
//   - at most a set number of imports a minute across the whole server, spaced out evenly within the
//     minute rather than in bursts, and one project per cron run, taking turns when several projects
//     have a refresh going
// so a large project's refresh is spread over as many nights as it needs. Each import is logged like
// any other run ("API import: bulk refresh requested by <user>"), so failures are flagged and retried
// on the Import Status page as usual.
//
// State is two project settings: "import-refresh-job", the refresh's status and counts, and
// "import-refresh-records", the records to work through, listed when it was requested (records
// created later are imported by their own surveys). Progress is saved after every record, so a cron
// run that stops partway loses at most one record's place, which is simply imported again.
trait ImportRefresh
{
    private static $importRefreshJobSetting = 'import-refresh-job';
    private static $importRefreshRecordsSetting = 'import-refresh-records';

    // Defaults for the system settings (config.json): 22:00 to 06:00 server time, 20 imports a minute
    private static $importRefreshDefaultStartHour = 22;
    private static $importRefreshDefaultEndHour = 6;
    private static $importRefreshDefaultPerMinute = 20;
    private static $importRefreshMaxPerMinute = 300;

    // How long one cron run keeps importing (seconds); the cron runs once a minute
    private static $importRefreshCronSeconds = 50;

    // Records read from the project in one getData() call
    private static $importRefreshChunk = 10;

    // The project's refresh: the latest one requested, finished or not, or null if there never was one.
    //   id, status (waiting | running | done | cancelled | failed), requested_by, requested_at,
    //   bypass_cache, record_count, next (how many records are done), imports, attention (imports that
    //   left mapped fields empty), skipped (places whose lookup values aren't in the record), started_at,
    //   last_worked_at, finished_at, cancelled_by, message (why a failed one stopped)
    public function importRefreshJob($projectId)
    {
        $job = $this->getProjectSetting(self::$importRefreshJobSetting, $projectId);
        return is_array($job) && isset($job['id']) ? $job : null;
    }

    public static function importRefreshActive($job)
    {
        return is_array($job) && in_array($job['status'] ?? '', ['waiting', 'running'], true);
    }

    // The off-peak hours and pace from the system settings, and whether $time (default now) is inside
    // them: start, end (hours, server time), per_minute, open. Equal start and end hours mean any time.
    public function importRefreshWindow($time = null)
    {
        $hour = function ($key, $default) {
            $value = $this->getSystemSetting($key);
            return is_numeric($value) && (int) $value >= 0 && (int) $value <= 23 ? (int) $value : $default;
        };
        $start = $hour('adif-refresh-start-hour', self::$importRefreshDefaultStartHour);
        $end = $hour('adif-refresh-end-hour', self::$importRefreshDefaultEndHour);

        $perMinute = $this->getSystemSetting('adif-refresh-per-minute');
        $perMinute = is_numeric($perMinute) && (int) $perMinute > 0
            ? min((int) $perMinute, self::$importRefreshMaxPerMinute) : self::$importRefreshDefaultPerMinute;

        $now = (int) date('G', $time ?? time());
        $open = $start === $end
            || ($start < $end ? $now >= $start && $now < $end : $now >= $start || $now < $end);

        return ['start' => $start, 'end' => $end, 'per_minute' => $perMinute, 'open' => $open];
    }

    // Requests a refresh of every record the current user can see (their data access group's, if
    // they're in one - see Access::adifVisibleRecords). Callers check the user may (Project Design
    // rights). Returns ['status' => 'started' | 'active' | 'nothing_mapped' | 'no_records', 'job' => ...].
    public function startImportRefresh($projectId, $bypassCache)
    {
        $job = $this->importRefreshJob($projectId);
        if (self::importRefreshActive($job)) {
            return ['status' => 'active', 'job' => $job];
        }
        if (empty($this->mappedInstruments($projectId))) {
            return ['status' => 'nothing_mapped', 'job' => $job];
        }

        $visible = $this->adifVisibleRecords($projectId);
        if ($visible !== null) {
            $records = array_map('strval', array_keys($visible));
        } else {
            $recordIdField = $this->getRecordIdField($projectId);
            $data = json_decode(REDCap::getData(['project_id' => $projectId, 'return_format' => 'json',
                'fields' => [$recordIdField]]), true);
            if (!is_array($data)) {
                throw new \Exception('ADIF couldn\'t read the project\'s records.');
            }
            $records = array_map('strval', array_column($data, $recordIdField));
        }
        $records = array_values(array_unique(array_filter($records, 'strlen')));
        if (empty($records)) {
            return ['status' => 'no_records', 'job' => $job];
        }
        sort($records, SORT_NATURAL);

        $user = $this->getUser();
        $job = [
            'id' => bin2hex(random_bytes(6)),
            'status' => 'waiting',
            'requested_by' => $user ? $user->getUsername() : '?',
            'requested_at' => time(),
            'bypass_cache' => (bool) $bypassCache,
            'record_count' => count($records),
            'next' => 0,
            'imports' => 0,
            'attention' => 0,
            'skipped' => 0,
            'started_at' => null,
            'last_worked_at' => null,
            'finished_at' => null,
            'cancelled_by' => null,
            'message' => null,
        ];
        // The records first: a job is only ever read with its records in place
        $this->setProjectSetting(self::$importRefreshRecordsSetting, $records, $projectId);
        $this->setProjectSetting(self::$importRefreshJobSetting, $job, $projectId);
        $this->writeModuleLog('ADIF bulk refresh requested', ['project_id' => $projectId,
            'refresh_id' => $job['id'], 'records' => count($records), 'bypass_cache' => $bypassCache ? 'yes' : 'no']);

        return ['status' => 'started', 'job' => $job];
    }

    // Stops the project's refresh where it is. Imports already run stay. Returns 'cancelled', or
    // 'not_active' when there's nothing to stop.
    public function cancelImportRefresh($projectId)
    {
        $job = $this->importRefreshJob($projectId);
        if (!self::importRefreshActive($job)) {
            return 'not_active';
        }
        $user = $this->getUser();
        $job['status'] = 'cancelled';
        $job['finished_at'] = time();
        $job['cancelled_by'] = $user ? $user->getUsername() : '?';
        $this->setProjectSetting(self::$importRefreshJobSetting, $job, $projectId);
        $this->setProjectSetting(self::$importRefreshRecordsSetting, null, $projectId);
        $this->writeModuleLog('ADIF bulk refresh cancelled', ['project_id' => $projectId, 'refresh_id' => $job['id'],
            'records_done' => $job['next'], 'imports' => $job['imports']]);
        return 'cancelled';
    }

    // The cron (see "crons" in config.json). Outside the off-peak hours it does nothing. Inside them it
    // works on one project's refresh for up to $importRefreshCronSeconds, at the server's pace; when
    // several projects have one going, they take turns, a cron run each. Settings are read for the
    // project in $_GET['pid'], as the framework documents for crons, as the import queue's cron does.
    public function processImportRefreshJobs($cronInfo = [])
    {
        $window = $this->importRefreshWindow();
        if (!$window['open']) {
            return sprintf('ADIF bulk refresh: outside the off-peak hours (%02d:00 to %02d:00).', $window['start'], $window['end']);
        }

        // One run at a time, should a run outlast the minute
        $lock = 'import-refresh-cron';
        if (!$this->importRetryAcquireLock($lock)) {
            return 'ADIF bulk refresh: the previous run is still going.';
        }

        $deadline = time() + self::$importRefreshCronSeconds;
        $hadPid = array_key_exists('pid', $_GET);
        $originalPid = $_GET['pid'] ?? null;
        try {
            $projectIds = $this->getProjectsWithModuleEnabled();
            try {
                $status = $this->projectDeletionStatus($projectIds);
            } catch (\Throwable $e) {
                $status = [];
            }

            $waiting = [];
            foreach ($projectIds as $projectId) {
                if (($status[(int) $projectId] ?? 'active') === 'active'
                    && self::importRefreshActive($this->importRefreshJob($projectId))) {
                    $waiting[] = (int) $projectId;
                }
            }
            if (empty($waiting)) {
                return 'ADIF bulk refresh: nothing to refresh.';
            }

            // The project after the one the last run worked on, so every refresh moves along
            sort($waiting);
            $last = (int) $this->getSystemSetting('import-refresh-last-project');
            $after = array_values(array_filter($waiting, fn($pid) => $pid > $last));
            $projectId = $after[0] ?? $waiting[0];
            $this->setSystemSetting('import-refresh-last-project', $projectId);

            $_GET['pid'] = $projectId;
            $result = $this->runImportRefreshBatch($projectId, $deadline, 60 / $window['per_minute']);
            return "ADIF bulk refresh, project $projectId: {$result['imports']} import(s) for {$result['records']}"
                . " record(s); {$result['remaining']} record(s) left.";
        } catch (\Throwable $e) {
            error_log('ADIF: the bulk refresh cron failed: ' . $e->getMessage());
            return 'ADIF bulk refresh failed: ' . $e->getMessage();
        } finally {
            if ($hadPid) {
                $_GET['pid'] = $originalPid;
            } else {
                unset($_GET['pid']);
            }
            $this->importRetryReleaseLock($lock);
        }
    }

    // Works through the project's refresh from where it left off until $deadline, the end of the
    // off-peak hours, or the last record, starting an import at most every $interval seconds.
    // Returns counts for this run: records, imports, remaining.
    private function runImportRefreshBatch($projectId, $deadline, $interval)
    {
        $counts = ['records' => 0, 'imports' => 0, 'remaining' => 0];
        $job = $this->importRefreshJob($projectId);
        if (!self::importRefreshActive($job)) {
            return $counts;
        }
        $records = $this->getProjectSetting(self::$importRefreshRecordsSetting, $projectId);
        $records = is_array($records) ? array_values($records) : [];

        if ($job['status'] === 'waiting') {
            $job['status'] = 'running';
            $job['started_at'] = time();
        }

        try {
            $project = new \Project($projectId);
            $shapes = $this->importUnitShapes($projectId, $project);
            $dictionary = REDCap::getDataDictionary($projectId, 'array');
        } catch (\Throwable $e) {
            $this->finishImportRefresh($projectId, $job, 'failed', 'ADIF couldn\'t read the project: ' . $e->getMessage());
            return $counts;
        }
        if (empty($shapes)) {
            $this->finishImportRefresh($projectId, $job, 'failed',
                'Nothing is mapped any more, so there was nothing to import. Map fields on the Mapping page and refresh again.');
            return $counts;
        }

        $mode = !empty($job['bypass_cache']) ? \UKModules\ADIF\Cache\FetchMode::Live : null;
        $description = 'API import: bulk refresh requested by ' . ($job['requested_by'] ?: '?');
        $lastStart = 0.0;
        $stillOpen = fn() => time() < $deadline && $this->importRefreshWindow()['open'];

        while ($job['next'] < count($records) && $stillOpen()) {
            $chunk = array_slice($records, $job['next'], self::$importRefreshChunk);
            try {
                $places = $this->importPlacesWithData($projectId, $project, $shapes, $dictionary, $chunk);
            } catch (\Throwable $e) {
                $this->finishImportRefresh($projectId, $job, 'failed', $e->getMessage());
                return $counts;
            }
            $placesByRecord = [];
            foreach ($places as $place) {
                $placesByRecord[$place['record']][] = $place;
            }

            foreach ($chunk as $record) {
                if (!$stillOpen()) {
                    break 2;
                }
                // A record deleted since the refresh was requested simply has no places
                foreach ($placesByRecord[(string) $record] ?? [] as $place) {
                    $shape = $place['shape'];
                    [, $importable] = $this->importUnitLookupCheck($place['rows'], $place['record'], $shape,
                        $place['instance'], $dictionary);
                    if (!$importable) {
                        $job['skipped']++;
                        continue;
                    }

                    // Spread the imports evenly over the minute rather than all at its start
                    $wait = $lastStart + $interval - microtime(true);
                    if ($wait > 0) {
                        usleep((int) ($wait * 1000000));
                    }
                    $lastStart = microtime(true);

                    if ($this->runRefreshImport($projectId, $place, $mode, $description) === 'attention') {
                        $job['attention']++;
                    }
                    $job['imports']++;
                    $counts['imports']++;
                }

                $job['next']++;
                $counts['records']++;
                if (!$this->saveImportRefreshProgress($projectId, $job)) {
                    return $counts;   // cancelled from the page meanwhile
                }
            }
        }

        $counts['remaining'] = max(0, count($records) - $job['next']);
        if ($counts['remaining'] === 0) {
            $this->finishImportRefresh($projectId, $job, 'done');
        } else {
            $this->saveImportRefreshProgress($projectId, $job);
        }
        return $counts;
    }

    // One place's import, logged like any other run. Returns 'attention' when it left mapped fields
    // empty (by the run list's rule), else 'ran'.
    private function runRefreshImport($projectId, array $place, $mode, $description)
    {
        $shape = $place['shape'];
        $start = microtime(true);
        try {
            $summary = $this->importMappedData($projectId, $place['record'], $shape['event_id'], $shape['instrument'],
                $place['instance'], $mode,
                $this->importUnitPlan($projectId, $shape['event_id'], $shape['instrument'], $place['instance']));
        } catch (\Throwable $e) {
            $summary = ['errors' => [$e->getMessage()]];
        }
        $summary += ['saved' => [], 'skipped' => [], 'errors' => [], 'notices' => [], 'not_populated' => [], 'requests' => []];
        $summary['elapsed_ms'] = round((microtime(true) - $start) * 1000, 1);
        $summary['repeat_instance'] = $place['instance'];

        $entries = $this->logImportRun($description, $projectId, $place['record'], $shape['event_id'],
            $shape['instrument'], $summary);

        $requestLogs = array_map(fn($entry) => $entry[1], array_slice($entries, 0, -1));
        $logged = end($entries) ?: [null, []];
        return $this->importRunNeedsAttention(['errors' => $logged[1]['errors'] ?? ''], $requestLogs) === 'attention'
            ? 'attention' : 'ran';
    }

    // Saves the refresh's progress, unless it was cancelled (or replaced) from the page since this run
    // read it, in which case that stands. Returns whether to carry on.
    private function saveImportRefreshProgress($projectId, array $job)
    {
        $stored = $this->importRefreshJob($projectId);
        if (($stored['id'] ?? null) !== $job['id'] || !self::importRefreshActive($stored)) {
            return false;
        }
        $job['last_worked_at'] = time();
        $this->setProjectSetting(self::$importRefreshJobSetting, $job, $projectId);
        return true;
    }

    private function finishImportRefresh($projectId, array $job, $status, $message = null)
    {
        $stored = $this->importRefreshJob($projectId);
        if (($stored['id'] ?? null) !== $job['id'] || !self::importRefreshActive($stored)) {
            return;   // cancelled meanwhile
        }
        $job['status'] = $status;
        $job['message'] = $message;
        $job['finished_at'] = $job['last_worked_at'] = time();
        $this->setProjectSetting(self::$importRefreshJobSetting, $job, $projectId);
        $this->setProjectSetting(self::$importRefreshRecordsSetting, null, $projectId);
        $this->writeModuleLog("ADIF bulk refresh $status", ['project_id' => $projectId, 'refresh_id' => $job['id'],
            'records_done' => $job['next'], 'imports' => $job['imports'], 'attention' => $job['attention'],
            'skipped' => $job['skipped'], 'message' => $message]);
    }
}
