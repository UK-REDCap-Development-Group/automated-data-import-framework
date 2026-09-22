<?php
namespace UKModules\ADIF;

/** @var \UKModules\ADIF\ADIF $module */

$pid = (int) $_POST['pid'];
$mappings = json_decode($_POST['mappings'], true);
$displayed = json_decode($_POST['displayed'] ?? '[]', true); // Get the displayed array

if (empty($pid) || !is_array($mappings)) {
    http_response_code(400);
    exit(json_encode(['error' => 'Invalid input']));
}

$module->setProjectId($pid);
$module->setProjectSetting('field-mappings', $mappings);
$module->setProjectSetting('displayed-instruments', $displayed); // Save displayed instruments

echo json_encode([
    'status' => 'success',
    'message' => 'All mappings saved successfully',
    'data' => $mappings,
    'displayed' => $displayed
]);
