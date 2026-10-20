<?php

/**
 * Open projecten zonder Grootboekposten_OHW horen in de maandlijst.
 * Eén company-brede Status-filter; afdeling filtert de UI achteraf.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../bc_fetch/project_numbers.php';

$failures = 0;

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    global $failures;
    if ($expected !== $actual) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n  expected " . var_export($expected, true) . "\n  actual   " . var_export($actual, true) . "\n");
    }
}

assert_same("Status eq 'Open'", bc_fetch_open_projects_odata_filter(), 'open projecten: één Status-filter, geen afdeling');
assert_same("LVS_Document_Status eq 'Open'", bc_fetch_open_projects_document_status_filter(), 'reserve is documentstatus Open');
assert_same(false, str_contains(bc_fetch_open_projects_odata_filter(), 'LVS_Global_Dimension_1_Code'), 'afdeling zit niet in de OData-filter');

assert_same(true, bc_fetch_project_is_open(['Status' => 'Open']), 'Status Open is open');
assert_same(true, bc_fetch_project_is_open(['Status' => ' open ']), 'Status met witruimte blijft open');
assert_same(true, bc_fetch_project_is_open(['Status' => 'Open', 'LVS_Document_Status' => 'Gesloten']), 'Status Open blijft open');
assert_same(true, bc_fetch_project_is_open(['LVS_Document_Status' => 'Open']), 'documentstatus Open telt als Status ontbreekt');
assert_same(false, bc_fetch_project_is_open(['Status' => 'Completed', 'LVS_Document_Status' => 'Open']), 'Completed met documentstatus Open is geen open kaart');
assert_same(false, bc_fetch_project_is_open(['Status' => 'Quote', 'LVS_Document_Status' => 'Open']), 'Quote met documentstatus Open is geen open kaart');
assert_same(false, bc_fetch_project_is_open(['Status' => 'Completed', 'LVS_Document_Status' => 'Gesloten']), 'gesloten project valt af');
assert_same(false, bc_fetch_project_is_open(['No' => 'PRJ1']), 'zonder status is het project niet open');
assert_same(
    false,
    bc_fetch_open_projects_use_document_status_fallback(new RuntimeException('Mímir cURL error: timeout')),
    'timeout schakelt niet over naar documentstatus'
);
assert_same(
    true,
    bc_fetch_open_projects_use_document_status_fallback(new RuntimeException("Could not find a property named 'Status' on type 'NAV.Projecten'")),
    'schemafout op Status mag de reserve-query'
);

$union = bc_fetch_union_project_numbers(
    ['PRJ1', 'prj2608435'],
    ['PRJ2608435', 'PRJ2608376', '']
);
assert_same(['PRJ1', 'prj2608435', 'PRJ2608376'], $union, 'OHW en open projecten worden samengevoegd zonder dubbelen');

$parsed = bc_fetch_parse_open_project_rows([
    [
        'No' => 'PRJ2608435',
        'Status' => 'Open',
        'Description' => 'Voorbeeld KvT',
        'LVS_Global_Dimension_1_Code' => '50',
        'LVS_Baseline_Total_Cost' => 135495,
    ],
    [
        'No' => 'PRJ2608376',
        'Status' => 'Open',
        'LVS_Global_Dimension_1_Code' => '50',
    ],
    [
        'No' => 'PRJ-OTHER-DEPT',
        'Status' => 'Open',
        'LVS_Global_Dimension_1_Code' => '10',
    ],
    [
        'No' => 'PRJ-CLOSED',
        'Status' => 'Completed',
        'LVS_Document_Status' => 'Gesloten',
        'LVS_Global_Dimension_1_Code' => '50',
    ],
    'geen-rij',
]);
assert_same(
    ['PRJ2608435', 'PRJ2608376', 'PRJ-OTHER-DEPT'],
    $parsed['numbers'],
    'afdeling 50 en andere afdelingen blijven; gesloten valt af'
);
assert_same('50', $parsed['rows_by_norm']['prj2608435']['LVS_Global_Dimension_1_Code'] ?? '', 'PRJ2608435 houdt afdeling 50');
assert_same('50', $parsed['rows_by_norm']['prj2608376']['LVS_Global_Dimension_1_Code'] ?? '', 'PRJ2608376 houdt afdeling 50');
assert_same(false, isset($parsed['rows_by_norm']['prj-closed']), 'gesloten project komt niet in de rijen');

require_once __DIR__ . '/../bc_fetch/column_grootboekposten_ohw.php';
require_once __DIR__ . '/../bc_fetch/column_project_details.php';

$leftover = [
    'Job_No' => '4084207',
    'Document_No' => 'OHW260629',
    'Posting_Date' => '2026-06-29',
    'Reverse_Date' => '0001-01-01',
    'G_L_Account_No' => '899900',
    'G_L_Bal_Account_No' => '899900',
    'WIP_Entry_Amount' => -129143.12,
];
assert_same(false, bc_fetch_ohw_should_ignore_row($leftover), 'onteruggedraaid 899900-saldo blijft staan');
$delta = bc_fetch_ohw_amount_delta($leftover);
assert_same('costs', $delta['kind'] ?? '', '899900 is kosten');
assert_same(129143.12, round((float) ($delta['delta'] ?? 0.0), 2), 'negatief 899900 telt positief als kosten');
assert_same(
    true,
    bc_fetch_ohw_should_ignore_row([
        'G_L_Account_No' => '390000',
        'G_L_Bal_Account_No' => '390000',
        'WIP_Entry_Amount' => 50,
    ]),
    'gelijke rekeningen buiten 899900/899901 blijven buiten de maand'
);

$members = bc_fetch_month_member_keys(
    ['4084207', 'OHW-ONLY'],
    [
        'open-1' => ['No' => 'OPEN-1', 'Status' => 'Open'],
        '4084207' => ['No' => '4084207', 'Status' => 'Completed', 'LVS_Document_Status' => 'Open'],
        'closed-doc' => ['No' => 'CLOSED-DOC', 'Status' => 'Completed', 'LVS_Document_Status' => 'Open'],
    ]
);
assert_same(true, isset($members['4084207']), 'Completed met OHW-saldo blijft lid');
assert_same(true, isset($members['ohw-only']), 'OHW-project zonder kaart blijft lid');
assert_same(true, isset($members['open-1']), 'Status Open blijft lid');
assert_same(false, isset($members['closed-doc']), 'Completed zonder OHW valt af');

$planning = bc_fetch_filter_keyed_to_members([
    '4084207' => ['aanneemsom' => 1000.0],
    'open-1' => ['aanneemsom' => 50.0],
    'closed-doc' => ['aanneemsom' => 3564184.92],
], $members);
$costs = bc_fetch_filter_keyed_to_members([
    '4084207' => ['costs' => 129143.12],
    'ohw-only' => ['costs' => 10.0],
    'closed-doc' => ['costs' => 0.0],
], $members);
$aanneemsom = 0.0;
foreach ($planning as $row) {
    $aanneemsom += (float) ($row['aanneemsom'] ?? 0);
}
$kosten = 0.0;
foreach ($costs as $row) {
    $kosten += (float) ($row['costs'] ?? 0);
}
assert_same(1050.0, round($aanneemsom, 2), 'omzet is de aanneemsom van de maandleden');
assert_same(129153.12, round($kosten, 2), 'kosten blijven de OHW-kosten van de maandleden');
assert_same(false, isset($planning['closed-doc']), 'aanneemsom van een kaart buiten de maand telt niet');

$details = bc_fetch_project_detail_rows_for_numbers([
    ['No' => '4084207', 'Status' => 'Completed'],
    ['No' => 'CLOSED-DOC', 'Status' => 'Completed', 'LVS_Document_Status' => 'Open'],
], ['4084207']);
assert_same(true, isset($details['4084207']), 'gevraagd project blijft in de detailkolom');
assert_same(false, isset($details['closed-doc']), 'ongefilterd Projecten-antwoord voegt geen project toe');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} assertion(s) failed\n");
    exit(1);
}

echo "OK open projects\n";
