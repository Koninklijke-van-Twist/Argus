<?php

/**
 * Includes/requires
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../project_finance.php';

/**
 * Functies
 */
/**
 * Geeft lege planning-structuur terug voor 1 project.
 */
function bc_fetch_empty_planning_project(): array
{
    return [
        'totals' => [
            'expected_revenue' => 0.0,
            'expected_costs' => 0.0,
            'extra_work' => 0.0,
        ],
        'breakdown' => [
            'expected_revenue_lines' => [],
            'expected_costs_lines' => [],
            'extra_work_lines' => [],
        ],
    ];
}

/**
 * Haalt planning op voor 1 projectnummer.
 */
function bc_fetch_column_planning_project(string $company, string $projectNumber, int $ttl): array
{
    $projectNo = trim($projectNumber);
    if ($projectNo === '') {
        return bc_fetch_empty_planning_project();
    }

    $financeService = new ProjectFinanceService($company);
    $forecast = $financeService->collectProjectForecastForProjects([$projectNo], $ttl);

    $normalizedProjectNo = bc_fetch_normalize_project_no($projectNo);
    $totalsByProject = is_array($forecast['forecast_totals_by_job'] ?? null)
        ? $forecast['forecast_totals_by_job']
        : [];
    $breakdownByProject = is_array($forecast['forecast_breakdown_by_job'] ?? null)
        ? $forecast['forecast_breakdown_by_job']
        : [];

    $result = bc_fetch_empty_planning_project();
    $totals = is_array($totalsByProject[$normalizedProjectNo] ?? null)
        ? $totalsByProject[$normalizedProjectNo]
        : [];
    $breakdown = is_array($breakdownByProject[$normalizedProjectNo] ?? null)
        ? $breakdownByProject[$normalizedProjectNo]
        : [];

    $result['totals']['expected_revenue'] = bc_fetch_float_value($totals, 'expected_revenue');
    $result['totals']['expected_costs'] = bc_fetch_float_value($totals, 'expected_costs');
    $result['totals']['extra_work'] = bc_fetch_float_value($totals, 'extra_work');
    $result['breakdown']['expected_revenue_lines'] = is_array($breakdown['expected_revenue_lines'] ?? null)
        ? $breakdown['expected_revenue_lines']
        : [];
    $result['breakdown']['expected_costs_lines'] = is_array($breakdown['expected_costs_lines'] ?? null)
        ? $breakdown['expected_costs_lines']
        : [];
    $result['breakdown']['extra_work_lines'] = is_array($breakdown['extra_work_lines'] ?? null)
        ? $breakdown['extra_work_lines']
        : [];

    return $result;
}

/**
 * Haalt planningsregels op voor de projectnummers van de maand.
 */
function bc_fetch_column_planning(string $company, string $yearMonth, array $projectNumbers, array $auth, int $ttl): array
{
    $dictionary = bc_fetch_seed_project_dictionary($projectNumbers);
    if ($projectNumbers === []) {
        return [
            'column' => 'planning',
            'by_project' => $dictionary,
            'warning' => null,
        ];
    }

    try {
        foreach ($projectNumbers as $projectNumber) {
            $projectNo = trim((string) $projectNumber);
            if ($projectNo === '') {
                continue;
            }

            $normProjectNo = bc_fetch_normalize_project_no($projectNo);
            $dictionary[$normProjectNo] = bc_fetch_column_planning_project($company, $projectNo, $ttl);
        }
    } catch (Throwable $e) {
        return [
            'column' => 'planning',
            'by_project' => $dictionary,
            'warning' => $e->getMessage(),
        ];
    }

    return [
        'column' => 'planning',
        'by_project' => $dictionary,
        'warning' => null,
    ];
}
