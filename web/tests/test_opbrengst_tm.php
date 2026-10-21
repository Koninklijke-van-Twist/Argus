<?php

/**
 * Ticket #1263: Opbrengst t/m is Contract_Invoiced_Price t/m de laatste dag van de maand.
 * Alleen Boeking-taken (JobTaskLines) tellen mee. Kop- en totaaltaken niet.
 * Peildatum 30-09-2026, fixtures:
 * PRJ2606162 = 3.810; PRJ2606730 = 6.512,40; PRJ2607691 = 529,96.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$GLOBALS['odata_mode'] = 'ok';
$GLOBALS['projecttaken_filters'] = [];
$GLOBALS['jobtask_filters'] = [];

/**
 * @return list<array<string,mixed>>
 */
function opbrengst_tm_task_rows(): array
{
    return [
        [
            'Job_No' => 'PRJ2606162',
            'Job_Task_No' => '000',
            'Description' => 'Project TOTAAL',
            'Job_Task_Type' => 'Totaal',
            'Contract_Invoiced_Price' => 3810,
            'LVS_Baseline_Total_Cost' => 100,
            'LVS_Schedule_Total_Price_2' => 9000,
            'LVS_Contract_Total_Price_2' => 12700,
        ],
        [
            'Job_No' => 'PRJ2606162',
            'Job_Task_No' => 'KOP',
            'Description' => 'Kop',
            'Job_Task_Type' => 'Kop',
            'Contract_Invoiced_Price' => 500,
        ],
        [
            'Job_No' => 'PRJ2606162',
            'Job_Task_No' => '010',
            'Description' => 'Boeking 1',
            'Job_Task_Type' => 'Boeking',
            'Contract_Invoiced_Price' => 2000,
        ],
        [
            'Job_No' => 'PRJ2606162',
            'Job_Task_No' => '020',
            'Description' => 'Boeking 2',
            'Job_Task_Type' => 'Boeking',
            'Contract_Invoiced_Price' => 1810,
        ],
        [
            'Job_No' => 'PRJ2606730',
            'Job_Task_No' => '000',
            'Description' => 'Project TOTAAL',
            'Job_Task_Type' => 'Totaal',
            'Contract_Invoiced_Price' => 6512.40,
            'LVS_Baseline_Total_Cost' => 50,
            'LVS_Schedule_Total_Price_2' => 8000,
            'LVS_Contract_Total_Price_2' => 9000,
        ],
        [
            'Job_No' => 'PRJ2606730',
            'Job_Task_No' => '010',
            'Description' => 'Boeking',
            'Job_Task_Type' => 'Boeking',
            'Contract_Invoiced_Price' => 6000,
        ],
        [
            'Job_No' => 'PRJ2606730',
            'Job_Task_No' => '020',
            'Description' => 'Boeking rest',
            'Job_Task_Type' => 'Boeking',
            'Contract_Invoiced_Price' => 512.40,
        ],
        [
            'Job_No' => 'PRJ2607691',
            'Job_Task_No' => '000',
            'Description' => 'Project TOTAAL',
            'Job_Task_Type' => 'Totaal',
            'Contract_Invoiced_Price' => 1059.92,
            'LVS_Baseline_Total_Cost' => 10,
            'LVS_Schedule_Total_Price_2' => 700,
            'LVS_Contract_Total_Price_2' => 800,
        ],
        [
            'Job_No' => 'PRJ2607691',
            'Job_Task_No' => 'TOT',
            'Description' => 'Totaal',
            'Job_Task_Type' => 'Totaal',
            'Contract_Invoiced_Price' => 529.96,
        ],
        [
            'Job_No' => 'PRJ2607691',
            'Job_Task_No' => '010',
            'Description' => 'Boeking',
            'Job_Task_Type' => 'Boeking',
            'Contract_Invoiced_Price' => 529.96,
        ],
    ];
}

/**
 * @return list<array<string,mixed>>
 */
function opbrengst_tm_planning_rows(): array
{
    return [
        [
            'Job_No' => 'PRJ2606162',
            'Job_Task_No' => '010',
            'Line_No' => 10000,
            'Line_Type' => 'Budget',
            'Type' => 'Grootboekrekening',
            'No' => '800000',
            'Description' => 'Aanneemsom',
            'Line_Amount_LCY' => 99999,
            'LVS_Job_Change_Order_No' => '',
        ],
        [
            'Job_No' => 'PRJ2606162',
            'Job_Task_No' => '010',
            'Line_No' => 20000,
            'Line_Type' => 'Budget',
            'Type' => 'Grootboekrekening',
            'No' => '800000',
            'Description' => 'Meerwerk',
            'Line_Amount_LCY' => 25,
            'LVS_Job_Change_Order_No' => 'SO-1',
        ],
    ];
}

function odata_get_all(string $url, array $auth, int $ttl): array
{
    $path = (string) parse_url($url, PHP_URL_PATH);
    $entity = rawurldecode(basename($path));
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $select = (string) ($query['$select'] ?? '');
    $filter = (string) ($query['$filter'] ?? '');

    if ($entity === 'ProjectTaken') {
        $GLOBALS['projecttaken_filters'][] = $filter;
        if ($GLOBALS['odata_mode'] === 'missing-field' && str_contains($select, 'Contract_Invoiced_Price')) {
            throw new RuntimeException(
                'HTTP 400 from OData: {"error":{"code":"BadRequest","message":"Could not find a property named \'Contract_Invoiced_Price\' on type \'NAV.ProjectTaken\'.  CorrelationId:  deadbeef."}}'
            );
        }

        return opbrengst_tm_task_rows();
    }

    if ($entity === 'JobTaskLines') {
        $GLOBALS['jobtask_filters'][] = $filter;
        if ($GLOBALS['odata_mode'] === 'jobtask-fail') {
            throw new RuntimeException(
                'HTTP 400 from OData: {"error":{"code":"BadRequest","message":"JobTaskLines query failed.  CorrelationId:  deadbeef."}}'
            );
        }

        $rows = [];
        foreach (opbrengst_tm_task_rows() as $row) {
            $taskRow = [
                'Job_No' => $row['Job_No'],
                'Job_Task_No' => $row['Job_Task_No'],
            ];
            if ($GLOBALS['odata_mode'] !== 'missing-type') {
                $taskRow['Job_Task_Type'] = $row['Job_Task_Type'];
            }
            $rows[] = $taskRow;
        }

        return $rows;
    }

    if ($entity === 'FactureerbareProjectPlanningsRegels') {
        return opbrengst_tm_planning_rows();
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

function money_is(float $actual, float $expected): bool
{
    return abs($actual - $expected) < 0.001;
}

check(finance_month_end_date('2026-09') === '2026-09-30', 'september 2026 eindigt op 30-09');
check(finance_month_end_date('2024-02') === '2024-02-29', 'schrikkeljaar');
check(finance_month_end_date('2026-02') === '2026-02-28', 'geen schrikkeljaar');
check(finance_month_end_date('2026-13') === null, 'ongeldige maand');
check(finance_month_end_date('') === null, 'lege maand');

$through = finance_posting_date_through_filter('2026-09');
check($through === "Posting_Date_Filter eq '..2026-09-30'", 'flowfilter met quotes en .., kreeg ' . $through);
check(!str_contains($through, ' le '), 'geen le-filter');
check(finance_posting_date_through_filter('geen-maand') === '', 'ongeldige maand geeft geen filter');

$onlyBookings = finance_invoiced_through_from_task_rows([
    ['Job_Task_No' => '010', 'Job_Task_Type' => 'Boeking', 'Description' => 'A', 'Contract_Invoiced_Price' => 6000],
    ['Job_Task_No' => '020', 'Job_Task_Type' => 'Boeking', 'Description' => 'B', 'Contract_Invoiced_Price' => 512.40],
    ['Job_Task_No' => '030', 'Job_Task_Type' => 'Boeking', 'Description' => 'Nul', 'Contract_Invoiced_Price' => 0],
]);
check(money_is($onlyBookings['amount'], 6512.40), 'som van boekingsregels is 6512,40, kreeg ' . $onlyBookings['amount']);
check(count($onlyBookings['lines']) === 2, 'nulregel komt niet in de specificatie');

check(ARGUS_MONTH_CACHE_VERSION === 5, 'snapshotversie is 5');
check(argus_month_cache_suffix() === '.v5.json', 'snapshotbestandsnaam is .v5.json');

$ref = new ReflectionClass(ProjectFinanceService::class);
$service = $ref->newInstanceWithoutConstructor();
foreach ([
    'auth' => [],
    'baseUrl' => 'https://bc.invalid:7148',
    'environment' => 'kvtmdlive_aad',
    'company' => 'Koninklijke van Twist',
] as $name => $value) {
    $prop = $ref->getProperty($name);
    $prop->setAccessible(true);
    $prop->setValue($service, $value);
}

$projects = ['PRJ2606162', 'PRJ2606730', 'PRJ2607691'];
$forecast = $service->collectProjectForecastForProjects($projects, 60, '2026-09');
$totals = is_array($forecast['forecast_totals_by_job'] ?? null) ? $forecast['forecast_totals_by_job'] : [];
$lines = is_array($forecast['forecast_breakdown_by_job'] ?? null) ? $forecast['forecast_breakdown_by_job'] : [];

check(empty($forecast['task_type_error']), 'geslaagde JobTaskLines blokkeert de maand niet');
check(money_is((float) ($totals['prj2606162']['invoiced_through'] ?? -1), 3810), 'PRJ2606162 is 3810, kreeg ' . var_export($totals['prj2606162']['invoiced_through'] ?? null, true));
check(money_is((float) ($totals['prj2606730']['invoiced_through'] ?? -1), 6512.40), 'PRJ2606730 is 6512,40, kreeg ' . var_export($totals['prj2606730']['invoiced_through'] ?? null, true));
check(money_is((float) ($totals['prj2607691']['invoiced_through'] ?? -1), 529.96), 'PRJ2607691 is 529,96, kreeg ' . var_export($totals['prj2607691']['invoiced_through'] ?? null, true));

$taskNos = [];
foreach ($lines['prj2606162']['invoiced_through_lines'] ?? [] as $line) {
    $taskNos[] = (string) ($line['Job_Task_No'] ?? '');
}
sort($taskNos);
check($taskNos === ['010', '020'], 'specificatie bevat alleen boekingstaken, kreeg ' . implode(',', $taskNos));

check(money_is((float) ($totals['prj2606162']['aanneemsom'] ?? -1), 100024), 'aanneemsom blijft de 800000-som');
check(money_is((float) ($totals['prj2606162']['extra_work'] ?? -1), 25), 'opbrengst meerwerk blijft het suborderdeel');
check(money_is((float) ($totals['prj2606162']['expected_revenue'] ?? -1), 12675), 'Opbrengst VC blijft contracttotaal min meerwerk');
check(money_is((float) ($totals['prj2606162']['expected_costs'] ?? -1), 100), 'Kosten VC blijft taak 000');
check(money_is((float) ($totals['prj2606162']['contract_total_price'] ?? -1), 12700), 'contracttotaal blijft LVS_Contract_Total_Price_2');

$invoicedFilters = array_values(array_filter(
    $GLOBALS['projecttaken_filters'],
    static fn (string $filter): bool => str_contains($filter, 'Posting_Date_Filter')
));
check(count($invoicedFilters) === 1, 'één ProjectTaken-query met datumfilter, kreeg ' . count($invoicedFilters));
check(
    ($invoicedFilters[0] ?? '') === "(Job_No eq 'PRJ2606162' or Job_No eq 'PRJ2606730' or Job_No eq 'PRJ2607691') and Posting_Date_Filter eq '..2026-09-30'",
    'filter is een gequote string met .., kreeg ' . ($invoicedFilters[0] ?? '')
);
check(!str_contains((string) ($invoicedFilters[0] ?? ''), ' le '), 'de query gebruikt geen le-filter: ' . ($invoicedFilters[0] ?? ''));
check(str_contains((string) ($invoicedFilters[0] ?? ''), "eq '..2026-09-30'"), 'datum staat tussen quotes');

$otherFilters = array_values(array_filter(
    $GLOBALS['projecttaken_filters'],
    static fn (string $filter): bool => !str_contains($filter, 'Posting_Date_Filter')
));
check($otherFilters !== [], 'andere ProjectTaken-kolommen blijven hun eigen query houden');
foreach ($otherFilters as $otherFilter) {
    check(!str_contains($otherFilter, 'Contract_Invoiced_Price'), 'datumfilter hoort niet op de andere ProjectTaken-queries: ' . $otherFilter);
}

check(count($GLOBALS['jobtask_filters']) === 1, 'één JobTaskLines-batch voor de drie projecten, kreeg ' . count($GLOBALS['jobtask_filters']));
check(str_contains((string) ($GLOBALS['jobtask_filters'][0] ?? ''), "Job_Task_Type eq 'Boeking'"), 'JobTaskLines filtert op Boeking');
check(str_contains((string) ($GLOBALS['jobtask_filters'][0] ?? ''), 'PRJ2606162'), 'JobTaskLines bevat het project');

$GLOBALS['odata_mode'] = 'jobtask-fail';
$blocked = $service->collectProjectForecastForProjects($projects, 60, '2026-09');
$blockedTotals = is_array($blocked['forecast_totals_by_job'] ?? null) ? $blocked['forecast_totals_by_job'] : [];
check(!empty($blocked['task_type_error']), 'JobTaskLines-fout blokkeert de snapshot');
check(is_string($blocked['warning']) && str_contains($blocked['warning'], 'JobTaskLines ophalen mislukt'), 'melding noemt JobTaskLines: ' . var_export($blocked['warning'] ?? null, true));
check(!isset($blockedTotals['prj2606162']['invoiced_through']), 'bij een typfout wordt de dubbeltelling niet opgeslagen');
check(money_is((float) ($blockedTotals['prj2606162']['aanneemsom'] ?? -1), 100024), 'andere kolommen blijven staan als het taaktype faalt');
check(finance_forecast_blocks_snapshot($blocked) === $blocked['warning'], 'publicatie blijft de vorige snapshot houden');

$GLOBALS['odata_mode'] = 'missing-field';
$missing = $service->collectProjectForecastForProjects($projects, 60, '2026-09');
$missingTotals = is_array($missing['forecast_totals_by_job'] ?? null) ? $missing['forecast_totals_by_job'] : [];
check(empty($missing['task_type_error']), 'ontbrekend prijsveld blokkeert de andere kolommen niet');
check(!isset($missingTotals['prj2606162']['invoiced_through']), 'ontbrekend veld laat Opbrengst t/m leeg');
check(money_is((float) ($missingTotals['prj2606162']['expected_revenue'] ?? -1), 12675), 'Opbrengst VC overleeft een ontbrekend factuurveld');
check(is_string($missing['warning']) && str_contains($missing['warning'], 'Contract_Invoiced_Price'), 'melding noemt het ontbrekende veld');

$GLOBALS['odata_mode'] = 'missing-type';
$untyped = $service->collectProjectForecastForProjects($projects, 60, '2026-09');
$untypedTotals = is_array($untyped['forecast_totals_by_job'] ?? null) ? $untyped['forecast_totals_by_job'] : [];
check(!empty($untyped['task_type_error']), 'ontbrekend taaktype blokkeert de snapshot');
check(!isset($untypedTotals['prj2606162']['invoiced_through']), 'zonder taaktype geen dubbeltelling');
check(money_is((float) ($untypedTotals['prj2607691']['expected_revenue'] ?? -1), 800), 'Opbrengst VC blijft staan zonder taaktype');

$GLOBALS['odata_mode'] = 'ok';
$GLOBALS['projecttaken_filters'] = [];
$noMonth = $service->collectProjectForecastForProjects($projects, 60, '');
$noMonthTotals = is_array($noMonth['forecast_totals_by_job'] ?? null) ? $noMonth['forecast_totals_by_job'] : [];
check(empty($noMonth['task_type_error']), 'zonder maand blijven de andere kolommen publiceerbaar');
check(!isset($noMonthTotals['prj2606162']['invoiced_through']), 'zonder maand geen Opbrengst t/m');
check(money_is((float) ($noMonthTotals['prj2606162']['expected_revenue'] ?? -1), 12675), 'Opbrengst VC blijft zonder maandargument');
$datedFilters = array_values(array_filter(
    $GLOBALS['projecttaken_filters'],
    static fn (string $filter): bool => str_contains($filter, 'Posting_Date_Filter')
));
check($datedFilters === [], 'zonder maand wordt geen datumfilter gestuurd');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} assertion(s) failed\n");
    exit(1);
}

echo "OK Opbrengst t/m\n";
