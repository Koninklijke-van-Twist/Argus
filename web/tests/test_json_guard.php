<?php

/**
 * JSON-endpoints: warnings worden niet getoond en losse output vóór de JSON wordt weggegooid.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../json_guard.php';

$failures = 0;
function check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

ini_set('error_log', sys_get_temp_dir() . '/argus_json_guard_test.log');
$outerLevel = ob_get_level();
ob_start();
$_GET['action'] = 'fetch_column_batch';
argus_configure_error_display();
check(ini_get('display_errors') === '0', 'display_errors uit voor JSON');
echo "<br />\n<b>Warning</b>: session_start(): Failed to acquire session lock<br />\n";
trigger_error('session_start(): Failed to read session data', E_USER_WARNING);
$stray = argus_json_discard_preamble();
echo json_encode(['ok' => true]);
while (ob_get_level() > $outerLevel + 1) {
    ob_end_flush();
}
$output = ob_get_clean();

check(str_contains($stray, 'Failed to acquire session lock'), 'losse output opgevangen');
check($output === '{"ok":true}', 'alleen JSON over: ' . var_export($output, true));
check(json_decode($output, true) === ['ok' => true], 'geldige JSON');

session_start();
$_SESSION['user'] = ['email' => 'test@kvt.nl'];
check(session_status() === PHP_SESSION_ACTIVE, 'sessie is actief voor het vrijgeven');
argus_release_request_session();
check(session_status() !== PHP_SESSION_ACTIVE, 'sessie-lock is los');
check(($_SESSION['user']['email'] ?? '') === 'test@kvt.nl', 'sessie blijft leesbaar na vrijgeven');
argus_release_request_session();
check(session_status() !== PHP_SESSION_ACTIVE, 'tweede vrijgave is een no-op');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} test(s) gefaald\n");
    exit(1);
}
echo "test_json_guard: OK\n";
