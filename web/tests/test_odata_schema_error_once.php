<?php

/**
 * Ticket #1170: een niet-bestaand veld (HTTP 400 "Could not find a property named ...")
 * mag maar één keer gevraagd en gemeld worden, niet per batch en per project.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$GLOBALS['odata_calls'] = 0;
$GLOBALS['odata_mode'] = 'schema';

function odata_get_all(string $url, array $auth, int $ttl): array
{
    $GLOBALS['odata_calls']++;
    if ($GLOBALS['odata_mode'] === 'schema') {
        $cid = bin2hex(random_bytes(8));
        throw new RuntimeException(
            'HTTP 400 from OData: {"error":{"code":"BadRequest","message":"Could not find a property named \'Contract_Total_Price\' on type \'NAV.ProjectTaken\'.  CorrelationId:  ' . $cid . '."}}'
        );
    }
    if ($GLOBALS['odata_mode'] === 'flaky' && str_contains($url, 'P2')) {
        throw new RuntimeException('HTTP 500 from OData: {"error":{"code":"Internal","message":"Tijdelijke fout.  CorrelationId:  abc-123."}}');
    }

    return [['Job_No' => 'x']];
}

require_once __DIR__ . '/../project_finance.php';

$failures = 0;

function assert_true(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$ref = new ReflectionClass(ProjectFinanceService::class);
$service = $ref->newInstanceWithoutConstructor();
$authProp = $ref->getProperty('auth');
$authProp->setAccessible(true);
$authProp->setValue($service, []);
$method = $ref->getMethod('fetchRowsForJobChunks');
$method->setAccessible(true);

$projects = [];
for ($i = 1; $i <= 40; $i++) {
    $projects[] = 'PRJ' . str_pad((string) $i, 7, '0', STR_PAD_LEFT);
}
$builder = static fn (string $filter): string => 'https://bc.invalid/ProjectTaken?$filter=' . rawurlencode($filter);

$result = $method->invoke($service, $projects, 60, 8, $builder, 'Voorcalculatie opbrengst ophalen mislukt (ProjectTaken)');
assert_true($GLOBALS['odata_calls'] === 1, 'schema error: exactly one request, got ' . $GLOBALS['odata_calls']);
assert_true(is_string($result['warning']) && substr_count($result['warning'], 'Could not find a property named') === 1, 'schema error: one message');
assert_true(!str_contains((string) $result['warning'], 'CorrelationId'), 'CorrelationId is stripped');
assert_true($result['rows'] === [], 'schema error: no rows');

// Gewone (niet-schema) fout: per project opnieuw proberen blijft werken.
$GLOBALS['odata_calls'] = 0;
$GLOBALS['odata_mode'] = 'flaky';
$result = $method->invoke($service, ['P1', 'P2', 'P3'], 60, 8, $builder, 'Test');
assert_true(count($result['rows']) === 2, 'transient error: other projects still load');
assert_true(is_string($result['warning']) && !str_contains($result['warning'], 'CorrelationId'), 'transient error: warning without CorrelationId');

assert_true(finance_is_odata_schema_error("Could not find a property named 'X' on type 'NAV.ProjectTaken'."), 'schema detector');
assert_true(!finance_is_odata_schema_error('HTTP 500: Tijdelijke fout'), 'schema detector ignores 500');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} assertion(s) failed\n");
    exit(1);
}

echo "OK odata schema error once\n";
