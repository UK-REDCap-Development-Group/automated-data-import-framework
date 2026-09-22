<?php
namespace UKModules\ADIF;

/** @var \UKModules\ADIF\ADIF $module */

$pid = (int) ($_GET['pid'] ?? $_POST['pid'] ?? 0);

if (empty($pid)) {
    http_response_code(400);
    exit(json_encode(['error' => 'Invalid project ID']));
}

$module->setProjectId($pid);
$mappings = $module->getProjectSetting('field-mappings') ?? [];
$displayed = $module->getProjectSetting('displayed-instruments') ?? []; // Load displayed instruments

echo json_encode([
    'status' => 'success',
    'data' => $mappings,
    'displayed' => $displayed // Return displayed instruments
]);
