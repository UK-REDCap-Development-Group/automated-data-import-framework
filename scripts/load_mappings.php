<?php
namespace UKModules\ADIF;

/** @var \UKModules\ADIF\ADIF $module */

// Returns the Mapping page's saved field mappings. The project is the page's own (the URL's pid,
// checked by REDCap), and anyone who can see the Mapping page can read them, since it shows them
// view-only to users who can't change them (see ADIF::requireMappingAccess and classes/Access.php).
$pid = $module->requireMappingAccess(ADIF::ACCESS_READ);

$mappings = $module->getProjectSetting('field-mappings', $pid) ?? [];
$displayed = $module->getProjectSetting('displayed-instruments', $pid) ?? []; // Load displayed instruments

echo json_encode([
    'status' => 'success',
    'data' => $mappings,
    'displayed' => $displayed // Return displayed instruments
]);
