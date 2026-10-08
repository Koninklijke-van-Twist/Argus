<?php

/**
 * Opbrengst t/m periode leest LVS_Contract_Total_Price_2 van ProjectTaken taak 000.
 * ProjectTaken publiceert geen Contract_Total_Price (ticket #1170).
 * Contract_Invoiced_Price en de schedule-prijs tellen niet mee.
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

$prj2602236 = [
    'Job_No' => 'PRJ2602236',
    'Job_Task_No' => '000',
    'Description' => 'Project TOTAAL',
    'LVS_Contract_Total_Price_2' => 280000,
    'Contract_Invoiced_Price' => 150000,
    'LVS_Schedule_Total_Price_2' => 219000,
];
assert_same('LVS_Contract_Total_Price_2', FINANCE_CONTRACT_TOTAL_PRICE_FIELD, 'contract field is LVS_Contract_Total_Price_2 (bestaat op ProjectTaken)');
assert_same(280000.0, finance_contract_total_price_amount($prj2602236), 'task 000 contract total is the column amount');

assert_same(280000.0, finance_contract_total_price_amount([
    'Job_No' => 'PRJ2602236',
    'Job_Task_No' => '000',
    'LVS_Contract_Total_Price_2' => 280000,
    'Contract_Invoiced_Price' => 150000,
]), 'invoiced price is not used when contract total is set');

assert_same(0.0, finance_contract_total_price_amount([
    'Job_No' => 'PRJ2602236',
    'Job_Task_No' => '000',
    'Contract_Invoiced_Price' => 150000,
    'LVS_Schedule_Total_Price_2' => 219000,
]), 'invoiced price and schedule price are not a fallback');

assert_same(0.0, finance_contract_total_price_amount([
    'Job_No' => 'PRJ2602236',
    'Job_Task_No' => '010',
    'LVS_Contract_Total_Price_2' => 280000,
]), 'non-total task is ignored');

assert_same(0.0, finance_contract_total_price_amount([
    'Job_No' => 'PRJ2602236',
    'Job_Task_No' => '000',
]), 'missing contract field stays 0');

assert_same(280000.5, finance_contract_total_price_amount([
    'Job_Task_No' => '000',
    'LVS_Contract_Total_Price_2' => '280000.50',
]), 'numeric strings are accepted');

assert_same(280000.0, finance_contract_total_price_amount([
    'LVS_Contract_Total_Price_2' => 280000,
]), 'missing task number still counts as the project card value');

// Live kvtmdlive_aad, Koninklijke van Twist, taak 000 (2026-10-08): contracttotaal, niet de basislijn.
assert_same(24297.0, finance_contract_total_price_amount([
    'Job_No' => 'PRJ2602239',
    'Job_Task_No' => '000',
    'LVS_Baseline_Total_Price' => 24209,
    'LVS_Contract_Total_Price_2' => 24297,
    'LVS_Schedule_Total_Price_2' => 25475.12,
]), 'baseline price is not the contract total');

assert_same(0.0, finance_contract_total_price_amount([
    'Job_Task_No' => '000',
    'Contract_Total_Price' => 280000,
]), 'Contract_Total_Price (niet op ProjectTaken) wordt niet gelezen');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} assertion(s) failed\n");
    exit(1);
}

echo "OK contract total price\n";
