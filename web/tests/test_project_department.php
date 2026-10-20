<?php

/**
 * Projectafdeling komt vers uit Projecten.LVS_Global_Dimension_1_Code.
 * Een oude kaart, OHW-regel of snapshot-code wordt niet overgenomen.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../bc_fetch/project_department.php';

$failures = 0;

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    global $failures;
    if ($expected !== $actual) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n  expected " . var_export($expected, true) . "\n  actual   " . var_export($actual, true) . "\n");
    }
}

function assert_true(bool $ok, string $message): void
{
    assert_same(true, $ok, $message);
}

assert_same('Projecten', BC_FETCH_PROJECT_DEPARTMENT_ENTITY, 'webservice blijft Projecten');
assert_same('LVS_Global_Dimension_1_Code', BC_FETCH_PROJECT_DEPARTMENT_FIELD, 'veld blijft LVS_Global_Dimension_1_Code');
assert_same(0, BC_FETCH_PROJECT_DEPARTMENT_MAX_AGE, 'max_age is 0, geen maandcache');

$reader = file_get_contents(__DIR__ . '/../bc_fetch/project_department.php');
$readerStart = is_string($reader) ? strpos($reader, 'function bc_fetch_project_department_rows_from_bc') : false;
$readerEnd = is_string($reader) ? strpos($reader, 'function bc_fetch_project_departments_from_rows') : false;
$readerBody = is_string($reader) && $readerStart !== false && $readerEnd !== false
    ? substr($reader, $readerStart, $readerEnd - $readerStart)
    : '';
assert_true(str_contains($readerBody, 'odata_mimir_query'), 'de lezing gaat via odata_mimir_query');
assert_true(str_contains($readerBody, 'BC_FETCH_PROJECT_DEPARTMENT_MAX_AGE'), 'de lezing geeft max_age 0 door');
assert_true(!str_contains($readerBody, 'odata_get_all'), 'odata_get_all(0) wordt niet gebruikt; die maakt er een uur cache van');
$fromRowsStart = is_string($reader) ? strpos($reader, 'function bc_fetch_project_departments_from_rows') : false;
$fromRowsEnd = is_string($reader) ? strpos($reader, 'function bc_fetch_project_departments(') : false;
$fromRowsBody = is_string($reader) && $fromRowsStart !== false && $fromRowsEnd !== false
    ? substr($reader, $fromRowsStart, $fromRowsEnd - $fromRowsStart)
    : '';
assert_true(!str_contains($fromRowsBody, 'Global_Dimension_1_Code'), 'de projectafdeling leest niet het OHW-veld');

$fromRows = bc_fetch_project_departments_from_rows([
    [
        'No' => 'PRJ1',
        'LVS_Global_Dimension_1_Code' => ' 50 ',
        'Global_Dimension_1_Code' => '10',
    ],
    ['No' => 'PRJ2', 'LVS_Global_Dimension_1_Code' => ''],
    ['No' => '  ', 'LVS_Global_Dimension_1_Code' => '99'],
    'geen-rij',
]);
assert_same(['prj1' => '50', 'prj2' => ''], $fromRows, 'alleen LVS_Global_Dimension_1_Code, getrimd');

$calls = [];
$departments = bc_fetch_project_departments('Koninklijke van Twist', [
    'PRJ1',
    'prj1',
    '',
    "O'Neil",
], static function (string $company, string $filter) use (&$calls): array {
    $calls[] = [$company, $filter];

    return [
        ['No' => 'PRJ1', 'LVS_Global_Dimension_1_Code' => '50', 'Global_Dimension_1_Code' => '10'],
        ['No' => "O'Neil", 'LVS_Global_Dimension_1_Code' => ''],
    ];
});
assert_same([['Koninklijke van Twist', "No eq 'PRJ1' or No eq 'O''Neil'"]], $calls, 'dubbelen vallen weg en een apostrof wordt geëscapet');
assert_same(['prj1' => '50', "o'neil" => ''], $departments, 'een lege BC-code blijft leeg');

$many = [];
for ($i = 1; $i <= 21; $i++) {
    $many[] = 'P' . $i;
}
$chunkCalls = 0;
$chunkFilters = [];
bc_fetch_project_departments('KvT', $many, static function (string $company, string $filter) use (&$chunkCalls, &$chunkFilters): array {
    $chunkCalls++;
    $chunkFilters[] = $filter;

    return [];
});
assert_same(2, $chunkCalls, '21 projecten zijn twee queries van hoogstens 20');
assert_same(20, substr_count($chunkFilters[0], 'No eq '), 'de eerste query heeft 20 nummers');
assert_same(1, substr_count($chunkFilters[1], 'No eq '), 'de tweede query heeft het laatste nummer');

$applied = bc_fetch_apply_project_departments(
    [
        ['Job_No' => 'PRJ1', 'Cost_Center' => '10'],
        ['Job_No' => 'PRJ2', 'Cost_Center' => '10'],
        ['Job_No' => 'PRJ9', 'Cost_Center' => '10'],
    ],
    [
        'prj1' => ['No' => 'PRJ1', 'LVS_Global_Dimension_1_Code' => '10'],
        'prj2' => ['No' => 'PRJ2', 'LVS_Global_Dimension_1_Code' => '10'],
    ],
    ['prj1' => '50', 'prj2' => '']
);
assert_same('50', $applied['project_summaries'][0]['Cost_Center'], 'een nieuwe afdeling vervangt de oude snapshot-code');
assert_same('', $applied['project_summaries'][1]['Cost_Center'], 'een lege afdeling wist de oude code');
assert_same('', $applied['project_summaries'][2]['Cost_Center'], 'een project zonder verse regel houdt de oude code niet');
assert_same('50', $applied['project_details']['prj1']['LVS_Global_Dimension_1_Code'], 'de projectkaart krijgt dezelfde verse code');
assert_same('', $applied['project_details']['prj2']['LVS_Global_Dimension_1_Code'], 'de kaart wordt ook leeg als BC leeg is');

$wip = [
    'project_numbers' => ['PRJ1', 'PRJ2', 'PRJ3'],
    'open_project_rows' => [
        'prj1' => ['No' => 'PRJ1', 'LVS_Global_Dimension_1_Code' => '10', 'Description' => 'oud'],
    ],
    'project_details' => [
        'prj3' => ['No' => 'PRJ3', 'LVS_Global_Dimension_1_Code' => '10'],
    ],
    'column_data_target' => [
        'project_details' => [
            'by_project' => [
                'prj2' => ['row' => ['No' => 'PRJ2', 'LVS_Global_Dimension_1_Code' => '10']],
            ],
        ],
        'grootboekposten_ohw' => [
            'by_project' => [
                'prj2' => ['rows' => [['Job_No' => 'PRJ2', 'Global_Dimension_1_Code' => '10']]],
            ],
        ],
    ],
];
$updated = bc_fetch_apply_departments_to_wip('KvT', $wip, static function (string $company, string $filter): array {
    assert_same('KvT', $company, 'de WIP-lezing blijft bij hetzelfde bedrijf');

    return [
        ['No' => 'PRJ1', 'LVS_Global_Dimension_1_Code' => '50'],
        ['No' => 'PRJ2', 'LVS_Global_Dimension_1_Code' => '40'],
    ];
});
assert_same('50', $updated['open_project_rows']['prj1']['LVS_Global_Dimension_1_Code'], 'open kaart krijgt de verse afdeling');
assert_same('oud', $updated['open_project_rows']['prj1']['Description'], 'de rest van de kaart blijft staan');
assert_same('40', $updated['column_data_target']['project_details']['by_project']['prj2']['row']['LVS_Global_Dimension_1_Code'], 'detailkolom krijgt de verse afdeling');
assert_same('', $updated['project_details']['prj3']['LVS_Global_Dimension_1_Code'], 'een project dat BC niet teruggeeft verliest de oude afdeling');
assert_same('10', $updated['column_data_target']['grootboekposten_ohw']['by_project']['prj2']['rows'][0]['Global_Dimension_1_Code'], 'de OHW-post houdt zijn eigen dimensie');

assert_same(true, bc_fetch_column_save_should_refresh_departments('fetch_column_batch', 'grootboekposten_ohw', true), 'de OHW-ronde leest de afdeling opnieuw');
assert_same(false, bc_fetch_column_save_should_refresh_departments('fetch_column_batch', 'project_details', false), 'een tussenchunk van projectdetails wacht');
assert_same(true, bc_fetch_column_save_should_refresh_departments('fetch_column_batch', 'project_details', true), 'de laatste projectdetail-chunk leest de afdeling opnieuw');
assert_same(false, bc_fetch_column_save_should_refresh_departments('fetch_sub_planning', 'project_details', true), 'voorcalculatie leest de afdeling niet nog eens per chunk');
assert_same(true, bc_fetch_column_save_should_refresh_departments('fetch_sub_projects', '', true), 'de losse projectdetailstap leest de afdeling opnieuw');

$maanden = file_get_contents(__DIR__ . '/../maanden.php');
$detailJs = file_get_contents(__DIR__ . '/../maand-detail.js');
assert_true(is_string($maanden) && str_contains($maanden, 'bc_fetch_project_departments('), 'de maandopbouw leest de afdeling uit BC');
assert_true(is_string($maanden) && str_contains($maanden, 'maanden_refresh_departments_on_column_save'), 'elke kolomronde kan de afdeling verversen');
assert_true(is_string($maanden) && !str_contains($maanden, 'bc_fetch_primary_department_from_breakdown'), 'de snapshot kopieert de afdeling niet meer uit OHW-regels');
assert_true(is_string($detailJs) && !str_contains($detailJs, 'primaryDepartmentFromBreakdown'), 'het scherm neemt de afdeling niet uit de opgeslagen OHW-regels');
assert_true(is_string($detailJs) && str_contains($detailJs, 'LVS_Global_Dimension_1_Code'), 'het scherm valt terug op hetzelfde projectveld');

$threw = false;
try {
    bc_fetch_project_departments('KvT', ['PRJ1'], static function (): string {
        return 'geen-rijen';
    });
} catch (RuntimeException $error) {
    $threw = str_contains($error->getMessage(), 'LVS_Global_Dimension_1_Code');
}
assert_true($threw, 'een onbruikbaar antwoord wordt geen stille oude afdeling');

if ($failures > 0) {
    fwrite(STDERR, $failures . " failed\n");
    exit(1);
}

echo "OK project department\n";
