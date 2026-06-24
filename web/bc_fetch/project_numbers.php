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
