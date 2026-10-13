<?php

/**
 * Kosten VC: taak 000, anders 000-000-000, anders som van detailtaken.
 * Totaal- en kopregels tellen in die som niet mee.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../finance_calculations.php';

$failures = 0;

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    global $failures;
    if ($expected !== $actual) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n  expected " . var_export($expected, true) . "\n  actual   " . var_export($actual, true) . "\n");
    }
}

$project159927 = finance_baseline_cost_from_task_rows([
    [
        'Job_No' => '159927',
        'Job_Task_No' => '000-000-000',
        'Job_Task_Type' => 'Totaal',
        'Description' => 'Project TOTAAL',
        'LVS_Baseline_Total_Cost' => 266254,
    ],
    [
        'Job_No' => '159927',
        'Job_Task_No' => '000-000-010',
        'Job_Task_Type' => 'Posting',
        'LVS_Baseline_Total_Cost' => 100000,
    ],
    [
        'Job_No' => '159927',
        'Job_Task_No' => '000-000-020',
        'Job_Task_Type' => 'Posting',
        'LVS_Baseline_Total_Cost' => 166254,
    ],
]);
assert_same(266254.0, $project159927['amount'], '159927 gebruikt taak 000-000-000, ook als het type Totaal is');

$project150933 = finance_baseline_cost_from_task_rows([
    [
        'Job_No' => '150933',
        'Job_Task_No' => '000-001',
        'Job_Task_Type' => 'Begin-Total',
        'LVS_Baseline_Total_Cost' => 121699,
    ],
    [
        'Job_No' => '150933',
        'Job_Task_No' => '100',
        'Job_Task_Type' => 'Kop',
        'LVS_Baseline_Total_Cost' => 121699,
    ],
    [
        'Job_No' => '150933',
        'Job_Task_No' => '110',
        'Job_Task_Type' => 'Posting',
        'LVS_Baseline_Total_Cost' => 70000,
    ],
    [
        'Job_No' => '150933',
        'Job_Task_No' => '120',
        'Job_Task_Type' => 'Posting',
        'LVS_Baseline_Total_Cost' => 51699,
    ],
]);
assert_same(121699.0, $project150933['amount'], '150933 somt detailtaken en slaat Begin-Total en Kop over');

$project153390 = finance_baseline_cost_from_task_rows([
    [
        'Job_No' => '153390',
        'Job_Task_No' => '900',
        'Job_Task_Type' => 'End-Total',
        'LVS_Baseline_Total_Cost' => 1448000,
    ],
    [
        'Job_No' => '153390',
        'Job_Task_No' => '100',
        'Job_Task_Type' => 'Posting',
        'LVS_Baseline_Total_Cost' => 1000000,
    ],
    [
        'Job_No' => '153390',
        'Job_Task_No' => '200',
        'Job_Task_Type' => 'Posting',
        'LVS_Baseline_Total_Cost' => 448000,
    ],
]);
assert_same(1448000.0, $project153390['amount'], '153390 somt detailtaken en slaat End-Total over');

$task000Wins = finance_baseline_cost_from_task_rows([
    [
        'Job_Task_No' => '000',
        'Job_Task_Type' => 'Totaal',
        'LVS_Baseline_Total_Cost' => 500,
    ],
    [
        'Job_Task_No' => '000-000-000',
        'LVS_Baseline_Total_Cost' => 9000,
    ],
    [
        'Job_Task_No' => '010',
        'Job_Task_Type' => 'Posting',
        'LVS_Baseline_Total_Cost' => 400,
    ],
]);
assert_same(500.0, $task000Wins['amount'], 'taak 000 wint van 000-000-000 en detailtaken');

$task000Zero = finance_baseline_cost_from_task_rows([
    [
        'Job_Task_No' => '000',
        'LVS_Baseline_Total_Cost' => 0,
    ],
    [
        'Job_Task_No' => '000-000-000',
        'LVS_Baseline_Total_Cost' => 266254,
    ],
]);
assert_same(0.0, $task000Zero['amount'], 'taak 000 met bedrag 0 valt niet terug');
assert_same([], $task000Zero['lines'], 'nulregel van taak 000 levert geen breakdownregel');

$emptyType = finance_baseline_cost_from_task_rows([
    [
        'Job_Task_No' => '010',
        'Job_Task_Type' => '',
        'LVS_Baseline_Total_Cost' => 40,
    ],
    [
        'Job_Task_No' => '020',
        'LVS_Baseline_Total_Cost' => 60,
    ],
    [
        'Job_Task_No' => '030',
        'Job_Task_Type' => 'Heading',
        'LVS_Baseline_Total_Cost' => 100,
    ],
    [
        'Job_Task_No' => '040',
        'Job_Task_Type' => 'Total',
        'LVS_Baseline_Total_Cost' => 100,
    ],
]);
assert_same(100.0, $emptyType['amount'], 'leeg taaktype telt mee, Heading en Total niet');

$onlyRollup = finance_baseline_cost_from_task_rows([
    [
        'Job_Task_No' => '000-001',
        'Job_Task_Type' => 'Begin-Total',
        'LVS_Baseline_Total_Cost' => 10,
    ],
]);
assert_same(0.0, $onlyRollup['amount'], 'alleen totaalregels geven som 0');

assert_same(2, ARGUS_MONTH_CACHE_VERSION, 'maandcache is versie 2');
assert_same('.v2.json', argus_month_cache_suffix(), 'snapshots heten .v2.json');
assert_same(false, argus_month_cache_is_current([]), 'ongenummerde snapshot is niet actueel');
assert_same(true, argus_month_cache_is_current(['cache_version' => 2]), 'versie 2 is actueel');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} assertion(s) failed\n");
    exit(1);
}

echo "OK baseline cost\n";
