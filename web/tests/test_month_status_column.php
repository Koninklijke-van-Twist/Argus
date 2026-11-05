<?php

/**
 * Status (nu) leest het bestaande projectveld Status.
 * Augustus 2026 is de peildatum waarvoor de cockpit is gedraaid.
 * Argus somt daarbij 899900/899901, niet 399900/399901.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../bc_fetch/column_project_details.php';
require_once __DIR__ . '/../bc_fetch/column_grootboekposten_ohw.php';

$failures = 0;

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    global $failures;
    if ($expected !== $actual) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n  expected " . var_export($expected, true) . "\n  actual   " . var_export($actual, true) . "\n");
    }
}

function assert_true(bool $actual, string $message): void
{
    global $failures;
    if ($actual !== true) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$select = bc_fetch_project_detail_odata_select();
assert_true(str_contains($select, ',Status,'), 'projectkaart vraagt het bestaande veld Status op');
assert_true(!str_contains($select, '399900') && !str_contains($select, '399901'), 'projectkaart voegt geen 399900/399901 toe');

assert_same('899900', OHW_GL_ACCOUNT_COSTS, 'kosten blijven grootboek 899900');
assert_same('899901', OHW_GL_ACCOUNT_REVENUE, 'OHW-opbrengstclassificatie blijft grootboek 899901');

$bounds = bc_fetch_snapshot_ohw_bounds('2026-08');
assert_same('2026-09-01', $bounds['posting_before'] ?? '', 'augustus peilt boekingen vóór 1 september');
assert_same('2026-08-31', $bounds['reverse_after'] ?? '', 'augustus peilt terugdraaien na 31 augustus');

$augustCost = bc_fetch_ohw_amount_delta([
    'Job_No' => '4084207',
    'Document_No' => 'OHW260629',
    'Posting_Date' => '2026-06-29',
    'G_L_Account_No' => '899900',
    'G_L_Bal_Account_No' => '17100',
    'WIP_Entry_Amount' => -10.0,
]);
assert_same('costs', $augustCost['kind'] ?? '', '899900 telt mee in augustus');

$otherAccount = bc_fetch_ohw_amount_delta([
    'Job_No' => '4084207',
    'Posting_Date' => '2026-08-15',
    'G_L_Account_No' => '399900',
    'G_L_Bal_Account_No' => '17100',
    'WIP_Entry_Amount' => -10.0,
]);
assert_same(null, $otherAccount, '399900 telt niet mee in de OHW-kosten');

$otherRevenue = bc_fetch_ohw_amount_delta([
    'Job_No' => '4084207',
    'Posting_Date' => '2026-08-15',
    'G_L_Account_No' => '399901',
    'G_L_Bal_Account_No' => '17100',
    'WIP_Entry_Amount' => 10.0,
]);
assert_same(null, $otherRevenue, '399901 telt niet mee in de OHW-classificatie');

$withoutStatus = bc_fetch_project_detail_odata_select_without_status();
assert_true(!str_contains(',' . $withoutStatus . ',', ',Status,'), 'fallback-selectie laat Status weg');
assert_true(str_contains($withoutStatus, 'LVS_Document_Status'), 'fallback houdt documentstatus');

$detailJs = file_get_contents(__DIR__ . '/../maand-detail.js');
assert_true(is_string($detailJs) && str_contains($detailJs, "project_status: 'Status (nu)'"), 'kolomkop is Status (nu)');
assert_true(is_string($detailJs) && str_contains($detailJs, 'projDetail.Status'), 'kolom leest Projecten.Status');
assert_true(is_string($detailJs) && str_contains($detailJs, "return '—';"), 'ontbrekend Status-veld toont —');
assert_true(is_string($detailJs) && !str_contains($detailJs, '399900'), 'maanddetail noemt 399900 niet');

$detailPhp = file_get_contents(__DIR__ . '/../maand-detail.php');
assert_true(is_string($detailPhp) && str_contains($detailPhp, "'project_status'"), 'maanddetail neemt de kolom op');

$GLOBALS['demeter_company_environment_map'] = ['Koninklijke van Twist' => 'Production'];
$auth_list = ['Production' => ['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's']];
$GLOBALS['baseUrl'] = 'https://bc.example/v2.0/tenant/';
$GLOBALS['detail_odata_calls'] = [];
$GLOBALS['detail_odata_mode'] = 'schema_status';

function company_entity_url_with_query(string $baseUrl, string $environment, string $company, string $entitySet, array $query): string
{
    return 'https://bc.example/' . rawurlencode($entitySet) . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}

function odata_get_all(string $url, array $auth, int $ttl): array
{
    $GLOBALS['detail_odata_calls'][] = $url;
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $select = (string) ($query['$select'] ?? '');
    if ($GLOBALS['detail_odata_mode'] === 'schema_status' && str_contains(',' . $select . ',', ',Status,')) {
        throw new RuntimeException("HTTP 400 from OData: Could not find a property named 'Status' on type 'NAV.Projecten'.");
    }
    if ($GLOBALS['detail_odata_mode'] === 'timeout') {
        throw new RuntimeException('cURL-fout: Operation timed out after 90001 milliseconds');
    }

    return [[
        'No' => '4084207',
        'Description' => 'Restsaldo',
        'LVS_Document_Status' => 'Open',
    ]];
}

function detail_select_from_call(string $url): string
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return (string) ($query['$select'] ?? '');
}

$omitStatus = false;
$rows = bc_fetch_projecten_rows('Koninklijke van Twist', "No eq '4084207'", [], 60, $omitStatus);
assert_same(2, count($GLOBALS['detail_odata_calls']), 'schemafout op Status probeert één keer opnieuw');
assert_true(str_contains(',' . detail_select_from_call($GLOBALS['detail_odata_calls'][0]) . ',', ',Status,'), 'eerste poging vraagt Status');
assert_true(!str_contains(',' . detail_select_from_call($GLOBALS['detail_odata_calls'][1]) . ',', ',Status,'), 'tweede poging laat Status weg');
assert_true($omitStatus === true, 'latere chunks slaan Status over');
assert_same('4084207', $rows[0]['No'] ?? '', 'kaart blijft beschikbaar zonder Status');
assert_true(!array_key_exists('Status', $rows[0]), 'antwoord bevat geen Status-sleutel');

$callsBeforeReuse = count($GLOBALS['detail_odata_calls']);
$reused = bc_fetch_projecten_rows('Koninklijke van Twist', "No eq '4084207'", [], 60, $omitStatus);
assert_same($callsBeforeReuse + 1, count($GLOBALS['detail_odata_calls']), 'volgende chunk doet één request');
assert_true(!str_contains(',' . detail_select_from_call($GLOBALS['detail_odata_calls'][$callsBeforeReuse]) . ',', ',Status,'), 'volgende chunk selecteert geen Status');
assert_same('4084207', $reused[0]['No'] ?? '', 'volgende chunk levert de kaart');

$GLOBALS['detail_odata_calls'] = [];
$GLOBALS['detail_odata_mode'] = 'timeout';
$omitOnTimeout = false;
$timedOut = false;
try {
    bc_fetch_projecten_rows('Koninklijke van Twist', "No eq '4084207'", [], 60, $omitOnTimeout);
} catch (Throwable $error) {
    $timedOut = str_contains($error->getMessage(), 'timed out');
}
assert_true($timedOut, 'timeout blijft een fout');
assert_same(1, count($GLOBALS['detail_odata_calls']), 'timeout probeert niet opnieuw zonder Status');
assert_true($omitOnTimeout === false, 'timeout zet de schema-fallback niet aan');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} assertion(s) failed\n");
    exit(1);
}

echo "OK month status column\n";
