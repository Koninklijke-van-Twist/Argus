<?php

/**
 * ProjectTaken heeft geen Job_Task_Type. De detailsom haalt Boeking uit één
 * JobTaskLines-query per bedrijf. Lukt die query niet, dan blijft de som
 * ongefilterd en blijft de melding staan.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$GLOBALS['odata_calls'] = 0;
$GLOBALS['jobtask_calls'] = 0;
$GLOBALS['jobtask_filters'] = [];
$GLOBALS['projecttaken_selects'] = [];
$GLOBALS['jobtask_mode'] = 'ok';

/**
 * 19 Boeking-taken die samen € 1.686.718,58 zijn, plus kop en totaal.
 *
 * @return list<array<string,mixed>>
 */
function baseline_schema_project_153390_rows(): array
{
    $rows = [];
    $amounts = array_fill(0, 18, 88774.66);
    $amounts[] = 88774.70;
    foreach ($amounts as $index => $amount) {
        $rows[] = [
            'Job_No' => '153390',
            'Job_Task_No' => sprintf('%03d', $index + 1),
            'Description' => 'Boeking ' . ($index + 1),
            'LVS_Baseline_Total_Cost' => $amount,
        ];
    }

    $rows[] = [
        'Job_No' => '153390',
        'Job_Task_No' => 'KOP',
        'Description' => 'Kop',
        'LVS_Baseline_Total_Cost' => 1000,
    ];
    $rows[] = [
        'Job_No' => '153390',
        'Job_Task_No' => 'TOT',
        'Description' => 'Totaal',
        'LVS_Baseline_Total_Cost' => 2000,
    ];

    return $rows;
}

function odata_get_all(string $url, array $auth, int $ttl): array
{
    $GLOBALS['odata_calls']++;
    $path = (string) parse_url($url, PHP_URL_PATH);
    $entity = rawurldecode(basename($path));
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $select = (string) ($query['$select'] ?? '');
    $filter = (string) ($query['$filter'] ?? '');

    if ($entity === 'ProjectTaken') {
        $GLOBALS['projecttaken_selects'][] = $select;
        if (in_array('Job_Task_Type', explode(',', $select), true)) {
            throw new RuntimeException(
                'HTTP 400 from OData: {"error":{"code":"BadRequest","message":"Could not find a property named \'Job_Task_Type\' on type \'NAV.ProjectTaken\'.  CorrelationId:  ' . bin2hex(random_bytes(6)) . '."}}'
            );
        }

        $rows = [];
        if (str_contains($filter, '153390')) {
            $rows = array_merge($rows, baseline_schema_project_153390_rows());
        }
        if (str_contains($filter, 'PRJ0')) {
            $rows[] = [
                'Job_No' => 'PRJ0',
                'Job_Task_No' => '000',
                'Description' => 'Project TOTAAL',
                'LVS_Baseline_Total_Cost' => 10,
            ];
        }
        if (str_contains($filter, '159927')) {
            $rows[] = [
                'Job_No' => '159927',
                'Job_Task_No' => '000-000-000',
                'Description' => 'Project TOTAAL',
                'LVS_Baseline_Total_Cost' => 266254,
            ];
            $rows[] = [
                'Job_No' => '159927',
                'Job_Task_No' => '000-000-010',
                'Description' => 'Detail',
                'LVS_Baseline_Total_Cost' => 100000,
            ];
        }

        return $rows;
    }

    if ($entity === 'JobTaskLines') {
        $GLOBALS['jobtask_calls']++;
        $GLOBALS['jobtask_filters'][] = $filter;
        if ($GLOBALS['jobtask_mode'] === 'fail') {
            throw new RuntimeException(
                'HTTP 400 from OData: {"error":{"code":"BadRequest","message":"JobTaskLines query failed.  CorrelationId:  deadbeef."}}'
            );
        }
        if ($GLOBALS['jobtask_mode'] === 'unreadable') {
            return [
                ['Description' => 'zonder sleutel', 'Job_Task_Type' => 'Boeking'],
            ];
        }

        $rows = [];
        foreach (baseline_schema_project_153390_rows() as $row) {
            $taskNo = (string) $row['Job_Task_No'];
            $type = $taskNo === 'KOP' ? 'Kop' : ($taskNo === 'TOT' ? 'Totaal' : 'Boeking');
            $rows[] = [
                'Job_No' => '153390',
                'Job_Task_No' => $taskNo,
                'Job_Task_Type' => $type,
                'Totaling' => '',
                'LVS_Job_Line_Type' => '',
                'LVS_Indentation' => 0,
            ];
        }

        return $rows;
    }

    throw new RuntimeException('Onverwachte entity ' . $entity);
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

$projects = ['153390', 'PRJ0', '159927'];
for ($i = 1; $i < 10; $i++) {
    $projects[] = 'PRJ' . $i;
}

$r = $method->invoke($service, $projects, 60);
check(($r['totals']['153390'] ?? null) !== null, '153390 heeft een totaal');
check(number_format((float) ($r['totals']['153390'] ?? 0), 2, '.', '') === '1686718.58', '153390 blijft € 1.686.718,58, kreeg ' . var_export($r['totals']['153390'] ?? null, true));
check(count($r['breakdown']['153390'] ?? []) === 19, '19 Boeking-regels, kreeg ' . count($r['breakdown']['153390'] ?? []));
check(($r['totals']['prj0'] ?? null) === 10.0, 'taak 000 blijft 10');
check(($r['totals']['159927'] ?? null) === 266254.0, '159927 blijft taak 000-000-000');
check($r['warning'] === null, 'geen melding als JobTaskLines het type levert: ' . var_export($r['warning'], true));
check($GLOBALS['jobtask_calls'] === 1, 'één JobTaskLines-query per bedrijf, kreeg ' . $GLOBALS['jobtask_calls']);
check($GLOBALS['odata_calls'] === 3, 'twee ProjectTaken-chunks plus één JobTaskLines, niet per project, kreeg ' . $GLOBALS['odata_calls']);
$jobFilter = (string) ($GLOBALS['jobtask_filters'][0] ?? '');
check(str_contains($jobFilter, "Job_Task_Type eq 'Boeking'"), 'filter op Boeking: ' . $jobFilter);
check(str_contains($jobFilter, '153390'), 'JobTaskLines beperkt tot fallback-projecten');
check(!str_contains($jobFilter, 'PRJ0'), 'project met taak 000 zit niet in JobTaskLines: ' . $jobFilter);
check(!str_contains($jobFilter, '159927'), 'project met taak 000-000-000 zit niet in JobTaskLines: ' . $jobFilter);
foreach ($GLOBALS['projecttaken_selects'] as $select) {
    check(!str_contains($select, 'Job_Task_Type'), 'ProjectTaken-select vraagt Job_Task_Type niet: ' . $select);
}

$callsAfterTask000 = $GLOBALS['jobtask_calls'];
$onlyTotal = $method->invoke($service, ['PRJ0'], 60);
check($GLOBALS['jobtask_calls'] === $callsAfterTask000, 'geen JobTaskLines als alleen taak 000 voorkomt');
check(($onlyTotal['totals']['prj0'] ?? null) === 10.0, 'alleen taak 000 blijft 10');
check($onlyTotal['warning'] === null, 'geen melding zonder detailsom');

$GLOBALS['jobtask_mode'] = 'fail';
$GLOBALS['odata_calls'] = 0;
$GLOBALS['jobtask_calls'] = 0;
$failed = $method->invoke($service, $projects, 60);
$expectedFailed = finance_baseline_cost_from_task_rows(baseline_schema_project_153390_rows());
check(($failed['totals']['153390'] ?? null) === $expectedFailed['amount'], 'mislukte query houdt de ongefilterde som');
check(is_string($failed['warning']) && str_contains($failed['warning'], 'Veld Job_Task_Type bestaat niet op ProjectTaken'), 'melding blijft staan: ' . var_export($failed['warning'], true));
check(is_string($failed['warning']) && str_contains($failed['warning'], 'totaal- en kopregels kunnen niet worden overgeslagen'), 'melding noemt het ontbrekende filter');
check(substr_count((string) $failed['warning'], 'Job_Task_Type') === 1, 'één melding, niet de ruwe schemafout erbij');
check(!str_contains((string) $failed['warning'], 'CorrelationId'), 'geen CorrelationId');
check($GLOBALS['jobtask_calls'] === 1, 'mislukte query wordt niet per project herhaald, kreeg ' . $GLOBALS['jobtask_calls']);

$GLOBALS['jobtask_mode'] = 'unreadable';
$unreadable = $method->invoke($service, ['153390'], 60);
check(($unreadable['totals']['153390'] ?? null) === $expectedFailed['amount'], 'onleesbare JobTaskLines valt terug op de ongefilterde som');
check(is_string($unreadable['warning']) && str_contains($unreadable['warning'], 'Veld Job_Task_Type bestaat niet op ProjectTaken'), 'onleesbare query houdt de melding');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} assertion(s) failed\n");
    exit(1);
}

echo "OK baseline cost schema\n";
