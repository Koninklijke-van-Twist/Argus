<?php

/**
 * Directe BC-paginering mag een herhaalde nextLink of een stille hang
 * niet eindeloos volgen.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../odata.php';

$failures = 0;

function check(bool $ok, string $message): void
{
    global $failures;
    if (!$ok) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$seen = [];
$started = 1000.0;
odata_guard_next_page('https://bc.example/page/1', $seen, 1, $started, 1001.0);
check(isset($seen['https://bc.example/page/1']), 'eerste pagina wordt onthouden');

$repeated = false;
try {
    odata_guard_next_page('https://bc.example/page/1', $seen, 2, $started, 1002.0);
} catch (Exception $error) {
    $repeated = str_contains($error->getMessage(), 'herhaalt dezelfde pagina');
}
check($repeated, 'dezelfde nextLink stopt met een fout');

$tooMany = false;
$fresh = [];
try {
    odata_guard_next_page('https://bc.example/page/501', $fresh, 501, $started, 1001.0);
} catch (Exception $error) {
    $tooMany = str_contains($error->getMessage(), "500 pagina's");
}
check($tooMany, 'paginalimiet stopt met een fout');

$slow = false;
try {
    odata_guard_next_page('https://bc.example/page/2', $fresh, 2, $started, $started + odata_direct_fetch_deadline_seconds() + 1);
} catch (Exception $error) {
    $slow = str_contains($error->getMessage(), 'afgebroken');
}
check($slow, 'overschrijden van de tijdsgrens stopt met een fout');
check(odata_direct_fetch_deadline_seconds() === odata_mimir_timeout_seconds() * 3, 'de directe fetch is begrensd');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} assertion(s) failed\n");
    exit(1);
}

echo "OK odata page guard\n";
