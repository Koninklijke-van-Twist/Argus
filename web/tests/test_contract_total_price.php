<?php

/**
 * Opbrengst t/m periode leest Contract_Total_Price van ProjectTaken taak 000.
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
    'Contract_Total_Price' => 280000,
    'Contract_Invoiced_Price' => 150000,
    'LVS_Schedule_Total_Price_2' => 219000,
];
assert_same('Contract_Total_Price', FINANCE_CONTRACT_TOTAL_PRICE_FIELD, 'contract field is Contract_Total_Price');
assert_same(280000.0, finance_contract_total_price_amount($prj2602236), 'task 000 contract total is the column amount');

assert_same(280000.0, finance_contract_total_price_amount([
    'Job_No' => 'PRJ2602236',
    'Job_Task_No' => '000',
    'Contract_Total_Price' => 280000,
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
    'Contract_Total_Price' => 280000,
]), 'non-total task is ignored');

assert_same(0.0, finance_contract_total_price_amount([
    'Job_No' => 'PRJ2602236',
    'Job_Task_No' => '000',
]), 'missing contract field stays 0');

assert_same(280000.5, finance_contract_total_price_amount([
    'Job_Task_No' => '000',
    'Contract_Total_Price' => '280000.50',
]), 'numeric strings are accepted');

assert_same(280000.0, finance_contract_total_price_amount([
    'Contract_Total_Price' => 280000,
]), 'missing task number still counts as the project card value');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} assertion(s) failed\n");
    exit(1);
}

echo "OK contract total price\n";
