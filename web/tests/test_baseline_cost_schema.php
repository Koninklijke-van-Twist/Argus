<?php

/**
 * Ontbreekt Job_Task_Type op ProjectTaken, dan één melding en één retry zonder dat veld.
 * De geslaagde retry mag die melding niet overschrijven.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$GLOBALS['missing_field'] = '';
$GLOBALS['odata_calls'] = 0;

function odata_get_all(string $url, array $auth, int $ttl): array
{
    $GLOBALS['odata_calls']++;
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $select = explode(',', (string) ($query['$select'] ?? ''));
    $missing = $GLOBALS['missing_field'];
    if ($missing !== '' && in_array($missing, $select, true)) {
        throw new RuntimeException(
            'HTTP 400 from OData: {"error":{"code":"BadRequest","message":"Could not find a property named \'' . $missing . '\' on type \'NAV.ProjectTaken\'.  CorrelationId:  ' . bin2hex(random_bytes(6)) . '."}}'
        );
    }

    $filter = (string) ($query['$filter'] ?? '');
    if (!str_contains($filter, '150933')) {
        return [];
    }

    $row = [
        'Job_No' => '150933',
        'Job_Task_No' => '110',
        'Description' => 'Posting',
        'LVS_Baseline_Total_Cost' => 121699,
    ];
    if (in_array('Job_Task_Type', $select, true)) {
        $row['Job_Task_Type'] = 'Posting';
    }

    return [$row];
}

require_once __DIR__ . '/../project_finance.php';

$failures = 0;

function check(bool $ok, string $message): void
{
    global $failures;
    if (!$ok) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$ref = new ReflectionClass(ProjectFinanceService::class);
$service = $ref->newInstanceWithoutConstructor();
foreach (['auth' => [], 'baseUrl' => 'https://bc.invalid:7148', 'environment' => 'kvtmdlive_aad', 'company' => 'Koninklijke van Twist'] as $name => $value) {
    $prop = $ref->getProperty($name);
    $prop->setAccessible(true);
    $prop->setValue($service, $value);
}
$method = $ref->getMethod('fetchVoorcalculatieCostsForProjects');
$method->setAccessible(true);

$projects = ['150933'];
for ($i = 0; $i < 10; $i++) {
    $projects[] = 'PRJ' . $i;
}

$r = $method->invoke($service, $projects, 60);
check(($r['totals']['150933'] ?? null) === 121699.0, 'kosten VC met Job_Task_Type');
check($r['warning'] === null, 'geen melding als het veld bestaat');

$GLOBALS['missing_field'] = 'Job_Task_Type';
$GLOBALS['odata_calls'] = 0;
$r = $method->invoke($service, $projects, 60);
check(($r['totals']['150933'] ?? null) === 121699.0, 'kosten VC na retry zonder Job_Task_Type');
check(is_string($r['warning']) && str_contains($r['warning'], 'Veld Job_Task_Type bestaat niet op ProjectTaken'), 'melding blijft staan: ' . var_export($r['warning'], true));
check(is_string($r['warning']) && str_contains($r['warning'], 'totaal- en kopregels kunnen niet worden overgeslagen'), 'melding noemt het ontbrekende filter');
check(substr_count((string) $r['warning'], 'Job_Task_Type') === 1, 'één melding, niet de ruwe schemafout erbij');
check(!str_contains((string) $r['warning'], 'CorrelationId'), 'geen CorrelationId');
check($GLOBALS['odata_calls'] === 3, 'één schemafout en daarna de chunks, niet per project, got ' . $GLOBALS['odata_calls']);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} assertion(s) failed\n");
    exit(1);
}

echo "OK baseline cost schema\n";
