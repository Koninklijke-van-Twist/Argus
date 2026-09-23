<?php

/**
 * Opbrengst meerwerk = factureerbare G/L 800000 met gevuld LVS_Job_Change_Order_No.
 * Aanneemsom = dezelfde regels met leeg subordernummer.
 * Q002 in Description alleen is geen meerwerk.
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

$base = [
    'Job_No' => '163679',
    'Type' => 'GB-rekening',
    'No' => '800000',
    'Line_Type' => 'Factureerbaar',
    'Line_Amount_LCY' => 2825,
    'LVS_Job_Change_Order_No' => 'SO-1',
];

assert_same(true, finance_is_revenue_gl_account_line($base), 'GB-rekening 800000 blijft omzetrekening');
assert_same(true, finance_is_revenue_gl_account_line([
    'Type' => 'G/L Account',
    'No' => '800000',
]), 'Engelse G/L Account telt als omzetrekening');
assert_same(true, finance_is_revenue_gl_account_line([
    'Type' => 'GLAccount',
    'No' => '800000',
]), 'GLAccount-alias telt als omzetrekening');
assert_same(false, finance_is_revenue_gl_account_line([
    'Type' => 'Resource',
    'No' => '800000',
]), 'Resource op 800000 telt niet');
assert_same(false, finance_is_revenue_gl_account_line([
    'Type' => 'GB-rekening',
    'No' => '800100',
]), 'andere grootboekrekening telt niet');

assert_same(2825.0, finance_opbrengst_meerwerk_amount($base), 'SO-1 op 800000 is opbrengst meerwerk');
assert_same(0.0, finance_aanneemsom_amount($base), 'gevuld subordernr. hoort niet bij aanneemsom');

$emptyChangeOrder = $base;
$emptyChangeOrder['LVS_Job_Change_Order_No'] = '   ';
$emptyChangeOrder['Line_Amount_LCY'] = 10000;
assert_same(10000.0, finance_aanneemsom_amount($emptyChangeOrder), 'witruimte in subordernr. blijft aanneemsom');
assert_same(0.0, finance_opbrengst_meerwerk_amount($emptyChangeOrder), 'leeg subordernr. is geen meerwerk');

$descriptionOnly = $base;
$descriptionOnly['LVS_Job_Change_Order_No'] = '';
$descriptionOnly['Description'] = 'Meerwerk Q002';
$descriptionOnly['Line_Amount_LCY'] = 400;
assert_same(400.0, finance_aanneemsom_amount($descriptionOnly), 'Q002 alleen in Description blijft aanneemsom');
assert_same(0.0, finance_opbrengst_meerwerk_amount($descriptionOnly), 'Q002 in Description is geen meerwerk');

$forecast = $base;
$forecast['Line_Type'] = 'Prognose';
assert_same(0.0, finance_opbrengst_meerwerk_amount($forecast), 'prognose telt niet als meerwerk');
assert_same(0.0, finance_aanneemsom_amount($forecast), 'prognose telt niet als aanneemsom');

$englishForecast = $base;
$englishForecast['Line_Type'] = 'Billable, Forecast';
assert_same(0.0, finance_opbrengst_meerwerk_amount($englishForecast), 'forecast sluit billable uit');

$budget = $base;
$budget['Line_Type'] = 'Budget';
$budget['LVS_Job_Change_Order_No'] = '';
assert_same(0.0, finance_aanneemsom_amount($budget), 'budget zonder factureerbaar telt niet');

$both = $base;
$both['Line_Type'] = 'Both Budget and Billable';
$both['LVS_Job_Change_Order_No'] = '';
$both['Line_Amount_LCY'] = 50;
assert_same(50.0, finance_aanneemsom_amount($both), 'billable combinatie zonder suborder is aanneemsom');

$cancelled = $base;
$cancelled['LVS_Cancelled_Original_Line'] = true;
assert_same(0.0, finance_opbrengst_meerwerk_amount($cancelled), 'geannuleerde regel telt niet');

$split = finance_split_contract_revenue([
    [
        'Job_No' => '163679',
        'Type' => 'GB-rekening',
        'No' => '800000',
        'Line_Type' => 'Factureerbaar',
        'Line_Amount_LCY' => 1000,
        'LVS_Job_Change_Order_No' => '',
    ],
    [
        'Job_No' => '163679',
        'Type' => 'G/L Account',
        'No' => '800000',
        'Line_Type' => 'Billable',
        'Line_Amount_LCY' => 1500,
        'LVS_Job_Change_Order_No' => 'SO-1',
    ],
    [
        'Job_No' => '163679',
        'Type' => 'GLAccount',
        'No' => '800000',
        'Line_Type' => 'Factureerbaar',
        'Line_Amount_LCY' => 1325,
        'LVS_Job_Change_Order_No' => 'SO-1',
    ],
    [
        'Job_No' => '163679',
        'Type' => 'GB-rekening',
        'No' => '800000',
        'Line_Type' => 'Prognose',
        'Line_Amount_LCY' => 9999,
        'LVS_Job_Change_Order_No' => 'SO-1',
    ],
    [
        'Job_No' => '163679',
        'Type' => 'Resource',
        'No' => '800000',
        'Line_Type' => 'Factureerbaar',
        'Line_Amount_LCY' => 500,
        'LVS_Job_Change_Order_No' => 'SO-1',
    ],
    [
        'Job_No' => '163679',
        'Type' => 'GB-rekening',
        'No' => '800000',
        'Line_Type' => 'Factureerbaar',
        'Line_Amount_LCY' => 400,
        'LVS_Job_Change_Order_No' => '',
        'Description' => 'Q002 extra',
    ],
    'geen-regel',
]);

assert_same(2825.0, $split['opbrengst_meerwerk'], 'project 163679 meerwerk somt 800000-regels met suborder SO-1');
assert_same(1400.0, $split['aanneemsom'], 'aanneemsom laat suborderregels weg');

assert_same(0.0, finance_opbrengst_vc_amount($base), 'meerwerkregel is geen Opbrengst VC');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} assertion(s) failed\n");
    exit(1);
}

echo "OK opbrengst meerwerk\n";
