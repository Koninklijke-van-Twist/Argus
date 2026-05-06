<?php

/**
 * Includes/requires
 */
require_once __DIR__ . '/helpers.php';

/**
 * Functies
 */
/**
 * Haalt voor een maand alle ProjectPosten op en groepeert ze op projectnummer.
 *
 * Retour:
 * - by_project: project -> rows, costs, revenue
 * - by_workorder: werkorder -> costs, revenue
 */
function bc_fetch_column_projectposten(string $company, string $yearMonth, array $projectNumbers, array $auth, int $ttl): array
{
    $from = DateTimeImmutable::createFromFormat('!Y-m', $yearMonth);
    if (!$from instanceof DateTimeImmutable) {
        return [
            'column' => 'projectposten',
            'by_project' => [],
            'by_workorder' => [],
        ];
    }

    $to = $from->modify('+1 month');
    $fromStr = $from->format('Y-m-d');
    $toStr = $to->format('Y-m-d');

    $projectDictionary = bc_fetch_seed_project_dictionary($projectNumbers);
    $workorderTotals = [];

    $url = company_entity_url_with_query($GLOBALS['baseUrl'], $GLOBALS['environment'], $company, 'ProjectPosten', [
        '$filter' => 'Posting_Date ge ' . $fromStr . ' and Posting_Date lt ' . $toStr,
    ]);
    $rows = odata_get_all($url, $auth, $ttl);

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $projectNo = trim((string) ($row['Job_No'] ?? ''));
        if ($projectNo === '') {
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

        $cost = bc_fetch_float_value($row, 'Total_Cost');
        $entryType = strtolower(trim((string) ($row['Entry_Type'] ?? $row['Type'] ?? '')));
        $isGebruikEntryType = $entryType === 'gebruik';
        // BC levert omzet negatief; voor UI wordt dit omgedraaid naar positief.
        $revenue = $isGebruikEntryType ? 0.0 : (-1 * bc_fetch_float_value($row, 'Line_Amount'));

        $projectDictionary[$normProjectNo]['costs'] = bc_fetch_add((float) ($projectDictionary[$normProjectNo]['costs'] ?? 0.0), $cost);
        $projectDictionary[$normProjectNo]['revenue'] = bc_fetch_add((float) ($projectDictionary[$normProjectNo]['revenue'] ?? 0.0), $revenue);
        $projectDictionary[$normProjectNo]['rows'][] = $row;

        $workorderNo = trim((string) ($row['Job_Task_No'] ?? ''));
        if ($workorderNo === '') {
            continue;
        }

        $normWorkorderNo = strtolower($workorderNo);
        if (!isset($workorderTotals[$normWorkorderNo])) {
            $workorderTotals[$normWorkorderNo] = [
                'costs' => 0.0,
                'revenue' => 0.0,
            ];
        }

        $workorderTotals[$normWorkorderNo]['costs'] = bc_fetch_add((float) ($workorderTotals[$normWorkorderNo]['costs'] ?? 0.0), $cost);
        $workorderTotals[$normWorkorderNo]['revenue'] = bc_fetch_add((float) ($workorderTotals[$normWorkorderNo]['revenue'] ?? 0.0), $revenue);
    }

    return [
        'column' => 'projectposten',
        'by_project' => $projectDictionary,
        'by_workorder' => $workorderTotals,
    ];
}
