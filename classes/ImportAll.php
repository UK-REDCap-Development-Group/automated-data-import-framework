<?php

namespace UKModules\ADIF;

// Mixed into UKModules\ADIF\ADIF alongside ImportStatus and ImportRetryAll. "Import all" under Never
// imported on the Import Status page works the way Retry all failed does: it doesn't import anything
// while the page waits, but saves a job listing the places to import (startImportAll), and the
// module's cron (processImportAllJobs, "adif_import_all" in config.json) works through it in the
// background, usually starting within a minute. Like Retry all it isn't held to the bulk refresh's
// off-peak hours, and it draws from the same server-wide limit on background imports a minute (see
// ImportRateLimit).
//
// Each place is imported by ImportStatus::runFirstImport(), the same as the per-row Import button,
// and logged as "API import: import all, requested by <user>". runFirstImport() looks the place up
// again first, so one imported since the job was queued (a survey, the row's own button), whose
// fields have values now, or whose lookup values are still missing is skipped rather than imported.
//
// State is one project setting, "import-all-job" (see importAllJob()). Progress is saved after every
// import, re-reading the job first so a cancel from the page wins.
trait ImportAll
{
    private static $importAllJobSetting = 'import-all-job';

    // How long one cron run keeps importing (seconds); the cron runs once a minute
    private static $importAllCronSeconds = 50;

    // The project's latest Import all job, finished or not, or null if there never was one:
    //   id, status (waiting | running | done | cancelled | failed), requested_by, requested_at,
    //   places (the places to import: [record, event_id, instrument, instance], as listed when it was
    //   requested), next (how many are done), imported (now filled), attention (imported but leaving
    //   fields empty), skipped (no longer waiting on a first import, still missing lookup values, or
    //   being imported by someone else at that moment), failed (the import itself errored),
    //   started_at, last_worked_at, finished_at, cancelled_by, message (why a failed job stopped)
    public function importAllJob($projectId)
    {
        $job = $this->getProjectSetting(self::$importAllJobSetting, $projectId);
        return is_array($job) && isset($job['id']) ? $job : null;
    }

    public static function importAllActive($job)
    {
        return is_array($job) && in_array($job['status'] ?? '', ['waiting', 'running'], true);
    }

    // Queues the first import of every place listed under Never imported that has the values its
    // lookups need, among the records the current user can see (their data access group's, if they're
    // in one), or only $record's when given (the page's record filter). Callers check the user may
    // resolve imports (see Access::canResolveImports).
    // Returns ['status' => 'started' | 'active' | 'nothing_to_import', 'job' => ...].
    public function startImportAll($projectId, $record = '')
    {
        $job = $this->importAllJob($projectId);
        if (self::importAllActive($job)) {
            return ['status' => 'active', 'job' => $job];
        }

        $places = [];
        foreach ($this->onlyVisibleRecords($projectId,
            $this->importNeverAttempted($projectId, (string) $record !== '' ? (string) $record : null)) as $unit) {
            if ($unit['importable']) {
                $places[] = [(string) $unit['record'], (int) $unit['event_id'], $unit['instrument'], (int) $unit['repeat_instance']];
            }
        }
        if (empty($places)) {
            return ['status' => 'nothing_to_import', 'job' => $job];
        }

        $user = $this->getUser();
        $job = [
            'id' => bin2hex(random_bytes(6)),
            'status' => 'waiting',
            'requested_by' => $user ? $user->getUsername() : '?',
            'requested_at' => time(),
            'places' => $places,
            'next' => 0,
            'imported' => 0,
            'attention' => 0,
            'skipped' => 0,
            'failed' => 0,
            'started_at' => null,
            'last_worked_at' => null,
            'finished_at' => null,
            'cancelled_by' => null,
            'message' => null,
        ];
        $this->setProjectSetting(self::$importAllJobSetting, $job, $projectId);
        $this->writeModuleLog('ADIF import all requested', ['project_id' => $projectId, 'import_all_id' => $job['id'],
            'places' => count($places), 'records' => count(array_unique(array_column($places, 0)))]);

        return ['status' => 'started', 'job' => $job];
    }

    // Stops the project's Import all job where it is; imports already run stay. Returns 'cancelled',
    // or 'not_active' when there's nothing to stop.
    public function cancelImportAll($projectId)
    {
        $job = $this->importAllJob($projectId);
        if (!self::importAllActive($job)) {
            return 'not_active';
        }
        $user = $this->getUser();
        $job['status'] = 'cancelled';
        $job['finished_at'] = time();
        $job['cancelled_by'] = $user ? $user->getUsername() : '?';
        $this->setProjectSetting(self::$importAllJobSetting, $job, $projectId);
        $this->writeModuleLog('ADIF import all cancelled', ['project_id' => $projectId, 'import_all_id' => $job['id'],
            'places_done' => $job['next']]);
        return 'cancelled';
    }

    // The cron (see "crons" in config.json): works through the projects' Import all jobs for up to
    // $importAllCronSeconds, starting with the project after the one the last run began with, so every
    // project's job moves along when several are waiting. Settings are read for the project in
    // $_GET['pid'], as the framework documents for crons.
    public function processImportAllJobs($cronInfo = [])
    {
        $lock = 'import-all-cron';
        if (!$this->importRetryAcquireLock($lock)) {
            return 'ADIF import all: the previous run is still going.';
        }

        $deadline = time() + self::$importAllCronSeconds;
        $hadPid = array_key_exists('pid', $_GET);
        $originalPid = $_GET['pid'] ?? null;
        $totals = ['projects' => 0, 'imports' => 0];
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
                    && self::importAllActive($this->importAllJob($projectId))) {
                    $waiting[] = (int) $projectId;
                }
            }
            if (empty($waiting)) {
                return 'ADIF import all: nothing waiting.';
            }

            sort($waiting);
            $last = (int) $this->getSystemSetting('import-all-last-project');
            $first = array_values(array_filter($waiting, fn($pid) => $pid > $last))[0] ?? $waiting[0];
            $ordered = array_merge(array_filter($waiting, fn($pid) => $pid >= $first),
                array_filter($waiting, fn($pid) => $pid < $first));
            $this->setSystemSetting('import-all-last-project', $first);

            foreach ($ordered as $projectId) {
                if (time() >= $deadline) {
                    break;
                }
                $_GET['pid'] = $projectId;
                try {
                    $totals['imports'] += $this->runImportAllBatch($projectId, $deadline);
                    $totals['projects']++;
                } catch (\Throwable $e) {
                    error_log("ADIF: import all for project $projectId failed: " . $e->getMessage());
                }
            }
            return "ADIF import all: {$totals['imports']} import(s) in {$totals['projects']} project(s).";
        } finally {
            if ($hadPid) {
                $_GET['pid'] = $originalPid;
            } else {
                unset($_GET['pid']);
            }
            $this->importRetryReleaseLock($lock);
        }
    }

    // Works through one project's Import all job from where it left off until $deadline or the end of
    // its list, within the server-wide import limit (see ImportRateLimit). Returns how many it ran.
    private function runImportAllBatch($projectId, $deadline)
    {
        $job = $this->importAllJob($projectId);
        if (!self::importAllActive($job)) {
            return 0;
        }
        if ($job['status'] === 'waiting') {
            $job['status'] = 'running';
            $job['started_at'] = time();
        }

        $requestedBy = $job['requested_by'] ?: '?';
        $imports = 0;
        while ($job['next'] < count($job['places']) && time() < $deadline) {
            [$record, $eventId, $instrument, $instance] = $job['places'][$job['next']];

            // This import's turn under the server-wide limit; none before the deadline means the next
            // cron run carries on from this place
            if (!$this->awaitImportSlot($deadline)) {
                break;
            }

            try {
                $result = $this->runFirstImport($projectId, (string) $record, (int) $eventId,
                    $instrument === null || $instrument === '' ? null : (string) $instrument, (int) $instance, $requestedBy);
            } catch (\Throwable $e) {
                $result = ['status' => 'error', 'message' => $e->getMessage()];
            }
            if ($result['status'] === 'success') {
                $imports++;
                $job[($result['run']['outcome'] ?? '') === 'attention' ? 'attention' : 'imported']++;
            } elseif (in_array($result['status'], ['not_pending', 'not_ready', 'locked'], true)) {
                $job['skipped']++;
            } else {
                $job['failed']++;
                error_log("ADIF: import all couldn't import record $record (event $eventId) in project $projectId: "
                    . ($result['message'] ?? '?'));
            }

            $job['next']++;
            if (!$this->saveImportAllProgress($projectId, $job)) {
                return $imports;   // cancelled from the page meanwhile
            }
        }

        if ($job['next'] >= count($job['places'])) {
            $this->finishImportAll($projectId, $job, 'done');
        }
        return $imports;
    }

    // Saves the job's progress unless it was cancelled (or replaced) since this run read it, in which
    // case that stands. Returns whether to carry on.
    private function saveImportAllProgress($projectId, array $job)
    {
        $stored = $this->importAllJob($projectId);
        if (($stored['id'] ?? null) !== $job['id'] || !self::importAllActive($stored)) {
            return false;
        }
        $job['last_worked_at'] = time();
        $this->setProjectSetting(self::$importAllJobSetting, $job, $projectId);
        return true;
    }

    private function finishImportAll($projectId, array $job, $status, $message = null)
    {
        $stored = $this->importAllJob($projectId);
        if (($stored['id'] ?? null) !== $job['id'] || !self::importAllActive($stored)) {
            return;   // cancelled meanwhile
        }
        $job['status'] = $status;
        $job['message'] = $message;
        $job['finished_at'] = $job['last_worked_at'] = time();
        $this->setProjectSetting(self::$importAllJobSetting, $job, $projectId);
        $this->writeModuleLog("ADIF import all $status", ['project_id' => $projectId, 'import_all_id' => $job['id'],
            'places_done' => $job['next'], 'imported' => $job['imported'], 'attention' => $job['attention'],
            'skipped' => $job['skipped'], 'failed' => $job['failed'], 'message' => $message]);
    }
}
