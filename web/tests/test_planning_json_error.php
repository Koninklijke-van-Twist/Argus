<?php

/**
 * Voorcalculatie die een BC-fout met de ruwe body teruggeeft.
 * Die body is vaak geen geldige UTF-8. De oude encode leverde false,
 * de handler echo't dan niets en de status is al 500. De browser ziet
 * "Server gaf geen geldige JSON terug. HTTP-status: 500".
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../month_rebuild_steps.php';

$failures = 0;

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    global $failures;
    if ($expected !== $actual) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n  expected " . var_export($expected, true) . "\n  actual   " . var_export($actual, true) . "\n");
    }
}

function assert_true(bool $ok, string $message): void
{
    assert_same(true, $ok, $message);
}

$odataError = "HTTP 500 from OData: \xE9n onleesbare BC-fout";
$bare = json_encode(['ok' => false, 'error' => $odataError], JSON_UNESCAPED_UNICODE);
assert_same(false, $bare, 'encode zonder UTF-8-vervanging geeft geen JSON voor de BC-fout');

$body = maanden_rebuild_json_response(['ok' => false, 'error' => $odataError]);
assert_true($body !== '', 'de voorcalculatie-response is niet leeg');
$decoded = json_decode($body, true);
assert_true(is_array($decoded), 'de voorcalculatie-response is JSON');
assert_same(false, $decoded['ok'] ?? null, 'de BC-fout blijft een mislukte stap');
assert_true(is_string($decoded['error'] ?? null) && str_contains((string) $decoded['error'], 'HTTP 500 from OData'), 'de fouttekst blijft leesbaar');

$handler = (string) file_get_contents(__DIR__ . '/../maanden.php');
assert_true(str_contains($handler, "action=fetch_sub_planning") || str_contains($handler, "=== 'fetch_sub_planning'"), 'de voorcalculatie-handler staat in maanden.php');
$planningPos = strpos($handler, "=== 'fetch_sub_planning'");
$nextPos = strpos($handler, "=== 'build_month_snapshot'");
assert_true($planningPos !== false && $nextPos !== false && $nextPos > $planningPos, 'de handlergrenzen zijn gevonden');
$planningHandler = substr($handler, $planningPos, $nextPos - $planningPos);
assert_true(str_contains($planningHandler, 'maanden_rebuild_json_response'), 'de voorcalculatie-handler stuurt het veilige JSON-antwoord');
assert_true(!str_contains($planningHandler, 'json_encode($result'), 'de voorcalculatie-handler encodeert de BC-fout niet meer kaal');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} assertion(s) failed\n");
    exit(1);
}

echo "OK planning json error\n";
