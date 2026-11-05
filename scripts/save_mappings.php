<?php
namespace UKModules\ADIF;

/** @var \UKModules\ADIF\ADIF $module */

// Saves the Mapping page's field mappings and which forms it shows. The project is the page's own
// (the URL's pid, checked by REDCap), and only users who may configure the module can save
// (see ADIF::requireMappingAccess).
$pid = $module->requireMappingAccess();

$mappings = json_decode($_POST['mappings'] ?? '', true);
$displayed = json_decode($_POST['displayed'] ?? '[]', true); // Get the displayed array

if (!is_array($mappings) || !is_array($displayed)) {
    http_response_code(400);
    exit(json_encode(['error' => 'Invalid input']));
}

$module->setProjectSetting('field-mappings', $mappings, $pid);
$module->setProjectSetting('displayed-instruments', $displayed, $pid); // Save displayed instruments

echo json_encode([
    'status' => 'success',
    'message' => 'All mappings saved successfully',
    'data' => $mappings,
    'displayed' => $displayed
]);
