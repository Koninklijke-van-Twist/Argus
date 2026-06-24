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
require_once __DIR__ . '/project_finance.php';
require_once __DIR__ . '/bc_fetch/registry.php';

/**
 * Constants
 */
$second = 1;
$minute = $second * 60;
$hour = $minute * 60;
$day = $hour * 24;
$week = $day * 7;
$year = $day * 365;
const REVENUE_DETAIL_TASK_FILTER_ENABLED = false;
const REVENUE_DETAIL_TASK_CODE = '000-000-010';

/**
 * Functies
 */
function maanden_cache_dir(): string
{
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'maanden';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir;
}

function maand_cache_path(string $company, string $yearMonth): string
{
    $safeCompany = preg_replace('/[^a-z0-9_-]/i', '_', strtolower(trim($company)));
    $safeYM = preg_replace('/[^0-9-]/', '', $yearMonth);
    return maanden_cache_dir() . DIRECTORY_SEPARATOR . $safeCompany . '_' . $safeYM . '.json';
}

function maand_load(string $company, string $yearMonth): ?array
{
    $path = maand_cache_path($company, $yearMonth);
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

function maand_save(string $company, string $yearMonth, array $data): bool
{
    maanden_cache_dir();
    $path = maand_cache_path($company, $yearMonth);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        return false;
    }
    return file_put_contents($path, $json, LOCK_EX) !== false;
}

function maand_delete(string $company, string $yearMonth): bool
{
    $path = maand_cache_path($company, $yearMonth);
    batch_wip_delete($company, $yearMonth);
    if (is_file($path)) {
        return @unlink($path);
    }
    return true;
}

function list_saved_months(string $company): array
{
    $dir = maanden_cache_dir();
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
        if (!str_starts_with($entry, $prefix)) {
            continue;
        }
        if (!str_ends_with($entry, '.json')) {
            continue;
        }
        $ym = substr($entry, strlen($prefix), -5); // remove prefix and .json
        if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
            continue;
        }
        $months[] = $ym;
    }
    rsort($months); // newest first
    return $months;
}

function company_entity_url_with_query(string $baseUrl, string $environment, string $company, string $entitySet, array $query): string
{
    $safeCompany = str_replace("'", "''", trim($company));
    $companySegment = "Company('" . rawurlencode($safeCompany) . "')";
    $url = rtrim($baseUrl, '/') . '/' . rawurlencode($environment) . '/ODataV4/' . $companySegment . '/' . rawurlencode($entitySet);

    if ($query !== []) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    return $url;
}

function project_modal_normalize_project_no(string $projectNo): string
{
    return strtolower(trim($projectNo));
}

function project_modal_is_formatted_task_no(string $taskNo): bool
{
    return preg_match('/^\d{3}-\d{3}-\d{3}$/', trim($taskNo)) === 1;
}

function project_modal_task_no_to_numeric(string $taskNo): ?int
{
    $value = trim($taskNo);
    if (!project_modal_is_formatted_task_no($value)) {
        return null;
    }

    $parts = explode('-', $value);
    return ((int) $parts[0] * 1000000) + ((int) $parts[1] * 1000) + (int) $parts[2];
}

function project_modal_parse_totaling_range(string $totaling): ?array
{
    $text = trim($totaling);
    if ($text === '') {
        return null;
    }

    if (!preg_match('/^(\d{3}-\d{3}-\d{3})\.\.(\d{3}-\d{3}-\d{3})$/', $text, $matches)) {
        return null;
    }

    $fromNumeric = project_modal_task_no_to_numeric($matches[1]);
    $toNumeric = project_modal_task_no_to_numeric($matches[2]);
    if ($fromNumeric === null || $toNumeric === null || $fromNumeric > $toNumeric) {
        return null;
    }

    return [
        'from' => $matches[1],
        'to' => $matches[2],
        'from_numeric' => $fromNumeric,
        'to_numeric' => $toNumeric,
    ];
}

function project_modal_task_no_in_range(string $taskNo, array $range): bool
{
    $taskNumeric = project_modal_task_no_to_numeric($taskNo);
    if ($taskNumeric === null) {
        return false;
    }

    $fromNumeric = (int) ($range['from_numeric'] ?? 0);
    $toNumeric = (int) ($range['to_numeric'] ?? -1);
    return $taskNumeric >= $fromNumeric && $taskNumeric <= $toNumeric;
}

function project_modal_is_total_task_type(string $taskType): bool
{
    return str_contains(strtolower(trim($taskType)), 'totaal');
}

function project_modal_collect_for_projects(string $company, string $yearMonth, array $projectNumbers, array $auth, int $ttl): array
{
    global $baseUrl;
    
    $environmentForCompany = auth_get_environment_for_company($company, 300);
    $auth = auth_get_auth_for_environment($environmentForCompany);

    $normalizedProjects = [];
    $seenProjects = [];
    foreach ($projectNumbers as $projectNo) {
        $projectNoText = trim((string) $projectNo);
        if ($projectNoText === '') {
            continue;
        }

        $normalized = project_modal_normalize_project_no($projectNoText);
        if ($normalized === '' || isset($seenProjects[$normalized])) {
            continue;
        }

        $seenProjects[$normalized] = true;
        $normalizedProjects[] = $projectNoText;
    }

    if ($normalizedProjects === []) {
        return [];
    }

    $byProject = [];
    foreach ($normalizedProjects as $projectNo) {
        $normProject = project_modal_normalize_project_no($projectNo);
        $byProject[$normProject] = [
            'project_no' => $projectNo,
            'contract_value' => 0.0,
            'installments_received' => 0.0,
            'budget_cost_total' => 0.0,
            'task_rows' => [],
            'task_rows_total' => [],
        ];
    }

    $chunks = array_chunk($normalizedProjects, 20);

    foreach ($chunks as $chunk) {
        $projectFilters = array_map(static function ($projectNo): string {
            return "Job_No eq '" . str_replace("'", "''", trim((string) $projectNo)) . "'";
        }, $chunk);
        $projectFilter = implode(' or ', $projectFilters);

        if ($projectFilter === '') {
            continue;
        }

        try {
            $contractUrl = company_entity_url_with_query($baseUrl, $environmentForCompany, $company, 'FactureerbareProjectPlanningsRegels', [
                '$select' => 'Job_No,Line_Type,Line_Amount_LCY',
                '$filter' => $projectFilter,
            ]);
            $contractRows = odata_get_all($contractUrl, $auth, $ttl);
        } catch (Throwable $ignoredContractLoadError) {
            $contractRows = [];
        }

        foreach ($contractRows as $contractRow) {
            if (!is_array($contractRow)) {
                continue;
            }

            $normProject = project_modal_normalize_project_no((string) ($contractRow['Job_No'] ?? ''));
            if ($normProject === '' || !isset($byProject[$normProject])) {
                continue;
            }

            $lineType = strtolower(trim((string) ($contractRow['Line_Type'] ?? '')));
            $isFactureerbaar = str_contains($lineType, 'factureer');
            $isForecast = str_contains($lineType, 'prognose') || str_contains($lineType, 'forecast');
            if (!$isFactureerbaar || $isForecast) {
                continue;
            }

            $lineAmount = finance_to_float($contractRow['Line_Amount_LCY'] ?? 0.0);
            $byProject[$normProject]['contract_value'] = finance_add_amount(
                (float) ($byProject[$normProject]['contract_value'] ?? 0.0),
                $lineAmount
            );
        }

        $customerFilters = [];
        foreach ($chunk as $projectNo) {
            $escapedProject = str_replace("'", "''", trim((string) $projectNo));
            $customerFilters[] = "(Your_Reference eq '" . $escapedProject . "' or External_Document_No eq '" . $escapedProject . "')";
        }

        if ($customerFilters !== []) {
            try {
                $customerUrl = company_entity_url_with_query($baseUrl, $environmentForCompany, $company, 'Customer_Ledger_Entries', [
                    '$select' => 'Amount_LCY,Open,Your_Reference,External_Document_No',
                    '$filter' => '(Open eq true) and (' . implode(' or ', $customerFilters) . ')',
                ]);
                $customerRows = odata_get_all($customerUrl, $auth, $ttl);
            } catch (Throwable $ignoredCustomerLoadError) {
                $customerRows = [];
            }

            foreach ($customerRows as $customerRow) {
                if (!is_array($customerRow)) {
                    continue;
                }

                $reference = trim((string) ($customerRow['Your_Reference'] ?? ''));
                if ($reference === '') {
                    $reference = trim((string) ($customerRow['External_Document_No'] ?? ''));
                }

                $normProject = project_modal_normalize_project_no($reference);
                if ($normProject === '' || !isset($byProject[$normProject])) {
                    continue;
                }

                $amountLcy = finance_to_float($customerRow['Amount_LCY'] ?? 0.0);
                $byProject[$normProject]['installments_received'] = finance_add_amount(
                    (float) ($byProject[$normProject]['installments_received'] ?? 0.0),
                    $amountLcy
                );
            }
        }

        try {
            $taskUrl = company_entity_url_with_query($baseUrl, $environmentForCompany, $company, 'ProjectenJobTaskLines', [
                '$select' => 'Job_No,Job_Task_No,Description,Job_Task_Type,Totaling,Schedule_Total_Cost',
                '$filter' => $projectFilter,
            ]);
            $taskRows = odata_get_all($taskUrl, $auth, $ttl);
        } catch (Throwable $ignoredTaskLoadError) {
            $taskRows = [];
        }

        foreach ($taskRows as $taskRow) {
            if (!is_array($taskRow)) {
                continue;
            }

            $normProject = project_modal_normalize_project_no((string) ($taskRow['Job_No'] ?? ''));
            if ($normProject === '' || !isset($byProject[$normProject])) {
                continue;
            }

            $taskNo = trim((string) ($taskRow['Job_Task_No'] ?? ''));
            if ($taskNo === '') {
                continue;
            }
            if (FORMATTED_TASK_NOS_ONLY && !project_modal_is_formatted_task_no($taskNo)) {
                continue;
            }

            $taskKey = strtolower($taskNo);
            $byProject[$normProject]['task_rows'][$taskKey] = [
                'Cost_Group_Code' => $taskNo,
                'Cost_Group_Description' => (string) ($taskRow['Description'] ?? ''),
                'Job_Task_Type' => (string) ($taskRow['Job_Task_Type'] ?? ''),
                'Totaling' => (string) ($taskRow['Totaling'] ?? ''),
                'Budget_Cost' => finance_to_float($taskRow['Schedule_Total_Cost'] ?? 0.0),
                'EAC' => 0.0,
                'Booked_Cost' => 0.0,
                'Entered_Obligations' => 0.0,
                'Variance_Budget_EAC' => 0.0,
                'Is_Total_Row' => project_modal_is_total_task_type((string) ($taskRow['Job_Task_Type'] ?? '')),
            ];
        }

        try {
            $ledgerUrl = company_entity_url_with_query($baseUrl, $environmentForCompany, $company, 'JobLedgerEntries', [
                '$select' => 'Job_No,Job_Task_No,Total_Cost_LCY',
                '$filter' => $projectFilter,
            ]);
            $ledgerRows = odata_get_all($ledgerUrl, $auth, $ttl);
        } catch (Throwable $ignoredLedgerLoadError) {
            $ledgerRows = [];
        }

        foreach ($ledgerRows as $ledgerRow) {
            if (!is_array($ledgerRow)) {
                continue;
            }

            $normProject = project_modal_normalize_project_no((string) ($ledgerRow['Job_No'] ?? ''));
            if ($normProject === '' || !isset($byProject[$normProject])) {
                continue;
            }

            $taskNo = trim((string) ($ledgerRow['Job_Task_No'] ?? ''));
            if ($taskNo === '') {
                continue;
            }
            if (FORMATTED_TASK_NOS_ONLY && !project_modal_is_formatted_task_no($taskNo)) {
                continue;
            }

            $taskKey = strtolower($taskNo);
            if (!isset($byProject[$normProject]['task_rows'][$taskKey])) {
                continue;
            }

            $byProject[$normProject]['task_rows'][$taskKey]['Booked_Cost'] = finance_add_amount(
                (float) ($byProject[$normProject]['task_rows'][$taskKey]['Booked_Cost'] ?? 0.0),
                finance_to_float($ledgerRow['Total_Cost_LCY'] ?? 0.0)
            );
        }

        try {
            $purchaseUrl = company_entity_url_with_query($baseUrl, $environmentForCompany, $company, 'PurchaseLines', [
                '$select' => 'Job_No,Job_Task_No,Line_Amount',
                '$filter' => $projectFilter,
            ]);
            $purchaseRows = odata_get_all($purchaseUrl, $auth, $ttl);
        } catch (Throwable $ignoredPurchaseLoadError) {
            $purchaseRows = [];
        }

        foreach ($purchaseRows as $purchaseRow) {
            if (!is_array($purchaseRow)) {
                continue;
            }

            $normProject = project_modal_normalize_project_no((string) ($purchaseRow['Job_No'] ?? ''));
            if ($normProject === '' || !isset($byProject[$normProject])) {
                continue;
            }

            $taskNo = trim((string) ($purchaseRow['Job_Task_No'] ?? ''));
            if ($taskNo === '') {
                continue;
            }
            if (FORMATTED_TASK_NOS_ONLY && !project_modal_is_formatted_task_no($taskNo)) {
                continue;
            }

            $taskKey = strtolower($taskNo);
            if (!isset($byProject[$normProject]['task_rows'][$taskKey])) {
                continue;
            }

            $byProject[$normProject]['task_rows'][$taskKey]['Entered_Obligations'] = finance_add_amount(
                (float) ($byProject[$normProject]['task_rows'][$taskKey]['Entered_Obligations'] ?? 0.0),
                finance_to_float($purchaseRow['Line_Amount'] ?? 0.0)
            );
        }
    }

    foreach ($byProject as $normProject => $projectData) {
        $taskRowsByKey = is_array($projectData['task_rows'] ?? null) ? $projectData['task_rows'] : [];
        $bookingRows = [];
        foreach ($taskRowsByKey as $taskKey => $taskRow) {
            if (!is_array($taskRow)) {
                continue;
            }

            $isTotalRow = (bool) ($taskRow['Is_Total_Row'] ?? false);
            if ($isTotalRow) {
                continue;
            }

            $bookingRows[$taskKey] = $taskRow;
        }

        foreach ($taskRowsByKey as $taskKey => $taskRow) {
            if (!is_array($taskRow)) {
                continue;
            }

            $isTotalRow = (bool) ($taskRow['Is_Total_Row'] ?? false);
            if ($isTotalRow) {
                $range = project_modal_parse_totaling_range((string) ($taskRow['Totaling'] ?? ''));
                if ($range !== null) {
                    $budgetTotal = 0.0;
                    $bookedTotal = 0.0;
                    $obligationTotal = 0.0;

                    foreach ($bookingRows as $bookingRow) {
                        if (!is_array($bookingRow)) {
                            continue;
                        }

                        $bookingTaskNo = (string) ($bookingRow['Cost_Group_Code'] ?? '');
                        if (!project_modal_task_no_in_range($bookingTaskNo, $range)) {
                            continue;
                        }

                        $budgetTotal = finance_add_amount($budgetTotal, finance_to_float($bookingRow['Budget_Cost'] ?? 0.0));
                        $bookedTotal = finance_add_amount($bookedTotal, finance_to_float($bookingRow['Booked_Cost'] ?? 0.0));
                        $obligationTotal = finance_add_amount($obligationTotal, finance_to_float($bookingRow['Entered_Obligations'] ?? 0.0));
                    }

                    $taskRowsByKey[$taskKey]['Budget_Cost'] = $budgetTotal;
                    $taskRowsByKey[$taskKey]['Booked_Cost'] = $bookedTotal;
                    $taskRowsByKey[$taskKey]['Entered_Obligations'] = $obligationTotal;
                }
            }

            $taskRowsByKey[$taskKey]['Variance_Budget_EAC'] = finance_calculate_result(
                finance_to_float($taskRowsByKey[$taskKey]['Budget_Cost'] ?? 0.0),
                finance_to_float($taskRowsByKey[$taskKey]['EAC'] ?? 0.0)
            );
        }

        uasort($taskRowsByKey, static function (array $left, array $right): int {
            return strnatcasecmp((string) ($left['Cost_Group_Code'] ?? ''), (string) ($right['Cost_Group_Code'] ?? ''));
        });

        $budgetTotal = 0.0;
        $eacTotal = 0.0;
        $bookedTotal = 0.0;
        $obligationTotal = 0.0;
        foreach ($taskRowsByKey as $taskRow) {
            if (!is_array($taskRow)) {
                continue;
            }
            if ((bool) ($taskRow['Is_Total_Row'] ?? false)) {
                continue;
            }

            $budgetTotal = finance_add_amount($budgetTotal, finance_to_float($taskRow['Budget_Cost'] ?? 0.0));
            $eacTotal = finance_add_amount($eacTotal, finance_to_float($taskRow['EAC'] ?? 0.0));
            $bookedTotal = finance_add_amount($bookedTotal, finance_to_float($taskRow['Booked_Cost'] ?? 0.0));
            $obligationTotal = finance_add_amount($obligationTotal, finance_to_float($taskRow['Entered_Obligations'] ?? 0.0));
        }

        $varianceTotal = finance_calculate_result($budgetTotal, $eacTotal);

        $projectData['budget_cost_total'] = $budgetTotal;
        $projectData['task_rows'] = array_values($taskRowsByKey);
        $projectData['task_rows_total'] = [
            'Cost_Group_Code' => 'TOTAL',
            'Cost_Group_Description' => 'Totaal alle regels',
            'Budget_Cost' => $budgetTotal,
            'EAC' => $eacTotal,
            'Booked_Cost' => $bookedTotal,
            'Entered_Obligations' => $obligationTotal,
            'Variance_Budget_EAC' => $varianceTotal,
            'Is_Total_Row' => true,
        ];

        $byProject[$normProject] = $projectData;
    }

    return $byProject;
}

function batch_wip_path(string $company, string $targetYearMonth): string
{
    $safeCompany = preg_replace('/[^a-z0-9_-]/i', '_', strtolower(trim($company)));
    $safeYM = preg_replace('/[^0-9-]/', '', $targetYearMonth);
    return maanden_cache_dir() . DIRECTORY_SEPARATOR . $safeCompany . '_wip_' . $safeYM . '.json';
}

function batch_wip_load(string $company, string $targetYearMonth): array
{
    $path = batch_wip_path($company, $targetYearMonth);
    if (!is_file($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($data) ? $data : [];
}

function batch_wip_save(string $company, string $targetYearMonth, array $data): void
{
    $path = batch_wip_path($company, $targetYearMonth);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (is_string($json)) {
        file_put_contents($path, $json, LOCK_EX);
    }
}

function batch_wip_delete(string $company, string $targetYearMonth): void
{
    $path = batch_wip_path($company, $targetYearMonth);
    if (is_file($path)) {
        @unlink($path);
    }
}

/**
 * Geeft lijst van alle te ophalen maanden voor de gegeven doelmaand (3 jaar terug t/m doelmaand),
 * gesorteerd van oud naar nieuw.
 */
function batch_months_for_target(string $targetYearMonth): array
{
    $target = DateTimeImmutable::createFromFormat('!Y-m', $targetYearMonth);
    if (!$target instanceof DateTimeImmutable) {
        return [];
    }
    $start = $target->modify('-35 months'); // 36 maanden totaal incl. doelmaand
    $months = [];
    $cursor = $start;
    while ($cursor <= $target) {
        $months[] = $cursor->format('Y-m');
        $cursor = $cursor->modify('+1 month');
    }
    return $months;
}

function data_start_month_for_target(string $targetYearMonth): string
{
    return $targetYearMonth;
}

/** Aantal projecten per HTTP-stap bij ophalen projectdetails (BC doet intern chunks van 20). */
const PROJECT_DETAILS_FETCH_CHUNK_SIZE = 150;

/**
 * Bepaalt OData cache TTL op basis van maandleeftijd:
 * - Huidige maand: 1 dag
 * - Vorige maand: 1 week
 * - Oudere maanden: 1 jaar
 */
function odata_ttl_for_month(string $yearMonth): int
{
    global $day, $week, $year;

    $target = DateTimeImmutable::createFromFormat('!Y-m', $yearMonth);
    if (!$target instanceof DateTimeImmutable) {
        return $day;
    }

    $currentMonth = (new DateTimeImmutable('first day of this month'))->format('Y-m');
    if ($yearMonth === $currentMonth) {
        return $day;
    }

    $previousMonth = (new DateTimeImmutable('first day of last month'))->format('Y-m');
    if ($yearMonth === $previousMonth) {
        return $week;
    }

    return $year;
}

/**
 * Bepaalt unieke projectnummers uit WIP, met fallback op project_numbers_by_month.
 */
function wip_project_numbers(array $wip): array
{
    $projectNumbers = is_array($wip['project_numbers'] ?? null) ? $wip['project_numbers'] : [];
    if ($projectNumbers !== []) {
        return array_values(array_unique(array_filter(array_map(static function ($value): string {
            return trim((string) $value);
        }, $projectNumbers), static function (string $value): bool {
            return $value !== '';
        })));
    }

    $projectNumbersByMonth = is_array($wip['project_numbers_by_month'] ?? null)
        ? $wip['project_numbers_by_month']
        : [];
    $merged = [];
    foreach ($projectNumbersByMonth as $monthProjectNumbers) {
        if (!is_array($monthProjectNumbers)) {
            continue;
        }
        foreach ($monthProjectNumbers as $projectNo) {
            $projectNoText = trim((string) $projectNo);
            if ($projectNoText !== '') {
                $merged[] = $projectNoText;
            }
        }
    }

    return array_values(array_unique($merged));
}

/**
 * Projectnummers uit de OHW-kolom in WIP.
 */
function maanden_wip_project_numbers_from_ohw(array $wip): array
{
    $columnDataTarget = is_array($wip['column_data_target'] ?? null) ? $wip['column_data_target'] : [];
    $ohwColumn = is_array($columnDataTarget['grootboekposten_ohw'] ?? null) ? $columnDataTarget['grootboekposten_ohw'] : [];
    $byProject = is_array($ohwColumn['by_project'] ?? null) ? $ohwColumn['by_project'] : [];

    $projectNumbers = [];
    $seen = [];
    foreach ($byProject as $projectValues) {
        if (!is_array($projectValues)) {
            continue;
        }

        $sourceRows = is_array($projectValues['rows'] ?? null) ? $projectValues['rows'] : [];
        $firstRow = is_array($sourceRows[0] ?? null) ? $sourceRows[0] : [];
        $projectNo = trim((string) ($firstRow['Job_No'] ?? ''));
        if ($projectNo === '' || isset($seen[$projectNo])) {
            continue;
        }

        $seen[$projectNo] = true;
        $projectNumbers[] = $projectNo;
    }

    return $projectNumbers;
}

/**
 * Vult project_numbers in WIP vanuit OHW en retourneert de lijst.
 */
function maanden_wip_sync_project_numbers_from_ohw(array &$wip): array
{
    $projectNumbers = maanden_wip_project_numbers_from_ohw($wip);
    $wip['project_numbers'] = $projectNumbers;
    $wip['planning_project_numbers'] = $projectNumbers;

    return $projectNumbers;
}

/**
 * Voegt een deel-fetch van projectdetails samen in de WIP-kolomstructuur.
 */
function maanden_merge_project_details_column(array $existing, array $partial): array
{
    $merged = is_array($existing) ? $existing : ['column' => 'project_details', 'by_project' => []];
    if (!isset($merged['by_project']) || !is_array($merged['by_project'])) {
        $merged['by_project'] = [];
    }

    $partialByProject = is_array($partial['by_project'] ?? null) ? $partial['by_project'] : [];
    foreach ($partialByProject as $normProjectNo => $projectValue) {
        $merged['by_project'][(string) $normProjectNo] = $projectValue;
    }

    return $merged;
}

/**
 * Aggregateert Grootboekposten_OHW-rijen naar projecttotalen.
 */
function aggregate_grootboekposten_ohw_rows(array $rows): array
{
    return bc_fetch_aggregate_ohw_rows($rows);
}

/**
 * Bouwt projectsamenvattingen en totalen vanuit eerder opgehaalde data.
 * Doet geen OData-calls; alle benodigde data wordt als parameter meegegeven.
 */
function build_month_rows(
    string $company,
    string $yearMonth,
    array $projectTotalsByJob,
    array $projectDetails,
    array $planningTotalsByJob,
    array $planningBreakdownByJob
): array {
    $totalRevenue = 0.0;
    $totalCosts = 0.0;
    $projectRows = [];

    $projectKeys = array_values(array_unique(array_merge(
        array_keys($projectTotalsByJob),
        array_keys($planningTotalsByJob),
        array_keys($projectDetails)
    )));

    foreach ($projectKeys as $normJob) {
        $normJob = strtolower(trim((string) $normJob));
        if ($normJob === '') {
            continue;
        }

        $proj = $projectDetails[$normJob] ?? null;
        $jobNo = trim((string) (($proj['No'] ?? '') ?: strtoupper($normJob)));
        $planningTotals = $planningTotalsByJob[$normJob] ?? ['expected_revenue' => 0.0, 'expected_costs' => 0.0, 'extra_work' => 0.0];
        $planningBreakdown = $planningBreakdownByJob[$normJob] ?? ['expected_revenue_lines' => [], 'expected_costs_lines' => [], 'extra_work_lines' => []];
        $projectTotals = $projectTotalsByJob[$normJob] ?? ['costs' => 0.0, 'revenue' => 0.0, 'resultaat' => 0.0];

        $projectRows[$normJob] = [
            'Job_No' => $jobNo,
            'Description' => (string) ($proj['Description'] ?? ''),
            'Customer_Id' => (string) ($proj['Bill_to_Customer_No'] ?? ''),
            'Customer_Name' => (string) ($proj['Bill_to_Name'] ?? ''),
            'Project_Manager' => (string) ($proj['Project_Manager'] ?? $proj['Person_Responsible'] ?? ''),
            'Cost_Center' => (string) ($proj['LVS_Global_Dimension_1_Code'] ?? ''),
            'Project_Actual_Costs' => (float) ($projectTotals['costs'] ?? 0.0),
            'Project_Total_Revenue' => (float) ($projectTotals['revenue'] ?? 0.0),
            'Expected_Revenue' => (float) ($planningTotals['expected_revenue'] ?? 0),
            'Expected_Costs_VC' => (float) ($planningTotals['expected_costs'] ?? 0),
            'Extra_Work' => (float) ($planningTotals['extra_work'] ?? 0),
            'Breakdown' => [
                'total_costs_lines' => [],
                'total_revenue_lines' => [],
                'expected_revenue_lines' => is_array($planningBreakdown['expected_revenue_lines'] ?? null) ? $planningBreakdown['expected_revenue_lines'] : [],
                'expected_costs_lines' => is_array($planningBreakdown['expected_costs_lines'] ?? null) ? $planningBreakdown['expected_costs_lines'] : [],
                'extra_work_lines' => is_array($planningBreakdown['extra_work_lines'] ?? null) ? $planningBreakdown['extra_work_lines'] : [],
            ],
        ];
    }

    foreach ($projectRows as $projectRow) {
        if (!is_array($projectRow)) {
            continue;
        }

        $totalRevenue = finance_add_amount($totalRevenue, $projectRow['Project_Total_Revenue'] ?? 0);
        $totalCosts = finance_add_amount($totalCosts, $projectRow['Project_Actual_Costs'] ?? 0);
    }

    $projectBreakdowns = [];
    foreach ($projectRows as $normJob => $projectRow) {
        if (!is_array($projectRow)) {
            continue;
        }
        $projectBreakdowns[$normJob] = is_array($projectRow['Breakdown'] ?? null)
            ? $projectRow['Breakdown']
            : [
                'total_costs_lines' => [],
                'total_revenue_lines' => [],
                'expected_revenue_lines' => [],
                'expected_costs_lines' => [],
                'extra_work_lines' => [],
            ];
    }

    return [
        'year_month' => $yearMonth,
        'data_start_month' => data_start_month_for_target($yearMonth),
        'company' => $company,
        'fetched_at' => gmdate('c'),
        'total_revenue' => $totalRevenue,
        'total_costs' => $totalCosts,
        'project_details' => $projectDetails,
        'project_summaries' => array_values($projectRows),
        'project_breakdowns' => $projectBreakdowns,
        'workorder_rows' => [],
    ];
}

function fetch_month_data(string $company, string $yearMonth, array $auth): array
{
    global $baseUrl;
    
    $environmentForCompany = auth_get_environment_for_company($company, 300);
    $auth = auth_get_auth_for_environment($environmentForCompany);
    @set_time_limit(0);
    @ini_set('max_execution_time', '0');

    try {
        $ohwRows = bc_fetch_grootboekposten_ohw_rows($company, $yearMonth, $auth, odata_ttl_for_month($yearMonth));
    } catch (Throwable $ohwLoadError) {
        throw new RuntimeException('Grootboekposten OHW konden niet worden opgehaald: ' . $ohwLoadError->getMessage());
    }

    $aggregatedFinance = aggregate_grootboekposten_ohw_rows($ohwRows);
    $projectTotalsByJob = is_array($aggregatedFinance['project_totals_by_job'] ?? null)
        ? $aggregatedFinance['project_totals_by_job']
        : [];
    $ppProjectNumbers = is_array($aggregatedFinance['project_numbers'] ?? null)
        ? $aggregatedFinance['project_numbers']
        : [];

    $projectNumbers = [];
    $seenProjectNos = [];
    foreach ($ppProjectNumbers as $pNo) {
        $pNo = trim((string) $pNo);
        if ($pNo !== '' && !isset($seenProjectNos[$pNo])) {
            $seenProjectNos[$pNo] = true;
            $projectNumbers[] = $pNo;
        }
    }

    $environmentForCompany = auth_get_environment_for_company($company, 300);
    $financeService = new ProjectFinanceService($company, $environmentForCompany);

    // Fetch project details in chunks
    $projectDetails = [];
    $projectChunks = array_chunk(array_unique($projectNumbers), 20);

    foreach ($projectChunks as $chunk) {
        $filterParts = array_map(fn($no) => "No eq '" . str_replace("'", "''", $no) . "'", $chunk);
        $filter = implode(' or ', $filterParts);
        try {
            $projectUrl = company_entity_url_with_query($baseUrl, $environmentForCompany, $company, 'Projecten', [
                '$select' => 'No,Description,Sell_to_Customer_No,Sell_to_Customer_Name,Bill_to_Customer_No,Bill_to_Name,Person_Responsible,Project_Manager,LVS_Global_Dimension_1_Code,Status,Percent_Completed,Total_WIP_Cost_Amount,Total_WIP_Sales_Amount,Recog_Costs_Amount,Recog_Sales_Amount,Calc_Recog_Costs_Amount,Calc_Recog_Sales_Amount,Acc_WIP_Costs_Amount,Acc_WIP_Sales_Amount,LVS_No_Of_Job_Change_Orders,External_Document_No,Your_Reference',
                '$filter' => $filter,
            ]);
            $batchProjects = odata_get_all($projectUrl, $auth, odata_ttl_for_month($yearMonth));
        } catch (Throwable $e) {
            continue;
        }
        foreach ($batchProjects as $proj) {
            if (!is_array($proj)) {
                continue;
            }
            $no = trim((string) ($proj['No'] ?? ''));
            if ($no !== '') {
                $projectDetails[strtolower($no)] = $proj;
            }
        }
    }

    $planningTotalsByJob = [];
    $planningBreakdownByJob = [];
    if ($projectNumbers !== []) {
        try {
            $projectForecast = $financeService->collectProjectForecastForProjects($projectNumbers, odata_ttl_for_month($yearMonth));
            $planningTotalsByJob = is_array($projectForecast['forecast_totals_by_job'] ?? null)
                ? $projectForecast['forecast_totals_by_job']
                : [];
            $planningBreakdownByJob = is_array($projectForecast['forecast_breakdown_by_job'] ?? null)
                ? $projectForecast['forecast_breakdown_by_job']
                : [];
        } catch (Throwable $forecastError) {
            $planningTotalsByJob = [];
            $planningBreakdownByJob = [];
        }
    }

    $result = build_month_rows(
        $company,
        $yearMonth,
        $projectTotalsByJob,
        $projectDetails,
        $planningTotalsByJob,
        $planningBreakdownByJob
    );

    $result['ohw_debug'] = [
        'loader_mode' => 'grootboekposten_ohw',
        'rows_total' => count($ohwRows),
        'projects_from_ohw' => count($ppProjectNumbers),
        'snapshot_bounds' => bc_fetch_snapshot_ohw_bounds($yearMonth),
    ];

    // Clean up WIP cache now that the full snapshot is built
    batch_wip_delete($company, $yearMonth);

    return $result;
}

/**
 * Consolideert maand- en kolomdata uit WIP naar de bestaande snapshot-structuur.
 */
function build_snapshot_from_column_wip(string $company, string $targetYm, array $wip): array
{
    $projectTotalsByJob = [];
    $projectDetails = [];
    $planningTotalsByJob = is_array($wip['planning_totals_by_job'] ?? null)
        ? $wip['planning_totals_by_job']
        : [];
    $planningBreakdownByJob = is_array($wip['planning_breakdown_by_job'] ?? null)
        ? $wip['planning_breakdown_by_job']
        : [];
    $ohwCostLinesByProject = [];
    $ohwRevenueLinesByProject = [];
    $columnDataTarget = is_array($wip['column_data_target'] ?? null) ? $wip['column_data_target'] : [];
    $projectNumbers = maanden_wip_sync_project_numbers_from_ohw($wip);

    $ohwColumn = is_array($columnDataTarget['grootboekposten_ohw'] ?? null) ? $columnDataTarget['grootboekposten_ohw'] : [];
    $ohwByProject = is_array($ohwColumn['by_project'] ?? null) ? $ohwColumn['by_project'] : [];
    foreach ($ohwByProject as $normProjectNo => $projectValues) {
        if (!is_array($projectValues)) {
            continue;
        }

        if (!isset($projectTotalsByJob[$normProjectNo])) {
            $projectTotalsByJob[$normProjectNo] = [
                'costs' => 0.0,
                'revenue' => 0.0,
                'resultaat' => 0.0,
            ];
        }

        $projectTotalsByJob[$normProjectNo]['costs'] = finance_add_amount(
            (float) ($projectTotalsByJob[$normProjectNo]['costs'] ?? 0.0),
            finance_to_float($projectValues['costs'] ?? 0.0)
        );
        $projectTotalsByJob[$normProjectNo]['revenue'] = finance_add_amount(
            (float) ($projectTotalsByJob[$normProjectNo]['revenue'] ?? 0.0),
            finance_to_float($projectValues['revenue'] ?? 0.0)
        );
        $projectTotalsByJob[$normProjectNo]['resultaat'] = finance_calculate_result(
            (float) ($projectTotalsByJob[$normProjectNo]['revenue'] ?? 0.0),
            (float) ($projectTotalsByJob[$normProjectNo]['costs'] ?? 0.0)
        );

        $ohwCostLinesByProject[$normProjectNo] = [];
        $ohwRevenueLinesByProject[$normProjectNo] = [];
        $sourceRows = is_array($projectValues['rows'] ?? null) ? $projectValues['rows'] : [];
        foreach ($sourceRows as $sourceRow) {
            if (!is_array($sourceRow)) {
                continue;
            }

            if (bc_fetch_ohw_should_ignore_row($sourceRow)) {
                continue;
            }

            $kind = bc_fetch_ohw_gl_account_kind((string) ($sourceRow['G_L_Account_No'] ?? ''));
            if ($kind === 'costs') {
                $ohwCostLinesByProject[$normProjectNo][] = bc_fetch_ohw_breakdown_line_from_row($sourceRow);
            } elseif ($kind === 'revenue') {
                $ohwRevenueLinesByProject[$normProjectNo][] = bc_fetch_ohw_breakdown_line_from_row($sourceRow);
            }
        }
    }

    $projectDetailsColumn = is_array($columnDataTarget['project_details'] ?? null) ? $columnDataTarget['project_details'] : [];
    $projectDetailsByProject = is_array($projectDetailsColumn['by_project'] ?? null) ? $projectDetailsColumn['by_project'] : [];
    foreach ($projectDetailsByProject as $normProjectNo => $projectValue) {
        $row = is_array($projectValue['row'] ?? null) ? $projectValue['row'] : [];
        if ($row !== []) {
            $projectDetails[$normProjectNo] = $row;
        }
    }

    $data = build_month_rows(
        $company,
        $targetYm,
        $projectTotalsByJob,
        $projectDetails,
        $planningTotalsByJob,
        $planningBreakdownByJob
    );

    $projectBreakdowns = is_array($data['project_breakdowns'] ?? null)
        ? $data['project_breakdowns']
        : [];
    foreach ($projectBreakdowns as $normProjectNo => $breakdown) {
        if (!is_array($breakdown)) {
            continue;
        }

        $breakdown['total_costs_lines'] = is_array($ohwCostLinesByProject[$normProjectNo] ?? null)
            ? $ohwCostLinesByProject[$normProjectNo]
            : [];
        $breakdown['total_revenue_lines'] = is_array($ohwRevenueLinesByProject[$normProjectNo] ?? null)
            ? $ohwRevenueLinesByProject[$normProjectNo]
            : [];

        $projectBreakdowns[$normProjectNo] = $breakdown;
    }
    $data['project_breakdowns'] = $projectBreakdowns;

    if (is_array($data['project_summaries'] ?? null)) {
        foreach ($data['project_summaries'] as $summaryIndex => $summaryRow) {
            if (!is_array($summaryRow)) {
                continue;
            }

            $normProjectNo = strtolower(trim((string) ($summaryRow['Job_No'] ?? '')));
            if ($normProjectNo === '') {
                continue;
            }

            $breakdown = is_array($projectBreakdowns[$normProjectNo] ?? null)
                ? $projectBreakdowns[$normProjectNo]
                : [];
            $departmentCode = bc_fetch_primary_department_from_breakdown($breakdown);
            if ($departmentCode !== '') {
                $data['project_summaries'][$summaryIndex]['Cost_Center'] = $departmentCode;
            }
        }
    }

    $data['ohw_debug'] = [
        'loader_mode' => 'grootboekposten_ohw',
        'row_count' => (int) ($ohwColumn['row_count'] ?? 0),
        'project_count' => count($projectNumbers),
        'snapshot_bounds' => $ohwColumn['snapshot_bounds'] ?? bc_fetch_snapshot_ohw_bounds($targetYm),
    ];

    return $data;
}

function current_user_email_or_fallback_m(): string
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

function usersettings_file_path_m(string $email): string
{
    $safeEmail = preg_replace('/[^a-z0-9@._-]/i', '_', strtolower(trim($email)));
    if (trim($safeEmail) === '') {
        $safeEmail = 'ict@kvt.nl';
    }
    return __DIR__ . '/cache/usersettings/' . $safeEmail . '.txt';
}

function load_user_settings_payload_m(string $email): array
{
    $path = usersettings_file_path_m($email);
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

function save_user_settings_m(string $email, array $patch): bool
{
    $directory = __DIR__ . '/cache/usersettings';
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        return false;
    }
    $existing = load_user_settings_payload_m($email);
    foreach ($patch as $k => $v) {
        $existing[$k] = $v;
    }
    $existing['updated_at'] = gmdate('c');
    $path = usersettings_file_path_m($email);
    $json = json_encode($existing, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        return false;
    }
    return file_put_contents($path, $json, LOCK_EX) !== false;
}

/**
 * Page load
 */
$currentUserEmail = current_user_email_or_fallback_m();

// Laden van alle bedrijven uit alle actieve environments
try {
    require_once __DIR__ . '/auth_helper.php';
    $discoveryResult = auth_discover_companies_across_active_environments(300);
    $companies = is_array($discoveryResult['companies'] ?? null) ? $discoveryResult['companies'] : [];
} catch (Throwable $e) {
    // Fallback op lege lijst als discovery mislukt
    $companies = [];
}

// Fallback naar hardcoded bedrijven als discovery leeg was
if ($companies === []) {
    $companies = [
        'Koninklijke van Twist',
        'Hunter van Twist',
        'KVT Gas',
    ];
}

$userSettings = load_user_settings_payload_m($currentUserEmail);
$savedCompany = trim((string) ($userSettings['selected_company'] ?? ''));
$selectedCompany = trim((string) ($_GET['company'] ?? ''));
if ($selectedCompany === '' || !in_array($selectedCompany, $companies, true)) {
    $selectedCompany = ($savedCompany !== '' && in_array($savedCompany, $companies, true))
        ? $savedCompany
        : $companies[0];
}

// --- AJAX endpoints ---

// Ophalen projectnummers voor één batch-maand (centrale startstap per maand)
if (($_GET['action'] ?? '') === 'fetch_project_numbers_batch') {
    header('Content-Type: application/json; charset=utf-8');
    @set_time_limit(0);
    @ini_set('max_execution_time', '0');

    $targetYm = trim((string) ($_POST['target_month'] ?? ''));
    $batchYm = trim((string) ($_POST['batch_month'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}$/', $targetYm) || !preg_match('/^\d{4}-\d{2}$/', $batchYm)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Ongeldige maand'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $company = trim((string) ($_POST['company'] ?? $companies[0]));
    if (!in_array($company, $companies, true)) {
        $company = $companies[0];
    }
    $auth = auth_get_auth_for_company($company, 300);

    try {
        $ttl = odata_ttl_for_month($batchYm);
        $projectNumbers = bc_fetch_project_numbers_for_month($company, $batchYm, $auth, $ttl);
        $batchProjectNumbers = array_values(array_unique(array_filter($projectNumbers, static function ($value): bool {
            return trim((string) $value) !== '';
        })));

        $wip = batch_wip_load($company, $targetYm);
        $projectNumbersByMonth = is_array($wip['project_numbers_by_month'] ?? null) ? $wip['project_numbers_by_month'] : [];
        $projectNumbersByMonth[$batchYm] = $batchProjectNumbers;

        $knownPlanningProjects = is_array($wip['planning_project_numbers'] ?? null)
            ? $wip['planning_project_numbers']
            : [];
        $knownPlanningProjects = array_values(array_unique(array_merge($knownPlanningProjects, $batchProjectNumbers)));

        $wip['project_numbers_by_month'] = $projectNumbersByMonth;
        $wip['planning_project_numbers'] = $knownPlanningProjects;
        batch_wip_save($company, $targetYm, $wip);

        echo json_encode([
            'ok' => true,
            'batch_month' => $batchYm,
            'project_count' => count($projectNumbersByMonth[$batchYm]),
            'project_numbers' => $batchProjectNumbers,
            'all_project_numbers' => $knownPlanningProjects,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Ophalen kolomdata voor één batch-maand (één endpoint per kolomstap)
if (($_GET['action'] ?? '') === 'fetch_column_batch') {
    header('Content-Type: application/json; charset=utf-8');
    @set_time_limit(0);
    @ini_set('max_execution_time', '0');

    $targetYm = trim((string) ($_POST['target_month'] ?? ''));
    $batchYm = trim((string) ($_POST['batch_month'] ?? ''));
    $columnKey = trim((string) ($_POST['column_key'] ?? ''));
    $targetScopedKeys = bc_fetch_target_scoped_column_keys();
    $isTargetScoped = in_array($columnKey, $targetScopedKeys, true);
    if (!preg_match('/^\d{4}-\d{2}$/', $targetYm) || $columnKey === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Ongeldige parameters'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!$isTargetScoped && !preg_match('/^\d{4}-\d{2}$/', $batchYm)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Ongeldige batch-maand'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $company = trim((string) ($_POST['company'] ?? $companies[0]));
    if (!in_array($company, $companies, true)) {
        $company = $companies[0];
    }
    $auth = auth_get_auth_for_company($company, 300);

    try {
        if (!$isTargetScoped) {
            throw new RuntimeException('Kolom ' . $columnKey . ' wordt alleen nog per snapshot opgehaald.');
        }

        $wip = batch_wip_load($company, $targetYm);
        $ttl = odata_ttl_for_month($targetYm);
        $columnDataTarget = is_array($wip['column_data_target'] ?? null) ? $wip['column_data_target'] : [];
        $chunkIndex = max(0, (int) ($_POST['chunk_index'] ?? 0));
        $response = [
            'ok' => true,
            'batch_month' => $targetYm,
            'column_key' => $columnKey,
            'target_scoped' => true,
            'chunk_index' => $chunkIndex,
            'total_chunks' => 1,
            'chunks_complete' => true,
            'warning' => null,
            'project_count' => count(wip_project_numbers($wip)),
        ];

        if ($columnKey === 'grootboekposten_ohw') {
            $columnResult = bc_fetch_run_column($columnKey, $company, $targetYm, [], $auth, $ttl);
            $columnDataTarget[$columnKey] = $columnResult;
            $wip['column_data_target'] = $columnDataTarget;
            maanden_wip_sync_project_numbers_from_ohw($wip);
            $response['project_count'] = count(wip_project_numbers($wip));
            $response['warning'] = $columnResult['warning'] ?? null;
        } elseif ($columnKey === 'project_details') {
            $allProjectNumbers = wip_project_numbers($wip);
            if ($allProjectNumbers === []) {
                maanden_wip_sync_project_numbers_from_ohw($wip);
                $allProjectNumbers = wip_project_numbers($wip);
            }

            $projectChunks = array_chunk($allProjectNumbers, PROJECT_DETAILS_FETCH_CHUNK_SIZE);
            $totalChunks = max(1, count($projectChunks));
            $response['total_chunks'] = $totalChunks;

            if ($allProjectNumbers === []) {
                $columnDataTarget[$columnKey] = [
                    'column' => 'project_details',
                    'by_project' => [],
                ];
                $response['chunks_complete'] = true;
            } else {
                if ($chunkIndex >= $totalChunks) {
                    $response['chunks_complete'] = true;
                } else {
                    $chunkProjects = $projectChunks[$chunkIndex] ?? [];
                    $columnResult = bc_fetch_run_column($columnKey, $company, $targetYm, $chunkProjects, $auth, $ttl);
                    $existing = is_array($columnDataTarget[$columnKey] ?? null) ? $columnDataTarget[$columnKey] : [];
                    $columnDataTarget[$columnKey] = maanden_merge_project_details_column($existing, $columnResult);
                    $response['chunks_complete'] = ($chunkIndex + 1) >= $totalChunks;
                    $response['warning'] = $columnResult['warning'] ?? null;
                }
            }

            $wip['column_data_target'] = $columnDataTarget;
        } else {
            $allProjectNumbers = wip_project_numbers($wip);
            $columnResult = bc_fetch_run_column($columnKey, $company, $targetYm, $allProjectNumbers, $auth, $ttl);
            $columnDataTarget[$columnKey] = $columnResult;
            $wip['column_data_target'] = $columnDataTarget;
            $response['warning'] = $columnResult['warning'] ?? null;
        }

        batch_wip_save($company, $targetYm, $wip);

        echo json_encode($response, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Sub-stap 1: projectnummers verzamelen uit WIP-werkorders
if (($_GET['action'] ?? '') === 'fetch_sub_collect') {
    header('Content-Type: application/json; charset=utf-8');
    @set_time_limit(0);
    @ini_set('max_execution_time', '0');

    $targetYm = trim((string) ($_POST['target_month'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}$/', $targetYm)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Ongeldige maand'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $company = trim((string) ($_POST['company'] ?? $companies[0]));
    if (!in_array($company, $companies, true)) {
        $company = $companies[0];
    }

    try {
        $wip = batch_wip_load($company, $targetYm);
        $projectNumbers = [];
        $seenProjectNos = [];

        $columnDataTarget = is_array($wip['column_data_target'] ?? null) ? $wip['column_data_target'] : [];
        $ohwColumn = is_array($columnDataTarget['grootboekposten_ohw'] ?? null) ? $columnDataTarget['grootboekposten_ohw'] : [];
        $ohwByProject = is_array($ohwColumn['by_project'] ?? null) ? $ohwColumn['by_project'] : [];
        $aggregatedFinance = [
            'project_totals_by_job' => [],
            'workorder_totals_by_number' => [],
        ];
        foreach ($ohwByProject as $normProjectNo => $projectValues) {
            if (!is_array($projectValues)) {
                continue;
            }
            $aggregatedFinance['project_totals_by_job'][$normProjectNo] = [
                'costs' => finance_to_float($projectValues['costs'] ?? 0.0),
                'revenue' => finance_to_float($projectValues['revenue'] ?? 0.0),
                'resultaat' => finance_calculate_result(
                    finance_to_float($projectValues['revenue'] ?? 0.0),
                    finance_to_float($projectValues['costs'] ?? 0.0)
                ),
            ];
            $sourceRows = is_array($projectValues['rows'] ?? null) ? $projectValues['rows'] : [];
            $firstRow = is_array($sourceRows[0] ?? null) ? $sourceRows[0] : [];
            $projectNo = trim((string) ($firstRow['Job_No'] ?? $normProjectNo));
            if ($projectNo !== '' && !isset($seenProjectNos[$projectNo])) {
                $seenProjectNos[$projectNo] = true;
                $projectNumbers[] = $projectNo;
            }
        }

        $wip['project_numbers'] = $projectNumbers;
        $wip['project_finance'] = [
            'project_totals_by_job' => is_array($aggregatedFinance['project_totals_by_job'] ?? null)
                ? $aggregatedFinance['project_totals_by_job']
                : [],
            'invoice_details_by_id' => [],
            'project_invoice_ids_by_job' => [],
            'project_invoiced_total_by_job' => [],
        ];
        $wip['workorder_finance'] = [
            'workorder_totals_by_number' => is_array($aggregatedFinance['workorder_totals_by_number'] ?? null)
                ? $aggregatedFinance['workorder_totals_by_number']
                : [],
        ];
        batch_wip_save($company, $targetYm, $wip);

        echo json_encode([
            'ok' => true,
            'project_count' => count($projectNumbers),
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Sub-stap 2: finance-data ophalen voor verzamelde projecten
if (($_GET['action'] ?? '') === 'fetch_sub_finance') {
    header('Content-Type: application/json; charset=utf-8');
    @set_time_limit(0);
    @ini_set('max_execution_time', '0');

    $targetYm = trim((string) ($_POST['target_month'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}$/', $targetYm)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Ongeldige maand'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $company = trim((string) ($_POST['company'] ?? $companies[0]));
    if (!in_array($company, $companies, true)) {
        $company = $companies[0];
    }

    try {
        $ttl = odata_ttl_for_month($targetYm);
        $wip = batch_wip_load($company, $targetYm);
        $projectNumbers = is_array($wip['project_numbers'] ?? null) ? $wip['project_numbers'] : [];
        $existingProjectFinance = is_array($wip['project_finance'] ?? null) ? $wip['project_finance'] : [];
        $existingWorkorderFinance = is_array($wip['workorder_finance'] ?? null) ? $wip['workorder_finance'] : [];

        $wip['project_finance'] = [
            'project_totals_by_job' => is_array($existingProjectFinance['project_totals_by_job'] ?? null)
                ? $existingProjectFinance['project_totals_by_job']
                : [],
            'invoice_details_by_id' => [],
            'project_invoice_ids_by_job' => [],
            'project_invoiced_total_by_job' => [],
        ];
        $wip['workorder_finance'] = [
            'workorder_totals_by_number' => is_array($existingWorkorderFinance['workorder_totals_by_number'] ?? null)
                ? $existingWorkorderFinance['workorder_totals_by_number']
                : [],
        ];
        batch_wip_save($company, $targetYm, $wip);

        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Sub-stap 3: projectdetails ophalen voor verzamelde projecten
if (($_GET['action'] ?? '') === 'fetch_sub_projects') {
    header('Content-Type: application/json; charset=utf-8');
    @set_time_limit(0);
    @ini_set('max_execution_time', '0');

    $targetYm = trim((string) ($_POST['target_month'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}$/', $targetYm)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Ongeldige maand'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $company = trim((string) ($_POST['company'] ?? $companies[0]));
    if (!in_array($company, $companies, true)) {
        $company = $companies[0];
    }

    try {
        $ttl = odata_ttl_for_month($targetYm);
        $wip = batch_wip_load($company, $targetYm);
        $projectNumbers = is_array($wip['project_numbers'] ?? null) ? $wip['project_numbers'] : [];
        $projectDetails = [];
        $projectChunks = array_chunk(array_unique($projectNumbers), 20);

        foreach ($projectChunks as $chunk) {
            $filterParts = array_map(fn($no) => "No eq '" . str_replace("'", "''", $no) . "'", $chunk);
            $filter = implode(' or ', $filterParts);
            try {
                $projectUrl = company_entity_url_with_query($baseUrl, $environmentForCompany, $company, 'Projecten', [
                    '$select' => 'No,Description,Sell_to_Customer_No,Sell_to_Customer_Name,Bill_to_Customer_No,Bill_to_Name,Person_Responsible,Project_Manager,KVT_Sales_Person_Code,LVS_Global_Dimension_1_Code,Status,Percent_Completed,Total_WIP_Cost_Amount,Total_WIP_Sales_Amount,Recog_Costs_Amount,Recog_Sales_Amount,Calc_Recog_Costs_Amount,Calc_Recog_Sales_Amount,Acc_WIP_Costs_Amount,Acc_WIP_Sales_Amount,LVS_No_Of_Job_Change_Orders,External_Document_No,Your_Reference,LVS_Your_reference,Creation_Date,Ending_Date',
                    '$filter' => $filter,
                ]);
                $batchProjects = odata_get_all($projectUrl, $auth, $ttl);
            } catch (Throwable $e) {
                continue;
            }
            foreach ($batchProjects as $proj) {
                if (!is_array($proj)) {
                    continue;
                }
                $no = trim((string) ($proj['No'] ?? ''));
                if ($no !== '') {
                    $projectDetails[strtolower($no)] = $proj;
                }
            }
        }

        $wip['project_details'] = $projectDetails;
        batch_wip_save($company, $targetYm, $wip);

        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Voorcalculatie ophalen voor projecten uit OHW-snapshot (één server-call)
if (($_GET['action'] ?? '') === 'fetch_sub_planning') {
    header('Content-Type: application/json; charset=utf-8');
    @set_time_limit(0);
    @ini_set('max_execution_time', '0');

    $targetYm = trim((string) ($_POST['target_month'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}$/', $targetYm)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Ongeldige maand'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $company = trim((string) ($_POST['company'] ?? $companies[0]));
    if (!in_array($company, $companies, true)) {
        $company = $companies[0];
    }

    try {
        $ttl = odata_ttl_for_month($targetYm);
        $wip = batch_wip_load($company, $targetYm);
        maanden_wip_sync_project_numbers_from_ohw($wip);
        $projectNumbers = wip_project_numbers($wip);
        $planningTotalsByJob = [];
        $planningBreakdownByJob = [];
        $planningWarning = null;

        if ($projectNumbers !== []) {
            try {
                $environmentForCompany = auth_get_environment_for_company($company, 300);
                $financeService = new ProjectFinanceService($company, $environmentForCompany);
                $projectForecast = $financeService->collectProjectForecastForProjects($projectNumbers, $ttl);
                $planningTotalsByJob = is_array($projectForecast['forecast_totals_by_job'] ?? null)
                    ? $projectForecast['forecast_totals_by_job']
                    : [];
                $planningBreakdownByJob = is_array($projectForecast['forecast_breakdown_by_job'] ?? null)
                    ? $projectForecast['forecast_breakdown_by_job']
                    : [];
            } catch (Throwable $forecastLoadError) {
                // Forecast mag ProjectPosten-snapshot niet blokkeren.
                $planningTotalsByJob = [];
                $planningBreakdownByJob = [];
                $planningWarning = $forecastLoadError->getMessage();
            }
        }

        $wip['planning_totals_by_job'] = $planningTotalsByJob;
        $wip['planning_breakdown_by_job'] = $planningBreakdownByJob;
        $wip['planning_warning'] = $planningWarning;
        batch_wip_save($company, $targetYm, $wip);

        echo json_encode([
            'ok' => true,
            'warning' => $planningWarning,
            'project_count' => count($projectNumbers),
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Bouw definitieve snapshot vanuit WIP-gegevens (na alle sub-stappen)
if (($_GET['action'] ?? '') === 'build_month_snapshot') {
    header('Content-Type: application/json; charset=utf-8');
    @set_time_limit(0);
    @ini_set('max_execution_time', '0');

    $targetYm = trim((string) ($_POST['target_month'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}$/', $targetYm)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Ongeldige maand'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $company = trim((string) ($_POST['company'] ?? $companies[0]));
    if (!in_array($company, $companies, true)) {
        $company = $companies[0];
    }

    try {
        $wip = batch_wip_load($company, $targetYm);

        $columnDataTarget = is_array($wip['column_data_target'] ?? null) ? $wip['column_data_target'] : [];
        if (is_array($columnDataTarget['grootboekposten_ohw'] ?? null)) {
            $data = build_snapshot_from_column_wip($company, $targetYm, $wip);
            maand_save($company, $targetYm, $data);
            batch_wip_delete($company, $targetYm);

            echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $projectFinance = is_array($wip['project_finance'] ?? null) ? $wip['project_finance'] : [];
        $projectDetails = is_array($wip['project_details'] ?? null) ? $wip['project_details'] : [];
        $planningTotalsByJob = is_array($wip['planning_totals_by_job'] ?? null) ? $wip['planning_totals_by_job'] : [];
        $planningBreakdownByJob = is_array($wip['planning_breakdown_by_job'] ?? null) ? $wip['planning_breakdown_by_job'] : [];

        $projectTotalsByJob = is_array($projectFinance['project_totals_by_job'] ?? null) ? $projectFinance['project_totals_by_job'] : [];

        $data = build_month_rows(
            $company,
            $targetYm,
            $projectTotalsByJob,
            $projectDetails,
            $planningTotalsByJob,
            $planningBreakdownByJob
        );

        maand_save($company, $targetYm, $data);
        batch_wip_delete($company, $targetYm);

        echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Verversen / aanmaken van een maand
if (($_GET['action'] ?? '') === 'refresh_month') {
    header('Content-Type: application/json; charset=utf-8');
    @set_time_limit(0);
    @ini_set('max_execution_time', '0');

    $ym = trim((string) ($_POST['year_month'] ?? $_GET['year_month'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Ongeldige maand'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $company = trim((string) ($_POST['company'] ?? $_GET['company'] ?? $companies[0]));
    if (!in_array($company, $companies, true)) {
        $company = $companies[0];
    }

    try {
        $data = fetch_month_data($company, $ym, $auth);
        maand_save($company, $ym, $data);
        echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Verwijderen van een maand
if (($_GET['action'] ?? '') === 'delete_month') {
    header('Content-Type: application/json; charset=utf-8');

    $ym = trim((string) ($_POST['year_month'] ?? $_GET['year_month'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Ongeldige maand'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $company = trim((string) ($_POST['company'] ?? $_GET['company'] ?? $companies[0]));
    if (!in_array($company, $companies, true)) {
        $company = $companies[0];
    }

    $ok = maand_delete($company, $ym);
    echo json_encode(['ok' => $ok], JSON_UNESCAPED_UNICODE);
    exit;
}

// Gebruikersinstellingen opslaan (kolomvolgorde)
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
    if (isset($decoded['maanden_column_order']) && is_array($decoded['maanden_column_order'])) {
        $patch['maanden_column_order'] = array_values(array_filter($decoded['maanden_column_order'], 'is_string'));
    }
    if (isset($decoded['selected_company']) && is_string($decoded['selected_company'])) {
        $companyValue = trim($decoded['selected_company']);
        if ($companyValue !== '' && in_array($companyValue, $companies, true)) {
            $patch['selected_company'] = $companyValue;
        }
    }
    $ok = save_user_settings_m($currentUserEmail, $patch);
    echo json_encode(['ok' => $ok], JSON_UNESCAPED_UNICODE);
    exit;
}

// Load saved months for this company
$savedMonths = list_saved_months($selectedCompany);

// Load cached data for each saved month (summary only)
$monthSummaries = [];
foreach ($savedMonths as $ym) {
    $data = maand_load($selectedCompany, $ym);
    if (!is_array($data)) {
        continue;
    }
    $monthSummaries[] = [
        'year_month' => $ym,
        'data_start_month' => (string) ($data['data_start_month'] ?? data_start_month_for_target($ym)),
        'total_revenue' => (float) ($data['total_revenue'] ?? 0),
        'total_costs' => (float) ($data['total_costs'] ?? 0),
        'fetched_at' => (string) ($data['fetched_at'] ?? ''),
    ];
}

// Build list of addable months: from earliest saved month -1 up to current month
$now = new DateTimeImmutable('first day of this month');
$currentMonth = $now->format('Y-m');
$prevMonth = $now->modify('-1 month');
$earliestSaved = null;
if ($savedMonths !== []) {
    // savedMonths is sorted newest first; last = oldest
    $oldestSaved = end($savedMonths);
    $earliestSaved = DateTimeImmutable::createFromFormat('!Y-m', $oldestSaved);
}

$addableMonths = [];
$cursor = $prevMonth;
$limit = 36; // max 3 years back
$count = 0;
while ($count < $limit) {
    $ym = $cursor->format('Y-m');
    if (!in_array($ym, $savedMonths, true)) {
        $addableMonths[] = $ym;
    }
    $cursor = $cursor->modify('-1 month');
    $count++;
    // Don't go back further than 3 years before the oldest saved month
    if ($earliestSaved !== null && $cursor < $earliestSaved->modify('-12 month')) {
        break;
    }
}

// Add current month if not already saved
if (!in_array($currentMonth, $savedMonths, true) && !in_array($currentMonth, $addableMonths, true)) {
    array_unshift($addableMonths, $currentMonth);
}

$savedColumnOrder = is_array($userSettings['maanden_column_order'] ?? null) ? $userSettings['maanden_column_order'] : [];
$targetScopedColumnKeys = bc_fetch_target_scoped_column_keys();

$initialData = [
    'companies' => $companies,
    'selected_company' => $selectedCompany,
    'target_scoped_column_keys' => $targetScopedColumnKeys,
    'month_summaries' => $monthSummaries,
    'addable_months' => $addableMonths,
    'current_month' => $currentMonth,
    'saved_column_order' => $savedColumnOrder,
    'refresh_url' => 'maanden.php?action=refresh_month',
    'project_numbers_url' => 'maanden.php?action=fetch_project_numbers_batch',
    'column_batch_url' => 'maanden.php?action=fetch_column_batch',
    'sub_planning_url' => 'maanden.php?action=fetch_sub_planning',
    'column_steps' => array_map(static function ($key, $cfg): array {
        return [
            'key' => (string) $key,
            'label' => (string) ($cfg['label'] ?? $key),
        ];
    }, array_keys(array_filter(bc_fetch_column_registry(), static function ($cfg, $key): bool {
        return (string) $key !== 'planning' && empty($cfg['target_scoped']);
    }, ARRAY_FILTER_USE_BOTH)), array_values(array_filter(bc_fetch_column_registry(), static function ($cfg, $key): bool {
        return (string) $key !== 'planning' && empty($cfg['target_scoped']);
    }, ARRAY_FILTER_USE_BOTH))),
    'target_column_steps' => array_map(static function ($key, $cfg): array {
        return [
            'key' => (string) $key,
            'label' => (string) ($cfg['label'] ?? $key),
        ];
    }, array_keys(array_filter(bc_fetch_column_registry(), static function ($cfg, $key): bool {
        return (string) $key !== 'planning' && !empty($cfg['target_scoped']);
    }, ARRAY_FILTER_USE_BOTH)), array_values(array_filter(bc_fetch_column_registry(), static function ($cfg, $key): bool {
        return (string) $key !== 'planning' && !empty($cfg['target_scoped']);
    }, ARRAY_FILTER_USE_BOTH))),
    'delete_url' => 'maanden.php?action=delete_month',
    'detail_url' => 'maand-detail.php',
    'save_settings_url' => 'maanden.php?action=save_user_settings',
];

function format_month_nl(string $yearMonth): string
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
    <title>Maandoverzicht</title>
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

        .page-header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 18px;
        }

        .page-header img {
            height: 48px;
            width: auto;
            flex-shrink: 0;
        }

        h1 {
            margin: 0;
            font-size: 24px;
            color: #1f2937;
        }

        .page-loader {
            position: fixed;
            inset: 0;
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
            background: rgba(244, 247, 251, 0.9);
        }

        .page-loader.is-visible {
            display: flex;
        }

        .page-loader-content {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            color: #203a63;
            font-weight: 600;
            max-height: 90vh;
        }

        .page-loader-spinner {
            width: 34px;
            height: 34px;
            border: 3px solid #c8d3e1;
            border-top-color: #1f4ea6;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            flex-shrink: 0;
        }

        .batch-progress-list {
            list-style: none;
            margin: 0;
            padding: 0;
            width: min(380px, 88vw);
            max-height: 55vh;
            overflow-y: auto;
            background: #fff;
            border: 1px solid #dbe3ee;
            border-radius: 10px;
            display: none;
            flex-direction: column;
            font-size: 13px;
            font-weight: 400;
            box-shadow: 0 4px 12px rgba(15, 23, 42, .10);
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        .batch-progress-list::-webkit-scrollbar {
            width: 0;
            height: 0;
            display: none;
        }

        .batch-progress-list.is-visible {
            display: flex;
        }

        .batch-progress-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 7px 12px;
            border-bottom: 1px solid #f1f5fb;
            color: #94a3b8;
        }

        .batch-progress-item:last-child {
            border-bottom: none;
        }

        .batch-progress-item span:nth-child(2) {
            flex: 1 1 auto;
            min-width: 0;
        }

        .batch-progress-pct {
            flex-shrink: 0;
            font-variant-numeric: tabular-nums;
            color: #64748b;
            min-width: 38px;
            text-align: right;
        }

        .batch-progress-item.is-done {
            color: #0b6b2f;
        }

        .batch-progress-item.is-loading {
            color: #1f4ea6;
            font-weight: 600;
        }

        .batch-progress-icon {
            flex-shrink: 0;
            width: 16px;
            text-align: center;
            font-size: 13px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .batch-progress-item-spinner {
            width: 12px;
            height: 12px;
            border: 2px solid #c8d3e1;
            border-top-color: #1f4ea6;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        .batch-progress-section {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 8px 12px;
            background: #f8fafc;
            border-bottom: 1px solid #e7edf5;
            color: #334155;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .batch-progress-section-pct {
            color: #1f4ea6;
            font-variant-numeric: tabular-nums;
            font-weight: 700;
        }

        .batch-progress-divider {
            height: 1px;
            margin: 2px 0;
            background: linear-gradient(90deg, transparent, #dbe3ee 12%, #dbe3ee 88%, transparent);
            border: 0;
            padding: 0;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .controls {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
            margin-bottom: 24px;
            padding: 14px;
            background: #ffffff;
            border: 1px solid #dbe3ee;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(15, 23, 42, .06);
        }

        .controls label {
            font-weight: 600;
            color: #334155;
        }

        .controls select {
            font: inherit;
            border: 1px solid #c8d3e1;
            border-radius: 8px;
            padding: 7px 10px;
            background: #fff;
        }

        .month-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .month-card {
            background: #fff;
            border: 1px solid #dbe3ee;
            border-radius: 12px;
            padding: 18px;
            box-shadow: 0 2px 8px rgba(15, 23, 42, .06);
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .month-card-title {
            font-size: 18px;
            font-weight: 700;
            color: #1f355a;
        }

        .month-card-stats {
            display: flex;
            flex-direction: column;
            gap: 4px;
            font-size: 13px;
        }

        .month-card-stat-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
        }

        .month-card-stat-label {
            color: #475569;
        }

        .month-card-stat-value {
            font-weight: 700;
            font-variant-numeric: tabular-nums;
        }

        .stat-positive {
            color: #0b6b2f;
        }

        .stat-negative {
            color: #b42318;
        }

        .month-card-fetched {
            font-size: 11px;
            color: #94a3b8;
        }

        .month-card-actions {
            display: flex;
            gap: 8px;
            margin-top: 4px;
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

        .btn-danger {
            background: #fee2e2;
            border-color: #fca5a5;
            color: #b42318;
        }

        .btn-danger:hover {
            background: #fecaca;
        }

        .add-card {
            background: #f8fafc;
            border: 2px dashed #c8d3e1;
            border-radius: 12px;
            padding: 18px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            align-items: flex-start;
        }

        .add-card-title {
            font-size: 15px;
            font-weight: 700;
            color: #334155;
        }

        .add-card-row {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .add-card select {
            font: inherit;
            border: 1px solid #c8d3e1;
            border-radius: 8px;
            padding: 7px 10px;
            background: #fff;
        }

        .add-card select option[data-is-current-month="true"] {
            background-color: #fed7aa;
            color: #92400e;
        }

        .confirm-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, .45);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1500;
            padding: 20px;
        }

        .confirm-overlay.is-hidden {
            display: none;
        }

        .confirm-modal {
            background: #fff;
            border-radius: 12px;
            padding: 24px;
            min-width: 320px;
            max-width: 460px;
            box-shadow: 0 20px 40px rgba(15, 23, 42, .2);
        }

        .confirm-modal-title {
            font-size: 16px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 12px;
        }

        .confirm-modal-text {
            margin-bottom: 18px;
            color: #475569;
        }

        .confirm-modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        .toast-container {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 3000;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .toast {
            background: #1f355a;
            color: #fff;
            padding: 10px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            box-shadow: 0 4px 12px rgba(15, 23, 42, .25);
            opacity: 1;
            transition: opacity .4s ease;
            max-width: min(420px, calc(100vw - 40px));
        }

        .toast.is-error {
            background: #b42318;
            cursor: pointer;
            padding: 12px 14px;
        }

        .toast-hint {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .02em;
            opacity: .9;
            margin-bottom: 6px;
        }

        .toast-preview {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .toast-details {
            display: none;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid rgba(255, 255, 255, .28);
            font-family: Consolas, Monaco, monospace;
            font-size: 12px;
            font-weight: 500;
            line-height: 1.45;
            white-space: pre-wrap;
            word-break: break-word;
            max-height: min(45vh, 360px);
            overflow: auto;
        }

        .toast.is-error.is-expanded .toast-preview {
            white-space: pre-wrap;
        }

        .toast.is-error.is-expanded .toast-details {
            display: block;
        }

        .toast.fade-out {
            opacity: 0;
        }

        .empty-state {
            padding: 32px;
            text-align: center;
            color: #64748b;
            font-size: 15px;
        }

        @media (max-width: 500px) {
            .month-grid {
                grid-template-columns: 1fr;
            }

            .month-card-actions {
                flex-wrap: wrap;
            }

            .toast-container {
                left: 12px;
                right: 12px;
                bottom: 12px;
            }

            .toast {
                max-width: none;
            }
        }
    </style>
</head>

<body>
    <div id="pageLoader" class="page-loader" aria-live="polite">
        <div class="page-loader-content">
            <div class="page-loader-spinner" aria-hidden="true"></div>
            <div id="pageLoaderText">Bezig...</div>
            <ul class="batch-progress-list" id="batchProgressList"></ul>
        </div>
    </div>

    <?= injectTimerHtml([
        'statusUrl' => 'odata.php?action=cache_status',
        'title' => 'Cachebestanden',
        'label' => 'Cache',
    ]) ?>

    <div class="page-header">
        <img src="logo-website.png" alt="Logo">
        <h1>Maandoverzicht</h1>
    </div>

    <div class="controls">
        <label for="companySelect">Bedrijf</label>
        <select id="companySelect" name="company">
            <?php foreach ($companies as $c): ?>
                <option value="<?= htmlspecialchars($c) ?>" <?= $c === $selectedCompany ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div id="monthGrid" class="month-grid"></div>

    <div class="confirm-overlay is-hidden" id="confirmOverlay">
        <div class="confirm-modal">
            <div class="confirm-modal-title" id="confirmTitle">Bevestiging</div>
            <div class="confirm-modal-text" id="confirmText"></div>
            <div class="confirm-modal-actions">
                <button type="button" class="btn btn-secondary" id="confirmCancel">Annuleren</button>
                <button type="button" class="btn btn-primary" id="confirmOk">Bevestigen</button>
            </div>
        </div>
    </div>

    <div class="toast-container" id="toastContainer"></div>

    <script>
        window.maandenData = <?= json_encode($initialData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <script src="maanden.js"></script>
</body>

</html>