<?php

/**
 * Constants
 */
const FINANCE_REVENUE_GL_ACCOUNT_TYPE = 'GB-rekening';
const FINANCE_REVENUE_GL_ACCOUNT_NO = '800000';

/**
 * Genormaliseerde Type-aliassen voor de omzetrekening (G/L Account).
 * BC levert afhankelijk van taal/OData o.a. GB-rekening, Grootboekrekening, G/L Account en GLAccount.
 */
const FINANCE_REVENUE_GL_ACCOUNT_TYPE_ALIASES = [
    'glaccount',
    'glrekening',
    'grootboekrekening',
];

/**
 * Opbrengst VC (omzet voorcalculatie) op de project-totaalregel.
 * Schedule/budgetprijs; niet de aanneemsom op G/L 800000.
 */
const FINANCE_OPBRENGST_VC_FIELD = 'LVS_Schedule_Total_Price_2';

/**
 * Functies
 */

/**
 * Berekent kolomwaarde Totale Kosten door alle werkorderkosten op te tellen.
 */
function finance_column_total_costs(array $workorders): float
{
    $total = 0.0;

    foreach ($workorders as $workorder) {
        if (!is_array($workorder)) {
            continue;
        }

        $total = finance_add_amount($total, $workorder['Actual_Costs'] ?? 0.0);
    }

    return $total;
}

/**
 * Berekent kolomwaarde Totale Opbrengst door alle werkorderopbrengsten op te tellen.
 */
function finance_column_total_revenue(array $workorders): float
{
    $total = 0.0;

    foreach ($workorders as $workorder) {
        if (!is_array($workorder)) {
            continue;
        }

        $total = finance_add_amount($total, $workorder['Total_Revenue'] ?? 0.0);
    }

    return $total;
}

/**
 * Berekent kolomwaarde Winst OHW met de formule: Marge Ttl x % gereed.
 */
function finance_column_winst_ohw(float $marginTotal, float $percentCompleted): float
{
    return $marginTotal * ($percentCompleted / 100.0);
}

/**
 * Berekent kolomwaarde Winst Vorige Periode als opbrengst minus kosten uit de vorige periode.
 */
function finance_column_prev_profit(?array $prevProfitData): ?float
{
    if (!is_array($prevProfitData)) {
        return null;
    }

    $revenue = finance_to_float($prevProfitData['revenue'] ?? 0.0);
    $costs = finance_to_float($prevProfitData['costs'] ?? 0.0);

    return finance_calculate_result($revenue, $costs);
}

/**
 * Berekent kolomwaarde Verschil als (huidige winst minus winst vorige periode).
 */
function finance_column_difference(float $currentRevenue, float $currentCosts, ?float $prevProfit): ?float
{
    if ($prevProfit === null) {
        return null;
    }

    $currentProfit = finance_calculate_result($currentRevenue, $currentCosts);

    return $currentProfit - $prevProfit;
}

/**
 * Berekent kolomwaarde Marge Totaal als verwachte opbrengst minus verwachte kosten VC.
 */
function finance_column_margin_total(float $expectedRevenue, float $expectedCostsVc): float
{
    return finance_calculate_result($expectedRevenue, $expectedCostsVc);
}

/**
 * Zet een inkomende waarde veilig om naar een numeriek bedrag.
 */
function finance_to_float(mixed $value): float
{
    return is_numeric($value) ? (float) $value : 0.0;
}

/**
 * Zet een bedrag om naar de absolute waarde voor consistente kosten/opbrengstvergelijking.
 */
function finance_abs_amount(mixed $value): float
{
    return abs(finance_to_float($value));
}

/**
 * Telt een nieuw bedrag op bij een bestaand financieel totaal.
 */
function finance_add_amount(float $current, mixed $value): float
{
    return $current + finance_to_float($value);
}

/**
 * Berekent financieel resultaat als opbrengst minus kosten.
 */
function finance_calculate_result(float $revenue, float $costs): float
{
    return $revenue - $costs;
}

/**
 * Bepaalt of een projectstatus financieel als afgesloten moet worden behandeld.
 */
function finance_is_closed_project_status(string $status): bool
{
    $normalized = strtolower(trim($status));

    return in_array($normalized, ['completed', 'closed', 'afgesloten', 'gereed'], true);
}

/**
 * Normaliseert row mode naar ondersteunde modi voor bedragberekening.
 */
function finance_normalize_row_mode(string $mode): string
{
    $normalized = strtolower(trim($mode));
    if ($normalized === 'sum_raw') {
        return 'sum_raw';
    }
    if ($normalized === 'sum') {
        return 'sum';
    }
    if ($normalized === 'sum_invert') {
        return 'sum_invert';
    }

    return 'first_numeric';
}

/**
 * Leest het eerste numerieke bedrag uit een prioriteitenlijst van bronkolommen.
 */
function finance_first_numeric_value(array $details, array $fields): float
{
    foreach ($fields as $field) {
        if (!is_string($field) || $field === '' || !array_key_exists($field, $details)) {
            continue;
        }

        $raw = $details[$field];
        if (!is_numeric($raw)) {
            continue;
        }

        return finance_abs_amount($raw);
    }

    return 0.0;
}

/**
 * Berekent een regelbedrag op basis van row mode.
 */
function finance_extract_row_amount(array $row, array $fields, string $mode): float
{
    $normalizedMode = finance_normalize_row_mode($mode);

    if ($normalizedMode === 'sum_raw') {
        $sum = 0.0;

        foreach ($fields as $field) {
            if (!is_string($field) || $field === '' || !array_key_exists($field, $row)) {
                continue;
            }

            $raw = $row[$field];
            if (!is_numeric($raw)) {
                continue;
            }

            $sum += (float) $raw;
        }

        return $sum;
    }

    if ($normalizedMode === 'sum_invert') {
        $sum = 0.0;
        $hasNegativeValue = false;

        foreach ($fields as $field) {
            if (!is_string($field) || $field === '' || !array_key_exists($field, $row)) {
                continue;
            }

            $raw = $row[$field];
            if (!is_numeric($raw)) {
                continue;
            }

            $numeric = (float) $raw;
            if ($numeric < 0.0) {
                $hasNegativeValue = true;
            }

            $sum += $numeric;
        }

        return $hasNegativeValue ? -$sum : $sum;
    }

    if ($normalizedMode === 'sum') {
        $sum = 0.0;
        foreach ($fields as $field) {
            if (!is_string($field) || $field === '' || !array_key_exists($field, $row)) {
                continue;
            }

            $raw = $row[$field];
            if (!is_numeric($raw)) {
                continue;
            }

            $sum += finance_abs_amount($raw);
        }

        return $sum;
    }

    return finance_first_numeric_value($row, $fields);
}

/**
 * Berekent kolomwaarde Actual_Costs voor één werkorderregel uit de twee BC kostenvelden.
 */
function finance_workorder_actual_costs(array $workorder): float
{
    $costItems = finance_to_float($workorder['KVT_Sum_Work_Order_Cost_Items'] ?? 0);
    $costOther = finance_to_float($workorder['KVT_Sum_Work_Order_Cost_Other'] ?? 0);

    return $costItems + $costOther;
}

/**
 * Berekent kolomwaarde Total_Revenue voor één werkorderregel uit het BC opbrengstveld.
 */
function finance_workorder_total_revenue(array $workorder): float
{
    return finance_abs_amount($workorder['KVT_Sum_Work_Order_Revenue'] ?? 0);
}

/**
 * Normaliseert een BC-code voor vergelijking: hoofdletters, spaties en leestekens vallen weg.
 */
function finance_normalize_code(string $value): string
{
    $normalized = strtolower(trim($value));

    return str_replace([' ', '-', '_', '/', '\\', '.'], '', $normalized);
}

/**
 * Bepaalt of Type een G/L-rekening is (GB-rekening, Grootboekrekening, G/L Account, GLAccount en gelijkwaardige aliassen).
 */
function finance_is_revenue_gl_account_type(string $type): bool
{
    $normalized = finance_normalize_code($type);
    if ($normalized === '') {
        return false;
    }

    if ($normalized === finance_normalize_code(FINANCE_REVENUE_GL_ACCOUNT_TYPE)) {
        return true;
    }

    return in_array($normalized, FINANCE_REVENUE_GL_ACCOUNT_TYPE_ALIASES, true);
}

/**
 * Bepaalt of een BC-projectplanningsregel op omzetrekening 800000 staat.
 * Alleen G/L-type (GB-rekening / Grootboekrekening / G/L Account / GLAccount) en No = 800000 telt mee;
 * resource-/artikelboekingen op dezelfde planning blijven buiten deze som.
 * Opbrengst VC gebruikt dit filter niet; die kolom leest LVS_Schedule_Total_Price_2.
 */
function finance_is_revenue_gl_account_line(array $row): bool
{
    $type = trim((string) ($row['Type'] ?? ''));
    $no = trim((string) ($row['No'] ?? ''));

    return finance_is_revenue_gl_account_type($type)
        && $no === FINANCE_REVENUE_GL_ACCOUNT_NO;
}

/**
 * Bepaalt of Line_Type factureerbaar/billable is en geen prognose/forecast.
 */
function finance_is_billable_planning_line_type(string $lineType): bool
{
    $normalized = strtolower(trim($lineType));
    if ($normalized === '') {
        return false;
    }

    if (str_contains($normalized, 'prognose') || str_contains($normalized, 'forecast')) {
        return false;
    }

    return str_contains($normalized, 'factureer') || str_contains($normalized, 'billable');
}

/**
 * Leest project subordernr. (BC-veld LVS_Job_Change_Order_No). Leeg of alleen witruimte telt als geen meerwerk.
 */
function finance_planning_change_order_no(array $row): string
{
    return trim((string) ($row['LVS_Job_Change_Order_No'] ?? ''));
}

/**
 * Bepaalt of een planningsregel een geannuleerde originele regel is.
 * Ontbreekt het veld, dan telt de regel gewoon mee.
 */
function finance_is_cancelled_planning_line(array $row): bool
{
    if (!array_key_exists('LVS_Cancelled_Original_Line', $row)) {
        return false;
    }

    $value = $row['LVS_Cancelled_Original_Line'];

    return $value === true || $value === 1 || $value === '1' || $value === 'true';
}

/**
 * Bepaalt of een planningsregel meetelt voor opbrengst of opbrengst meerwerk.
 * Vereist G/L 800000, factureerbaar/billable (geen prognose/forecast) en geen geannuleerde regel.
 * Een gevuld subordernummer maakt de regel meerwerk, maar haalt hem niet uit de opbrengst.
 */
function finance_is_contract_revenue_planning_line(array $row): bool
{
    if (finance_is_cancelled_planning_line($row)) {
        return false;
    }

    if (!finance_is_revenue_gl_account_line($row)) {
        return false;
    }

    return finance_is_billable_planning_line_type((string) ($row['Line_Type'] ?? ''));
}

/**
 * Berekent kolomwaarde Opbrengst voor één planningsregel.
 * Factureerbare G/L 800000, met leeg én gevuld LVS_Job_Change_Order_No.
 * Meerwerk blijft in dit totaal; finance_opbrengst_meerwerk_amount is alleen extra informatie.
 * Q002 in Description zonder subordernummer telt gewoon mee.
 */
function finance_opbrengst_amount(array $row): float
{
    if (!finance_is_contract_revenue_planning_line($row)) {
        return 0.0;
    }

    return finance_to_float($row['Line_Amount_LCY'] ?? 0.0);
}

/**
 * Berekent de opbrengst/aanneemsom voor één planningsregel.
 * Zelfde totaal als finance_opbrengst_amount: leeg en gevuld subordernummer tellen allebei mee.
 */
function finance_aanneemsom_amount(array $row): float
{
    return finance_opbrengst_amount($row);
}

/**
 * Berekent kolomwaarde Opbrengst meerwerk voor één planningsregel.
 * Factureerbare G/L 800000-regel waarvan LVS_Job_Change_Order_No gevuld is; som van Line_Amount_LCY.
 * Q002 alleen in Description telt niet als meerwerk.
 */
function finance_opbrengst_meerwerk_amount(array $row): float
{
    if (!finance_is_contract_revenue_planning_line($row)) {
        return 0.0;
    }

    if (finance_planning_change_order_no($row) === '') {
        return 0.0;
    }

    return finance_to_float($row['Line_Amount_LCY'] ?? 0.0);
}

/**
 * Telt opbrengst (leeg én gevuld subordernr.) en het meerwerk-deel (alleen gevuld).
 * Opbrengst meerwerk zit in de opbrengst en wordt daar niet afgetrokken.
 *
 * @param array<int,mixed> $rows
 * @return array{opbrengst:float,aanneemsom:float,opbrengst_meerwerk:float}
 */
function finance_split_contract_revenue(array $rows): array
{
    $opbrengst = 0.0;
    $meerwerk = 0.0;

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $opbrengst = finance_add_amount($opbrengst, finance_opbrengst_amount($row));
        $meerwerk = finance_add_amount($meerwerk, finance_opbrengst_meerwerk_amount($row));
    }

    return [
        'opbrengst' => $opbrengst,
        'aanneemsom' => $opbrengst,
        'opbrengst_meerwerk' => $meerwerk,
    ];
}

/**
 * Leest Opbrengst VC uit LVS_Schedule_Total_Price_2 (schedule/omzet VC).
 * Alleen Job_Task_No 000 (Project TOTAAL) telt; andere taken leveren 0.
 * Ontbreekt het taaknummer, dan telt de regel wel (één projectkaartwaarde).
 * LVS_Baseline_Total_Price wordt niet gelezen: op PRJ2602236 is dat veld 0
 * terwijl de schedule-prijs ongeveer 219000 is.
 * Line_Amount_LCY en G/L 800000 (aanneemsom) worden genegeerd.
 */
function finance_opbrengst_vc_amount(array $row, string $projectTotaalTaskNo = '000'): float
{
    if (array_key_exists('Job_Task_No', $row)) {
        $taskNo = trim((string) $row['Job_Task_No']);
        if ($taskNo !== $projectTotaalTaskNo) {
            return 0.0;
        }
    }

    if (!array_key_exists(FINANCE_OPBRENGST_VC_FIELD, $row)) {
        return 0.0;
    }

    return finance_to_float($row[FINANCE_OPBRENGST_VC_FIELD]);
}
