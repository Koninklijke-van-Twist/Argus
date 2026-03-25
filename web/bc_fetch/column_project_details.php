<?php

/**
 * Includes/requires
 */
require_once __DIR__ . '/helpers.php';

/**
 * Functies
 */
/**
 * Haalt projectdetails op voor de projectnummers van de maand.
 */
function bc_fetch_column_project_details(string $company, string $yearMonth, array $projectNumbers, array $auth, int $ttl): array
{
    $dictionary = bc_fetch_seed_project_dictionary($projectNumbers);
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

        $url = company_entity_url_with_query($GLOBALS['baseUrl'], $GLOBALS['environment'], $company, 'Projecten', [
            '$select' => 'No,Description,Sell_to_Customer_No,Sell_to_Customer_Name,Bill_to_Customer_No,Bill_to_Name,Person_Responsible,Project_Manager,LVS_Global_Dimension_1_Code,Status,Percent_Completed,Total_WIP_Cost_Amount,Total_WIP_Sales_Amount,Recog_Costs_Amount,Recog_Sales_Amount,Calc_Recog_Costs_Amount,Calc_Recog_Sales_Amount,Acc_WIP_Costs_Amount,Acc_WIP_Sales_Amount,LVS_No_Of_Job_Change_Orders,External_Document_No,Your_Reference',
            '$filter' => implode(' or ', $filterParts),
        ]);

        $rows = odata_get_all($url, $auth, $ttl);
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $projectNo = trim((string) ($row['No'] ?? ''));
            if ($projectNo === '') {
                continue;
            }

            $dictionary[bc_fetch_normalize_project_no($projectNo)] = [
                'row' => $row,
            ];
        }
    }

    return [
        'column' => 'project_details',
        'by_project' => $dictionary,
    ];
}
