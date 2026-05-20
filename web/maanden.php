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
    $target = DateTimeImmutable::createFromFormat('!Y-m', $targetYearMonth);
    if (!$target instanceof DateTimeImmutable) {
        return $targetYearMonth;
    }
    return $target->modify('-35 months')->format('Y-m');
}

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
 * Haalt werkorders op voor één maand en voegt ze toe aan de WIP-cache.
 * Retourneert de werkorder-array voor die maand.
 */
function fetch_workorders_for_batch_month(string $company, string $batchYearMonth, array $auth, int $ttl): array
{
    global $baseUrl;
    
    $environmentForCompany = auth_get_environment_for_company($company, 300);
    $auth = auth_get_auth_for_environment($environmentForCompany);

    $from = DateTimeImmutable::createFromFormat('!Y-m', $batchYearMonth);
    if (!$from instanceof DateTimeImmutable) {
        return [];
    }
    $to = $from->modify('+1 month');
    $fromStr = $from->format('Y-m-d');
    $toStr = $to->format('Y-m-d');

    $workorderUrl = company_entity_url_with_query($baseUrl, $environmentForCompany, $company, 'Werkorders', [
        '$select' => 'No,Task_Code,Task_Description,Status,KVT_Document_Status,Job_No,Job_Task_No,Contract_No,Start_Date,End_Date,Bill_to_Customer_No,Bill_to_Name,Sell_to_Customer_No,Sell_to_Name,Job_Dimension_1_Value,Memo,Memo_Internal_Use_Only,Memo_Invoice,KVT_Memo_Invoice_Details,KVT_Remarks_Invoicing,LVS_Show_on_Planboard,LVS_Fixed_Planned',
        '$filter' => 'Start_Date ge ' . $fromStr . ' and Start_Date lt ' . $toStr,
    ]);

    return odata_get_all($workorderUrl, $auth, $ttl);
}

/**
 * Haalt ALLE ProjectPosten-rijen op voor exact één maand (alleen datumfilter).
 */
function fetch_projectposten_for_batch_month(string $company, string $batchYearMonth, array $auth, int $ttl): array
{
    global $baseUrl;
    
    $environmentForCompany = auth_get_environment_for_company($company, 300);
    $auth = auth_get_auth_for_environment($environmentForCompany);

    $from = DateTimeImmutable::createFromFormat('!Y-m', $batchYearMonth);
    if (!$from instanceof DateTimeImmutable) {
        return [];
    }
    $to = $from->modify('+1 month');
    $fromStr = $from->format('Y-m-d');
    $toStr = $to->format('Y-m-d');

    $projectPostenUrl = company_entity_url_with_query($baseUrl, $environmentForCompany, $company, 'ProjectPosten', [
        '$filter' => 'Posting_Date ge ' . $fromStr . ' and Posting_Date lt ' . $toStr,
    ]);

    return odata_get_all($projectPostenUrl, $auth, $ttl);
}

/**
 * Aggregateert lokaal opgehaalde ProjectPosten-rijen naar project- en werkordertotalen.
 */
function aggregate_projectposten_rows(array $rows): array
{
    $projectTotalsByJob = [];
    $workorderTotalsByNumber = [];
    $projectNumbers = [];
    $workorderNumbers = [];
    $seenProjectNos = [];
    $seenWorkorderNos = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $jobNo = trim((string) ($row['Job_No'] ?? ''));
        $jobTaskNo = trim((string) ($row['Job_Task_No'] ?? ''));
        $normJob = strtolower($jobNo);
        $normWorkorder = strtolower($jobTaskNo);

        $costAmount = finance_extract_row_amount($row, ['Total_Cost'], finance_normalize_row_mode('sum_raw'));
        $entryType = strtolower(trim((string) ($row['Entry_Type'] ?? $row['Type'] ?? '')));
        $isGebruikEntryType = $entryType === 'gebruik';
        $revenueAmount = $isGebruikEntryType
            ? 0.0
            : finance_extract_row_amount($row, ['Line_Amount'], finance_normalize_row_mode('sum_invert'));

        if ($jobNo !== '') {
            if (!isset($projectTotalsByJob[$normJob])) {
                $projectTotalsByJob[$normJob] = ['costs' => 0.0, 'revenue' => 0.0, 'resultaat' => 0.0];
            }

            $projectTotalsByJob[$normJob]['costs'] = finance_add_amount(
                (float) ($projectTotalsByJob[$normJob]['costs'] ?? 0.0),
                $costAmount
            );
            $projectTotalsByJob[$normJob]['revenue'] = finance_add_amount(
                (float) ($projectTotalsByJob[$normJob]['revenue'] ?? 0.0),
                $revenueAmount
            );
            $projectTotalsByJob[$normJob]['resultaat'] = finance_calculate_result(
                (float) ($projectTotalsByJob[$normJob]['revenue'] ?? 0.0),
                (float) ($projectTotalsByJob[$normJob]['costs'] ?? 0.0)
            );

            if (!isset($seenProjectNos[$jobNo])) {
                $seenProjectNos[$jobNo] = true;
                $projectNumbers[] = $jobNo;
            }
        }

        if ($jobTaskNo !== '') {
            if (!isset($workorderTotalsByNumber[$normWorkorder])) {
                $workorderTotalsByNumber[$normWorkorder] = ['costs' => 0.0, 'revenue' => 0.0, 'resultaat' => 0.0];
            }

            $workorderTotalsByNumber[$normWorkorder]['costs'] = finance_add_amount(
                (float) ($workorderTotalsByNumber[$normWorkorder]['costs'] ?? 0.0),
                $costAmount
            );
            $workorderTotalsByNumber[$normWorkorder]['revenue'] = finance_add_amount(
                (float) ($workorderTotalsByNumber[$normWorkorder]['revenue'] ?? 0.0),
                $revenueAmount
            );
            $workorderTotalsByNumber[$normWorkorder]['resultaat'] = finance_calculate_result(
                (float) ($workorderTotalsByNumber[$normWorkorder]['revenue'] ?? 0.0),
                (float) ($workorderTotalsByNumber[$normWorkorder]['costs'] ?? 0.0)
            );

            if (!isset($seenWorkorderNos[$jobTaskNo])) {
                $seenWorkorderNos[$jobTaskNo] = true;
                $workorderNumbers[] = $jobTaskNo;
            }
        }
    }

    return [
        'project_totals_by_job' => $projectTotalsByJob,
        'workorder_totals_by_number' => $workorderTotalsByNumber,
        'project_numbers' => $projectNumbers,
        'workorder_numbers' => $workorderNumbers,
    ];
}

/**
 * Haalt project- en werkordersleutels op uit ProjectPosten voor dezelfde datarange als de maand-snapshot.
 */
function fetch_projectposten_keys_for_target(string $company, string $targetYearMonth, array $auth, int $ttl): array
{
    global $baseUrl;
    
    $environmentForCompany = auth_get_environment_for_company($company, 300);
    $auth = auth_get_auth_for_environment($environmentForCompany);

    $startYearMonth = data_start_month_for_target($targetYearMonth);
    $from = DateTimeImmutable::createFromFormat('!Y-m', $startYearMonth);
    $to = DateTimeImmutable::createFromFormat('!Y-m', $targetYearMonth);
    if (!$from instanceof DateTimeImmutable || !$to instanceof DateTimeImmutable) {
        return [
            'project_numbers' => [],
            'workorder_numbers' => [],
        ];
    }

    $fromStr = $from->format('Y-m-d');
    $toStr = $to->modify('+1 month')->format('Y-m-d');

    try {
        $projectPostenUrl = company_entity_url_with_query($baseUrl, $environmentForCompany, $company, 'ProjectPosten', [
            '$select' => 'Job_No,Job_Task_No',
            '$filter' => 'Posting_Date ge ' . $fromStr . ' and Posting_Date lt ' . $toStr,
        ]);
        $rows = odata_get_all($projectPostenUrl, $auth, $ttl);
    } catch (Throwable $ignoredProjectPostenLoadError) {
        return [
            'project_numbers' => [],
            'workorder_numbers' => [],
        ];
    }

    $projectNumbers = [];
    $workorderNumbers = [];
    $seenProjectNos = [];
    $seenWorkorderNos = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $jobNo = trim((string) ($row['Job_No'] ?? ''));
        if ($jobNo !== '' && !isset($seenProjectNos[$jobNo])) {
            $seenProjectNos[$jobNo] = true;
            $projectNumbers[] = $jobNo;
        }

        $jobTaskNo = trim((string) ($row['Job_Task_No'] ?? ''));
        if ($jobTaskNo !== '' && !isset($seenWorkorderNos[$jobTaskNo])) {
            $seenWorkorderNos[$jobTaskNo] = true;
            $workorderNumbers[] = $jobTaskNo;
        }
    }

    return [
        'project_numbers' => $projectNumbers,
        'workorder_numbers' => $workorderNumbers,
    ];
}

/**
 * Bouwt werkorderrijen, projectsamenvattingen en totalen vanuit eerder opgehaalde data.
 * Doet geen OData-calls; alle benodigde data wordt als parameter meegegeven.
 */
function build_month_rows(
    string $company,
    string $yearMonth,
    array $workorders,
    array $projectTotalsByJob,
    array $invoiceIdsByJob,
    array $invoicedTotalByJob,
    array $invoiceDetailsById,
    array $workorderTotalsByNumber,
    array $projectDetails,
    array $planningTotalsByJob,
    array $planningBreakdownByJob
): array {
    $rows = [];
    foreach ($workorders as $wo) {
        if (!is_array($wo)) {
            continue;
        }

        $jobNo = trim((string) ($wo['Job_No'] ?? ''));
        $normJob = strtolower($jobNo);

        $jobTaskNo = trim((string) ($wo['Job_Task_No'] ?? ''));
        $normWorkorder = strtolower($jobTaskNo);
        $workorderTotals = $workorderTotalsByNumber[$normWorkorder] ?? [
            'costs' => 0.0,
            'revenue' => 0.0,
            'resultaat' => 0.0,
        ];
        $projectTotals = $projectTotalsByJob[$normJob] ?? [
            'costs' => 0.0,
            'revenue' => 0.0,
            'resultaat' => 0.0,
        ];
        $costs = (float) ($workorderTotals['costs'] ?? 0.0);
        $revenue = (float) ($workorderTotals['revenue'] ?? 0.0);
        $projectCosts = (float) ($projectTotals['costs'] ?? 0.0);
        $projectRevenue = (float) ($projectTotals['revenue'] ?? 0.0);
        $invoicedTotal = (float) ($invoicedTotalByJob[$normJob] ?? 0.0);
        $notesParts = [
            ['label' => 'KVT_Memo', 'value' => trim((string) ($wo['Memo'] ?? ''))],
            ['label' => 'KVT_Memo_Internal_Use_Only', 'value' => trim((string) ($wo['Memo_Internal_Use_Only'] ?? ''))],
            ['label' => 'KVT_Memo_Invoice', 'value' => trim((string) ($wo['Memo_Invoice'] ?? ''))],
            ['label' => 'KVT_Memo_Billing_Details', 'value' => trim((string) ($wo['KVT_Memo_Invoice_Details'] ?? ''))],
            ['label' => 'KVT_Remarks_Invoicing', 'value' => trim((string) ($wo['KVT_Remarks_Invoicing'] ?? ''))],
        ];
        $rows[] = [
            'No' => (string) ($wo['No'] ?? ''),
            'Task_Code' => (string) ($wo['Task_Code'] ?? ''),
            'Description' => (string) ($wo['Task_Description'] ?? ''),
            'Status' => (string) ($wo['Status'] ?? ''),
            'Document_Status' => (string) ($wo['KVT_Document_Status'] ?? ''),
            'Job_No' => $jobNo,
            'Job_Task_No' => $jobTaskNo,
            'Contract_No' => (string) ($wo['Contract_No'] ?? ''),
            'Start_Date' => (string) ($wo['Start_Date'] ?? ''),
            'End_Date' => (string) ($wo['End_Date'] ?? ''),
            'Customer_Id' => (string) ($wo['Bill_to_Customer_No'] ?? ''),
            'Customer_Name' => (string) ($wo['Bill_to_Name'] ?? ''),
            'Cost_Center' => (string) ($wo['Job_Dimension_1_Value'] ?? ''),
            'Actual_Costs' => $costs,
            'Total_Revenue' => $revenue,
            'Project_Actual_Costs' => $projectCosts,
            'Project_Total_Revenue' => $projectRevenue,
            'Invoiced_Total' => $invoicedTotal,
            'Invoice_Ids' => $invoiceIdsByJob[$normJob] ?? [],
            'Notes' => $notesParts,
        ];
    }

    $totalRevenue = 0.0;
    $totalCosts = 0.0;
    $projectRows = [];

    $projectKeys = array_values(array_unique(array_merge(
        array_keys($projectTotalsByJob),
        array_keys($planningTotalsByJob),
        array_keys($projectDetails),
        array_keys($invoiceIdsByJob),
        array_keys($invoicedTotalByJob)
    )));
    foreach ($rows as $row) {
        $jobNo = trim((string) ($row['Job_No'] ?? ''));
        $normJob = strtolower($jobNo);
        if ($normJob !== '') {
            $projectKeys[] = $normJob;
        }
    }
    $projectKeys = array_values(array_unique($projectKeys));

    foreach ($projectKeys as $normJob) {
        $normJob = strtolower(trim((string) $normJob));
        if ($normJob === '') {
            continue;
        }

        $proj = $projectDetails[$normJob] ?? null;
        $jobNo = trim((string) (($proj['No'] ?? '') ?: strtoupper($normJob)));
        $invoicedTotal = $invoicedTotalByJob[$normJob] ?? 0.0;
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
            'Invoiced_Total' => $invoicedTotal,
            'Invoice_Ids' => $invoiceIdsByJob[$normJob] ?? [],
            'Workorders' => [],
            'Breakdown' => [
                'total_costs_lines' => [],
                'total_revenue_lines' => [],
                'expected_revenue_lines' => is_array($planningBreakdown['expected_revenue_lines'] ?? null) ? $planningBreakdown['expected_revenue_lines'] : [],
                'expected_costs_lines' => is_array($planningBreakdown['expected_costs_lines'] ?? null) ? $planningBreakdown['expected_costs_lines'] : [],
                'extra_work_lines' => is_array($planningBreakdown['extra_work_lines'] ?? null) ? $planningBreakdown['extra_work_lines'] : [],
            ],
        ];
    }

    foreach ($rows as $row) {
        $jobNo = (string) ($row['Job_No'] ?? '');
        $normJob = strtolower($jobNo);
        if ($normJob === '' || !isset($projectRows[$normJob])) {
            continue;
        }

        if (trim((string) ($projectRows[$normJob]['Description'] ?? '')) === '') {
            $projectRows[$normJob]['Description'] = (string) ($row['Description'] ?? '');
        }
        if (trim((string) ($projectRows[$normJob]['Customer_Id'] ?? '')) === '') {
            $projectRows[$normJob]['Customer_Id'] = (string) ($row['Customer_Id'] ?? '');
        }
        if (trim((string) ($projectRows[$normJob]['Customer_Name'] ?? '')) === '') {
            $projectRows[$normJob]['Customer_Name'] = (string) ($row['Customer_Name'] ?? '');
        }
        if (trim((string) ($projectRows[$normJob]['Cost_Center'] ?? '')) === '') {
            $projectRows[$normJob]['Cost_Center'] = (string) ($row['Cost_Center'] ?? '');
        }

        $projectRows[$normJob]['Workorders'][] = $row;
        $projectRows[$normJob]['Breakdown']['total_costs_lines'][] = [
            'Workorder_No' => (string) ($row['No'] ?? ''),
            'Status' => (string) ($row['Status'] ?? ''),
            'Description' => (string) ($row['Description'] ?? ''),
            'Amount' => (float) ($row['Actual_Costs'] ?? 0),
        ];
        $projectRows[$normJob]['Breakdown']['total_revenue_lines'][] = [
            'Workorder_No' => (string) ($row['No'] ?? ''),
            'Status' => (string) ($row['Status'] ?? ''),
            'Description' => (string) ($row['Description'] ?? ''),
            'Amount' => (float) ($row['Total_Revenue'] ?? 0),
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
        'workorder_rows' => $rows,
        'invoice_details_by_id' => $invoiceDetailsById,
    ];
}

function fetch_month_data(string $company, string $yearMonth, array $auth): array
{
    global $baseUrl;
    
    $environmentForCompany = auth_get_environment_for_company($company, 300);
    $auth = auth_get_auth_for_environment($environmentForCompany);
    @set_time_limit(0);
    @ini_set('max_execution_time', '0');

    // Collect all workorders from WIP cache (already fetched batch months) plus current month
    $wip = batch_wip_load($company, $yearMonth);
    $wipWorkorders = is_array($wip['workorders'] ?? null) ? $wip['workorders'] : [];
    $wipDone = is_array($wip['done_months'] ?? null) ? $wip['done_months'] : [];

    // Also fetch the target month itself (may already be in WIP if batch completed)
    if (!in_array($yearMonth, $wipDone, true)) {
        $targetMonthWOs = fetch_workorders_for_batch_month($company, $yearMonth, $auth, odata_ttl_for_month($yearMonth));
        $wipWorkorders = array_merge($wipWorkorders, $targetMonthWOs);
    }

    $workorders = $wipWorkorders;

    $projectPostenByMonth = is_array($wip['projectposten_by_month'] ?? null)
        ? $wip['projectposten_by_month']
        : [];
    $allBatchMonths = batch_months_for_target($yearMonth);
    foreach ($allBatchMonths as $batchYm) {
        if (!is_array($projectPostenByMonth[$batchYm] ?? null)) {
            try {
                $projectPostenByMonth[$batchYm] = fetch_projectposten_for_batch_month($company, $batchYm, $auth, odata_ttl_for_month($batchYm));
            } catch (Throwable $projectPostenLoadError) {
                throw new RuntimeException('ProjectPosten-data kon niet worden opgehaald voor maand ' . $batchYm . '.');
            }
        }
    }

    $projectPostenRows = [];
    $projectPostenCountsByMonth = [];
    foreach ($projectPostenByMonth as $monthKey => $monthRows) {
        $projectPostenCountsByMonth[(string) $monthKey] = is_array($monthRows) ? count($monthRows) : 0;
        if (is_array($monthRows)) {
            $projectPostenRows = array_merge($projectPostenRows, $monthRows);
        }
    }

    $aggregatedFinance = aggregate_projectposten_rows($projectPostenRows);
    $projectTotalsByJob = is_array($aggregatedFinance['project_totals_by_job'] ?? null)
        ? $aggregatedFinance['project_totals_by_job']
        : [];
    $workorderTotalsByNumber = is_array($aggregatedFinance['workorder_totals_by_number'] ?? null)
        ? $aggregatedFinance['workorder_totals_by_number']
        : [];
    $ppProjectNumbers = is_array($aggregatedFinance['project_numbers'] ?? null)
        ? $aggregatedFinance['project_numbers']
        : [];
    $ppWorkorderNumbers = is_array($aggregatedFinance['workorder_numbers'] ?? null)
        ? $aggregatedFinance['workorder_numbers']
        : [];

    // Collect project and workorder numbers
    $projectNumbers = [];
    $seenProjectNos = [];
    $workorderNumbers = [];
    $seenWorkorderNos = [];
    foreach ($workorders as $wo) {
        if (!is_array($wo)) {
            continue;
        }

        $jNo = trim((string) ($wo['Job_No'] ?? ''));
        if ($jNo !== '' && !isset($seenProjectNos[$jNo])) {
            $seenProjectNos[$jNo] = true;
            $projectNumbers[] = $jNo;
        }

        $jobTaskNo = trim((string) ($wo['Job_Task_No'] ?? ''));
        if ($jobTaskNo !== '' && !isset($seenWorkorderNos[$jobTaskNo])) {
            $seenWorkorderNos[$jobTaskNo] = true;
            $workorderNumbers[] = $jobTaskNo;
        }
    }

    $environmentForCompany = auth_get_environment_for_company($company, 300);
    $financeService = new ProjectFinanceService($company, $environmentForCompany);

    foreach ($ppProjectNumbers as $pNo) {
        $pNo = trim((string) $pNo);
        if ($pNo !== '' && !isset($seenProjectNos[$pNo])) {
            $seenProjectNos[$pNo] = true;
            $projectNumbers[] = $pNo;
        }
    }

    foreach ($ppWorkorderNumbers as $woNo) {
        $woNo = trim((string) $woNo);
        if ($woNo !== '' && !isset($seenWorkorderNos[$woNo])) {
            $seenWorkorderNos[$woNo] = true;
            $workorderNumbers[] = $woNo;
        }
    }

    $projectInvoiceData = [
        'invoice_details_by_id' => [],
        'project_invoice_ids_by_job' => [],
        'project_invoiced_total_by_job' => [],
    ];

    try {
        if ($projectNumbers !== []) {
            $projectInvoiceData = $financeService->collectProjectInvoicesForProjects($projectNumbers, odata_ttl_for_month($yearMonth));
        }
    } catch (Throwable $invoiceError) {
        throw new RuntimeException('Factuurdata kon niet worden opgehaald.');
    }

    $invoiceIdsByJob = is_array($projectInvoiceData['project_invoice_ids_by_job'] ?? null)
        ? $projectInvoiceData['project_invoice_ids_by_job']
        : [];
    $invoicedTotalByJob = is_array($projectInvoiceData['project_invoiced_total_by_job'] ?? null)
        ? $projectInvoiceData['project_invoiced_total_by_job']
        : [];
    $invoiceDetailsById = is_array($projectInvoiceData['invoice_details_by_id'] ?? null)
        ? $projectInvoiceData['invoice_details_by_id']
        : [];

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
        $workorders,
        $projectTotalsByJob,
        $invoiceIdsByJob,
        $invoicedTotalByJob,
        $invoiceDetailsById,
        $workorderTotalsByNumber,
        $projectDetails,
        $planningTotalsByJob,
        $planningBreakdownByJob
    );

    $result['projectposten_debug'] = [
        'months_loaded' => array_values(array_map('strval', array_keys($projectPostenByMonth))),
        'rows_per_month' => $projectPostenCountsByMonth,
        'rows_total' => count($projectPostenRows),
        'projects_from_projectposten' => count($ppProjectNumbers),
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
    $allBatchMonths = batch_months_for_target($targetYm);
    $projectNumbersByMonth = is_array($wip['project_numbers_by_month'] ?? null)
        ? $wip['project_numbers_by_month']
        : [];
    $columnDataByMonth = is_array($wip['column_data_by_month'] ?? null)
        ? $wip['column_data_by_month']
        : [];

    $workorders = [];
    $projectTotalsByJob = [];
    $workorderTotalsByNumber = [];
    $projectDetails = [];
    $planningTotalsByJob = is_array($wip['planning_totals_by_job'] ?? null)
        ? $wip['planning_totals_by_job']
        : [];
    $planningBreakdownByJob = is_array($wip['planning_breakdown_by_job'] ?? null)
        ? $wip['planning_breakdown_by_job']
        : [];
    $invoiceIdsByJob = [];
    $invoicedTotalByJob = [];
    $invoiceDetailsById = [];
    $projectPostenCostLinesByProject = [];
    $projectPostenRevenueLinesByProject = [];
    $projectNumbers = [];
    $seenProjectNos = [];

    foreach ($allBatchMonths as $batchYm) {
        $monthProjectNumbers = is_array($projectNumbersByMonth[$batchYm] ?? null)
            ? $projectNumbersByMonth[$batchYm]
            : [];
        foreach ($monthProjectNumbers as $projectNo) {
            $projectNoText = trim((string) $projectNo);
            if ($projectNoText === '' || isset($seenProjectNos[$projectNoText])) {
                continue;
            }
            $seenProjectNos[$projectNoText] = true;
            $projectNumbers[] = $projectNoText;
        }

        $monthColumns = is_array($columnDataByMonth[$batchYm] ?? null)
            ? $columnDataByMonth[$batchYm]
            : [];

        $workorderColumn = is_array($monthColumns['workorders'] ?? null) ? $monthColumns['workorders'] : [];
        $monthWorkorders = is_array($workorderColumn['all_rows'] ?? null) ? $workorderColumn['all_rows'] : [];
        if ($monthWorkorders !== []) {
            $workorders = array_merge($workorders, $monthWorkorders);
        }

        $projectPostenColumn = is_array($monthColumns['projectposten'] ?? null) ? $monthColumns['projectposten'] : [];
        $byProject = is_array($projectPostenColumn['by_project'] ?? null) ? $projectPostenColumn['by_project'] : [];
        foreach ($byProject as $normProjectNo => $projectValues) {
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

            if (!isset($projectPostenCostLinesByProject[$normProjectNo])) {
                $projectPostenCostLinesByProject[$normProjectNo] = [];
            }
            if (!isset($projectPostenRevenueLinesByProject[$normProjectNo])) {
                $projectPostenRevenueLinesByProject[$normProjectNo] = [];
            }

            $sourceRows = is_array($projectValues['rows'] ?? null) ? $projectValues['rows'] : [];
            foreach ($sourceRows as $sourceRow) {
                if (!is_array($sourceRow)) {
                    continue;
                }

                $costValue = finance_to_float($sourceRow['Total_Cost'] ?? 0.0);
                if ($costValue !== 0.0) {
                    $projectPostenCostLinesByProject[$normProjectNo][] = [
                        'Posting_Date' => (string) ($sourceRow['Posting_Date'] ?? ''),
                        'Job_Task_No' => (string) ($sourceRow['Job_Task_No'] ?? ''),
                        'Entry_Type' => (string) ($sourceRow['Entry_Type'] ?? ''),
                        'No' => (string) ($sourceRow['No'] ?? ''),
                        'Description' => (string) ($sourceRow['Description'] ?? ''),
                        'Total_Cost' => $costValue,
                    ];
                }

                $lineAmount = finance_to_float($sourceRow['Line_Amount'] ?? 0.0);
                $revenueValue = -1 * $lineAmount;
                $jobTaskNo = trim((string) ($sourceRow['Job_Task_No'] ?? ''));
                $entryType = strtolower(trim((string) ($sourceRow['Entry_Type'] ?? $sourceRow['Type'] ?? '')));
                $isGebruikEntryType = $entryType === 'gebruik';
                $matchesRevenueTaskFilter = !REVENUE_DETAIL_TASK_FILTER_ENABLED || $jobTaskNo === REVENUE_DETAIL_TASK_CODE;
                if ($revenueValue !== 0.0 && !$isGebruikEntryType && $matchesRevenueTaskFilter) {
                    $projectPostenRevenueLinesByProject[$normProjectNo][] = [
                        'Posting_Date' => (string) ($sourceRow['Posting_Date'] ?? ''),
                        'Job_Task_No' => $jobTaskNo,
                        'Entry_Type' => (string) ($sourceRow['Entry_Type'] ?? ''),
                        'No' => (string) ($sourceRow['No'] ?? ''),
                        'Description' => (string) ($sourceRow['Description'] ?? ''),
                        'Line_Amount' => $revenueValue,
                    ];
                }
            }
        }

        $byWorkorder = is_array($projectPostenColumn['by_workorder'] ?? null) ? $projectPostenColumn['by_workorder'] : [];
        foreach ($byWorkorder as $normWorkorderNo => $workorderValues) {
            if (!isset($workorderTotalsByNumber[$normWorkorderNo])) {
                $workorderTotalsByNumber[$normWorkorderNo] = [
                    'costs' => 0.0,
                    'revenue' => 0.0,
                    'resultaat' => 0.0,
                ];
            }

            $workorderTotalsByNumber[$normWorkorderNo]['costs'] = finance_add_amount(
                (float) ($workorderTotalsByNumber[$normWorkorderNo]['costs'] ?? 0.0),
                finance_to_float($workorderValues['costs'] ?? 0.0)
            );
            $workorderTotalsByNumber[$normWorkorderNo]['revenue'] = finance_add_amount(
                (float) ($workorderTotalsByNumber[$normWorkorderNo]['revenue'] ?? 0.0),
                finance_to_float($workorderValues['revenue'] ?? 0.0)
            );
            $workorderTotalsByNumber[$normWorkorderNo]['resultaat'] = finance_calculate_result(
                (float) ($workorderTotalsByNumber[$normWorkorderNo]['revenue'] ?? 0.0),
                (float) ($workorderTotalsByNumber[$normWorkorderNo]['costs'] ?? 0.0)
            );
        }

        $projectDetailsColumn = is_array($monthColumns['project_details'] ?? null) ? $monthColumns['project_details'] : [];
        $projectDetailsByProject = is_array($projectDetailsColumn['by_project'] ?? null) ? $projectDetailsColumn['by_project'] : [];
        foreach ($projectDetailsByProject as $normProjectNo => $projectValue) {
            $row = is_array($projectValue['row'] ?? null) ? $projectValue['row'] : [];
            if ($row !== []) {
                $projectDetails[$normProjectNo] = $row;
            }
        }

        $invoiceColumn = is_array($monthColumns['invoices'] ?? null) ? $monthColumns['invoices'] : [];
        $invoiceByProject = is_array($invoiceColumn['by_project'] ?? null) ? $invoiceColumn['by_project'] : [];
        foreach ($invoiceByProject as $normProjectNo => $invoiceValue) {
            if (!isset($invoiceIdsByJob[$normProjectNo])) {
                $invoiceIdsByJob[$normProjectNo] = [];
            }

            $monthIds = is_array($invoiceValue['invoice_ids'] ?? null) ? $invoiceValue['invoice_ids'] : [];
            $invoiceIdsByJob[$normProjectNo] = array_values(array_unique(array_merge($invoiceIdsByJob[$normProjectNo], $monthIds)));
            $invoicedTotalByJob[$normProjectNo] = finance_to_float($invoiceValue['invoiced_total'] ?? ($invoicedTotalByJob[$normProjectNo] ?? 0.0));
        }

        $monthInvoiceDetails = is_array($invoiceColumn['invoice_details_by_id'] ?? null)
            ? $invoiceColumn['invoice_details_by_id']
            : [];
        foreach ($monthInvoiceDetails as $invoiceId => $invoiceDetail) {
            $invoiceDetailsById[(string) $invoiceId] = $invoiceDetail;
        }
    }

    $data = build_month_rows(
        $company,
        $targetYm,
        $workorders,
        $projectTotalsByJob,
        $invoiceIdsByJob,
        $invoicedTotalByJob,
        $invoiceDetailsById,
        $workorderTotalsByNumber,
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

        $breakdown['total_costs_lines'] = is_array($projectPostenCostLinesByProject[$normProjectNo] ?? null)
            ? $projectPostenCostLinesByProject[$normProjectNo]
            : [];
        $breakdown['total_revenue_lines'] = is_array($projectPostenRevenueLinesByProject[$normProjectNo] ?? null)
            ? $projectPostenRevenueLinesByProject[$normProjectNo]
            : [];

        $projectBreakdowns[$normProjectNo] = $breakdown;
    }
    $data['project_breakdowns'] = $projectBreakdowns;

    $data['projectposten_debug'] = [
        'loader_mode' => 'column_fetchers',
        'months_loaded' => $allBatchMonths,
        'project_count' => count($projectNumbers),
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

$selectedCompany = $_GET['company'] ?? $companies[0];
if (!in_array($selectedCompany, $companies, true)) {
    $selectedCompany = $companies[0];
}

// --- AJAX endpoints ---

// Ophalen werkorders voor één batch-maand (stap in de progressieve laadflow)
if (($_GET['action'] ?? '') === 'fetch_workorders_batch') {
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

    try {
        $ttl = odata_ttl_for_month($batchYm);
        $batchWorkorders = fetch_workorders_for_batch_month($company, $batchYm, $auth, $ttl);
        $batchProjectPostenRows = fetch_projectposten_for_batch_month($company, $batchYm, $auth, $ttl);

        // Merge into WIP cache
        $wip = batch_wip_load($company, $targetYm);
        $existing = is_array($wip['workorders'] ?? null) ? $wip['workorders'] : [];
        $done = is_array($wip['done_months'] ?? null) ? $wip['done_months'] : [];
        $projectPostenByMonth = is_array($wip['projectposten_by_month'] ?? null) ? $wip['projectposten_by_month'] : [];

        if (!in_array($batchYm, $done, true)) {
            $existing = array_merge($existing, $batchWorkorders);
            $done[] = $batchYm;
        }

        $projectPostenByMonth[$batchYm] = $batchProjectPostenRows;

        $allBatchMonths = batch_months_for_target($targetYm);
        $remaining = array_values(array_filter($allBatchMonths, fn($m) => !in_array($m, $done, true)));

        batch_wip_save($company, $targetYm, [
            'workorders' => $existing,
            'done_months' => $done,
            'projectposten_by_month' => $projectPostenByMonth,
        ]);

        $nextBatch = $remaining[0] ?? null;
        $isDone = $nextBatch === null;

        echo json_encode([
            'ok' => true,
            'batch_month' => $batchYm,
            'next_batch' => $nextBatch,
            'done' => $isDone,
            'batches_remaining' => count($remaining),
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

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
    if (!preg_match('/^\d{4}-\d{2}$/', $targetYm) || !preg_match('/^\d{4}-\d{2}$/', $batchYm) || $columnKey === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Ongeldige parameters'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $company = trim((string) ($_POST['company'] ?? $companies[0]));
    if (!in_array($company, $companies, true)) {
        $company = $companies[0];
    }
    $auth = auth_get_auth_for_company($company, 300);

    try {
        $ttl = odata_ttl_for_month($batchYm);
        $wip = batch_wip_load($company, $targetYm);
        $projectNumbersByMonth = is_array($wip['project_numbers_by_month'] ?? null) ? $wip['project_numbers_by_month'] : [];
        $projectNumbers = is_array($projectNumbersByMonth[$batchYm] ?? null) ? $projectNumbersByMonth[$batchYm] : [];

        $columnResult = bc_fetch_run_column($columnKey, $company, $batchYm, $projectNumbers, $auth, $ttl);

        $columnDataByMonth = is_array($wip['column_data_by_month'] ?? null) ? $wip['column_data_by_month'] : [];
        if (!isset($columnDataByMonth[$batchYm]) || !is_array($columnDataByMonth[$batchYm])) {
            $columnDataByMonth[$batchYm] = [];
        }
        $columnDataByMonth[$batchYm][$columnKey] = $columnResult;
        $wip['column_data_by_month'] = $columnDataByMonth;
        batch_wip_save($company, $targetYm, $wip);

        echo json_encode([
            'ok' => true,
            'batch_month' => $batchYm,
            'column_key' => $columnKey,
            'warning' => $columnResult['warning'] ?? null,
        ], JSON_UNESCAPED_UNICODE);
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
        $wipWorkorders = is_array($wip['workorders'] ?? null) ? $wip['workorders'] : [];
        $projectNumbers = [];
        $seenProjectNos = [];
        $workorderNumbers = [];
        $seenWorkorderNos = [];

        foreach ($wipWorkorders as $wo) {
            if (!is_array($wo)) {
                continue;
            }
            $jNo = trim((string) ($wo['Job_No'] ?? ''));
            if ($jNo !== '' && !isset($seenProjectNos[$jNo])) {
                $seenProjectNos[$jNo] = true;
                $projectNumbers[] = $jNo;
            }
            $jobTaskNo = trim((string) ($wo['Job_Task_No'] ?? ''));
            if ($jobTaskNo !== '' && !isset($seenWorkorderNos[$jobTaskNo])) {
                $seenWorkorderNos[$jobTaskNo] = true;
                $workorderNumbers[] = $jobTaskNo;
            }
        }

        $projectPostenByMonth = is_array($wip['projectposten_by_month'] ?? null) ? $wip['projectposten_by_month'] : [];
        $projectPostenRows = [];
        foreach ($projectPostenByMonth as $monthRows) {
            if (is_array($monthRows)) {
                $projectPostenRows = array_merge($projectPostenRows, $monthRows);
            }
        }

        $aggregatedFinance = aggregate_projectposten_rows($projectPostenRows);
        $ppProjectNumbers = is_array($aggregatedFinance['project_numbers'] ?? null)
            ? $aggregatedFinance['project_numbers']
            : [];
        $ppWorkorderNumbers = is_array($aggregatedFinance['workorder_numbers'] ?? null)
            ? $aggregatedFinance['workorder_numbers']
            : [];

        foreach ($ppProjectNumbers as $pNo) {
            $pNo = trim((string) $pNo);
            if ($pNo !== '' && !isset($seenProjectNos[$pNo])) {
                $seenProjectNos[$pNo] = true;
                $projectNumbers[] = $pNo;
            }
        }

        foreach ($ppWorkorderNumbers as $woNo) {
            $woNo = trim((string) $woNo);
            if ($woNo !== '' && !isset($seenWorkorderNos[$woNo])) {
                $seenWorkorderNos[$woNo] = true;
                $workorderNumbers[] = $woNo;
            }
        }

        $wip['project_numbers'] = $projectNumbers;
        $wip['workorder_numbers'] = $workorderNumbers;
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
            'workorder_count' => count($workorderNumbers),
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

        $environmentForCompany = auth_get_environment_for_company($company, 300);
        $financeService = new ProjectFinanceService($company, $environmentForCompany);
        $projectInvoiceData = [
            'invoice_details_by_id' => [],
            'project_invoice_ids_by_job' => [],
            'project_invoiced_total_by_job' => [],
        ];

        if ($projectNumbers !== []) {
            $projectInvoiceData = $financeService->collectProjectInvoicesForProjects($projectNumbers, $ttl);
        }

        $wip['project_finance'] = [
            'project_totals_by_job' => is_array($existingProjectFinance['project_totals_by_job'] ?? null)
                ? $existingProjectFinance['project_totals_by_job']
                : [],
            'invoice_details_by_id' => is_array($projectInvoiceData['invoice_details_by_id'] ?? null)
                ? $projectInvoiceData['invoice_details_by_id']
                : [],
            'project_invoice_ids_by_job' => is_array($projectInvoiceData['project_invoice_ids_by_job'] ?? null)
                ? $projectInvoiceData['project_invoice_ids_by_job']
                : [],
            'project_invoiced_total_by_job' => is_array($projectInvoiceData['project_invoiced_total_by_job'] ?? null)
                ? $projectInvoiceData['project_invoiced_total_by_job']
                : [],
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

// Sub-stap 4: planningsregels ophalen voor verzamelde projecten
if (($_GET['action'] ?? '') === 'planning_project_list') {
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
        $projectNumbers = is_array($wip['planning_project_numbers'] ?? null)
            ? $wip['planning_project_numbers']
            : [];
        if ($projectNumbers === []) {
            $projectNumbers = wip_project_numbers($wip);
        }

        $wip['project_numbers'] = $projectNumbers;
        $wip['planning_totals_by_job'] = [];
        $wip['planning_breakdown_by_job'] = [];
        $wip['planning_warning'] = null;
        batch_wip_save($company, $targetYm, $wip);

        echo json_encode([
            'ok' => true,
            'projects' => $projectNumbers,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (($_GET['action'] ?? '') === 'fetch_sub_planning_project') {
    header('Content-Type: application/json; charset=utf-8');
    @set_time_limit(0);
    @ini_set('max_execution_time', '0');

    $targetYm = trim((string) ($_POST['target_month'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}$/', $targetYm)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Ongeldige maand'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $projectNo = trim((string) ($_POST['project_no'] ?? ''));
    if ($projectNo === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Projectnummer ontbreekt'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $company = trim((string) ($_POST['company'] ?? $companies[0]));
    if (!in_array($company, $companies, true)) {
        $company = $companies[0];
    }

    try {
        $ttl = odata_ttl_for_month($targetYm);
        $wip = batch_wip_load($company, $targetYm);
        $planningTotalsByJob = is_array($wip['planning_totals_by_job'] ?? null) ? $wip['planning_totals_by_job'] : [];
        $planningBreakdownByJob = is_array($wip['planning_breakdown_by_job'] ?? null) ? $wip['planning_breakdown_by_job'] : [];

        $environmentForCompany = auth_get_environment_for_company($company, 300);
        $financeService = new ProjectFinanceService($company, $environmentForCompany);
        $forecast = $financeService->collectProjectForecastForProjects([$projectNo], $ttl);

        $normProjectNo = strtolower($projectNo);
        $totals = is_array($forecast['forecast_totals_by_job'] ?? null) ? $forecast['forecast_totals_by_job'] : [];
        $breakdown = is_array($forecast['forecast_breakdown_by_job'] ?? null) ? $forecast['forecast_breakdown_by_job'] : [];

        $planningTotalsByJob[$normProjectNo] = [
            'expected_revenue' => finance_to_float(($totals[$normProjectNo]['expected_revenue'] ?? 0.0)),
            'expected_costs' => finance_to_float(($totals[$normProjectNo]['expected_costs'] ?? 0.0)),
            'extra_work' => finance_to_float(($totals[$normProjectNo]['extra_work'] ?? 0.0)),
        ];
        $planningBreakdownByJob[$normProjectNo] = [
            'expected_revenue_lines' => is_array($breakdown[$normProjectNo]['expected_revenue_lines'] ?? null)
                ? $breakdown[$normProjectNo]['expected_revenue_lines']
                : [],
            'expected_costs_lines' => is_array($breakdown[$normProjectNo]['expected_costs_lines'] ?? null)
                ? $breakdown[$normProjectNo]['expected_costs_lines']
                : [],
            'extra_work_lines' => is_array($breakdown[$normProjectNo]['extra_work_lines'] ?? null)
                ? $breakdown[$normProjectNo]['extra_work_lines']
                : [],
        ];

        $wip['planning_totals_by_job'] = $planningTotalsByJob;
        $wip['planning_breakdown_by_job'] = $planningBreakdownByJob;
        batch_wip_save($company, $targetYm, $wip);

        echo json_encode([
            'ok' => true,
            'project_no' => $projectNo,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (($_GET['action'] ?? '') === 'fetch_sub_planning_batch') {
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

    $rawProjectNumbers = trim((string) ($_POST['project_numbers_json'] ?? ''));
    $decodedProjectNumbers = json_decode($rawProjectNumbers, true);
    if (!is_array($decodedProjectNumbers)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'project_numbers_json ontbreekt of is ongeldig'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $projectNumbers = [];
    $seenProjectNumbers = [];
    foreach ($decodedProjectNumbers as $value) {
        $projectNo = trim((string) $value);
        if ($projectNo === '' || isset($seenProjectNumbers[$projectNo])) {
            continue;
        }
        $seenProjectNumbers[$projectNo] = true;
        $projectNumbers[] = $projectNo;
    }

    if ($projectNumbers === []) {
        echo json_encode(['ok' => true, 'processed' => 0], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $ttl = odata_ttl_for_month($targetYm);
        $wip = batch_wip_load($company, $targetYm);
        $planningTotalsByJob = is_array($wip['planning_totals_by_job'] ?? null) ? $wip['planning_totals_by_job'] : [];
        $planningBreakdownByJob = is_array($wip['planning_breakdown_by_job'] ?? null) ? $wip['planning_breakdown_by_job'] : [];

        $environmentForCompany = auth_get_environment_for_company($company, 300);
        $financeService = new ProjectFinanceService($company, $environmentForCompany);
        $forecast = $financeService->collectProjectForecastForProjects($projectNumbers, $ttl);
        $totalsByProject = is_array($forecast['forecast_totals_by_job'] ?? null)
            ? $forecast['forecast_totals_by_job']
            : [];
        $breakdownByProject = is_array($forecast['forecast_breakdown_by_job'] ?? null)
            ? $forecast['forecast_breakdown_by_job']
            : [];

        foreach ($projectNumbers as $projectNo) {
            $normProjectNo = strtolower(trim($projectNo));
            if ($normProjectNo === '') {
                continue;
            }

            $totals = is_array($totalsByProject[$normProjectNo] ?? null)
                ? $totalsByProject[$normProjectNo]
                : [];
            $breakdown = is_array($breakdownByProject[$normProjectNo] ?? null)
                ? $breakdownByProject[$normProjectNo]
                : [];

            $planningTotalsByJob[$normProjectNo] = [
                'expected_revenue' => finance_to_float(($totals['expected_revenue'] ?? 0.0)),
                'expected_costs' => finance_to_float(($totals['expected_costs'] ?? 0.0)),
                'extra_work' => finance_to_float(($totals['extra_work'] ?? 0.0)),
            ];
            $planningBreakdownByJob[$normProjectNo] = [
                'expected_revenue_lines' => is_array($breakdown['expected_revenue_lines'] ?? null)
                    ? $breakdown['expected_revenue_lines']
                    : [],
                'expected_costs_lines' => is_array($breakdown['expected_costs_lines'] ?? null)
                    ? $breakdown['expected_costs_lines']
                    : [],
                'extra_work_lines' => is_array($breakdown['extra_work_lines'] ?? null)
                    ? $breakdown['extra_work_lines']
                    : [],
            ];
        }

        $wip['planning_totals_by_job'] = $planningTotalsByJob;
        $wip['planning_breakdown_by_job'] = $planningBreakdownByJob;
        batch_wip_save($company, $targetYm, $wip);

        echo json_encode([
            'ok' => true,
            'processed' => count($projectNumbers),
            'from' => $projectNumbers[0],
            'to' => $projectNumbers[count($projectNumbers) - 1],
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Sub-stap 4: planningsregels ophalen voor verzamelde projecten
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

        if (is_array($wip['project_numbers_by_month'] ?? null) && is_array($wip['column_data_by_month'] ?? null)) {
            $data = build_snapshot_from_column_wip($company, $targetYm, $wip);
            maand_save($company, $targetYm, $data);
            batch_wip_delete($company, $targetYm);

            echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $workorders = is_array($wip['workorders'] ?? null) ? $wip['workorders'] : [];
        $projectFinance = is_array($wip['project_finance'] ?? null) ? $wip['project_finance'] : [];
        $workorderFinance = is_array($wip['workorder_finance'] ?? null) ? $wip['workorder_finance'] : [];
        $projectDetails = is_array($wip['project_details'] ?? null) ? $wip['project_details'] : [];
        $planningTotalsByJob = is_array($wip['planning_totals_by_job'] ?? null) ? $wip['planning_totals_by_job'] : [];
        $planningBreakdownByJob = is_array($wip['planning_breakdown_by_job'] ?? null) ? $wip['planning_breakdown_by_job'] : [];

        $projectTotalsByJob = is_array($projectFinance['project_totals_by_job'] ?? null) ? $projectFinance['project_totals_by_job'] : [];
        $invoiceIdsByJob = is_array($projectFinance['project_invoice_ids_by_job'] ?? null) ? $projectFinance['project_invoice_ids_by_job'] : [];
        $invoicedTotalByJob = is_array($projectFinance['project_invoiced_total_by_job'] ?? null) ? $projectFinance['project_invoiced_total_by_job'] : [];
        $invoiceDetailsById = is_array($projectFinance['invoice_details_by_id'] ?? null) ? $projectFinance['invoice_details_by_id'] : [];
        $workorderTotalsByNumber = is_array($workorderFinance['workorder_totals_by_number'] ?? null) ? $workorderFinance['workorder_totals_by_number'] : [];

        $data = build_month_rows(
            $company,
            $targetYm,
            $workorders,
            $projectTotalsByJob,
            $invoiceIdsByJob,
            $invoicedTotalByJob,
            $invoiceDetailsById,
            $workorderTotalsByNumber,
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

// Load user column order preference
$userSettings = load_user_settings_payload_m($currentUserEmail);
$savedColumnOrder = is_array($userSettings['maanden_column_order'] ?? null) ? $userSettings['maanden_column_order'] : [];

$initialData = [
    'companies' => $companies,
    'selected_company' => $selectedCompany,
    'month_summaries' => $monthSummaries,
    'addable_months' => $addableMonths,
    'current_month' => $currentMonth,
    'saved_column_order' => $savedColumnOrder,
    'refresh_url' => 'maanden.php?action=refresh_month',
    'batch_url' => 'maanden.php?action=fetch_workorders_batch',
    'project_numbers_url' => 'maanden.php?action=fetch_project_numbers_batch',
    'column_batch_url' => 'maanden.php?action=fetch_column_batch',
    'planning_project_list_url' => 'maanden.php?action=planning_project_list',
    'planning_project_url' => 'maanden.php?action=fetch_sub_planning_project',
    'planning_batch_url' => 'maanden.php?action=fetch_sub_planning_batch',
    'sub_planning_url' => 'maanden.php?action=fetch_sub_planning',
    'column_steps' => array_map(static function ($key, $cfg): array {
        return [
            'key' => (string) $key,
            'label' => (string) ($cfg['label'] ?? $key),
        ];
    }, array_keys(array_filter(bc_fetch_column_registry(), static function ($cfg, $key): bool {
        return (string) $key !== 'planning';
    }, ARRAY_FILTER_USE_BOTH)), array_values(array_filter(bc_fetch_column_registry(), static function ($cfg, $key): bool {
        return (string) $key !== 'planning';
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