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
 * Velden van de projectkaart voor de maand.
 * Status is de huidige kaartstatus in BC, niet de status op de peildatum.
 */
function bc_fetch_project_detail_odata_select(): string
{
    return 'No,Description,Bill_to_Customer_No,Bill_to_Name,Person_Responsible,Project_Manager,LVS_Global_Dimension_1_Code,LVS_Document_Status,Status,Percent_Completed,Total_WIP_Sales_Amount,Recog_Sales_Amount,Calc_Recog_Sales_Amount,KVT_Reason_Code';
}

/**
 * Zelfde kaartselectie zonder Status.
 * Een schemafout op dat veld (HTTP 400) mag de rest van de kaart niet weggooien.
 */
function bc_fetch_project_detail_odata_select_without_status(): string
{
    $fields = [];
    foreach (explode(',', bc_fetch_project_detail_odata_select()) as $field) {
        $field = trim($field);
        if ($field === '' || strcasecmp($field, 'Status') === 0) {
            continue;
        }
        $fields[] = $field;
    }

    return implode(',', $fields);
}

/**
 * Zelfde schema-fallback als de open-projectquery: een 400 over een onbekend
 * veld probeert één keer opnieuw zonder Status. Latere chunks slaan Status over.
 *
 * @return list<array<string,mixed>>
 */
function bc_fetch_projecten_rows(string $company, string $filter, array $auth, int $ttl, ?bool &$omitStatus = null): array
{
    $environment = auth_get_environment_for_company($company, 300);
    $auth = auth_get_auth_for_environment($environment);
    $selects = ($omitStatus === true)
        ? [bc_fetch_project_detail_odata_select_without_status()]
        : [bc_fetch_project_detail_odata_select(), bc_fetch_project_detail_odata_select_without_status()];

    $lastError = null;
    foreach ($selects as $index => $select) {
        try {
            $url = company_entity_url_with_query($GLOBALS['baseUrl'], $environment, $company, 'Projecten', [
                '$select' => $select,
                '$filter' => $filter,
            ]);
            $rows = odata_get_all($url, $auth, $ttl);

            return is_array($rows) ? $rows : [];
        } catch (Throwable $error) {
            $lastError = $error;
            $retryWithoutStatus = $index === 0
                && $omitStatus !== true
                && count($selects) > 1
                && finance_is_odata_schema_error($error->getMessage());
            if ($retryWithoutStatus) {
                $omitStatus = true;
                continue;
            }

            throw $error;
        }
    }

    throw $lastError instanceof Throwable ? $lastError : new RuntimeException('Projecten ophalen mislukt.');
}

/**
 * Bewaart alleen kaarten waarvan het nummer in de gevraagde maandlijst zit.
 * Een Projecten-antwoord zonder filter mag de lijst niet verbreden.
 *
 * @param array<int,mixed> $rows
 * @param array<int,mixed> $projectNumbers
 * @return array<string,array{row:array<string,mixed>}>
 */
function bc_fetch_project_detail_rows_for_numbers(array $rows, array $projectNumbers): array
{
    $allowed = bc_fetch_project_key_set($projectNumbers);
    $dictionary = bc_fetch_seed_project_dictionary($projectNumbers);
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $projectNo = trim((string) ($row['No'] ?? ''));
        $norm = bc_fetch_normalize_project_no($projectNo);
        if ($norm === '' || !isset($allowed[$norm])) {
            continue;
        }

        $dictionary[$norm] = [
            'row' => $row,
        ];
    }

    return $dictionary;
}

/**
 * Haalt projectdetails op voor de projectnummers van de maand.
 */
function bc_fetch_column_project_details(string $company, string $yearMonth, array $projectNumbers, array $auth, int $ttl): array
{
    $dictionary = bc_fetch_seed_project_dictionary($projectNumbers);
    $omitStatus = false;
    $chunks = array_chunk(array_values(array_unique(array_filter($projectNumbers, static function ($v): bool {
        return trim((string) $v) !== '';
    }))), 20);

    foreach ($chunks as $chunk) {
        if ($chunk === []) {
            continue;
        }

        $filterParts = array_map(static function ($no): string {
            return "No eq '" . str_replace("'", "''", trim((string) $no)) . "'";
        }, $chunk);

        $rows = bc_fetch_projecten_rows($company, implode(' or ', $filterParts), $auth, $ttl, $omitStatus);
        $accepted = bc_fetch_project_detail_rows_for_numbers($rows, $chunk);
        foreach ($accepted as $norm => $projectValue) {
            $dictionary[$norm] = $projectValue;
        }
    }

    return [
        'column' => 'project_details',
        'by_project' => $dictionary,
    ];
}
