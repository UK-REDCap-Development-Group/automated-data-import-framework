<?php

namespace UKModules\ADIF;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use REDCap;

require_once __DIR__ . '/classes/Proxy.php';
require_once __DIR__ . '/classes/Import.php';

class ADIF extends AbstractExternalModule
{
    use Proxy;
    use Import;

    // provided courtesy of Scott J. Pearson
    private static function isExternalModulePage()
    {
        $page = isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : "";
        if (preg_match("/ExternalModules\/manager\/project.php/", $page)) {
            return TRUE;
        }
        if (preg_match("/ExternalModules\/manager\/ajax\//", $page)) {
            return TRUE;
        }
        if (preg_match("/external_modules\/manager\/project.php/", $page)) {
            return TRUE;
        }
        if (preg_match("/external_modules\/manager\/ajax\//", $page)) {
            return TRUE;
        }
        return FALSE;
    }

    // Script assumes root level, so include folders
    protected function includeJS($path)
    {
        // Use this function to use your JavaScript files in the frontend
        echo '<script src="' . $this->getUrl($path) . '"></script>';
    }

    // This function needs more updates before it is finished.
    private static function isMappingPage()
    {
        $page = isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : "";
        if (preg_match("/ExternalModules\/\??prefix=ADIF&page=pages%2FMapping/", $_SERVER['REQUEST_URI'])) {
            return TRUE;
        }
        return FALSE;
    }

    public static function getRecordStatusDashboard($pid)
    {
        return $_SERVER['REQUEST_URI'];
    }

    // Checks for which form we are on and includes instructions for mapping data to fields on that page
    function redcap_every_page_top($project_id)
    {

    }

    // Pulls from the configured APIs and saves the mapped values into the record once a survey is submitted.
    // Failures are logged rather than thrown so the respondent still reaches the completion page.
    function redcap_survey_complete($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance = 1)
    {
        try {
            $summary = $this->importMappedData($project_id, $record, $event_id, $instrument, $repeat_instance);
        } catch (\Throwable $e) {
            $summary = ['saved' => [], 'skipped' => [], 'errors' => [$e->getMessage()]];
        }

        if (empty($summary['saved']) && empty($summary['skipped']) && empty($summary['errors'])) {
            return;
        }

        $this->log('API import after survey completion', [
            'record' => $record,
            'event_id' => $event_id,
            'instrument' => $instrument,
            'saved' => implode(', ', $summary['saved']),
            'skipped' => implode("\n", $summary['skipped']),
            'errors' => implode("\n", $summary['errors']),
        ]);
    }
}
