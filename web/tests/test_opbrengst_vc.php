<?php

/**
 * Opbrengst VC leest LVS_Contract_Total_Price_2.
 * Taak 000, anders 000-000-000, anders de som van Boeking-taken.
 * Het contracttotaal bevat het meerwerk. Het hoofdproject is dat totaal
 * minus de opbrengst meerwerk die Argus al apart toont (ticket #1264).
 * Schedule-prijs, basislijnprijs en de aanneemsom tellen niet mee.
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

assert_same('LVS_Contract_Total_Price_2', FINANCE_OPBRENGST_VC_FIELD, 'Opbrengst VC field is het contracttotaal');

$prj2602195Tasks = [
    [
        'Job_No' => 'PRJ2602195',
        'Job_Task_No' => '000',
        'Description' => 'Project TOTAAL',
        'LVS_Contract_Total_Price_2' => 743300,
        'LVS_Schedule_Total_Price_2' => 598238.42,
        'LVS_Baseline_Total_Price' => 0,
        'LVS_Baseline_Total_Cost' => 589296.04,
    ],
    [
        'Job_No' => 'PRJ2602195',
        'Job_Task_No' => '000-000-010',
        'Description' => 'Hoofdproject',
        'LVS_Contract_Total_Price_2' => 685000,
        'Job_Task_Type' => 'Boeking',
    ],
    [
        'Job_No' => 'PRJ2602195',
        'Job_Task_No' => '000-000-011',
        'Description' => 'Meerwerk',
        'LVS_Contract_Total_Price_2' => 58300,
        'Job_Task_Type' => 'Boeking',
    ],
    [
        'Job_No' => 'PRJ2602195',
        'Job_Task_No' => '200-000-070',
        'Description' => '2x generator 23-GE1650-29',
        'LVS_Schedule_Total_Price_2' => 598238.42,
        'Job_Task_Type' => 'Boeking',
    ],
];

$withTask000 = finance_opbrengst_vc_from_task_rows($prj2602195Tasks);
assert_same(743300.0, $withTask000['amount'], 'PRJ2602195 taak 000 is het contracttotaal, inclusief meerwerk');
assert_same(1, count($withTask000['lines']), 'taak 000 levert één bronregel');
assert_same('000', $withTask000['lines'][0]['Job_Task_No'], 'bronregel is taak 000');

$hoofdproject = finance_opbrengst_vc_hoofdproject($withTask000['amount'], 58300, $withTask000['lines']);
assert_same(685000.0, $hoofdproject['amount'], 'hoofdproject is contracttotaal minus het al getoonde meerwerk');
assert_same(1, count($hoofdproject['lines']), 'hoofdproject blijft één regel');
assert_same(685000.0, $hoofdproject['lines'][0]['Line_Amount'], 'de weergaveregel telt het meerwerk niet nog eens');
assert_same('000', $hoofdproject['lines'][0]['Job_Task_No'], 'de regel blijft taak 000');

assert_same(0.0, finance_opbrengst_vc_amount([
    'Job_No' => 'PRJ2602195',
    'Job_Task_No' => '000',
    'LVS_Schedule_Total_Price_2' => 598238.42,
    'LVS_Baseline_Total_Price' => 743300,
]), 'schedule-prijs en basislijnprijs zijn geen Opbrengst VC');

assert_same(743300.0, finance_opbrengst_vc_amount([
    'Job_No' => 'PRJ2602195',
    'Job_Task_No' => '000',
    'LVS_Contract_Total_Price_2' => 743300,
    'LVS_Schedule_Total_Price_2' => 598238.42,
    'LVS_Baseline_Total_Price' => 0,
]), 'taak 000 leest het contracttotaal, ook als de schedule-prijs erbij staat');

assert_same(0.0, finance_opbrengst_vc_amount([
    'Job_No' => 'PRJ2602195',
    'Job_Task_No' => '000-000-010',
    'LVS_Contract_Total_Price_2' => 685000,
]), 'een losse detailtaak is voor de enkelregel-helper geen taak 000');

$withoutTask000 = finance_opbrengst_vc_from_task_rows([
    [
        'Job_No' => 'PRJ2602195',
        'Job_Task_No' => '000-000-000',
        'Description' => 'Project TOTAAL',
        'LVS_Contract_Total_Price_2' => 743300,
    ],
    [
        'Job_No' => 'PRJ2602195',
        'Job_Task_No' => '000-000-010',
        'LVS_Contract_Total_Price_2' => 685000,
        'Job_Task_Type' => 'Boeking',
    ],
]);
assert_same(743300.0, $withoutTask000['amount'], 'zonder taak 000 wint taak 000-000-000');
$legacyHoofd = finance_opbrengst_vc_hoofdproject($withoutTask000['amount'], 58300, $withoutTask000['lines']);
assert_same(685000.0, $legacyHoofd['amount'], 'ook de legacy-totaaltaak wordt gesplitst in hoofdproject en meerwerk');

$detailOnly = finance_opbrengst_vc_from_task_rows([
    [
        'Job_No' => 'PRJLEGACY',
        'Job_Task_No' => 'KOP',
        'Job_Task_Type' => 'Kop',
        'LVS_Contract_Total_Price_2' => 743300,
    ],
    [
        'Job_No' => 'PRJLEGACY',
        'Job_Task_No' => '000-000-010',
        'Job_Task_Type' => 'Boeking',
        'Description' => 'Hoofdproject',
        'LVS_Contract_Total_Price_2' => 685000,
    ],
    [
        'Job_No' => 'PRJLEGACY',
        'Job_Task_No' => '000-000-011',
        'Job_Task_Type' => 'Boeking',
        'Description' => 'Meerwerk',
        'LVS_Contract_Total_Price_2' => 58300,
    ],
    [
        'Job_No' => 'PRJLEGACY',
        'Job_Task_No' => 'TOT',
        'Job_Task_Type' => 'Totaal',
        'LVS_Contract_Total_Price_2' => 743300,
    ],
]);
assert_same(743300.0, $detailOnly['amount'], 'zonder totaaltaak sommen de Boeking-taken, kop en totaal niet');
assert_same(2, count($detailOnly['lines']), 'twee Boeking-taken blijven als bronregels');
$detailHoofd = finance_opbrengst_vc_hoofdproject($detailOnly['amount'], 58300, $detailOnly['lines']);
assert_same(685000.0, $detailHoofd['amount'], 'detailproject: hoofdproject is de som minus het meerwerk');
$detailLineSum = 0.0;
foreach ($detailHoofd['lines'] as $line) {
    $detailLineSum += (float) $line['Line_Amount'];
}
assert_same(685000.0, $detailLineSum, 'de weergaveregels sommeren tot het hoofdproject, niet tot het contracttotaal');

$task000Zero = finance_opbrengst_vc_from_task_rows([
    [
        'Job_Task_No' => '000',
        'LVS_Contract_Total_Price_2' => 0,
    ],
    [
        'Job_Task_No' => '000-000-000',
        'LVS_Contract_Total_Price_2' => 999000,
    ],
]);
assert_same(0.0, $task000Zero['amount'], 'taak 000 met contract 0 valt niet terug op een andere taak');
assert_same(0.0, finance_opbrengst_vc_hoofdproject($task000Zero['amount'], 58300, $task000Zero['lines'])['amount'], 'meerwerk boven een contract van 0 maakt het hoofdproject niet negatief');

$unchanged = finance_opbrengst_vc_hoofdproject(214908, 0, [
    ['Job_Task_No' => '000', 'Line_Amount' => 214908, 'Description' => 'Project TOTAAL'],
]);
assert_same(214908.0, $unchanged['amount'], 'zonder meerwerk blijft het contracttotaal het hoofdproject');
assert_same(214908.0, $unchanged['lines'][0]['Line_Amount'], 'zonder meerwerk blijft de bronregel gelijk');

assert_same(0.0, finance_opbrengst_vc_amount([
    'Type' => 'GB-rekening',
    'No' => '800000',
    'Line_Amount_LCY' => 685000,
]), 'aanneemsomregel is geen Opbrengst VC');

assert_same(743300.5, finance_opbrengst_vc_amount([
    'Job_Task_No' => '000',
    'LVS_Contract_Total_Price_2' => '743300.50',
]), 'numerieke strings worden geaccepteerd');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} assertion(s) failed\n");
    exit(1);
}

echo "OK opbrengst VC\n";
