<?php

namespace UKModules\ADIF;

use REDCap;

// Which instruments' completion starts an import, chosen on the Mapping page ("Import Triggers", saved
// by scripts/save_triggers.php) as the "import-triggers" project setting:
//   { "instruments": ["contact", ...], "mode": "any" | "all" }
//   any - completing any of them starts an import
//   all - completing one starts an import once every one of them that the event has is complete
// Applies to the imports that start by themselves: a survey's completion (ADIF::redcap_survey_complete)
// and the question asked after a data entry save (ImportDataEntry), where "completed" means saved with
// the form status Complete. Not to imports started by hand on the Import Status page (retries, Import
// all, Refresh all records, Run queued imports now), nor to imports already queued.
// With no instruments chosen, nothing changes: a survey completed in an event with mappings imports,
// and a data entry save of a form with mapped or lookup fields asks.
// Completion is read within the event being imported. In a longitudinal project, a chosen instrument
// the event doesn't have is left out of "all".
trait ImportTriggers
{
    private static $importTriggersSetting = 'import-triggers';

    // ['instruments' => [...], 'mode' => 'any' | 'all'], with instruments the project no longer has left out
    public function getImportTriggers($projectId)
    {
        $saved = $this->getProjectSetting(self::$importTriggersSetting, $projectId);
        $saved = is_array($saved) ? $saved : [];
        $existing = $this->importTriggerProjectForms($projectId);
        return [
            'instruments' => array_values(array_intersect(array_map('strval', (array) ($saved['instruments'] ?? [])), $existing)),
            'mode' => ($saved['mode'] ?? '') === 'all' ? 'all' : 'any',
        ];
    }

    // The project's instruments, in project order
    private function importTriggerProjectForms($projectId)
    {
        return array_keys((new \Project($projectId))->forms ?? []);
    }

    public function importTriggersConfigured($projectId)
    {
        return !empty($this->getImportTriggers($projectId)['instruments']);
    }

    // Saves the Mapping page's choice and logs what changed (see AuditLog). Instruments the project
    // doesn't have are dropped. Returns what was saved, as getImportTriggers() reads it.
    public function saveImportTriggers($projectId, array $instruments, $mode)
    {
        $before = $this->getImportTriggers($projectId);
        $existing = $this->importTriggerProjectForms($projectId);
        $instruments = array_values(array_intersect($existing, array_map('strval', $instruments)));   // project order
        $mode = $mode === 'all' ? 'all' : 'any';

        $this->setProjectSetting(self::$importTriggersSetting,
            empty($instruments) ? null : ['instruments' => $instruments, 'mode' => $mode], $projectId);
        $after = $this->getImportTriggers($projectId);

        $added = array_values(array_diff($after['instruments'], $before['instruments']));
        $removed = array_values(array_diff($before['instruments'], $after['instruments']));
        $modeChanged = !empty($after['instruments']) && $before['mode'] !== $after['mode'];
        if ($added || $removed || $modeChanged) {
            $this->logAuditEvent('import triggers changed', [
                'Instruments added' => $added,
                'Instruments removed' => $removed,
                'Starts an import' => empty($after['instruments'])
                    ? 'no instruments chosen: any completed survey in an event with mappings'
                    : self::importTriggerDescription($after),
            ], $projectId);
        }
        return $after;
    }

    // "when any of contact, baseline is completed", for logs and the page
    public static function importTriggerDescription(array $triggers)
    {
        return 'when ' . ($triggers['mode'] === 'all' ? 'all' : 'any') . ' of '
            . implode(', ', $triggers['instruments']) . ' ' . ($triggers['mode'] === 'all' ? 'are' : 'is') . ' completed';
    }

    // Whether completing $instrument (record, event, instance) starts an import under the project's
    // triggers. Call only when importTriggersConfigured(). $instrument itself must be one of them and
    // have the form status Complete (a survey's completion sets it); with "all", so must every other
    // chosen instrument the event has.
    public function importTriggerMet($projectId, $record, $eventId, $instrument, $repeatInstance = 1)
    {
        $triggers = $this->getImportTriggers($projectId);
        if (!in_array((string) $instrument, $triggers['instruments'], true)) {
            return false;
        }

        $needed = [(string) $instrument];
        if ($triggers['mode'] === 'all') {
            $eventForms = (new \Project($projectId))->eventsForms[$eventId] ?? [];
            $needed = array_values(array_intersect($triggers['instruments'], $eventForms));
        }

        $rows = json_decode(REDCap::getData([
            'project_id' => $projectId,
            'records' => [(string) $record],
            'events' => [(int) $eventId],
            'fields' => array_map(fn($form) => "{$form}_complete", $needed),
            'return_format' => 'json',
        ]), true);
        if (!is_array($rows)) {
            return false;
        }

        foreach ($needed as $form) {
            $complete = false;
            foreach ($rows as $row) {
                if ((string) ($row["{$form}_complete"] ?? '') !== '2') {
                    continue;
                }
                // The instrument just completed: its own instance, when it repeats
                $rowInstrument = (string) ($row['redcap_repeat_instrument'] ?? '');
                $rowInstance = (int) ($row['redcap_repeat_instance'] ?? 0);
                if ($form === (string) $instrument && $rowInstance > 0 && $rowInstance !== (int) $repeatInstance
                    && ($rowInstrument === $form || $rowInstrument === '')) {
                    continue;
                }
                $complete = true;
                break;
            }
            if (!$complete) {
                return false;
            }
        }
        return true;
    }
}
