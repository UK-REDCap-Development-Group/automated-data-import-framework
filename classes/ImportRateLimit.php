<?php

namespace UKModules\ADIF;

// Mixed into UKModules\ADIF\ADIF. One server-wide limit on how many imports ADIF's background crons
// start a minute, set by administrators in the module's system settings ("adif-imports-per-minute"):
// the background survey import queue (ImportQueue), Refresh all records (ImportRefresh), Retry all
// failed (ImportRetryAll) and Import all under Never imported (ImportAll) all draw from it, however
// many of them are running at once, so together they never start more than that many imports a
// minute, spaced evenly rather than in bursts. Imports
// started by a single click (one retry, one first import) and imports run while a survey respondent
// waits ("import-immediately") aren't held to it.
//
// The limit works as a shared timetable: each import reserves the next free start time, one interval
// (60 / imports per minute seconds) after the last one reserved by any process, and waits for it.
// The next free time is a system setting, "import-next-slot", read and written under a MySQL named
// lock so two crons can't reserve the same time.
trait ImportRateLimit
{
    private static $importsPerMinuteDefault = 20;
    private static $importsPerMinuteMax = 300;
    private static $importSlotSetting = 'import-next-slot';
    private static $importSlotLock = 'adif-import-slots';

    // The server-wide limit. "adif-refresh-per-minute" is the setting's earlier name, still read when
    // the new one hasn't been set.
    public function importsPerMinute()
    {
        foreach (['adif-imports-per-minute', 'adif-refresh-per-minute'] as $key) {
            $value = $this->getSystemSetting($key);
            if (is_numeric($value) && (int) $value > 0) {
                return min((int) $value, self::$importsPerMinuteMax);
            }
        }
        return self::$importsPerMinuteDefault;
    }

    // Waits until this process may start its next background import, and returns true. Returns false
    // straight away, reserving nothing, when the next free start time is after $deadline (a unix
    // time): the caller should stop for now and carry on in its next cron run.
    public function awaitImportSlot($deadline)
    {
        $slot = $this->reserveImportSlot(60 / $this->importsPerMinute(), $deadline);
        if ($slot === null) {
            return false;
        }
        $wait = $slot - microtime(true);
        if ($wait > 0) {
            usleep((int) ($wait * 1000000));
        }
        return true;
    }

    // The start time reserved for this import (microtime), or null if it would be after $deadline
    private function reserveImportSlot($interval, $deadline)
    {
        $locked = false;
        try {
            $row = $this->query('select get_lock(?, 10) as got', [self::$importSlotLock])->fetch_assoc();
            $locked = (int) ($row['got'] ?? 0) === 1;
        } catch (\Throwable $e) {
            error_log('ADIF: the import rate limit lock is unavailable: ' . $e->getMessage());
        }
        // Without the lock this is best effort: two crons may now and then start an import together

        try {
            $now = microtime(true);
            $next = $this->readImportSlot();
            // A reservation further ahead than any real queue could make (the server's clock went
            // back, or the limit was raised) is dropped rather than waited out
            if ($next > $now + max(120, 2 * $interval)) {
                $next = $now;
            }
            $slot = max($now, $next);
            if ($slot > $deadline) {
                return null;
            }
            $this->setSystemSetting(self::$importSlotSetting, sprintf('%.3f', $slot + $interval));
            return $slot;
        } finally {
            if ($locked) {
                try {
                    $this->query('select release_lock(?)', [self::$importSlotLock]);
                } catch (\Throwable $e) {
                    error_log('ADIF: couldn\'t release the import rate limit lock: ' . $e->getMessage());
                }
            }
        }
    }

    // The next free start time, read from the database itself rather than through getSystemSetting(),
    // so a long-running cron sees what other processes have reserved since it started
    private function readImportSlot()
    {
        try {
            $row = $this->query('select s.value from redcap_external_module_settings s'
                . ' join redcap_external_modules m on m.external_module_id = s.external_module_id'
                . ' where m.directory_prefix = ? and s.project_id is null and s.`key` = ?',
                [$this->getPrefix(), self::$importSlotSetting])->fetch_assoc();
            return is_numeric($row['value'] ?? null) ? (float) $row['value'] : 0.0;
        } catch (\Throwable $e) {
            error_log('ADIF: couldn\'t read the import rate limit: ' . $e->getMessage());
            return (float) $this->getSystemSetting(self::$importSlotSetting);
        }
    }
}
