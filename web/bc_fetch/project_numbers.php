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
 * Haalt voor een maand alle projectnummers op uit ProjectPosten en Werkorders.
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

    $projectPostenUrl = company_entity_url_with_query($GLOBALS['baseUrl'], auth_get_environment_for_company($company, 300), $company, 'ProjectPosten', [
        '$select' => 'Job_No',
        '$filter' => 'Posting_Date ge ' . $fromStr . ' and Posting_Date lt ' . $toStr,
    ]);
    $projectPostenRows = odata_get_all($projectPostenUrl, $auth, $ttl);
    foreach ($projectPostenRows as $row) {
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

function bc_fetch_project_numbers_from_werkorders(string $company, string $fromStr, string $toStr, array $auth, int $ttl): array
{
    $seen = [];
    $result = [];
    $auth = auth_get_auth_for_environment(auth_get_environment_for_company($company, 300));

    $workorderUrl = company_entity_url_with_query($GLOBALS['baseUrl'], auth_get_environment_for_company($company, 300), $company, 'Werkorders', [
        '$select' => 'Job_No',
        '$filter' => 'Start_Date ge ' . $fromStr . ' and Start_Date lt ' . $toStr,
    ]);
    $workorderRows = odata_get_all($workorderUrl, $auth, $ttl);
    foreach ($workorderRows as $row) {
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
