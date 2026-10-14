<?php

/**
 * ProjectTaken heeft geen Job_Task_Type. De detailsom haalt Boeking uit
 * gebatchte JobTaskLines-queries. Een onleesbaar antwoord houdt de ongefilterde
 * som. Een timeout of HTTP-fout wordt een duidelijke fout en blokkeert de snapshot.
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

        if (in_array($GLOBALS['jobtask_mode'], ['many', 'fail-many', 'timeout-many'], true)) {
            preg_match_all("/Job_No eq '([^']+)'/", $filter, $matches);
            $rows = [];
            foreach ($matches[1] as $job) {
                $rows[] = [
                    'Job_No' => $job,
                    'Job_Task_No' => '010',
                    'Description' => 'Boeking',
                    'LVS_Baseline_Total_Cost' => 10,
                ];
            }

            return $rows;
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
        if ($GLOBALS['jobtask_mode'] === 'fail' || $GLOBALS['jobtask_mode'] === 'fail-many') {
            throw new RuntimeException(
                'HTTP 400 from OData: {"error":{"code":"BadRequest","message":"JobTaskLines query failed.  CorrelationId:  deadbeef."}}'
            );
        }
        if ($GLOBALS['jobtask_mode'] === 'timeout' || $GLOBALS['jobtask_mode'] === 'timeout-many') {
            throw new RuntimeException('cURL error: Operation timed out after 90000 milliseconds with 0 bytes received');
        }
        if ($GLOBALS['jobtask_mode'] === 'unreadable') {
            return [
                ['Description' => 'zonder sleutel', 'Job_Task_Type' => 'Boeking'],
            ];
        }
        if ($GLOBALS['jobtask_mode'] === 'many') {
            preg_match_all("/Job_No eq '([^']+)'/", $filter, $matches);
            $rows = [];
            foreach ($matches[1] as $job) {
                $rows[] = [
                    'Job_No' => $job,
                    'Job_Task_No' => '010',
                    'Job_Task_Type' => 'Boeking',
                ];
            }

            return $rows;
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
check(empty($r['task_type_error']), 'geslaagde query blokkeert de snapshot niet');
check($GLOBALS['jobtask_calls'] === 1, 'één fallback-project blijft één JobTaskLines-query, kreeg ' . $GLOBALS['jobtask_calls']);
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
check(!empty($failed['task_type_error']), 'HTTP-fout op JobTaskLines blokkeert de snapshot');
check(is_string($failed['warning']) && str_contains($failed['warning'], 'JobTaskLines ophalen mislukt'), 'melding noemt de mislukte query: ' . var_export($failed['warning'], true));
check(is_string($failed['warning']) && str_contains($failed['warning'], 'HTTP 400'), 'melding houdt de HTTP-status: ' . var_export($failed['warning'], true));
check(!str_contains((string) $failed['warning'], 'CorrelationId'), 'geen CorrelationId');
check(!str_contains((string) $failed['warning'], 'Veld Job_Task_Type bestaat niet'), 'een queryfout is geen ontbrekend veld');
check(finance_forecast_blocks_snapshot(['task_type_error' => true, 'warning' => $failed['warning']]) === $failed['warning'], 'endpoint houdt de vorige snapshot bij deze fout');
check($GLOBALS['jobtask_calls'] === 1, 'mislukte query wordt niet per project herhaald, kreeg ' . $GLOBALS['jobtask_calls']);

$GLOBALS['jobtask_mode'] = 'timeout';
$timedOut = $method->invoke($service, ['153390'], 60);
check(!empty($timedOut['task_type_error']), 'timeout blokkeert de snapshot');
check(is_string($timedOut['warning']) && str_contains($timedOut['warning'], 'JobTaskLines ophalen mislukt'), 'timeout krijgt een melding: ' . var_export($timedOut['warning'], true));
check(is_string($timedOut['warning']) && str_contains($timedOut['warning'], 'timed out'), 'timeout noemt de oorzaak: ' . var_export($timedOut['warning'], true));

$GLOBALS['jobtask_mode'] = 'unreadable';
$unreadable = $method->invoke($service, ['153390'], 60);
check(($unreadable['totals']['153390'] ?? null) === $expectedFailed['amount'], 'onleesbare JobTaskLines valt terug op de ongefilterde som');
check(empty($unreadable['task_type_error']), 'onleesbaar antwoord blijft een waarschuwing');
check(is_string($unreadable['warning']) && str_contains($unreadable['warning'], 'Veld Job_Task_Type bestaat niet op ProjectTaken'), 'onleesbare query houdt de melding');
check(finance_forecast_blocks_snapshot(['task_type_error' => false, 'warning' => $unreadable['warning']]) === null, 'schema-waarschuwing vervangt de snapshot nog wel');

$many = [];
for ($i = 0; $i < 40; $i++) {
    $many[] = '15' . str_pad((string) $i, 4, '0', STR_PAD_LEFT);
}
$GLOBALS['jobtask_mode'] = 'many';
$GLOBALS['jobtask_calls'] = 0;
$GLOBALS['jobtask_filters'] = [];
$batched = $method->invoke($service, $many, 60);
check($GLOBALS['jobtask_calls'] === 2, '40 fallback-projecten worden twee JobTaskLines-batches, kreeg ' . $GLOBALS['jobtask_calls']);
check(empty($batched['task_type_error']), 'gebatchte query slaagt');
$covered = [];
foreach ($GLOBALS['jobtask_filters'] as $batchedFilter) {
    check(strlen($batchedFilter) <= FINANCE_JOB_TASK_LINES_MAX_FILTER_CHARS, 'filter blijft binnen ' . FINANCE_JOB_TASK_LINES_MAX_FILTER_CHARS . ' tekens: ' . strlen($batchedFilter));
    check(str_contains($batchedFilter, "Job_Task_Type eq 'Boeking'"), 'elke batch filtert op Boeking');
    preg_match_all("/Job_No eq '([^']+)'/", $batchedFilter, $coveredMatches);
    check(count($coveredMatches[1]) <= FINANCE_JOB_TASK_LINES_MAX_PROJECTS, 'batch heeft hoogstens ' . FINANCE_JOB_TASK_LINES_MAX_PROJECTS . ' projecten');
    foreach ($coveredMatches[1] as $jobNo) {
        $covered[$jobNo] = true;
    }
}
check(count($covered) === 40, 'elke fallback zit in precies de batches, kreeg ' . count($covered));
check(($batched['totals']['150000'] ?? null) === 10.0, 'eerste batch-project houdt het Boeking-bedrag');
check(($batched['totals']['150039'] ?? null) === 10.0, 'laatste batch-project houdt het Boeking-bedrag');

$GLOBALS['jobtask_mode'] = 'fail-many';
$GLOBALS['jobtask_calls'] = 0;
$method->invoke($service, $many, 60);
check($GLOBALS['jobtask_calls'] === 1, 'een mislukte batch stopt de rest, kreeg ' . $GLOBALS['jobtask_calls']);

$GLOBALS['jobtask_mode'] = 'timeout-many';
$GLOBALS['jobtask_calls'] = 0;
$method->invoke($service, $many, 60);
check($GLOBALS['jobtask_calls'] === 1, 'een timeout stopt de rest, kreeg ' . $GLOBALS['jobtask_calls']);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} assertion(s) failed\n");
    exit(1);
}

echo "OK baseline cost schema\n";
