<?php
namespace UKModules\ADIF;

/** @var \UKModules\ADIF\ADIF $module */

// Returns the Mapping page's saved field mappings. The project is the page's own (the URL's pid,
// checked by REDCap), and only users who may configure the module can read them
// (see ADIF::requireMappingAccess).
$pid = $module->requireMappingAccess();

$mappings = $module->getProjectSetting('field-mappings', $pid) ?? [];
$displayed = $module->getProjectSetting('displayed-instruments', $pid) ?? []; // Load displayed instruments

echo json_encode([
    'status' => 'success',
    'data' => $mappings,
    'displayed' => $displayed // Return displayed instruments
]);
