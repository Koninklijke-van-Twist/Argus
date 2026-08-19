<?php

/**
 * Includes/requires
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../auth_helper.php';

/**
 * Functies
 */
/**
 * Berekent OData-datums voor OHW-snapshot op een doelmaand (einde van die maand).
 *
 * @return array{posting_before:string,reverse_after:string,blank_reverse:string}|null
 */
function bc_fetch_snapshot_ohw_bounds(string $targetYearMonth): ?array
{
    $target = DateTimeImmutable::createFromFormat('!Y-m', $targetYearMonth);
    if (!$target instanceof DateTimeImmutable) {
        return null;
    }

    return [
        'posting_before' => $target->modify('+1 month')->format('Y-m-d'),
        'reverse_after' => $target->format('Y-m-t'),
        'blank_reverse' => '0001-01-01',
    ];
}

/**
 * OHW-bounds voor een willekeurige peildatum (inclusief die dag).
 *
 * @return array{posting_before:string,reverse_after:string,blank_reverse:string}
 */
function bc_fetch_ohw_bounds_for_date(DateTimeImmutable $asOfDate): array
{
    return [
        'posting_before' => $asOfDate->modify('+1 day')->format('Y-m-d'),
        'reverse_after' => $asOfDate->format('Y-m-d'),
        'blank_reverse' => '0001-01-01',
    ];
}

/**
 * OData-filter: geboekt vóór snapshot, niet (of later) teruggedraaid t.o.v. snapshot.
 */
function bc_fetch_snapshot_ohw_filter(string $targetYearMonth): string
{
    $bounds = bc_fetch_snapshot_ohw_bounds($targetYearMonth);
    if ($bounds === null) {
        return '';
    }

    return sprintf(
        'Posting_Date lt %s and (Reverse_Date gt %s or Reverse_Date eq %s)',
        $bounds['posting_before'],
        $bounds['reverse_after'],
        $bounds['blank_reverse']
    );
}

/**
 * Haalt alle Grootboekposten_OHW op voor de snapshot van een doelmaand.
 */
function bc_fetch_grootboekposten_ohw_rows(string $company, string $targetYearMonth, array $auth, int $ttl): array
{
    $filter = bc_fetch_snapshot_ohw_filter($targetYearMonth);
    if ($filter === '') {
        return [];
    }

    $auth = auth_get_auth_for_environment(auth_get_environment_for_company($company, 300));
    $url = company_entity_url_with_query(
        $GLOBALS['baseUrl'],
        auth_get_environment_for_company($company, 300),
        $company,
        'Grootboekposten_OHW',
        ['$filter' => $filter]
    );

    return odata_get_all($url, $auth, $ttl);
}

/**
 * Aggregateert OHW-kosten per project tot en met een peildatum (standaard vandaag).
 *
 * @return array<string,float> genormaliseerd projectnummer => kostentotaal
 */
function bc_fetch_ohw_costs_through_date(string $company, array $auth, int $ttl, ?DateTimeImmutable $asOfDate = null): array
{
    $asOfDate = $asOfDate ?? new DateTimeImmutable('today');
    $bounds = bc_fetch_ohw_bounds_for_date($asOfDate);
    $filter = sprintf(
        'Posting_Date lt %s and (Reverse_Date gt %s or Reverse_Date eq %s)',
        $bounds['posting_before'],
        $bounds['reverse_after'],
        $bounds['blank_reverse']
    );

    $auth = auth_get_auth_for_environment(auth_get_environment_for_company($company, 300));
    $url = company_entity_url_with_query(
        $GLOBALS['baseUrl'],
        auth_get_environment_for_company($company, 300),
        $company,
        'Grootboekposten_OHW',
        ['$filter' => $filter]
    );

    try {
        $rows = odata_get_all($url, $auth, $ttl);
    } catch (Throwable $ignored) {
        return [];
    }

    $costsByProject = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $jobNo = trim((string) ($row['Job_No'] ?? ''));
        if ($jobNo === '') {
            continue;
        }

        $deltaInfo = bc_fetch_ohw_amount_delta($row);
        if ($deltaInfo === null || $deltaInfo['kind'] !== 'costs') {
            continue;
        }

        $normJob = bc_fetch_normalize_project_no($jobNo);
        $costsByProject[$normJob] = bc_fetch_add(
            (float) ($costsByProject[$normJob] ?? 0.0),
            (float) $deltaInfo['delta']
        );
    }

    return $costsByProject;
}

/**
 * Formatteert Job_Complete uit een OHW-rij als Ja/Nee.
 */
function bc_fetch_ohw_job_complete_label($value): string
{
    if ($value === true || $value === 1 || $value === '1' || $value === 'true') {
        return 'Ja';
    }
    if ($value === false || $value === 0 || $value === '0' || $value === 'false') {
        return 'Nee';
    }

    $text = trim((string) $value);
    if ($text === '') {
        return '';
    }

    return $text;
}

/**
 * Grootboekrekeningen voor OHW-kosten en -opbrengst.
 */
const OHW_GL_ACCOUNT_COSTS = '899900';
const OHW_GL_ACCOUNT_REVENUE = '899901';

/**
 * Bepaalt of een OHW-regel kosten of opbrengst is op basis van G_L_Account_No.
 *
 * @return 'costs'|'revenue'|null
 */
function bc_fetch_ohw_gl_account_kind(string $glAccountNo): ?string
{
    $normalized = preg_replace('/\s+/', '', trim($glAccountNo));
    if ($normalized === OHW_GL_ACCOUNT_COSTS) {
        return 'costs';
    }
    if ($normalized === OHW_GL_ACCOUNT_REVENUE) {
        return 'revenue';
    }

    return null;
}

/**
 * Regels waarbij G/L-rekening gelijk is aan balansrekening worden overgeslagen.
 */
function bc_fetch_ohw_should_ignore_row(array $row): bool
{
    $glAccount = preg_replace('/\s+/', '', trim((string) ($row['G_L_Account_No'] ?? '')));
    $glBalAccount = preg_replace('/\s+/', '', trim((string) ($row['G_L_Bal_Account_No'] ?? '')));

    return $glAccount !== '' && $glBalAccount !== '' && $glAccount === $glBalAccount;
}

/**
 * Berekent de kosten-/opbrengstmutatie voor één OHW-regel.
 *
 * Kosten (899900): negatief bedrag telt positief op, positief bedrag trekt af.
 * Opbrengst (899901): positief telt op, negatief trekt af.
 *
 * @return array{kind:string,delta:float}|null
 */
function bc_fetch_ohw_amount_delta(array $row): ?array
{
    if (bc_fetch_ohw_should_ignore_row($row)) {
        return null;
    }

    $kind = bc_fetch_ohw_gl_account_kind((string) ($row['G_L_Account_No'] ?? ''));
    if ($kind === null) {
        return null;
    }

    $amount = bc_fetch_float_value($row, 'WIP_Entry_Amount');
    if ($amount === 0.0) {
        return null;
    }

    if ($kind === 'costs') {
        return ['kind' => 'costs', 'delta' => -$amount];
    }

    return ['kind' => 'revenue', 'delta' => $amount];
}

/**
 * Zet een Grootboekposten_OHW-rij om naar een breakdownregel voor detailmodals.
 */
function bc_fetch_ohw_breakdown_line_from_row(array $sourceRow): array
{
    $amount = bc_fetch_float_value($sourceRow, 'WIP_Entry_Amount');
    $kind = bc_fetch_ohw_gl_account_kind((string) ($sourceRow['G_L_Account_No'] ?? ''));
    $isCost = $kind === 'costs';

    return [
        'Posting_Date' => (string) ($sourceRow['Posting_Date'] ?? ''),
        'Job_Complete' => bc_fetch_ohw_job_complete_label($sourceRow['Job_Complete'] ?? ''),
        'Document_No' => (string) ($sourceRow['Document_No'] ?? ''),
        'G_L_Account_No' => (string) ($sourceRow['G_L_Account_No'] ?? ''),
        'WIP_Method_Used' => (string) ($sourceRow['WIP_Method_Used'] ?? ''),
        'Type' => (string) ($sourceRow['Type'] ?? ''),
        'Global_Dimension_1_Code' => (string) ($sourceRow['Global_Dimension_1_Code'] ?? ''),
        'WIP_Entry_Amount' => $amount,
        'Total_Cost' => $isCost ? -$amount : 0.0,
        'Line_Amount' => $isCost ? 0.0 : $amount,
    ];
}

/**
 * Eerste niet-lege afdelingscode (Global_Dimension_1_Code) uit OHW-breakdownregels.
 */
function bc_fetch_primary_department_from_breakdown(array $breakdown): string
{
    foreach (['total_costs_lines', 'total_revenue_lines'] as $lineKey) {
        $lines = is_array($breakdown[$lineKey] ?? null) ? $breakdown[$lineKey] : [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }

            $code = trim((string) ($line['Global_Dimension_1_Code'] ?? ''));
            if ($code !== '') {
                return $code;
            }
        }
    }

    return '';
}

/**
 * Aggregateert OHW-rijen naar projecttotalen (G_L_Account_No 899900/899901).
 */
function bc_fetch_aggregate_ohw_rows(array $rows): array
{
    $projectTotalsByJob = [];
    $projectNumbers = [];
    $seenProjectNos = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $jobNo = trim((string) ($row['Job_No'] ?? ''));
        if ($jobNo === '') {
            continue;
        }

        $deltaInfo = bc_fetch_ohw_amount_delta($row);
        if ($deltaInfo === null) {
            continue;
        }

        $normJob = bc_fetch_normalize_project_no($jobNo);
        if (!isset($projectTotalsByJob[$normJob])) {
            $projectTotalsByJob[$normJob] = ['costs' => 0.0, 'revenue' => 0.0, 'resultaat' => 0.0];
        }

        if ($deltaInfo['kind'] === 'costs') {
            $projectTotalsByJob[$normJob]['costs'] = bc_fetch_add(
                (float) ($projectTotalsByJob[$normJob]['costs'] ?? 0.0),
                (float) $deltaInfo['delta']
            );
        } else {
            $projectTotalsByJob[$normJob]['revenue'] = bc_fetch_add(
                (float) ($projectTotalsByJob[$normJob]['revenue'] ?? 0.0),
                (float) $deltaInfo['delta']
            );
        }

        $projectTotalsByJob[$normJob]['resultaat'] = finance_calculate_result(
            (float) ($projectTotalsByJob[$normJob]['revenue'] ?? 0.0),
            (float) ($projectTotalsByJob[$normJob]['costs'] ?? 0.0)
        );

        if (!isset($seenProjectNos[$jobNo])) {
            $seenProjectNos[$jobNo] = true;
            $projectNumbers[] = $jobNo;
        }
    }

    return [
        'project_totals_by_job' => $projectTotalsByJob,
        'workorder_totals_by_number' => [],
        'project_numbers' => $projectNumbers,
        'workorder_numbers' => [],
    ];
}

/**
 * Haalt OHW voor de snapshot-doelmaand op en groepeert op projectnummer.
 */
function bc_fetch_column_grootboekposten_ohw(string $company, string $yearMonth, array $projectNumbers, array $auth, int $ttl): array
{
    $rows = bc_fetch_grootboekposten_ohw_rows($company, $yearMonth, $auth, $ttl);
    $projectDictionary = bc_fetch_seed_project_dictionary($projectNumbers);

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $projectNo = trim((string) ($row['Job_No'] ?? ''));
        if ($projectNo === '') {
            continue;
        }

        if (bc_fetch_ohw_should_ignore_row($row)) {
            continue;
        }

        $normProjectNo = bc_fetch_normalize_project_no($projectNo);
        if (!isset($projectDictionary[$normProjectNo])) {
            $projectDictionary[$normProjectNo] = [];
        }
        if (!isset($projectDictionary[$normProjectNo]['rows'])) {
            $projectDictionary[$normProjectNo]['rows'] = [];
            $projectDictionary[$normProjectNo]['costs'] = 0.0;
            $projectDictionary[$normProjectNo]['revenue'] = 0.0;
        }

        $deltaInfo = bc_fetch_ohw_amount_delta($row);
        if ($deltaInfo !== null) {
            if ($deltaInfo['kind'] === 'costs') {
                $projectDictionary[$normProjectNo]['costs'] = bc_fetch_add(
                    (float) ($projectDictionary[$normProjectNo]['costs'] ?? 0.0),
                    (float) $deltaInfo['delta']
                );
            } else {
                $projectDictionary[$normProjectNo]['revenue'] = bc_fetch_add(
                    (float) ($projectDictionary[$normProjectNo]['revenue'] ?? 0.0),
                    (float) $deltaInfo['delta']
                );
            }
        }

        $projectDictionary[$normProjectNo]['rows'][] = $row;
    }

    return [
        'column' => 'grootboekposten_ohw',
        'by_project' => $projectDictionary,
        'by_workorder' => [],
        'row_count' => count($rows),
        'snapshot_bounds' => bc_fetch_snapshot_ohw_bounds($yearMonth),
    ];
}
