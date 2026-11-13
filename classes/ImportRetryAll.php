<?php

namespace UKModules\ADIF;

// Mixed into UKModules\ADIF\ADIF alongside ImportStatus and ImportRefresh. "Retry all failed" on the
// Import Status page doesn't retry anything while the page waits: it saves a retry job listing the
// imports to re-run (startRetryAll), and the module's cron (processRetryAllJobs, "adif_retry_all" in
// config.json) works through it in the background, usually starting within a minute. Unlike a bulk
// refresh it isn't held to the off-peak hours, since a short list of failures is wanted soon, but it
// draws from the same server-wide limit on background imports a minute (see ImportRateLimit).
//
// Each import is re-run by ImportStatus::retryImportRun(), the same as the per-run Retry button, and
// logged as "API import: retry all failed, requested by <user>". Just before each one, the run is
// checked against the project's current retry targets (ImportStatus::importRetryTargets): one whose
// import has run again since, was dismissed, or has dropped out of the latest 500 runs is skipped.
//
// State is one project setting, "import-retry-all-job" (see importRetryAllJob()). Progress is saved
// after every retry, re-reading the job first so a cancel from the page wins.
trait ImportRetryAll
{
    private static $retryAllJobSetting = 'import-retry-all-job';

    // How long one cron run keeps retrying (seconds); the cron runs once a minute
    private static $retryAllCronSeconds = 50;

    // The project's latest retry job, finished or not, or null if there never was one:
    //   id, status (waiting | running | done | cancelled | failed), requested_by, requested_at,
    //   bypass_cache, run_ids (the runs to retry, newest first), next (how many are done), retried
    //   (re-run and now fine), attention (re-run but still leaving fields empty), skipped (no longer
    //   needing a retry, their fields no longer mapped, or being retried by someone else at that
    //   moment), failed (the retry itself errored), started_at, last_worked_at, finished_at,
    //   cancelled_by, message (why a failed job stopped)
    public function importRetryAllJob($projectId)
    {
        $job = $this->getProjectSetting(self::$retryAllJobSetting, $projectId);
        return is_array($job) && isset($job['id']) ? $job : null;
    }

    public static function retryAllActive($job)
    {
        return is_array($job) && in_array($job['status'] ?? '', ['waiting', 'running'], true);
    }

    // Queues a retry of every import whose latest run needs attention, among the runs the current user
    // can see (their data access group's, if they're in one), or only $record's when given (the page's
    // record filter). Callers check the user may resolve imports (see Access::canResolveImports).
    // Returns ['status' => 'started' | 'active' | 'nothing_to_retry', 'job' => ...].
    public function startRetryAll($projectId, $bypassCache, $record = '')
    {
        $job = $this->importRetryAllJob($projectId);
        if (self::retryAllActive($job)) {
            return ['status' => 'active', 'job' => $job];
        }

        $runs = $this->onlyVisibleRecords($projectId, $this->importRuns($projectId, ['limit' => 500]));
        $recordOfRun = array_column($runs, 'record', 'run_id');
        $runIds = array_values(array_filter($this->importRetryTargets($projectId, $runs),
            fn($runId) => (string) $record === '' || (string) ($recordOfRun[$runId] ?? '') === (string) $record));
        if (empty($runIds)) {
            return ['status' => 'nothing_to_retry', 'job' => $job];
        }

        $user = $this->getUser();
        $job = [
            'id' => bin2hex(random_bytes(6)),
            'status' => 'waiting',
            'requested_by' => $user ? $user->getUsername() : '?',
            'requested_at' => time(),
            'bypass_cache' => (bool) $bypassCache,
            'run_ids' => $runIds,
            'next' => 0,
            'retried' => 0,
            'attention' => 0,
            'skipped' => 0,
            'failed' => 0,
            'started_at' => null,
            'last_worked_at' => null,
            'finished_at' => null,
            'cancelled_by' => null,
            'message' => null,
        ];
        $this->setProjectSetting(self::$retryAllJobSetting, $job, $projectId);
        $this->writeModuleLog('ADIF retry all requested', ['project_id' => $projectId, 'retry_all_id' => $job['id'],
            'runs' => count($runIds), 'bypass_cache' => $bypassCache ? 'yes' : 'no']);

        return ['status' => 'started', 'job' => $job];
    }

    // Stops the project's retry job where it is; retries already run stay. Returns 'cancelled', or
    // 'not_active' when there's nothing to stop.
    public function cancelRetryAll($projectId)
    {
        $job = $this->importRetryAllJob($projectId);
        if (!self::retryAllActive($job)) {
            return 'not_active';
        }
        $user = $this->getUser();
        $job['status'] = 'cancelled';
        $job['finished_at'] = time();
        $job['cancelled_by'] = $user ? $user->getUsername() : '?';
        $this->setProjectSetting(self::$retryAllJobSetting, $job, $projectId);
        $this->writeModuleLog('ADIF retry all cancelled', ['project_id' => $projectId, 'retry_all_id' => $job['id'],
            'runs_done' => $job['next']]);
        return 'cancelled';
    }

    // The cron (see "crons" in config.json): works through the projects' retry jobs for up to
    // $retryAllCronSeconds, starting with the project after the one the last run began with, so every
    // project's job moves along when several are waiting. Settings are read for the project in
    // $_GET['pid'], as the framework documents for crons.
    public function processRetryAllJobs($cronInfo = [])
    {
        $lock = 'import-retry-all-cron';
        if (!$this->importRetryAcquireLock($lock)) {
            return 'ADIF retry all: the previous run is still going.';
        }

        $deadline = time() + self::$retryAllCronSeconds;
        $hadPid = array_key_exists('pid', $_GET);
        $originalPid = $_GET['pid'] ?? null;
        $totals = ['projects' => 0, 'retries' => 0];
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
                    && self::retryAllActive($this->importRetryAllJob($projectId))) {
                    $waiting[] = (int) $projectId;
                }
            }
            if (empty($waiting)) {
                return 'ADIF retry all: nothing waiting.';
            }

            sort($waiting);
            $last = (int) $this->getSystemSetting('import-retry-all-last-project');
            $first = array_values(array_filter($waiting, fn($pid) => $pid > $last))[0] ?? $waiting[0];
            $ordered = array_merge(array_filter($waiting, fn($pid) => $pid >= $first),
                array_filter($waiting, fn($pid) => $pid < $first));
            $this->setSystemSetting('import-retry-all-last-project', $first);

            foreach ($ordered as $projectId) {
                if (time() >= $deadline) {
                    break;
                }
                $_GET['pid'] = $projectId;
                try {
                    $totals['retries'] += $this->runRetryAllBatch($projectId, $deadline);
                    $totals['projects']++;
                } catch (\Throwable $e) {
                    error_log("ADIF: retry all for project $projectId failed: " . $e->getMessage());
                }
            }
            return "ADIF retry all: {$totals['retries']} retr" . ($totals['retries'] === 1 ? 'y' : 'ies')
                . " in {$totals['projects']} project(s).";
        } finally {
            if ($hadPid) {
                $_GET['pid'] = $originalPid;
            } else {
                unset($_GET['pid']);
            }
            $this->importRetryReleaseLock($lock);
        }
    }

    // Works through one project's retry job from where it left off until $deadline or the end of its
    // list, within the server-wide import limit (see ImportRateLimit). Returns how many it re-ran.
    private function runRetryAllBatch($projectId, $deadline)
    {
        $job = $this->importRetryAllJob($projectId);
        if (!self::retryAllActive($job)) {
            return 0;
        }
        if ($job['status'] === 'waiting') {
            $job['status'] = 'running';
            $job['started_at'] = time();
        }

        // Which of the listed runs still need a retry, as of now
        try {
            $runs = $this->importRuns($projectId, ['limit' => 500]);
            $current = array_flip($this->importRetryTargets($projectId, $runs));
            $runsById = array_column($runs, null, 'run_id');
        } catch (\Throwable $e) {
            $this->finishRetryAll($projectId, $job, 'failed', 'ADIF couldn\'t read the import runs: ' . $e->getMessage());
            return 0;
        }

        $requestedBy = $job['requested_by'] ?: '?';
        $retries = 0;
        while ($job['next'] < count($job['run_ids']) && time() < $deadline) {
            $runId = (string) $job['run_ids'][$job['next']];
            if (!isset($current[$runId], $runsById[$runId])) {
                $job['skipped']++;   // run again, dismissed, or no longer among the latest 500 runs
            } else {
                // This retry's turn under the server-wide limit; none before the deadline means the
                // next cron run carries on from this run
                if (!$this->awaitImportSlot($deadline)) {
                    break;
                }

                try {
                    $result = $this->retryImportRun($projectId, $runsById[$runId], !empty($job['bypass_cache']), $requestedBy);
                } catch (\Throwable $e) {
                    $result = ['status' => 'error', 'message' => $e->getMessage()];
                }
                $retries++;
                if ($result['status'] === 'success') {
                    $job[($result['run']['outcome'] ?? '') === 'attention' ? 'attention' : 'retried']++;
                } elseif (in_array($result['status'], ['not_mapped', 'locked'], true)) {
                    $job['skipped']++;
                } else {
                    $job['failed']++;
                    error_log("ADIF: retry all couldn't retry run $runId in project $projectId: " . ($result['message'] ?? '?'));
                }
            }

            $job['next']++;
            if (!$this->saveRetryAllProgress($projectId, $job)) {
                return $retries;   // cancelled from the page meanwhile
            }
        }

        if ($job['next'] >= count($job['run_ids'])) {
            $this->finishRetryAll($projectId, $job, 'done');
        }
        return $retries;
    }

    // Saves the job's progress unless it was cancelled (or replaced) since this run read it, in which
    // case that stands. Returns whether to carry on.
    private function saveRetryAllProgress($projectId, array $job)
    {
        $stored = $this->importRetryAllJob($projectId);
        if (($stored['id'] ?? null) !== $job['id'] || !self::retryAllActive($stored)) {
            return false;
        }
        $job['last_worked_at'] = time();
        $this->setProjectSetting(self::$retryAllJobSetting, $job, $projectId);
        return true;
    }

    private function finishRetryAll($projectId, array $job, $status, $message = null)
    {
        $stored = $this->importRetryAllJob($projectId);
        if (($stored['id'] ?? null) !== $job['id'] || !self::retryAllActive($stored)) {
            return;   // cancelled meanwhile
        }
        $job['status'] = $status;
        $job['message'] = $message;
        $job['finished_at'] = $job['last_worked_at'] = time();
        $this->setProjectSetting(self::$retryAllJobSetting, $job, $projectId);
        $this->writeModuleLog("ADIF retry all $status", ['project_id' => $projectId, 'retry_all_id' => $job['id'],
            'runs_done' => $job['next'], 'retried' => $job['retried'], 'attention' => $job['attention'],
            'skipped' => $job['skipped'], 'failed' => $job['failed'], 'message' => $message]);
    }
}
