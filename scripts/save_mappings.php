<?php
namespace UKModules\ADIF;

/** @var \UKModules\ADIF\ADIF $module */

// Saves the Mapping page's field mappings and which forms it shows. The project is the page's own
// (the URL's pid, checked by REDCap), and only users who may configure the module can save
// (see ADIF::requireMappingAccess). What a save changed goes to the project's Logging page (see
// AuditLog::logMappingChanges); the page saves after every change, so saves that change nothing aren't logged.
$pid = $module->requireMappingAccess();

$mappings = json_decode($_POST['mappings'] ?? '', true);
$displayed = json_decode($_POST['displayed'] ?? '[]', true); // Get the displayed array

if (!is_array($mappings) || !is_array($displayed)) {
    http_response_code(400);
    exit(json_encode(['error' => 'Invalid input']));
}

$oldMappings = $module->getProjectSetting('field-mappings', $pid) ?? [];
$oldDisplayed = $module->getProjectSetting('displayed-instruments', $pid) ?? [];

$module->setProjectSetting('field-mappings', $mappings, $pid);
$module->setProjectSetting('displayed-instruments', $displayed, $pid); // Save displayed instruments

// 'upload': the save came from Upload Config, which replaces every mapping
$module->logMappingChanges($pid, $oldMappings, $mappings, $oldDisplayed, $displayed, ($_POST['reason'] ?? '') === 'upload');

echo json_encode([
    'status' => 'success',
    'message' => 'All mappings saved successfully',
    'data' => $mappings,
    'displayed' => $displayed
]);
