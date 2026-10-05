<?php

namespace UKModules\ADIF;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use REDCap;

require_once __DIR__ . '/classes/Proxy.php';
require_once __DIR__ . '/classes/Import.php';
require_once __DIR__ . '/classes/SchemaDetection.php';

class ADIF extends AbstractExternalModule
{
    use Proxy;
    use Import;
    use SchemaDetection;

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

    // The project's External Modules page, where the configuration dialog opens. Unlike
    // isExternalModulePage(), this leaves out the manager's ajax endpoints, whose JSON responses
    // must not have anything printed into them.
    private static function isModuleManagerPage()
    {
        $page = isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : "";
        return (bool) preg_match("/(ExternalModules|external_modules)\/manager\/project\.php/", $page);
    }

    // Users who may change the module's configuration, and so the API sources it calls
    private function canConfigureApiSources($project_id)
    {
        $user = $this->getUser();
        return $user && ($user->isSuperUser() || $user->hasDesignRights($project_id));
    }

    function redcap_every_page_top($project_id)
    {
        // Adds the "Detect schema" button to each API source in the configuration dialog
        if ($project_id && self::isModuleManagerPage() && $this->canConfigureApiSources($project_id)) {
            $this->initializeJavascriptModuleObject();
            echo '<script>window.ADIFSchemaDetect = { prefix: ' . json_encode($this->getPrefix())
                . ', module: ' . $this->getJavascriptModuleObjectName() . ' };</script>';
            $this->includeJS('js/schema_detect.js');
        }
    }

    // Requests from module.ajax() in the browser. Actions must also be listed under auth-ajax-actions in config.json.
    function redcap_module_ajax($action, $payload, $project_id, $record, $instrument, $event_id, $repeat_instance, $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id)
    {
        switch ($action) {
            case 'detect-schema':
                if (!$project_id || !$this->canConfigureApiSources($project_id)) {
                    return ['status' => 'error', 'message' => 'Only users with Project Design rights can detect API schemas.'];
                }
                try {
                    return ['status' => 'success'] + $this->detectApiSchema(
                        $payload['url'] ?? '',
                        $this->apiKeyForDetection($payload),
                        $payload['probePath'] ?? ''
                    );
                } catch (\Throwable $e) {
                    return ['status' => 'error', 'message' => $e->getMessage()];
                }

            default:
                return ['status' => 'error', 'message' => "Unknown action: $action"];
        }
    }

    // The key typed into the dialog, or, when that's blank, the one already saved for the same source.
    // The saved key is only used when the saved URL at that position matches, since adding or removing
    // sources in the dialog shifts positions before they're saved.
    private function apiKeyForDetection($payload)
    {
        $typed = trim((string) ($payload['apiKey'] ?? ''));
        if ($typed !== '' || !isset($payload['instance']) || !is_numeric($payload['instance'])) {
            return $typed;
        }

        $index = (int) $payload['instance'];
        $urls = $this->getProjectSetting('api') ?: [];
        $keys = $this->getProjectSetting('api-key') ?: [];
        if (trim((string) ($urls[$index] ?? '')) === trim((string) ($payload['url'] ?? ''))) {
            return trim((string) ($keys[$index] ?? ''));
        }
        return '';
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
