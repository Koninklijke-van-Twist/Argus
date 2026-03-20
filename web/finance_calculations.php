<?php

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
 * Berekent kolomwaarde Winst OHW met de formule: verwachte opbrengst x % gereed - totale kosten.
 */
function finance_column_winst_ohw(float $expectedRevenue, float $percentCompleted, float $totalCosts): float
{
    $recognizedRevenue = $expectedRevenue * ($percentCompleted / 100.0);

    return finance_calculate_result($recognizedRevenue, $totalCosts);
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
 * Berekent een regelbedrag op basis van row mode (som, omgekeerde som of eerste numerieke waarde).
 */
function finance_extract_row_amount(array $row, array $fields, string $mode): float
{
    $normalizedMode = finance_normalize_row_mode($mode);

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
