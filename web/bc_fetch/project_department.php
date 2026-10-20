<?php

/**
 * Projectafdeling opnieuw uit Business Central.
 *
 * Het veld dat Argus al op de projectkaart leest is Projecten.LVS_Global_Dimension_1_Code.
 * Dit bestand verandert die veldnaam niet. De waarde komt uit een nieuwe Mímir/OData-query
 * met max_age 0, niet uit een eerder opgeslagen maandsnapshot, WIP-kaart of OHW-regel.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../odata.php';

/**
 * Constants
 */
const BC_FETCH_PROJECT_DEPARTMENT_ENTITY = 'Projecten';
const BC_FETCH_PROJECT_DEPARTMENT_FIELD = 'LVS_Global_Dimension_1_Code';
const BC_FETCH_PROJECT_DEPARTMENT_MAX_AGE = 0;
const BC_FETCH_PROJECT_DEPARTMENT_CHUNK_SIZE = 20;

/**
 * Functies
 */
/**
 * Of een kolomstap de projectafdeling opnieuw moet lezen.
 * Een tussenchunk van projectdetails wacht tot de laatste chunk, zodat de
 * net opgehaalde kaarten niet meteen daarna weer de oude afdeling terugzetten.
 */
function bc_fetch_column_save_should_refresh_departments(string $action, string $columnKey, bool $chunkComplete): bool
{
    if ($action === 'fetch_sub_projects') {
        return true;
    }
    if ($action !== 'fetch_column_batch') {
        return false;
    }
    if ($columnKey === 'grootboekposten_ohw') {
        return true;
    }

    return $columnKey === 'project_details' && $chunkComplete;
}

/**
 * OData-query voor alleen het projectnummer en de afdeling.
 *
 * @return array{'$select':string,'$filter':string}
 */
function bc_fetch_project_department_odata_query(string $filter): array
{
    return [
        '$select' => 'No,' . BC_FETCH_PROJECT_DEPARTMENT_FIELD,
        '$filter' => $filter,
    ];
}

/**
 * Filter op projectnummer. Een apostrof in het nummer wordt geëscapet.
 *
 * @param array<int,mixed> $projectNumbers
 */
function bc_fetch_project_department_filter(array $projectNumbers): string
{
    $parts = [];
    foreach ($projectNumbers as $projectNo) {
        $text = trim((string) $projectNo);
        if ($text === '') {
            continue;
        }
        $parts[] = "No eq '" . str_replace("'", "''", $text) . "'";
    }

    return implode(' or ', $parts);
}

/**
 * Leest Projecten via de bestaande Mímir-laag.
 * max_age 0 gaat rechtstreeks naar odata_mimir_query. odata_get_all(0) zou
 * daar 3600 van maken en dus alsnog een uur cache teruggeven.
 *
 * @return list<array<string,mixed>>
 */
function bc_fetch_project_department_rows_from_bc(string $company, string $filter): array
{
    $rows = odata_mimir_query(
        $company,
        BC_FETCH_PROJECT_DEPARTMENT_ENTITY,
        bc_fetch_project_department_odata_query($filter),
        BC_FETCH_PROJECT_DEPARTMENT_MAX_AGE
    );

    return is_array($rows) ? $rows : [];
}

/**
 * Afdelingscode per genormaliseerd projectnummer, alleen uit LVS_Global_Dimension_1_Code.
 *
 * @param array<int,mixed> $rows
 * @return array<string,string>
 */
function bc_fetch_project_departments_from_rows(array $rows): array
{
    $byNorm = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $projectNo = trim((string) ($row['No'] ?? ''));
        $norm = bc_fetch_normalize_project_no($projectNo);
        if ($norm === '') {
            continue;
        }
        $byNorm[$norm] = trim((string) ($row[BC_FETCH_PROJECT_DEPARTMENT_FIELD] ?? ''));
    }

    return $byNorm;
}

/**
 * Verse afdeling per gevraagd project. Ontbreekt het project in BC, dan is de code leeg.
 * Een lege code vervangt een eerder opgeslagen afdeling.
 *
 * @param array<int,mixed> $projectNumbers
 * @return array<string,string>
 */
function bc_fetch_project_departments(string $company, array $projectNumbers, ?callable $readRows = null): array
{
    $readRows ??= 'bc_fetch_project_department_rows_from_bc';
    $unique = [];
    foreach ($projectNumbers as $projectNo) {
        $text = trim((string) $projectNo);
        $norm = bc_fetch_normalize_project_no($text);
        if ($norm === '' || isset($unique[$norm])) {
            continue;
        }
        $unique[$norm] = $text;
    }

    $found = [];
    $chunkSize = max(1, BC_FETCH_PROJECT_DEPARTMENT_CHUNK_SIZE);
    foreach (array_chunk($unique, $chunkSize, true) as $chunk) {
        $filter = bc_fetch_project_department_filter(array_values($chunk));
        if ($filter === '') {
            continue;
        }
        $rows = $readRows($company, $filter);
        if (!is_array($rows)) {
            throw new RuntimeException(
                'Projectafdeling ophalen mislukt (' . BC_FETCH_PROJECT_DEPARTMENT_ENTITY . '.' . BC_FETCH_PROJECT_DEPARTMENT_FIELD . ').'
            );
        }
        // array_merge hernummert numerieke sleutels (159927 wordt 0) en de afdeling valt dan weg.
        $found = array_replace($found, bc_fetch_project_departments_from_rows($rows));
    }

    $result = [];
    foreach ($unique as $norm => $_text) {
        $result[$norm] = array_key_exists($norm, $found) ? $found[$norm] : '';
    }

    return $result;
}

/**
 * Zet Cost_Center en de projectkaart op de verse afdeling.
 * Projecten die niet in de verse map staan verliezen de oude code.
 *
 * @param array<int,mixed> $summaries
 * @param array<string,mixed> $projectDetails
 * @param array<string,string> $departmentsByNorm
 * @return array{project_summaries:array<int,mixed>,project_details:array<string,mixed>}
 */
function bc_fetch_apply_project_departments(array $summaries, array $projectDetails, array $departmentsByNorm): array
{
    foreach ($summaries as $index => $summary) {
        if (!is_array($summary)) {
            continue;
        }
        $norm = bc_fetch_normalize_project_no((string) ($summary['Job_No'] ?? ''));
        if ($norm === '') {
            continue;
        }
        $summaries[$index]['Cost_Center'] = $departmentsByNorm[$norm] ?? '';
    }

    foreach ($projectDetails as $key => $row) {
        if (!is_array($row)) {
            continue;
        }
        $norm = bc_fetch_normalize_project_no((string) $key);
        if ($norm === '') {
            $norm = bc_fetch_normalize_project_no((string) ($row['No'] ?? ''));
        }
        if ($norm === '') {
            continue;
        }
        $projectDetails[$key][BC_FETCH_PROJECT_DEPARTMENT_FIELD] = $departmentsByNorm[$norm] ?? '';
    }

    return [
        'project_summaries' => $summaries,
        'project_details' => $projectDetails,
    ];
}

/**
 * @param array<string,mixed> $rowsByNorm
 * @param array<string,string> $departmentsByNorm
 * @return array<string,mixed>
 */
function bc_fetch_write_department_on_rows(array $rowsByNorm, array $departmentsByNorm): array
{
    foreach ($rowsByNorm as $key => $row) {
        if (!is_array($row)) {
            continue;
        }
        $norm = bc_fetch_normalize_project_no((string) ($row['No'] ?? $key));
        $rowsByNorm[$key][BC_FETCH_PROJECT_DEPARTMENT_FIELD] = $norm === ''
            ? ''
            : ($departmentsByNorm[$norm] ?? '');
    }

    return $rowsByNorm;
}

/**
 * Haalt de projectnummers uit de WIP-kaarten en schrijft de verse afdeling terug.
 * OHW-regels (Global_Dimension_1_Code) blijven staan; die zijn geen projectafdeling.
 *
 * @param array<string,mixed> $wip
 * @return array<string,mixed>
 */
function bc_fetch_apply_departments_to_wip(string $company, array $wip, ?callable $readRows = null): array
{
    $numbers = [];
    $collect = static function (mixed $row) use (&$numbers): void {
        if (!is_array($row)) {
            return;
        }
        $projectNo = trim((string) ($row['No'] ?? ''));
        if ($projectNo !== '') {
            $numbers[] = $projectNo;
        }
    };

    $openRows = is_array($wip['open_project_rows'] ?? null) ? $wip['open_project_rows'] : [];
    foreach ($openRows as $row) {
        $collect($row);
    }

    $legacyDetails = is_array($wip['project_details'] ?? null) ? $wip['project_details'] : [];
    foreach ($legacyDetails as $row) {
        $collect($row);
    }

    $column = is_array($wip['column_data_target']['project_details']['by_project'] ?? null)
        ? $wip['column_data_target']['project_details']['by_project']
        : [];
    foreach ($column as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $collect($entry['row'] ?? null);
    }

    if (is_array($wip['project_numbers'] ?? null)) {
        foreach ($wip['project_numbers'] as $projectNo) {
            $text = trim((string) $projectNo);
            if ($text !== '') {
                $numbers[] = $text;
            }
        }
    }

    if ($numbers === []) {
        return $wip;
    }

    $departments = bc_fetch_project_departments($company, $numbers, $readRows);
    if ($openRows !== []) {
        $wip['open_project_rows'] = bc_fetch_write_department_on_rows($openRows, $departments);
    }
    if ($legacyDetails !== []) {
        $wip['project_details'] = bc_fetch_write_department_on_rows($legacyDetails, $departments);
    }
    if ($column !== []) {
        foreach ($column as $key => $entry) {
            if (!is_array($entry) || !is_array($entry['row'] ?? null)) {
                continue;
            }
            $written = bc_fetch_write_department_on_rows([$entry['row']], $departments);
            $entry['row'] = $written[0];
            $column[$key] = $entry;
        }
        $wip['column_data_target']['project_details']['by_project'] = $column;
    }

    return $wip;
}
