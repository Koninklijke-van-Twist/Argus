<?php

/**
 * Includes/requires
 */
require_once __DIR__ . '/helpers.php';

/**
 * Functies
 */
/**
 * Haalt werkorders voor een maand op en groepeert ze op projectnummer.
 */
function bc_fetch_column_workorders(string $company, string $yearMonth, array $projectNumbers, array $auth, int $ttl): array
{
    $from = DateTimeImmutable::createFromFormat('!Y-m', $yearMonth);
    if (!$from instanceof DateTimeImmutable) {
        return [
            'column' => 'workorders',
            'by_project' => [],
            'all_rows' => [],
        ];
    }

    $to = $from->modify('+1 month');
    $fromStr = $from->format('Y-m-d');
    $toStr = $to->format('Y-m-d');

    $dictionary = bc_fetch_seed_project_dictionary($projectNumbers);

    $url = company_entity_url_with_query($GLOBALS['baseUrl'], $GLOBALS['environment'], $company, 'Werkorders', [
        '$select' => 'No,Task_Code,Task_Description,Status,KVT_Document_Status,Job_No,Job_Task_No,Contract_No,Start_Date,End_Date,Bill_to_Customer_No,Bill_to_Name,Sell_to_Customer_No,Sell_to_Name,Job_Dimension_1_Value,Memo,Memo_Internal_Use_Only,Memo_Invoice,KVT_Memo_Invoice_Details,KVT_Remarks_Invoicing,LVS_Show_on_Planboard,LVS_Fixed_Planned',
        '$filter' => 'Start_Date ge ' . $fromStr . ' and Start_Date lt ' . $toStr,
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
        if (!isset($dictionary[$normProjectNo])) {
            $dictionary[$normProjectNo] = [];
        }
        if (!isset($dictionary[$normProjectNo]['rows'])) {
            $dictionary[$normProjectNo]['rows'] = [];
        }

        $dictionary[$normProjectNo]['rows'][] = $row;
    }

    return [
        'column' => 'workorders',
        'by_project' => $dictionary,
        'all_rows' => $rows,
    ];
}
