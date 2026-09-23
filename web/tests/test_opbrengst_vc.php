<?php

/**
 * Opbrengst VC leest LVS_Schedule_Total_Price_2 van taak 000.
 * Aanneemsom blijft Type GB-rekening + No 800000 en telt niet mee voor deze kolom.
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
    'LVS_Schedule_Total_Price_2' => 219000,
    'Line_Amount_LCY' => 280000,
    'Type' => 'GB-rekening',
    'No' => '800000',
];
assert_same('LVS_Schedule_Total_Price_2', FINANCE_OPBRENGST_VC_FIELD, 'Opbrengst VC field is schedule price');
assert_same(219000.0, finance_opbrengst_vc_amount($prj2602236), 'PRJ2602236 schedule price is Opbrengst VC');

$ariadne = [
    'Job_No' => 'PRJ2602236',
    'Job_Task_No' => '000',
    'LVS_Schedule_Total_Price_2' => 219000,
    'LVS_Baseline_Total_Price' => 0,
];
assert_same(219000.0, finance_opbrengst_vc_amount($ariadne), 'schedule price is kept when baseline price is 0');
assert_same(0.0, finance_opbrengst_vc_amount([
    'Job_No' => 'PRJ2602236',
    'Job_Task_No' => '000',
    'LVS_Baseline_Total_Price' => 219000,
]), 'baseline price alone is not Opbrengst VC');
assert_same(0.0, finance_opbrengst_vc_amount([
    'Job_No' => 'PRJ2602236',
    'Job_Task_No' => '000',
    'LVS_Schedule_Total_Price_2' => 0,
    'LVS_Baseline_Total_Price' => 219000,
]), 'baseline price is not a fallback when schedule price is 0');

assert_same(0.0, finance_opbrengst_vc_amount([
    'Job_No' => 'PRJ2602236',
    'Job_Task_No' => '010',
    'LVS_Schedule_Total_Price_2' => 219000,
]), 'non-total task is ignored');

assert_same(0.0, finance_opbrengst_vc_amount([
    'Job_No' => 'PRJ2602236',
    'Job_Task_No' => '000',
]), 'missing schedule field stays 0');

assert_same(0.0, finance_opbrengst_vc_amount([
    'Type' => 'GB-rekening',
    'No' => '800000',
    'Line_Amount_LCY' => 280000,
]), 'aanneemsom line amount is not Opbrengst VC');

assert_same(true, finance_is_revenue_gl_account_line([
    'Type' => 'GB-rekening',
    'No' => '800000',
    'Line_Amount_LCY' => 280000,
]), '800000 aanneemsom mapping still matches G/L 800000');

assert_same(false, finance_is_revenue_gl_account_line([
    'Type' => 'Resource',
    'No' => '800000',
]), '800000 aanneemsom mapping still requires Type GB-rekening');

assert_same(219000.5, finance_opbrengst_vc_amount([
    'Job_Task_No' => '000',
    'LVS_Schedule_Total_Price_2' => '219000.50',
]), 'numeric strings are accepted');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} assertion(s) failed\n");
    exit(1);
}

echo "OK opbrengst VC\n";
