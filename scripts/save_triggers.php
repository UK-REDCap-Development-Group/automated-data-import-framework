<?php
namespace UKModules\ADIF;

/** @var \UKModules\ADIF\ADIF $module */

// Saves the Mapping page's Import Triggers: which instruments' completion starts an import, and whether
// any or all of them must be completed (see classes/ImportTriggers.php). Only users who may configure
// the module can save (see ADIF::requireMappingAccess); the change is logged (see AuditLog).
$pid = $module->requireMappingAccess();

$instruments = json_decode($_POST['instruments'] ?? '', true);
$mode = (string) ($_POST['mode'] ?? 'any');

if (!is_array($instruments) || !in_array($mode, ['any', 'all'], true)) {
    http_response_code(400);
    exit(json_encode(['error' => 'Invalid input']));
}
$instruments = array_values(array_filter($instruments, fn($name) => is_string($name) && preg_match('/^[a-z][a-z0-9_]*$/', $name)));

echo json_encode([
    'status' => 'success',
    'triggers' => $module->saveImportTriggers($pid, $instruments, $mode),
]);
