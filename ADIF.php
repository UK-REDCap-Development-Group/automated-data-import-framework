<?php

namespace UKModules\ADIF;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use REDCap;

require_once __DIR__ . '/classes/Proxy.php';
require_once __DIR__ . '/classes/Import.php';
require_once __DIR__ . '/classes/SchemaDetection.php';
require_once __DIR__ . '/classes/DataTables.php';
require_once __DIR__ . '/classes/ApiCache.php';
require_once __DIR__ . '/classes/ImportLog.php';
require_once __DIR__ . '/classes/ImportStatus.php';
require_once __DIR__ . '/classes/ApiSources.php';

class ADIF extends AbstractExternalModule
{
    use Proxy;
    use Import;
    use SchemaDetection;
    use DataTables;
    use ApiCache;
    use ImportLog;
    use ImportStatus;
    use ApiSources;

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

    // The module's own pages (pages/*.php), where the Imports page's retry buttons live
    private static function isModulePage()
    {
        $page = isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : "";
        return (bool) preg_match("/\/external_modules\/index\.php$/", $page)
            || (bool) preg_match("/ExternalModules\/index\.php$/", $page);
    }

    // Users who may change the module's configuration, and so the API sources it calls and the
    // tables uploaded on the Data Sources page
    public function canConfigureApiSources($project_id)
    {
        $user = $this->getUser();
        return $user && ($user->isSuperUser() || $user->hasDesignRights($project_id));
    }

    function redcap_every_page_top($project_id)
    {
        // Adds the "Detect schema" button to each API source in the configuration dialog, and a note
        // pointing to the Data Sources page, which edits the same settings
        if ($project_id && self::isModuleManagerPage() && $this->canConfigureApiSources($project_id)) {
            $this->initializeJavascriptModuleObject();
            echo '<script>window.ADIFSchemaDetect = { prefix: ' . json_encode($this->getPrefix())
                . ', module: ' . $this->getJavascriptModuleObjectName()
                . ', dataSourcesUrl: ' . json_encode($this->getUrl('pages/Data_Sources.php') . '&tab=apis') . ' };</script>';
            $this->includeJS('js/schema_detect.js');
        }

        // Gives the Imports page its retry buttons (see pages/Imports.php and js/imports.js)
        if ($project_id && self::isModulePage() && $this->canConfigureApiSources($project_id)) {
            $this->initializeJavascriptModuleObject();
            echo '<script>window.ADIFImports = { prefix: ' . json_encode($this->getPrefix())
                . ', module: ' . $this->getJavascriptModuleObjectName() . ' };</script>';
            $this->includeJS('js/imports.js');

            // The Data Sources page's Detect schema buttons and form behaviour (see js/data_sources.js);
            // like imports.js, it does nothing on pages without its elements
            echo '<script>window.ADIFDataSources = { module: ' . $this->getJavascriptModuleObjectName() . ' };</script>';
            $this->includeJS('js/data_sources.js');
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

            case 'retry-import':
                // Re-runs a logged import run that left mapped fields empty (see ImportStatus::retryImportRun).
                // Writes record data, so it's limited to the same users who define what gets imported.
                if (!$project_id || !$this->canConfigureApiSources($project_id)) {
                    return ['status' => 'error', 'message' => 'Only users with Project Design rights can retry imports.'];
                }
                if (!isset($payload['run_id']) || !is_string($payload['run_id']) || !preg_match('/^[0-9a-f]{12}$/', $payload['run_id'])) {
                    return ['status' => 'error', 'message' => 'The retry request is missing a valid run id.'];
                }

                $run = $this->importRunFromLog($project_id, $payload['run_id']);
                if ($run === null) {
                    return ['status' => 'error', 'message' => 'That run is no longer among the most recent 500 imports. Reload the page.'];
                }
                if (trim((string) $run['record']) === '') {
                    return ['status' => 'error', 'message' => 'That run has no record on it, so there is nothing to re-run it for.'];
                }

                return $this->retryImportRun($project_id, $run, !empty($payload['bypass_cache']));

            case 'dismiss-import':
                // Clears (or, with 'undo', restores) a run's "Needs attention" flag, for failures nobody
                // intends to fix (see ImportStatus::dismissImportRun). Same users as retrying.
                if (!$project_id || !$this->canConfigureApiSources($project_id)) {
                    return ['status' => 'error', 'message' => 'Only users with Project Design rights can dismiss import errors.'];
                }
                if (!isset($payload['run_id']) || !is_string($payload['run_id']) || !preg_match('/^[0-9a-f]{12}$/', $payload['run_id'])) {
                    return ['status' => 'error', 'message' => 'The request is missing a valid run id.'];
                }

                $run = $this->importRunFromLog($project_id, $payload['run_id']);
                if ($run === null) {
                    return ['status' => 'error', 'message' => 'That run is no longer among the most recent 500 imports. Reload the page.'];
                }

                return $this->dismissImportRun($project_id, $run, !empty($payload['undo']),
                    is_string($payload['reason'] ?? null) ? $payload['reason'] : '');

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
    // Each endpoint hit and what it returned goes to the module log, and a summary to the project's
    // Logging page (see ImportLog). Failed runs and the fields they left empty are then visible on the
    // Imports page (see ImportStatus and pages/Imports.php), where they can also be re-run by hand.
    function redcap_survey_complete($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance = 1)
    {
        $start = microtime(true);
        try {
            $summary = $this->importMappedData($project_id, $record, $event_id, $instrument, $repeat_instance);
        } catch (\Throwable $e) {
            $summary = ['saved' => [], 'skipped' => [], 'errors' => [$e->getMessage()], 'requests' => []];
        }
        $summary['elapsed_ms'] = round((microtime(true) - $start) * 1000, 1);
        $summary['repeat_instance'] = $repeat_instance;

        $this->logImportRun('API import after survey completion', $project_id, $record, $event_id, $instrument, $summary);
    }
}
