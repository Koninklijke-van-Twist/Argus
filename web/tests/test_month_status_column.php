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

$detailJs = file_get_contents(__DIR__ . '/../maand-detail.js');
assert_true(is_string($detailJs) && str_contains($detailJs, "project_status: 'Status (nu)'"), 'kolomkop is Status (nu)');
assert_true(is_string($detailJs) && str_contains($detailJs, 'projDetail.Status'), 'kolom leest Projecten.Status');
assert_true(is_string($detailJs) && !str_contains($detailJs, '399900'), 'maanddetail noemt 399900 niet');

$detailPhp = file_get_contents(__DIR__ . '/../maand-detail.php');
assert_true(is_string($detailPhp) && str_contains($detailPhp, "'project_status'"), 'maanddetail neemt de kolom op');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} assertion(s) failed\n");
    exit(1);
}

echo "OK month status column\n";
