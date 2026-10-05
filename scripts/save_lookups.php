<?php
namespace UKModules\ADIF;

/** @var \UKModules\ADIF\ADIF $module */

// Saves which REDCap field supplies each [placeholder] in the API sources' endpoint paths, from the
// Mapping page's lookup prompt, as { "<source index>": { "<placeholder>": "<field>" } }.
// Read back by Import::getLookupField.

$pid = (int) ($_POST['pid'] ?? 0);
$lookups = json_decode($_POST['lookups'] ?? '', true);

if (empty($pid) || !is_array($lookups)) {
    http_response_code(400);
    exit(json_encode(['error' => 'Invalid input']));
}

// Placeholders and REDCap field names are both lowercase variable names; anything else is dropped
$namePattern = '/^[a-z][a-z0-9_]*$/';
$clean = [];
foreach ($lookups as $source => $placeholders) {
    if (!is_numeric($source) || !is_array($placeholders)) {
        continue;
    }
    foreach ($placeholders as $placeholder => $field) {
        if (preg_match($namePattern, (string) $placeholder) && is_string($field) && preg_match($namePattern, $field)) {
            $clean[(string) (int) $source][$placeholder] = $field;
        }
    }
}

$module->setProjectId($pid);
$module->setProjectSetting('lookup-fields', $clean);

echo json_encode([
    'status' => 'success',
    'lookups' => $clean,
]);
