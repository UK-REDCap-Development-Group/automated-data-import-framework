<?php

namespace UKModules\ADIF;

// Mixed into UKModules\ADIF\ADIF. Removes what ADIF keeps for a project once the project is deleted.
// REDCap has no hook for project deletion, so an hourly cron (cleanUpDeletedProjects, see "crons"
// in config.json) looks for projects ADIF holds anything for that have been deleted:
//
//   Deleted (redcap_projects.date_deleted is set; an administrator can still restore it):
//     - its cached API responses (project- and record-scoped entries in adif_api_cache) are removed.
//       They can hold identifying values (encrypted), and a restored project simply fetches again.
//     - its queued imports are left alone, and the queue cron skips the project (see ImportQueue).
//   Gone (permanently deleted - no row in redcap_projects any more):
//     - the files behind its uploaded data tables are marked deleted in redcap_edocs_metadata, as
//       DataTables does when a table is removed, so REDCap's own cron removes them from storage
//     - ADIF's module settings for it (mappings, API sources and keys, the table list) and its module
//       log entries (import runs, the queue) are removed
//   Uploaded tables are kept until then so that restoring a deleted project restores its tables.
//
// REDCap may already remove some of these with the project (its own files, rows tied to the project
// by a foreign key); everything here is a no-op when it has. Entries cached for sharing across
// projects ("system" scope: public, keyless sources only) belong to no project and stay.
//
// A permanently deleted project's settings may be gone by the time the cron runs, and with them the
// list of its tables' files. So each project's table files are also kept in a system setting
// ($tableEdocRegistryKey), updated whenever its tables change and refreshed by every cron run.
trait ProjectCleanup
{
    // System setting: [project_id => [edoc ids]] of each project's uploaded tables
    private static $tableEdocRegistryKey = 'adif-table-edocs';

    private static $projectDataRemovedMessage = 'ADIF data removed for a deleted project';

    // Records a project's table files in the registry; called whenever its tables change
    // (DataTables::saveDataTables). A failure is only logged: the next cron run refreshes the
    // registry from the project's settings anyway.
    private function registerDataTableEdocs($projectId, array $tables)
    {
        try {
            $registry = $this->dataTableEdocRegistry();
            $ids = self::edocIdsOf($tables);
            if (empty($ids)) {
                unset($registry[(int) $projectId]);
            } else {
                $registry[(int) $projectId] = $ids;
            }
            $this->setSystemSetting(self::$tableEdocRegistryKey, $registry);
        } catch (\Throwable $e) {
            error_log("ADIF: couldn't record project $projectId's table files for cleanup: " . $e->getMessage());
        }
    }

    // The cron. Returns a one-line summary for the cron log.
    public function cleanUpDeletedProjects($cronInfo = [])
    {
        $moduleId = $this->adifModuleId();
        if ($moduleId === null) {
            return 'ADIF cleanup: the module isn\'t registered, so there is nothing to clean up.';
        }

        $registry = $this->refreshDataTableEdocRegistry($moduleId);
        $storage = null;
        try {
            $storage = $this->apiCacheStorage();
        } catch (\Throwable $e) {
            error_log('ADIF cleanup: the API cache table is unavailable: ' . $e->getMessage());
        }

        // Every project ADIF holds anything for
        $projectIds = array_keys($registry);
        if ($storage !== null) {
            array_push($projectIds, ...$storage->projectIds());
        }
        foreach (['redcap_external_module_settings', 'redcap_external_modules_log'] as $table) {
            foreach ($this->importQueryRows(
                "select distinct project_id from $table where external_module_id = ? and project_id is not null",
                [$moduleId]
            ) as $row) {
                $projectIds[] = (int) $row['project_id'];
            }
        }
        $status = $this->projectDeletionStatus($projectIds);

        $cleaned = 0;
        foreach ($status as $projectId => $state) {
            if ($state === 'active') {
                continue;
            }
            try {
                $removed = ['cache_entries' => $storage !== null ? $storage->purgeProjectData($projectId) : 0];
                if ($state === 'gone') {
                    $removed += $this->removeGoneProjectData($moduleId, $projectId, $registry[$projectId] ?? []);
                    unset($registry[$projectId]);
                }
                if (array_sum($removed) > 0) {
                    $cleaned++;
                    // A system-level entry: the project's own log is gone or about to be
                    $this->writeModuleLog(self::$projectDataRemovedMessage, ['deleted_project_id' => $projectId,
                        'project_state' => $state === 'gone' ? 'permanently deleted' : 'deleted'] + $removed);
                }
            } catch (\Throwable $e) {
                error_log("ADIF cleanup: project $projectId's data couldn't all be removed: " . $e->getMessage());
            }
        }

        $this->setSystemSetting(self::$tableEdocRegistryKey, $registry);

        return "ADIF cleanup: removed data for $cleaned deleted project(s).";
    }

    // Everything left of a permanently deleted project: its table files (marked deleted for REDCap's
    // cron to remove), and ADIF's module settings and log entries for it. Returns counts.
    private function removeGoneProjectData($moduleId, $projectId, array $edocIds)
    {
        $removed = ['table_files' => 0, 'settings' => 0, 'log_entries' => 0];

        if (!empty($edocIds)) {
            // Only files still recorded under this project (or under no project, if REDCap cleared it),
            // so a stale registry can never touch another project's file
            $in = implode(',', array_fill(0, count($edocIds), '?'));
            $where = "doc_id in ($in) and delete_date is null and (project_id = ? or project_id is null)";
            $params = array_merge(array_map('intval', $edocIds), [(int) $projectId]);
            $removed['table_files'] = $this->cleanupCount("select count(*) as n from redcap_edocs_metadata where $where", $params);
            if ($removed['table_files'] > 0) {
                $this->query("update redcap_edocs_metadata set delete_date = now() where $where", $params);
            }
        }

        $params = [(int) $moduleId, (int) $projectId];
        $removed['settings'] = $this->cleanupCount(
            'select count(*) as n from redcap_external_module_settings where external_module_id = ? and project_id = ?', $params);
        if ($removed['settings'] > 0) {
            $this->query('delete from redcap_external_module_settings where external_module_id = ? and project_id = ?', $params);
        }

        $removed['log_entries'] = $this->cleanupCount(
            'select count(*) as n from redcap_external_modules_log where external_module_id = ? and project_id = ?', $params);
        if ($removed['log_entries'] > 0) {
            $this->query('delete p from redcap_external_modules_log_parameters p'
                . ' join redcap_external_modules_log l on l.log_id = p.log_id'
                . ' where l.external_module_id = ? and l.project_id = ?', $params);
            $this->query('delete from redcap_external_modules_log where external_module_id = ? and project_id = ?', $params);
        }

        return $removed;
    }

    // [project_id => 'active' | 'deleted' | 'gone'] for the given projects: 'deleted' while REDCap
    // has the project marked deleted (it can still be restored), 'gone' once its row has been removed
    public function projectDeletionStatus(array $projectIds)
    {
        $projectIds = array_values(array_unique(array_filter(array_map('intval', $projectIds))));
        if (empty($projectIds)) {
            return [];
        }

        $status = array_fill_keys($projectIds, 'gone');
        foreach ($this->importQueryRows(
            'select project_id, date_deleted from redcap_projects where project_id in ('
            . implode(',', array_fill(0, count($projectIds), '?')) . ')',
            $projectIds
        ) as $row) {
            $status[(int) $row['project_id']] = ($row['date_deleted'] ?? null) === null || $row['date_deleted'] === ''
                ? 'active' : 'deleted';
        }
        ksort($status);

        return $status;
    }

    // The registry, brought up to date from every project's "data-tables" setting still in the
    // database. Projects whose settings are gone keep their last known files.
    private function refreshDataTableEdocRegistry($moduleId)
    {
        $registry = $this->dataTableEdocRegistry();
        foreach ($this->importQueryRows(
            'select project_id, value from redcap_external_module_settings'
            . ' where external_module_id = ? and project_id is not null and `key` = ?',
            [(int) $moduleId, 'data-tables']
        ) as $row) {
            $tables = json_decode((string) ($row['value'] ?? ''), true);
            $ids = is_array($tables) ? self::edocIdsOf($tables) : [];
            if (empty($ids)) {
                unset($registry[(int) $row['project_id']]);
            } else {
                $registry[(int) $row['project_id']] = $ids;
            }
        }

        return $registry;
    }

    private function dataTableEdocRegistry()
    {
        $registry = $this->getSystemSetting(self::$tableEdocRegistryKey);
        if (!is_array($registry)) {
            return [];
        }
        $clean = [];
        foreach ($registry as $projectId => $ids) {
            if (is_array($ids) && (int) $projectId > 0) {
                $clean[(int) $projectId] = array_values(array_filter(array_map('intval', $ids)));
            }
        }
        return $clean;
    }

    private static function edocIdsOf(array $tables)
    {
        $ids = [];
        foreach ($tables as $table) {
            if (is_array($table) && (int) ($table['edoc_id'] ?? 0) > 0) {
                $ids[] = (int) $table['edoc_id'];
            }
        }
        return array_values(array_unique($ids));
    }

    // This module's id in redcap_external_modules, or null
    private function adifModuleId()
    {
        $rows = $this->importQueryRows('select external_module_id from redcap_external_modules where directory_prefix = ?',
            [$this->getPrefix()]);
        return isset($rows[0]['external_module_id']) ? (int) $rows[0]['external_module_id'] : null;
    }

    private function cleanupCount($sql, array $params)
    {
        $rows = $this->importQueryRows($sql, $params);
        return (int) ($rows[0]['n'] ?? 0);
    }
}
