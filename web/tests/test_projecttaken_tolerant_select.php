<?php

/**
 * Ticket #1170 / #1264: een ontbrekend prijsveld op ProjectTaken maakt Opbrengst VC leeg
 * met één melding, niet een fout per project. Opbrengst VC en het bewaarde contracttotaal
 * lezen allebei LVS_Contract_Total_Price_2.
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
    $select = (string) ($query['$select'] ?? '');
    $filter = (string) ($query['$filter'] ?? '');
    $missing = $GLOBALS['missing_field'];
    if ($missing !== '' && in_array($missing, explode(',', $select), true)) {
        throw new RuntimeException(
            'HTTP 400 from OData: {"error":{"code":"BadRequest","message":"Could not find a property named \'' . $missing . '\' on type \'NAV.ProjectTaken\'.  CorrelationId:  ' . bin2hex(random_bytes(6)) . '."}}'
        );
    }

    $rows = [];
    foreach (['PRJ2606162' => 12700, 'PRJ2607918' => 108830] as $job => $contract) {
        if (!str_contains($filter, $job)) {
            continue;
        }
        $row = ['Job_No' => $job, 'Job_Task_No' => '000', 'Description' => 'Project TOTAAL'];
        foreach (explode(',', $select) as $field) {
            if ($field === 'LVS_Contract_Total_Price_2') {
                $row[$field] = $contract;
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

// Veld bestaat: Opbrengst VC en het bewaarde contracttotaal komen uit taak 000.
$r = $method->invoke($service, $projects, 60);
check(($r['contract_totals']['prj2606162'] ?? null) === 12700.0, 'contract total from LVS_Contract_Total_Price_2');
check(($r['totals']['prj2606162'] ?? null) === 12700.0, 'Opbrengst VC from contract field');
check(($r['contract_totals']['prj2607918'] ?? null) === 108830.0, 'tweede project houdt zijn contracttotaal');
check(($r['totals']['prj2607918'] ?? null) === 108830.0, 'Opbrengst VC volgt het contracttotaal, niet de schedule-prijs');
check($r['warning'] === null, 'no warning when the field exists');
check(empty($r['task_type_error']), 'taak 000 heeft geen JobTaskLines nodig');

// Contractveld ontbreekt: Opbrengst VC blijft leeg, één melding, geen per-project-storm.
$GLOBALS['missing_field'] = 'LVS_Contract_Total_Price_2';
$GLOBALS['odata_calls'] = 0;
$r = $method->invoke($service, $projects, 60);
check($r['totals'] === [], 'Opbrengst VC empty when its field is missing');
check($r['contract_totals'] === [], 'contract total empty when its field is missing');
check(substr_count((string) $r['warning'], 'LVS_Contract_Total_Price_2') <= 2 && substr_count((string) $r['warning'], ' | ') <= 1, 'one warning: ' . $r['warning']);
check(!str_contains((string) $r['warning'], 'CorrelationId'), 'no CorrelationId');
check($GLOBALS['odata_calls'] === 1, 'één falende request, niet per chunk, kreeg ' . $GLOBALS['odata_calls']);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} assertion(s) failed\n");
    exit(1);
}

echo "OK ProjectTaken tolerant select\n";
