<?php
/**
 * Eenmalige probe: welke G/L-velden en rekeningnummers komen terug uit Grootboekposten_OHW?
 * Usage: php web/tools/probe_ohw_gl_accounts.php [YYYY-MM] [company]
 */
declare(strict_types=1);

require __DIR__ . '/../auth.php';
require_once __DIR__ . '/../odata.php';
require_once __DIR__ . '/../auth_helper.php';
require_once __DIR__ . '/../bc_fetch/helpers.php';
require_once __DIR__ . '/../bc_fetch/column_grootboekposten_ohw.php';

function probe_company_entity_url_with_query(string $baseUrl, string $environment, string $company, string $entitySet, array $query): string
{
    $safeCompany = str_replace("'", "''", trim($company));
    $companySegment = "Company('" . rawurlencode($safeCompany) . "')";
    $url = rtrim($baseUrl, '/') . '/' . rawurlencode($environment) . '/ODataV4/' . $companySegment . '/' . rawurlencode($entitySet);

    if ($query !== []) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    return $url;
}

$targetYm = $argv[1] ?? (new DateTimeImmutable('first day of last month'))->format('Y-m');
$company = $argv[2] ?? 'Koninklijke van Twist';

$environment = auth_get_environment_for_company($company, 1);
$auth = auth_get_auth_for_environment($environment);

$filter = bc_fetch_snapshot_ohw_filter($targetYm);
$url = probe_company_entity_url_with_query(
    $GLOBALS['baseUrl'],
    $environment,
    $company,
    'Grootboekposten_OHW',
    [
        '$filter' => $filter,
        '$top' => '200',
        '$select' => 'Job_No,Posting_Date,WIP_Entry_Amount,G_L_Account_No,G_L_Bal_Account_No,Type',
    ]
);

echo "Company: {$company}\n";
echo "Environment: {$environment}\n";
echo "Month: {$targetYm}\n";
echo "Filter: {$filter}\n";
echo "URL: {$url}\n\n";

try {
    $resp = odata_get_json($url, $auth);
} catch (Throwable $e) {
    fwrite(STDERR, "OData error: " . $e->getMessage() . "\n");
    exit(1);
}

$rows = is_array($resp['value'] ?? null) ? $resp['value'] : [];
echo 'Rows returned: ' . count($rows) . "\n\n";

if ($rows === []) {
    echo "No rows.\n";
    exit(0);
}

echo "First row keys:\n";
echo implode(', ', array_keys($rows[0])) . "\n\n";

$glFieldStats = [];
$sampleByGl = [];

foreach ($rows as $row) {
    if (!is_array($row)) {
        continue;
    }

    $glAccount = trim((string) ($row['G_L_Account_No'] ?? ''));
    $glBalAccount = trim((string) ($row['G_L_Bal_Account_No'] ?? ''));
    $amount = (float) ($row['WIP_Entry_Amount'] ?? 0);

    $keys = [];
    if ($glAccount !== '') {
        $keys[] = 'G_L_Account_No=' . $glAccount;
    }
    if ($glBalAccount !== '') {
        $keys[] = 'G_L_Bal_Account_No=' . $glBalAccount;
    }
    if ($keys === []) {
        $keys[] = '(empty gl fields)';
    }

    foreach ($keys as $key) {
        if (!isset($glFieldStats[$key])) {
            $glFieldStats[$key] = ['count' => 0, 'amount_sum' => 0.0];
        }
        $glFieldStats[$key]['count']++;
        $glFieldStats[$key]['amount_sum'] += $amount;
    }

    $primaryKey = $keys[0];
    if (!isset($sampleByGl[$primaryKey]) && count($sampleByGl) < 15) {
        $sampleByGl[$primaryKey] = $row;
    }
}

uksort($glFieldStats, static function (string $a, string $b) use ($glFieldStats): int {
    return ($glFieldStats[$b]['count'] <=> $glFieldStats[$a]['count']);
});

echo "Distinct G/L values (top 200 rows):\n";
foreach ($glFieldStats as $label => $stats) {
    printf(
        "  %-45s count=%4d  sum(WIP_Entry_Amount)=%.2f\n",
        $label,
        $stats['count'],
        $stats['amount_sum']
    );
}

echo "\nSample rows per first-seen G/L key:\n";
foreach ($sampleByGl as $label => $row) {
    echo "\n--- {$label} ---\n";
    echo json_encode($row, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
}

$matchedCosts = 0;
$matchedRevenue = 0;
$unmatched = 0;
foreach ($rows as $row) {
    if (!is_array($row)) {
        continue;
    }
    $delta = bc_fetch_ohw_amount_delta($row);
    if ($delta === null) {
        $unmatched++;
    } elseif ($delta['kind'] === 'costs') {
        $matchedCosts++;
    } else {
        $matchedRevenue++;
    }
}

echo "\nCurrent classifier (G_L_Account_No 899900/899901):\n";
echo "  matched costs: {$matchedCosts}\n";
echo "  matched revenue: {$matchedRevenue}\n";
echo "  unmatched/skipped: {$unmatched}\n";
