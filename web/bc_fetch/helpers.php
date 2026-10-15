<?php

/**
 * Includes/requires
 */
require_once __DIR__ . '/../finance_calculations.php';

/**
 * Functies
 */
/**
 * Normaliseert een projectnummer voor dictionary-keys.
 */
function bc_fetch_normalize_project_no(string $projectNo): string
{
    return strtolower(trim($projectNo));
}

/**
 * Leest een numerieke waarde veilig uit een rij.
 */
function bc_fetch_float_value(array $row, string $field): float
{
    return finance_to_float($row[$field] ?? 0.0);
}

/**
 * Voegt bedragen op veilige wijze op.
 */
function bc_fetch_add(float $left, float $right): float
{
    return finance_add_amount($left, $right);
}

/**
 * Bouwt een dictionary met lege projectkeys voor alle aangeleverde projectnummers.
 */
function bc_fetch_seed_project_dictionary(array $projectNumbers): array
{
    $result = [];
    foreach ($projectNumbers as $projectNo) {
        $projectNoText = trim((string) $projectNo);
        if ($projectNoText === '') {
            continue;
        }

        $result[bc_fetch_normalize_project_no($projectNoText)] = [];
    }

    return $result;
}

/**
 * Vaste projectchunks voor een maandfetch.
 * De lijst krimpt niet nadat eerdere chunks zijn opgeslagen: chunk 5 blijft
 * dezelfde projecten, ook als chunk 0-4 al in de WIP staan.
 *
 * @param array<int,mixed> $projectNumbers
 * @return list<list<string>>
 */
function bc_fetch_stable_project_chunks(array $projectNumbers, int $chunkSize): array
{
    $clean = [];
    $seen = [];
    foreach ($projectNumbers as $projectNo) {
        $text = trim((string) $projectNo);
        if ($text === '') {
            continue;
        }

        $key = bc_fetch_normalize_project_no($text);
        if ($key === '' || isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $clean[] = $text;
    }

    if ($clean === []) {
        return [];
    }

    return array_chunk($clean, max(1, $chunkSize));
}

/**
 * Eén stabiele projectdetail-chunk.
 *
 * @param array<int,mixed> $projectNumbers
 * @return array{total_chunks:int,chunk_projects:list<string>,chunks_complete:bool}
 */
function bc_fetch_project_detail_chunk_at(array $projectNumbers, int $chunkIndex, int $chunkSize): array
{
    $chunks = bc_fetch_stable_project_chunks($projectNumbers, $chunkSize);
    $total = max(1, count($chunks));
    $index = max(0, $chunkIndex);
    if ($chunks === [] || $index >= count($chunks)) {
        return [
            'total_chunks' => $total,
            'chunk_projects' => [],
            'chunks_complete' => true,
        ];
    }

    return [
        'total_chunks' => $total,
        'chunk_projects' => $chunks[$index],
        'chunks_complete' => ($index + 1) >= $total,
    ];
}

/**
 * Projecten per HTTP-stap voor de voorcalculatie.
 * Eén request voor alle projecten (KvT: ~3700, 17–23 min) wordt door de
 * proxy of de browser afgekapt. Een stap van dit formaat blijft daar onder.
 */
const VOORCALCULATIE_PROJECT_CHUNK_SIZE = 40;

/**
 * Eén stabiele voorcalculatie-chunk op de volledige projectlijst.
 *
 * @param array<int,mixed> $projectNumbers
 * @return array{total_chunks:int,chunk_projects:list<string>,chunks_complete:bool}
 */
function bc_fetch_voorcalculatie_chunk_at(array $projectNumbers, int $chunkIndex): array
{
    return bc_fetch_project_detail_chunk_at($projectNumbers, $chunkIndex, VOORCALCULATIE_PROJECT_CHUNK_SIZE);
}

/**
 * Vervangt de voorcalculatie van de projecten in deze chunk.
 * De totalen zijn absoluut per project: dezelfde chunk nogmaals uitvoeren telt niet dubbel.
 *
 * @param array<string,mixed> $existingTotals
 * @param array<string,mixed> $existingBreakdown
 * @param array<string,mixed> $chunkTotals
 * @param array<string,mixed> $chunkBreakdown
 * @param array<int,mixed> $chunkProjects
 * @return array{totals:array<string,mixed>,breakdown:array<string,mixed>}
 */
function bc_fetch_merge_forecast_chunk(
    array $existingTotals,
    array $existingBreakdown,
    array $chunkTotals,
    array $chunkBreakdown,
    array $chunkProjects
): array {
    $totals = $existingTotals;
    $breakdown = $existingBreakdown;

    foreach ($chunkProjects as $projectNo) {
        $key = bc_fetch_normalize_project_no((string) $projectNo);
        if ($key === '') {
            continue;
        }

        unset($totals[$key], $breakdown[$key]);
        if (isset($chunkTotals[$key]) && is_array($chunkTotals[$key])) {
            $totals[$key] = $chunkTotals[$key];
        }
        if (isset($chunkBreakdown[$key]) && is_array($chunkBreakdown[$key])) {
            $breakdown[$key] = $chunkBreakdown[$key];
        }
    }

    return [
        'totals' => $totals,
        'breakdown' => $breakdown,
    ];
}

/**
 * Elke project in de maandlijst moet een voorcalculatie-totaal hebben
 * (ook 0) voordat de snapshot de vorige mag vervangen.
 *
 * @param array<int,mixed> $projectNumbers
 * @param array<string,mixed> $forecastTotals
 */
function bc_fetch_forecast_covers_projects(array $projectNumbers, array $forecastTotals): bool
{
    foreach ($projectNumbers as $projectNo) {
        $key = bc_fetch_normalize_project_no((string) $projectNo);
        if ($key === '') {
            continue;
        }

        if (!isset($forecastTotals[$key]) || !is_array($forecastTotals[$key])) {
            return false;
        }
    }

    return true;
}
