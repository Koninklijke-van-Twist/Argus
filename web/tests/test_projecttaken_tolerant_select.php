<?php

/**
 * Ticket #1170: een ontbrekend prijsveld op ProjectTaken mag de andere kolom niet breken.
 * Opbrengst VC (schedule) en Opbrengst t/m (contract) delen één fetch; valt één veld weg,
 * dan blijft de ander staan en komt er één melding.
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
    $select = '';
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $select = (string) ($query['$select'] ?? '');
    $missing = $GLOBALS['missing_field'];
    if ($missing !== '' && in_array($missing, explode(',', $select), true)) {
        throw new RuntimeException(
            'HTTP 400 from OData: {"error":{"code":"BadRequest","message":"Could not find a property named \'' . $missing . '\' on type \'NAV.ProjectTaken\'.  CorrelationId:  ' . bin2hex(random_bytes(6)) . '."}}'
        );
    }

    $rows = [];
    foreach (['PRJ2606162' => [12700, 9000], 'PRJ2607918' => [108830, 0]] as $job => [$contract, $schedule]) {
        $row = ['Job_No' => $job, 'Job_Task_No' => '000', 'Description' => 'Project TOTAAL'];
        foreach (explode(',', $select) as $field) {
            if ($field === 'LVS_Contract_Total_Price_2') {
                $row[$field] = $contract;
            } elseif ($field === 'LVS_Schedule_Total_Price_2') {
                $row[$field] = $schedule;
            }
        }
        $rows[] = $row;
    }

    return $rows;
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
$method = $ref->getMethod('fetchVoorcalculatieRevenueForProjects');
$method->setAccessible(true);
$projects = [];
for ($i = 0; $i < 30; $i++) {
    $projects[] = 'PRJ26' . str_pad((string) $i, 5, '0', STR_PAD_LEFT);
}
$projects[] = 'PRJ2606162';
$projects[] = 'PRJ2607918';

// Alles bestaat: beide kolommen gevuld, geen melding.
$r = $method->invoke($service, $projects, 60);
check(($r['contract_totals']['prj2606162'] ?? null) === 12700.0, 'contract total from LVS_Contract_Total_Price_2');
check(($r['totals']['prj2606162'] ?? null) === 9000.0, 'Opbrengst VC from schedule field');
check($r['warning'] === null, 'no warning when all fields exist');

// Contractveld ontbreekt: Opbrengst VC blijft, één melding, geen per-project-storm.
$GLOBALS['missing_field'] = 'LVS_Contract_Total_Price_2';
$GLOBALS['odata_calls'] = 0;
$r = $method->invoke($service, $projects, 60);
check(($r['totals']['prj2606162'] ?? null) === 9000.0, 'Opbrengst VC survives missing contract field');
check($r['contract_totals'] === [], 'contract column empty when its field is missing');
check(substr_count((string) $r['warning'], 'LVS_Contract_Total_Price_2') <= 2 && substr_count((string) $r['warning'], ' | ') <= 1, 'one warning: ' . $r['warning']);
check(!str_contains((string) $r['warning'], 'CorrelationId'), 'no CorrelationId');
check($GLOBALS['odata_calls'] === 1 + 4, 'one failing request plus one pass over 4 chunks, got ' . $GLOBALS['odata_calls']);

// Scheduleveld ontbreekt: Opbrengst t/m blijft.
$GLOBALS['missing_field'] = 'LVS_Schedule_Total_Price_2';
$r = $method->invoke($service, $projects, 60);
check(($r['contract_totals']['prj2607918'] ?? null) === 108830.0, 'contract total survives missing schedule field');
check($r['totals'] === [], 'Opbrengst VC empty when its field is missing');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} assertion(s) failed\n");
    exit(1);
}

echo "OK ProjectTaken tolerant select\n";
