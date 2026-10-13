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
assert_same(true, bc_fetch_project_is_open(['LVS_Document_Status' => 'Open']), 'documentstatus Open is open');
assert_same(false, bc_fetch_project_is_open(['Status' => 'Completed', 'LVS_Document_Status' => 'Gesloten']), 'gesloten project valt af');
assert_same(false, bc_fetch_project_is_open(['No' => 'PRJ1']), 'zonder status is het project niet open');

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

if ($failures > 0) {
    fwrite(STDERR, "{$failures} assertion(s) failed\n");
    exit(1);
}

echo "OK open projects\n";
