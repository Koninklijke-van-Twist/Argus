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
 * Haalt voor een maand projectnummers op uit Grootboekposten_OHW (boekingen in die maand).
 */
function bc_fetch_project_numbers_for_month(string $company, string $yearMonth, array $auth, int $ttl): array
{
    $from = DateTimeImmutable::createFromFormat('!Y-m', $yearMonth);
    if (!$from instanceof DateTimeImmutable) {
        return [];
    }

    $to = $from->modify('+1 month');
    $fromStr = $from->format('Y-m-d');
    $toStr = $to->format('Y-m-d');

    $seen = [];
    $result = [];
    $auth = auth_get_auth_for_environment(auth_get_environment_for_company($company, 300));

    $url = company_entity_url_with_query($GLOBALS['baseUrl'], auth_get_environment_for_company($company, 300), $company, 'Grootboekposten_OHW', [
        '$select' => 'Job_No',
        '$filter' => 'Posting_Date ge ' . $fromStr . ' and Posting_Date lt ' . $toStr,
    ]);
    $rows = odata_get_all($url, $auth, $ttl);
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $projectNo = trim((string) ($row['Job_No'] ?? ''));
        if ($projectNo === '' || isset($seen[$projectNo])) {
            continue;
        }

        $seen[$projectNo] = true;
        $result[] = $projectNo;
    }

    return $result;
}

/**
 * OData-filter voor open projecten op de projectkaart.
 * Bedrijf zit in het company-segment van de URL, niet in dit filter.
 * Afdeling (LVS_Global_Dimension_1_Code) filtert de UI achteraf, net als bij OHW-projecten.
 */
function bc_fetch_open_projects_odata_filter(): string
{
    return "Status eq 'Open'";
}

/**
 * Reserve-filter als Status op Projecten niet filterbaar is.
 * Zelfde "open"-betekenis als de documentstatus in het maandoverzicht.
 */
function bc_fetch_open_projects_document_status_filter(): string
{
    return "LVS_Document_Status eq 'Open'";
}

/**
 * Bepaalt of een projectkaart in de maand hoort.
 * Status Open telt. Zonder Status-veld (reserve-query) telt documentstatus Open.
 * Een gevulde Status die niet Open is blijft buiten de maand, ook als
 * LVS_Document_Status Open is. Anders houdt een ongefilterde Projecten-payload
 * elk document-open project vast: de telling en de aanneemsom stijgen,
 * de OHW-kosten blijven gelijk.
 */
function bc_fetch_project_is_open(array $row): bool
{
    $status = strtolower(trim((string) ($row['Status'] ?? '')));
    if ($status !== '') {
        return $status === 'open';
    }

    $documentStatus = strtolower(trim((string) ($row['LVS_Document_Status'] ?? '')));

    return $documentStatus === 'open';
}

/**
 * De reserve-query op documentstatus is alleen voor een schemafout op Status.
 * Een timeout of HTTP-fout mag niet overschakelen naar een bredere populatie.
 */
function bc_fetch_open_projects_use_document_status_fallback(Throwable $error): bool
{
    return finance_is_odata_schema_error($error->getMessage());
}

/**
 * Voegt OHW-projectnummers en open projecten samen, zonder dubbelen.
 *
 * @param array<int,mixed> $primaryNumbers
 * @param array<int,mixed> $extraNumbers
 * @return array<int,string>
 */
function bc_fetch_union_project_numbers(array $primaryNumbers, array $extraNumbers): array
{
    $result = [];
    $seen = [];
    foreach ([$primaryNumbers, $extraNumbers] as $list) {
        foreach ($list as $projectNo) {
            $projectNoText = trim((string) $projectNo);
            if ($projectNoText === '') {
                continue;
            }

            $key = bc_fetch_normalize_project_no($projectNoText);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = $projectNoText;
        }
    }

    return $result;
}

/**
 * Haalt alle open projecten van één bedrijf in één Projecten-query op.
 * Geen query per project. Alleen als Status niet op Projecten bestaat,
 * één keer documentstatus Open.
 *
 * @return array{numbers:array<int,string>,rows_by_norm:array<string,array<string,mixed>>,warning:?string}
 */
function bc_fetch_open_projects(string $company, array $auth, int $ttl): array
{
    $empty = ['numbers' => [], 'rows_by_norm' => [], 'warning' => null];
    $company = trim($company);
    if ($company === '') {
        return $empty;
    }

    $auth = auth_get_auth_for_environment(auth_get_environment_for_company($company, 300));
    $environment = auth_get_environment_for_company($company, 300);
    $select = 'No,Description,Bill_to_Customer_No,Bill_to_Name,Person_Responsible,Project_Manager,LVS_Global_Dimension_1_Code,LVS_Document_Status,Percent_Completed,Status,KVT_Reason_Code';
    $attempts = [
        ['select' => $select, 'filter' => bc_fetch_open_projects_odata_filter()],
        [
            'select' => str_replace(',Status', '', $select),
            'filter' => bc_fetch_open_projects_document_status_filter(),
        ],
    ];

    $lastError = null;
    foreach ($attempts as $index => $attempt) {
        try {
            $url = company_entity_url_with_query(
                $GLOBALS['baseUrl'],
                $environment,
                $company,
                'Projecten',
                [
                    '$select' => $attempt['select'],
                    '$filter' => $attempt['filter'],
                ]
            );
            $rows = odata_get_all($url, $auth, $ttl);
            $parsed = bc_fetch_parse_open_project_rows(is_array($rows) ? $rows : []);

            return [
                'numbers' => $parsed['numbers'],
                'rows_by_norm' => $parsed['rows_by_norm'],
                'warning' => null,
            ];
        } catch (Throwable $error) {
            $lastError = $error;
            if ($index === 0 && bc_fetch_open_projects_use_document_status_fallback($error)) {
                continue;
            }

            break;
        }
    }

    return [
        'numbers' => [],
        'rows_by_norm' => [],
        'warning' => 'Open projecten ophalen mislukt (Projecten): ' . ($lastError instanceof Throwable ? $lastError->getMessage() : 'onbekende fout'),
    ];
}

/**
 * @param array<int,mixed> $rows
 * @return array{numbers:array<int,string>,rows_by_norm:array<string,array<string,mixed>>}
 */
function bc_fetch_parse_open_project_rows(array $rows): array
{
    $numbers = [];
    $rowsByNorm = [];
    foreach ($rows as $row) {
        if (!is_array($row) || !bc_fetch_project_is_open($row)) {
            continue;
        }

        $projectNo = trim((string) ($row['No'] ?? ''));
        if ($projectNo === '') {
            continue;
        }

        $norm = bc_fetch_normalize_project_no($projectNo);
        if ($norm === '' || isset($rowsByNorm[$norm])) {
            continue;
        }

        $numbers[] = $projectNo;
        $rowsByNorm[$norm] = $row;
    }

    return [
        'numbers' => $numbers,
        'rows_by_norm' => $rowsByNorm,
    ];
}

/**
 * Maandleden: OHW-projectnummers plus kaarten die bc_fetch_project_is_open doorlaten.
 * Opgeslagen rijen worden opnieuw beoordeeld, zodat een eerdere wijde lijst
 * niet blijft hangen in project_numbers.
 *
 * @param array<int,mixed> $ohwProjectNumbers
 * @param array<string,mixed> $openProjectRows
 * @return array<string,true>
 */
function bc_fetch_month_member_keys(array $ohwProjectNumbers, array $openProjectRows): array
{
    $keys = bc_fetch_project_key_set($ohwProjectNumbers);
    $parsed = bc_fetch_parse_open_project_rows(array_values($openProjectRows));
    foreach ($parsed['numbers'] as $projectNo) {
        $key = bc_fetch_normalize_project_no($projectNo);
        if ($key !== '') {
            $keys[$key] = true;
        }
    }

    return $keys;
}
