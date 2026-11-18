<?php

namespace UKModules\ADIF;

use REDCap;

// Imports after a data entry form is saved. Surveys import on their own (see ADIF::redcap_survey_complete),
// but a data entry save asks first: staff often open a form to correct a value by hand, and an import on
// every save would overwrite the mapped fields they just fixed.
//   1. redcap_save_record -> offerDataEntryImport(): if the saved form starts an import - with Import
//      Triggers chosen on the Mapping page, it's one of them, saved as Complete (see ImportTriggers);
//      without, it holds mapped fields or a lookup field an import reads - remembers an offer in the session
//   2. the page REDCap shows next (the form again, the record's home page or the status dashboard)
//      -> dataEntryImportOfferScript() asks, once, in a dialog (js/data_entry_import.js)
//   3. "Import" -> the 'import-after-data-entry' AJAX action -> runDataEntryImport(), which sends the
//      import the offer describes the same way a survey submission's goes: onto the background queue for
//      the cron (see ImportQueue), logged as "API import after data entry (in the background), requested
//      by <user>", or, in a project set to import immediately (or if the queue can't be written to), runs
//      it there and then, logged as "API import: after data entry, by <user>"
//   4. while it's queued, the dialog asks 'data-entry-import-status' (dataEntryImportStatus()) every few
//      seconds, and reloads the page once it has run, so the form shows the imported values
// The import runs exactly as a survey submission's would, with the project's cache settings. Only users
// who can resolve imports (View & Edit on every mapped instrument, see Access) are asked, since an import
// writes the mapped fields on all of the event's mapped instruments. A project can turn the question off
// with the "data-entry-imports" setting; data entry saves then never import.
trait ImportDataEntry
{
    private static $dataEntryOfferSession = 'adif_data_entry_import_offer';
    // [project id => [queue_id => [record, queued at]]]: the imports this user queued, which the dialog
    // may ask about
    private static $dataEntryQueuedSession = 'adif_data_entry_import_queued';
    private static $dataEntryOfferSeconds = 900;   // an offer not acted on by then is dropped
    // The pages REDCap goes to after a data entry save, where the question is asked
    private static $dataEntryOfferPages = ['DataEntry/index.php', 'DataEntry/record_home.php',
        'DataEntry/record_status_dashboard.php'];

    // Called from redcap_save_record for a data entry save (not a survey). Never throws.
    public function offerDataEntryImport($projectId, $record, $instrument, $eventId, $repeatInstance)
    {
        try {
            unset($_SESSION[self::$dataEntryOfferSession][(int) $projectId]);   // a new save replaces an old offer

            if (($this->getProjectSetting('data-entry-imports', $projectId) ?: 'ask') === 'off') {
                return;
            }
            if (!$this->canResolveImports($projectId) || !$this->canSeeRecord($projectId, $record)) {
                return;
            }

            $repeatInstance = max(1, (int) $repeatInstance);
            $plan = $this->importPlan($projectId, $eventId, $instrument, $repeatInstance);
            if (empty($plan['requests'])) {
                return;
            }

            // The fields an import would write, and the fields it reads [placeholder] values from
            $targets = [];
            $lookups = [];
            foreach ($plan['requests'] as $request) {
                foreach ($request['fields'] as $target) {
                    $targets[$target['field']] = $target['repeatInstance'];
                }
                if (preg_match_all('/\[([a-z][a-z0-9_]*)\]/', (string) $request['path'], $matches)) {
                    foreach (array_unique($matches[1]) as $placeholder) {
                        $lookups[] = $this->getLookupField($request['source'], $placeholder);
                    }
                }
            }

            // Only when the saved form starts an import. With Import Triggers chosen (see ImportTriggers):
            // it's one of them, saved as Complete (and, with "all", the others are complete too).
            if ($this->importTriggersConfigured($projectId)) {
                if (!$this->importTriggerMet($projectId, $record, $eventId, $instrument, $repeatInstance)) {
                    return;
                }
                $relevant = true;
            } else {
                $relevant = null;
            }

            // Without triggers: when the saved form had something to do with the import, so editing an
            // unrelated form doesn't ask
            $formFields = [];
            foreach (REDCap::getDataDictionary($projectId, 'array') as $field => $meta) {
                if (($meta['form_name'] ?? '') === $instrument) {
                    $formFields[] = $field;
                }
            }
            if ($relevant === null && !array_intersect($formFields, array_merge(array_keys($targets), $lookups))) {
                return;
            }

            $_SESSION[self::$dataEntryOfferSession][(int) $projectId] = [
                'token' => bin2hex(random_bytes(8)),
                'record' => (string) $record,
                'event_id' => (int) $eventId,
                'instrument' => (string) $instrument,
                'instance' => $repeatInstance,
                'fields' => array_keys($targets),
                'filled' => $this->dataEntryFilledFields($projectId, $record, $eventId, $targets),
                'created' => time(),
                'shown' => false,
            ];
        } catch (\Throwable $e) {
            error_log("ADIF: couldn't prepare the import question for record $record in project $projectId: " . $e->getMessage());
        }
    }

    // Which of $targets ([field => repeat instance, or null]) already have a value, so the question
    // can say the import would replace them. null when that can't be read.
    private function dataEntryFilledFields($projectId, $record, $eventId, array $targets)
    {
        try {
            $rows = json_decode(REDCap::getData([
                'project_id' => $projectId,
                'records' => [(string) $record],
                'events' => [(int) $eventId],
                'fields' => array_keys($targets),
                'return_format' => 'json',
            ]), true);
            if (!is_array($rows)) {
                return null;
            }

            $filled = [];
            foreach ($rows as $row) {
                $rowInstance = (int) ($row['redcap_repeat_instance'] ?? 0);
                foreach ($targets as $field => $instance) {
                    $inPlace = $instance === null ? $rowInstance === 0 : $rowInstance === (int) $instance;
                    if ($inPlace && trim((string) ($row[$field] ?? '')) !== '') {
                        $filled[$field] = true;
                    }
                }
            }
            return array_keys($filled);
        } catch (\Throwable $e) {
            return null;
        }
    }

    // Called from redcap_every_page_top: asks about the offer offerDataEntryImport() remembered, once,
    // on the next page the user opens after the save
    public function dataEntryImportOfferScript($projectId)
    {
        $offer = $_SESSION[self::$dataEntryOfferSession][(int) $projectId] ?? null;
        if (!is_array($offer) || !empty($offer['shown'])) {
            return;
        }
        if (time() - (int) $offer['created'] > self::$dataEntryOfferSeconds) {
            unset($_SESSION[self::$dataEntryOfferSession][(int) $projectId]);
            return;
        }
        // Only on the pages a save leads to, and never into an AJAX response
        $page = defined('PAGE') ? PAGE : '';
        if (!in_array($page, self::$dataEntryOfferPages, true) || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET'
            || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest') {
            return;
        }

        $_SESSION[self::$dataEntryOfferSession][(int) $projectId]['shown'] = true;

        $this->initializeJavascriptModuleObject();
        echo '<script>window.ADIFDataEntryImport = { module: ' . $this->getJavascriptModuleObjectName()
            . ', offer: ' . json_encode([
                'token' => $offer['token'],
                'record' => $offer['record'],
                'instrument' => $offer['instrument'],
                'fields' => $offer['fields'],
                'filled' => $offer['filled'],
                'background' => $this->importsRunInBackground($projectId),
            ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ' };</script>';
        $this->includeJS('js/data_entry_import.js');
    }

    // The 'import-after-data-entry' AJAX action: queues, or runs, the import the user was asked about.
    // The record, event and instance come from the offer in the session, not from the request. Returns
    //   ['status' => 'queued', 'queue_id' => ...]   for the cron; see dataEntryImportStatus()
    //   ['status' => 'success', 'saved' => n, 'not_populated' => [field...], 'errors' => [...]]   ran here
    //   ['status' => 'error' | 'locked', 'message' => ...]
    public function runDataEntryImport($projectId, $token)
    {
        $offer = $_SESSION[self::$dataEntryOfferSession][(int) $projectId] ?? null;
        if (!is_array($offer) || !is_string($token) || $token === '' || !hash_equals((string) $offer['token'], $token)
            || time() - (int) $offer['created'] > self::$dataEntryOfferSeconds) {
            return ['status' => 'error', 'message' => 'This import is no longer available. Save the form again to be asked again.'];
        }
        unset($_SESSION[self::$dataEntryOfferSession][(int) $projectId]);

        if (!$this->canSeeRecord($projectId, $offer['record'])) {
            return ['status' => 'error', 'message' => self::$recordNotInGroup];
        }

        $username = $this->importLoggedInUsername();

        // The same channel as a survey submission: the background queue, unless the project imports
        // immediately. A queue that can't be written to runs the import here instead, as for a survey.
        if ($this->importsRunInBackground($projectId)) {
            try {
                $queueId = $this->queueImport($projectId, $offer['record'], $offer['event_id'], $offer['instrument'],
                    $offer['instance'], $username !== '' ? $username : '?');
                $queued = array_filter($_SESSION[self::$dataEntryQueuedSession][(int) $projectId] ?? [],
                    fn($entry) => time() - (int) ($entry['queued'] ?? 0) < 3600);
                $queued[$queueId] = ['record' => $offer['record'], 'queued' => time()];
                $_SESSION[self::$dataEntryQueuedSession][(int) $projectId] = $queued;
                return ['status' => 'queued', 'queue_id' => $queueId];
            } catch (\Throwable $e) {
                error_log("ADIF: couldn't queue the import for record {$offer['record']}, so it runs now instead: "
                    . $e->getMessage());
            }
        }

        $description = 'API import: after data entry, by ' . ($username !== '' ? $username : '?');
        $lock = 'import-data-entry-' . md5(json_encode([(int) $projectId, $offer['record'], $offer['event_id'],
                $offer['instrument'], $offer['instance']]));
        if (!$this->importRetryAcquireLock($lock)) {
            return ['status' => 'locked', 'message' => 'This import is already running. Wait a moment, then reload the page.'];
        }

        try {
            $start = microtime(true);
            try {
                $summary = $this->importMappedData($projectId, $offer['record'], $offer['event_id'], $offer['instrument'],
                    $offer['instance']);
            } catch (\Throwable $e) {
                $summary = ['errors' => [$e->getMessage()]];
            }
            $summary += ['saved' => [], 'skipped' => [], 'errors' => [], 'notices' => [], 'not_populated' => [], 'requests' => []];
            $summary['elapsed_ms'] = round((microtime(true) - $start) * 1000, 1);
            $summary['repeat_instance'] = $offer['instance'];

            $this->logImportRun($description, $projectId, $offer['record'], $offer['event_id'], $offer['instrument'], $summary);
        } finally {
            $this->importRetryReleaseLock($lock);
        }

        return [
            'status' => 'success',
            'saved' => count($summary['saved']),
            'not_populated' => array_keys($summary['not_populated']),
            'errors' => array_values($summary['errors']),
        ];
    }

    // The 'data-entry-import-status' AJAX action: whether an import this user queued after a data entry
    // save has run yet. Returns ['status' => 'pending'], the 'success' result runDataEntryImport() gives
    // for an import it ran itself, or ['status' => 'error', 'message' => ...]
    public function dataEntryImportStatus($projectId, $queueId)
    {
        $queued = $_SESSION[self::$dataEntryQueuedSession][(int) $projectId] ?? [];
        if (!is_string($queueId) || !isset($queued[$queueId])) {
            return ['status' => 'error', 'message' => 'ADIF has no record of that import. See the Import Status page.'];
        }

        $done = $this->importQueueOutcome($projectId, $queueId);
        if ($done === null) {
            return ['status' => 'pending'];
        }
        unset($_SESSION[self::$dataEntryQueuedSession][(int) $projectId][$queueId]);

        $run = $done['run_id'] !== null ? $this->importRunFromLog($projectId, $done['run_id']) : null;
        if ($run === null) {
            return ['status' => 'success', 'saved' => 0, 'not_populated' => [],
                'errors' => [$done['outcome'] === 'abandoned'
                    ? 'The import stopped before finishing. See the Import Status page.'
                    : 'The import has run. See the Import Status page for how it went.']];
        }
        $notPopulated = [];
        foreach (array_filter(explode("\n", $run['not_populated'])) as $line) {
            $notPopulated[] = trim(explode(':', $line, 2)[0]);
        }
        return [
            'status' => 'success',
            'saved' => $run['saved'] !== '' ? count(explode(', ', $run['saved'])) : 0,
            'not_populated' => $notPopulated,
            'errors' => array_values(array_filter(explode("\n", $run['errors']))),
        ];
    }
}
