<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/**
 * Includes/requires
 */
require __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/odata.php';
require_once __DIR__ . '/finance_calculations.php';

/**
 * Functies
 */
function maanden_cache_dir_d(): string
{
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'maanden';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir;
}

function maand_cache_path_d(string $company, string $yearMonth): string
{
    $safeCompany = preg_replace('/[^a-z0-9_-]/i', '_', strtolower(trim($company)));
    $safeYM = preg_replace('/[^0-9-]/', '', $yearMonth);
    return maanden_cache_dir_d() . DIRECTORY_SEPARATOR . $safeCompany . '_' . $safeYM . '.json';
}

function maand_load_d(string $company, string $yearMonth): ?array
{
    $path = maand_cache_path_d($company, $yearMonth);
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function list_saved_months_d(string $company): array
{
    $dir = maanden_cache_dir_d();
    if (!is_dir($dir)) {
        return [];
    }
    $safeCompany = preg_replace('/[^a-z0-9_-]/i', '_', strtolower(trim($company)));
    $prefix = $safeCompany . '_';
    $entries = @scandir($dir);
    if (!is_array($entries)) {
        return [];
    }
    $months = [];
    foreach ($entries as $entry) {
        if (!str_starts_with($entry, $prefix) || !str_ends_with($entry, '.json')) {
            continue;
        }
        $ym = substr($entry, strlen($prefix), -5);
        if (preg_match('/^\d{4}-\d{2}$/', $ym)) {
            $months[] = $ym;
        }
    }
    rsort($months);
    return $months;
}

function current_user_email_d(): string
{
    if (isset($_SESSION) && is_array($_SESSION)) {
        $u = $_SESSION['user'] ?? null;
        if (is_array($u)) {
            $email = trim((string) ($u['email'] ?? ''));
            if ($email !== '') {
                return $email;
            }
        }
    }
    return 'ict@kvt.nl';
}

function usersettings_path_d(string $email): string
{
    $safe = preg_replace('/[^a-z0-9@._-]/i', '_', strtolower(trim($email)));
    if (trim($safe) === '') {
        $safe = 'ict@kvt.nl';
    }
    return __DIR__ . '/cache/usersettings/' . $safe . '.txt';
}

function load_user_settings_d(string $email): array
{
    $path = usersettings_path_d($email);
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $parsed = json_decode($raw, true);
    return is_array($parsed) ? $parsed : [];
}

function save_user_settings_d(string $email, array $patch): bool
{
    $dir = __DIR__ . '/cache/usersettings';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }
    $existing = load_user_settings_d($email);
    foreach ($patch as $k => $v) {
        $existing[$k] = $v;
    }
    $existing['updated_at'] = gmdate('c');
    $path = usersettings_path_d($email);
    $json = json_encode($existing, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        return false;
    }
    return file_put_contents($path, $json, LOCK_EX) !== false;
}

function format_month_nl_d(string $yearMonth): string
{
    static $months = [
    '01' => 'Januari',
    '02' => 'Februari',
    '03' => 'Maart',
    '04' => 'April',
    '05' => 'Mei',
    '06' => 'Juni',
    '07' => 'Juli',
    '08' => 'Augustus',
    '09' => 'September',
    '10' => 'Oktober',
    '11' => 'November',
    '12' => 'December',
    ];
    [$year, $month] = explode('-', $yearMonth);
    return ($months[$month] ?? $month) . ' ' . $year;
}

function find_prev_month(string $yearMonth, array $savedMonths): ?string
{
    // savedMonths sorted newest first
    $idx = array_search($yearMonth, $savedMonths, true);
    if ($idx === false) {
        return null;
    }
    // Previous period = next index (older)
    $prevIdx = $idx + 1;
    return $savedMonths[$prevIdx] ?? null;
}

/**
 * Leest projecttotaal uit werkorderrijen als samenvatting ontbreekt.
 */
function project_total_from_workorders_d(array $workorders, string $field): float
{
    foreach ($workorders as $row) {
        if (!is_array($row)) {
            continue;
        }

        if (!array_key_exists($field, $row)) {
            continue;
        }

        return finance_to_float($row[$field] ?? 0.0);
    }

    return 0.0;
}

/**
 * Page load
 */
$currentUserEmail = current_user_email_d();

$companies = [
    'Koninklijke van Twist',
    'Hunter van Twist',
    'KVT Gas',
];

$selectedCompany = $_GET['company'] ?? $companies[0];
if (!in_array($selectedCompany, $companies, true)) {
    $selectedCompany = $companies[0];
}

$yearMonth = trim((string) ($_GET['year_month'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}$/', $yearMonth)) {
    $yearMonth = '';
}

// --- AJAX: save user settings (column order)
if (($_GET['action'] ?? '') === 'save_user_settings') {
    header('Content-Type: application/json; charset=utf-8');
    $raw = file_get_contents('php://input');
    $decoded = json_decode(is_string($raw) ? $raw : '', true);
    if (!is_array($decoded)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Ongeldig verzoek'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $patch = [];
    if (isset($decoded['detail_column_order']) && is_array($decoded['detail_column_order'])) {
        $patch['detail_column_order'] = array_values(array_filter($decoded['detail_column_order'], 'is_string'));
    }
    if (isset($decoded['detail_hidden_columns']) && is_array($decoded['detail_hidden_columns'])) {
        $patch['detail_hidden_columns'] = array_values(array_filter($decoded['detail_hidden_columns'], 'is_string'));
    }
    $ok = save_user_settings_d($currentUserEmail, $patch);
    echo json_encode(['ok' => $ok], JSON_UNESCAPED_UNICODE);
    exit;
}

// Load month data
$monthData = null;
$errorMessage = null;

if ($yearMonth !== '') {
    $monthData = maand_load_d($selectedCompany, $yearMonth);
    if ($monthData === null) {
        $errorMessage = 'Geen data gevonden voor ' . htmlspecialchars(format_month_nl_d($yearMonth)) . '. Laad de maand eerst via het overzicht.';
    }
}

// Find previous month
$savedMonths = list_saved_months_d($selectedCompany);
$prevYearMonth = $yearMonth !== '' ? find_prev_month($yearMonth, $savedMonths) : null;
$prevMonthData = $prevYearMonth !== null ? maand_load_d($selectedCompany, $prevYearMonth) : null;

// Build prev month profit per project: job_no -> profit (invoiced revenue - invoiced costs from workorders)
$prevProfitByProject = [];
if (is_array($prevMonthData)) {
    $prevRows = $prevMonthData['workorder_rows'] ?? [];
    foreach ($prevRows as $row) {
        $jNo = strtolower(trim((string) ($row['Job_No'] ?? '')));
        if ($jNo === '') {
            continue;
        }
        $costs = finance_to_float($row['Actual_Costs'] ?? 0);
        $revenue = finance_to_float($row['Total_Revenue'] ?? 0);
        if (!isset($prevProfitByProject[$jNo])) {
            $prevProfitByProject[$jNo] = ['revenue' => 0.0, 'costs' => 0.0];
        }
        $prevProfitByProject[$jNo]['revenue'] = finance_add_amount(
            (float) ($prevProfitByProject[$jNo]['revenue'] ?? 0.0),
            $revenue
        );
        $prevProfitByProject[$jNo]['costs'] = finance_add_amount(
            (float) ($prevProfitByProject[$jNo]['costs'] ?? 0.0),
            $costs
        );
    }
}

$projectColumnValuesByJob = [];
if (is_array($monthData)) {
    $projectSummaries = is_array($monthData['project_summaries'] ?? null)
        ? $monthData['project_summaries']
        : [];
    $projectDetails = is_array($monthData['project_details'] ?? null)
        ? $monthData['project_details']
        : [];
    $workorderRows = is_array($monthData['workorder_rows'] ?? null)
        ? $monthData['workorder_rows']
        : [];

    $projectSummaryByJob = [];
    foreach ($projectSummaries as $summaryRow) {
        if (!is_array($summaryRow)) {
            continue;
        }

        $normJobNo = strtolower(trim((string) ($summaryRow['Job_No'] ?? '')));
        if ($normJobNo === '') {
            continue;
        }

        $projectSummaryByJob[$normJobNo] = $summaryRow;
    }

    $workordersByJob = [];
    foreach ($workorderRows as $workorderRow) {
        if (!is_array($workorderRow)) {
            continue;
        }

        $normJobNo = strtolower(trim((string) ($workorderRow['Job_No'] ?? '')));
        if ($normJobNo === '') {
            continue;
        }

        if (!isset($workordersByJob[$normJobNo])) {
            $workordersByJob[$normJobNo] = [];
        }

        $workordersByJob[$normJobNo][] = $workorderRow;
    }

    $allJobNos = array_values(array_unique(array_merge(array_keys($projectSummaryByJob), array_keys($workordersByJob))));

    foreach ($allJobNos as $normJobNo) {
        $jobWorkorders = is_array($workordersByJob[$normJobNo] ?? null) ? $workordersByJob[$normJobNo] : [];
        $summaryRow = $projectSummaryByJob[$normJobNo] ?? [];
        $detailRow = is_array($projectDetails[$normJobNo] ?? null) ? $projectDetails[$normJobNo] : [];

        $totalCosts = array_key_exists('Project_Actual_Costs', $summaryRow)
            ? finance_to_float($summaryRow['Project_Actual_Costs'])
            : project_total_from_workorders_d($jobWorkorders, 'Project_Actual_Costs');
        $totalRevenue = array_key_exists('Project_Total_Revenue', $summaryRow)
            ? finance_to_float($summaryRow['Project_Total_Revenue'])
            : project_total_from_workorders_d($jobWorkorders, 'Project_Total_Revenue');

        $expectedRevenue = finance_to_float($summaryRow['Expected_Revenue'] ?? 0.0);
        $expectedCostsVc = finance_to_float($summaryRow['Expected_Costs_VC'] ?? 0.0);
        $pctCompleted = finance_to_float($detailRow['Percent_Completed'] ?? 0.0);
        $marginTotal = finance_column_margin_total($expectedRevenue, $expectedCostsVc);
        $winstOhw = finance_column_winst_ohw($marginTotal, $pctCompleted);
        $prevProfit = finance_column_prev_profit($prevProfitByProject[$normJobNo] ?? null);
        $difference = finance_column_difference($totalRevenue, $totalCosts, $prevProfit);

        $projectColumnValuesByJob[$normJobNo] = [
            'total_costs' => $totalCosts,
            'total_revenue' => $totalRevenue,
            'costs_vc' => $expectedCostsVc,
            'margin_total' => $marginTotal,
            'winst_ohw' => $winstOhw,
            'prev_profit' => $prevProfit,
            'difference' => $difference,
        ];
    }
}

// Load user settings
$userSettings = load_user_settings_d($currentUserEmail);
$savedColumnOrder = is_array($userSettings['detail_column_order'] ?? null) ? $userSettings['detail_column_order'] : [];
$savedHiddenColumns = is_array($userSettings['detail_hidden_columns'] ?? null) ? $userSettings['detail_hidden_columns'] : [];

// Default columns definition (keys)
$defaultColumns = [
    'workorders',
    'total_costs',
    'total_revenue',
    'customer',
    'description',
    'cost_center',
    'expected_revenue',
    'costs_vc',
    'extra_work',
    'margin_total',
    'pct_ready',
    'winst_ohw',
    'notes',
    'project_manager',
    'invoices',
];

// Apply saved order (only include known columns)
$orderedColumns = $savedColumnOrder !== []
    ? array_values(array_filter($savedColumnOrder, fn($k) => in_array($k, $defaultColumns, true)))
    : [];
// Add any missing columns at the end
foreach ($defaultColumns as $col) {
    if (!in_array($col, $orderedColumns, true)) {
        $orderedColumns[] = $col;
    }
}

$initialData = [
    'companies' => $companies,
    'selected_company' => $selectedCompany,
    'year_month' => $yearMonth,
    'prev_year_month' => $prevYearMonth,
    'prev_profit_by_project' => $prevProfitByProject,
    'month_data' => $monthData,
    'project_column_values_by_job' => $projectColumnValuesByJob,
    'error' => $errorMessage,
    'default_columns' => $defaultColumns,
    'column_order' => $orderedColumns,
    'hidden_columns' => $savedHiddenColumns,
    'save_settings_url' => 'maand-detail.php?action=save_user_settings',
];
?>
<!doctype html>
<html lang="nl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="/favicon.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="manifest" href="site.webmanifest">
    <title><?= $yearMonth !== '' ? htmlspecialchars(format_month_nl_d($yearMonth)) . ' – ' : '' ?>Maanddetail</title>
    <style>
        *,
        *::before,
        *::after {
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background: #f4f7fb;
            color: #1f2937;
        }

        .page-loader {
            position: fixed;
            inset: 0;
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
            background: rgba(244, 247, 251, .92);
        }

        .page-loader.is-visible {
            display: flex;
        }

        .page-loader.is-error {
            background: rgba(127, 29, 29, .94);
        }

        .page-loader-content {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            color: #203a63;
            font-weight: 600;
        }

        .page-loader-spinner {
            width: 34px;
            height: 34px;
            border: 3px solid #c8d3e1;
            border-top-color: #1f4ea6;
            border-radius: 50%;
            animation: spin .8s linear infinite;
        }

        .page-loader.is-error .page-loader-spinner {
            display: none;
        }

        .page-loader-error {
            margin: 10px 0 0;
            max-width: min(92vw, 980px);
            max-height: 56vh;
            overflow: auto;
            white-space: pre-wrap;
            background: rgba(17, 24, 39, .35);
            border: 1px solid rgba(254, 202, 202, .55);
            color: #fee2e2;
            border-radius: 10px;
            padding: 12px;
            font-size: 12px;
            line-height: 1.45;
            font-family: Consolas, 'Courier New', monospace;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .page-header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }

        .page-header img {
            height: 40px;
            width: auto;
        }

        h1 {
            margin: 0;
            font-size: 22px;
            color: #1f2937;
        }

        .controls {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
            margin-bottom: 18px;
            padding: 14px;
            background: #fff;
            border: 1px solid #dbe3ee;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(15, 23, 42, .06);
        }

        .controls label {
            font-weight: 600;
            color: #334155;
        }

        .controls select,
        .controls input {
            font: inherit;
            border: 1px solid #c8d3e1;
            border-radius: 8px;
            padding: 7px 10px;
            background: #fff;
        }

        .btn {
            font: inherit;
            border-radius: 8px;
            padding: 7px 14px;
            font-weight: 700;
            cursor: pointer;
            border: 1px solid transparent;
            font-size: 13px;
        }

        .btn-primary {
            background: #1f4ea6;
            border-color: #1f4ea6;
            color: #fff;
        }

        .btn-primary:hover {
            background: #1a438e;
        }

        .btn-secondary {
            background: #f1f5fb;
            border-color: #c8d3e1;
            color: #334155;
        }

        .btn-secondary:hover {
            background: #e4edf9;
        }

        .btn-dark {
            background: #334155;
            border-color: #334155;
            color: #fff;
        }

        .btn-dark:hover {
            background: #1f2937;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #1f4ea6;
            font-weight: 700;
            text-decoration: none;
            font-size: 13px;
            margin-bottom: 12px;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        .summary-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            align-items: center;
            margin-bottom: 14px;
            padding: 12px 14px;
            background: #fff;
            border: 1px solid #dbe3ee;
            border-radius: 10px;
        }

        .summary-stat {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .summary-stat-label {
            font-size: 11px;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .summary-stat-value {
            font-size: 15px;
            font-weight: 700;
            font-variant-numeric: tabular-nums;
        }

        .stat-positive {
            color: #0b6b2f;
        }

        .stat-negative {
            color: #b42318;
        }

        .status-filter-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 12px;
            align-items: center;
        }

        .status-filter-title {
            font-weight: 700;
            color: #334155;
            margin-right: 4px;
        }

        .status-filter-btn {
            border: 1px solid #c8d3e1;
            border-radius: 999px;
            padding: 5px 10px;
            font-size: 12px;
            font-weight: 600;
            color: #1f2937;
            cursor: pointer;
            background: #fff;
        }

        .status-filter-btn.is-off {
            opacity: .4;
            text-decoration: line-through;
        }

        .status-toggle-all-btn {
            border: 1px solid #334155;
            background: #334155;
            color: #fff;
            border-radius: 999px;
            padding: 5px 10px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
        }

        .status-toggle-all-btn:hover {
            background: #1f2937;
        }

        .search-bar {
            display: flex;
            gap: 8px;
            align-items: center;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }

        .search-bar input {
            font: inherit;
            border: 1px solid #c8d3e1;
            border-radius: 8px;
            padding: 6px 10px;
            min-width: 220px;
            background: #fff;
        }

        #app {
            background: #fff;
            border: 1px solid #dbe3ee;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(15, 23, 42, .06);
            padding: 14px;
            overflow: hidden;
        }

        .table-scroll-wrap {
            width: 100%;
            overflow-x: auto;
            overflow-y: auto;
            max-height: 65vh;
            -webkit-overflow-scrolling: touch;
            cursor: grab;
        }

        .table-scroll-wrap.is-dragging-scroll {
            cursor: grabbing;
        }

        body.dragging-table-scroll,
        body.dragging-table-scroll * {
            user-select: none !important;
        }

        table {
            width: max-content;
            min-width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        th,
        td {
            border-bottom: 1px solid #e7edf5;
            padding: 8px 10px;
            text-align: left;
            vertical-align: top;
            white-space: normal;
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        th {
            background: #f1f5fb;
            color: #203a63;
            font-weight: 700;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 12;
            font-size: 12px;
        }

        th.sortable {
            cursor: pointer;
            user-select: none;
        }

        th.sortable:hover {
            background: #e4edf9;
        }

        td {
            font-size: 12px;
        }

        tbody tr:hover {
            filter: brightness(.98);
        }

        /* Subtable */
        .subtable-row td {
            background: #f8fafc;
        }

        .subtable-wrap {
            width: 100%;
            border: 1px solid #e7edf5;
            border-radius: 6px;
            overflow: hidden;
        }

        .subtable-wrap table {
            width: 100%;
            font-size: 11px;
        }

        .subtable-wrap th {
            background: #eef4ff;
            font-size: 11px;
        }

        .subtable-toggle-btn {
            border: 1px solid #1f4ea6;
            background: #1f4ea6;
            color: #fff;
            border-radius: 6px;
            padding: 4px 8px;
            font: inherit;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
        }

        .subtable-toggle-btn:hover {
            background: #1a438e;
        }

        .project-row td {
            background: #f9fafb;
        }

        .project-row-expanded td {
            border-bottom: none;
        }

        /* Amount colours */
        .amount-pos {
            color: #0b6b2f;
            font-weight: 700;
        }

        .amount-neg {
            color: #b42318;
            font-weight: 700;
        }

        .amount-neutral {
            font-variant-numeric: tabular-nums;
        }

        /* Notes */
        .notes-btn {
            border: 1px solid #1f4ea6;
            background: #1f4ea6;
            color: #fff;
            border-radius: 6px;
            padding: 4px 8px;
            font: inherit;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
        }

        .notes-btn:hover {
            background: #1a438e;
        }

        .notes-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, .45);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            padding: 20px;
        }

        .notes-modal {
            width: min(820px, 95vw);
            max-height: 85vh;
            overflow: auto;
            background: #fff;
            border-radius: 12px;
            border: 1px solid #dbe3ee;
            box-shadow: 0 20px 30px rgba(15, 23, 42, .25);
            padding: 14px;
        }

        .notes-modal-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }

        .notes-close {
            border: 1px solid #c8d3e1;
            background: #fff;
            border-radius: 8px;
            padding: 5px 10px;
            font: inherit;
            cursor: pointer;
        }

        .notes-section {
            border: 1px solid #e7edf5;
            border-radius: 8px;
            margin-bottom: 8px;
            overflow: hidden;
        }

        .notes-section-title {
            background: #f1f5fb;
            color: #203a63;
            font-weight: 700;
            padding: 6px 10px;
            font-size: 12px;
        }

        .notes-section-text {
            margin: 0;
            padding: 8px 10px;
            white-space: pre-wrap;
            font-family: inherit;
            font-size: 12px;
            line-height: 1.4;
        }

        .notes-workorder-header {
            font-weight: 700;
            color: #334155;
            margin: 10px 0 4px;
            font-size: 13px;
        }

        /* Invoice modal */
        .invoice-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, .45);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            padding: 20px;
        }

        .invoice-modal {
            width: min(700px, 95vw);
            max-height: 85vh;
            overflow: auto;
            background: #fff;
            border-radius: 12px;
            border: 1px solid #dbe3ee;
            box-shadow: 0 20px 30px rgba(15, 23, 42, .25);
            padding: 14px;
        }

        .invoice-modal-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .invoice-close {
            border: 1px solid #c8d3e1;
            background: #fff;
            border-radius: 8px;
            padding: 4px 10px;
            font: inherit;
            cursor: pointer;
        }

        .invoice-id-link {
            color: #1f4ea6;
            cursor: pointer;
            text-decoration: underline dotted;
            font-size: 12px;
        }

        .invoice-id-link:hover {
            text-decoration: underline;
        }

        .aggregate-source-link {
            color: #1f4ea6;
            cursor: pointer;
            text-decoration: underline dotted;
        }

        .aggregate-source-link:hover {
            text-decoration: underline;
        }

        /* Source modal */
        .source-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, .45);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            padding: 20px;
        }

        .source-modal {
            width: min(980px, 96vw);
            max-height: 86vh;
            overflow: auto;
            background: #fff;
            border-radius: 12px;
            border: 1px solid #dbe3ee;
            box-shadow: 0 20px 30px rgba(15, 23, 42, .25);
            padding: 14px;
        }

        .source-modal-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .source-close {
            border: 1px solid #c8d3e1;
            background: #fff;
            border-radius: 8px;
            padding: 4px 10px;
            font: inherit;
            cursor: pointer;
        }

        /* Column reorder modal */
        .col-reorder-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, .45);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            padding: 20px;
        }

        .col-reorder-modal {
            width: min(480px, 95vw);
            max-height: 85vh;
            overflow: auto;
            background: #fff;
            border-radius: 12px;
            border: 1px solid #dbe3ee;
            box-shadow: 0 20px 30px rgba(15, 23, 42, .25);
            padding: 18px;
        }

        .col-reorder-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 14px;
        }

        .col-reorder-title {
            font-size: 16px;
            font-weight: 700;
            color: #1f2937;
        }

        .col-reorder-close {
            border: 1px solid #c8d3e1;
            background: #fff;
            border-radius: 8px;
            padding: 4px 10px;
            font: inherit;
            cursor: pointer;
        }

        .col-reorder-list {
            list-style: none;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .col-reorder-item {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #f8fafc;
            border: 1px solid #dbe3ee;
            border-radius: 8px;
            padding: 8px 10px;
            cursor: grab;
            font-size: 13px;
        }

        .col-reorder-item:active {
            cursor: grabbing;
        }

        .col-reorder-item.dragging {
            opacity: .45;
            border-style: dashed;
        }

        .col-reorder-item.col-hidden {
            background: #6b7280;
            border-color: #4b5563;
            color: #f3f4f6;
        }

        .col-reorder-item.col-hidden .col-reorder-handle {
            color: #d1d5db;
        }

        .col-reorder-checkbox {
            flex-shrink: 0;
            width: 16px;
            height: 16px;
            cursor: pointer;
        }

        .col-reorder-handle {
            color: #94a3b8;
            font-size: 16px;
            flex-shrink: 0;
            user-select: none;
        }

        .col-reorder-actions {
            display: flex;
            gap: 8px;
            justify-content: flex-end;
            margin-top: 14px;
        }

        /* Preferences panel */
        .memo-menu-wrap {
            position: relative;
            margin-left: auto;
        }

        .memo-menu-trigger {
            display: inline-flex;
            align-items: center;
            border: 1px solid #334155;
            background: #334155;
            color: #fff;
            border-radius: 8px;
            padding: 7px 10px;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
        }

        .memo-menu-trigger:hover {
            background: #1f2937;
        }

        .memo-menu-panel {
            position: absolute;
            top: calc(100% + 6px);
            right: 0;
            min-width: 220px;
            background: #fff;
            border: 1px solid #dbe3ee;
            border-radius: 10px;
            box-shadow: 0 10px 20px rgba(15, 23, 42, .16);
            padding: 10px;
            z-index: 30;
            display: none;
        }

        .memo-menu-panel.is-open {
            display: block;
        }

        .memo-menu-title {
            font-weight: 700;
            color: #334155;
            margin-bottom: 8px;
        }

        .memo-menu-action-btn {
            width: 100%;
            text-align: left;
            border: 1px solid #e7edf5;
            background: #f8fafc;
            color: #1f2937;
            border-radius: 6px;
            padding: 6px 10px;
            font: inherit;
            font-size: 13px;
            cursor: pointer;
            margin-bottom: 4px;
        }

        .memo-menu-action-btn:hover {
            background: #eef2f7;
        }

        .error-box {
            background: #ffe9ed;
            border: 1px solid #f4b5c0;
            border-radius: 8px;
            padding: 10px 14px;
            color: #b00020;
            margin-bottom: 14px;
        }

        .empty {
            color: #475569;
            padding: 12px 0;
        }

        .status-open {
            background: #ffffff;
        }

        .status-signed {
            background: #f6f9e9;
        }

        .status-completed {
            background: #e9f9ee;
        }

        .status-checked {
            background: #fff1dd;
        }

        .status-cancelled {
            background: #ffa7a7;
        }

        .status-closed {
            background: #c5c5c5;
        }

        .status-planned {
            background: #ddefff;
        }

        .status-in-progress {
            background: #ffe9e9;
        }

        tr[data-wo-hidden="1"] {
            display: none;
        }

        @media (max-width: 600px) {
            .summary-bar {
                gap: 10px;
            }

            .summary-stat-value {
                font-size: 13px;
            }
        }
    </style>
</head>

<body>
    <div id="pageLoader" class="page-loader is-visible" aria-live="polite">
        <div class="page-loader-content">
            <div class="page-loader-spinner" aria-hidden="true"></div>
            <div id="pageLoaderText">Gegevens laden...</div>
        </div>
    </div>

    <?= injectTimerHtml([
        'statusUrl' => 'odata.php?action=cache_status',
        'title' => 'Cachebestanden',
        'label' => 'Cache',
    ]) ?>

    <div class="page-header">
        <img src="logo-website.png" alt="Logo">
        <h1><?= $yearMonth !== '' ? htmlspecialchars(format_month_nl_d($yearMonth)) : 'Maanddetail' ?></h1>
    </div>

    <a class="back-link" href="maanden.php?company=<?= urlencode($selectedCompany) ?>">← Terug naar overzicht</a>

    <div class="controls">
        <label for="companySelect">Bedrijf</label>
        <select id="companySelect">
            <?php foreach ($companies as $c): ?>
                <option value="<?= htmlspecialchars($c) ?>" <?= $c === $selectedCompany ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <div class="memo-menu-wrap" id="memoMenuWrap">
            <button type="button" class="memo-menu-trigger" id="memoMenuTrigger">Voorkeuren</button>
            <div class="memo-menu-panel" id="memoMenuPanel">
                <div class="memo-menu-title">Kolommen</div>
                <button type="button" class="memo-menu-action-btn" id="openColReorderBtn">Kolommen
                    herordenen...</button>
            </div>
        </div>
    </div>

    <div id="statusFilterBar" class="status-filter-bar"></div>
    <div id="departmentFilterBar" class="status-filter-bar"></div>
    <div class="search-bar">
        <input type="search" id="searchInput" placeholder="Zoeken in projecten / werkorders...">
        <button type="button" id="exportCsvBtn" class="status-toggle-all-btn">CSV export</button>
    </div>

    <div id="summaryBar" class="summary-bar"></div>

    <div id="app">
        <?php if ($errorMessage): ?>
            <div class="error-box"><?= htmlspecialchars($errorMessage) ?></div>
        <?php endif; ?>
    </div>

    <!-- Notes overlay -->
    <div class="notes-overlay" id="notesOverlay" style="display:none">
        <div class="notes-modal" role="dialog" aria-modal="true">
            <div class="notes-modal-head">
                <strong id="notesModalTitle">Notities</strong>
                <button type="button" class="notes-close" id="notesClose">Sluiten</button>
            </div>
            <div id="notesModalBody"></div>
        </div>
    </div>

    <!-- Invoice overlay -->
    <div class="invoice-overlay" id="invoiceOverlay" style="display:none">
        <div class="invoice-modal" role="dialog" aria-modal="true">
            <div class="invoice-modal-head">
                <strong id="invoiceModalTitle">Factuur</strong>
                <button type="button" class="invoice-close" id="invoiceClose">Sluiten</button>
            </div>
            <div id="invoiceModalBody"></div>
        </div>
    </div>

    <!-- Source overlay -->
    <div class="source-overlay" id="sourceOverlay" style="display:none">
        <div class="source-modal" role="dialog" aria-modal="true">
            <div class="source-modal-head">
                <strong id="sourceModalTitle">Herkomst</strong>
                <button type="button" class="source-close" id="sourceClose">Sluiten</button>
            </div>
            <div id="sourceModalBody"></div>
        </div>
    </div>

    <!-- Column reorder overlay -->
    <div class="col-reorder-overlay" id="colReorderOverlay" style="display:none">
        <div class="col-reorder-modal" role="dialog" aria-modal="true">
            <div class="col-reorder-head">
                <span class="col-reorder-title">Kolommen herordenen</span>
                <button type="button" class="col-reorder-close" id="colReorderClose">Sluiten</button>
            </div>
            <ul class="col-reorder-list" id="colReorderList"></ul>
            <div class="col-reorder-actions">
                <button type="button" class="btn btn-secondary" id="colReorderCancel">Annuleren</button>
                <button type="button" class="btn btn-primary" id="colReorderSave">Opslaan</button>
            </div>
        </div>
    </div>

    <script>
        window.maandDetailData = <?= json_encode($initialData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <script src="maand-detail.js"></script>
</body>

</html>