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
        $financeService = new ProjectFinanceService($company);
        $forecast = $financeService->collectProjectForecastForProjects($projectNumbers, $ttl);
    } catch (Throwable $e) {
        return [
            'column' => 'planning',
            'by_project' => $dictionary,
            'warning' => $e->getMessage(),
        ];
    }

    $totals = is_array($forecast['forecast_totals_by_job'] ?? null) ? $forecast['forecast_totals_by_job'] : [];
    $breakdown = is_array($forecast['forecast_breakdown_by_job'] ?? null) ? $forecast['forecast_breakdown_by_job'] : [];

    foreach ($totals as $normProjectNo => $values) {
        if (!isset($dictionary[$normProjectNo])) {
            $dictionary[$normProjectNo] = [];
        }

        $dictionary[$normProjectNo]['totals'] = is_array($values) ? $values : [];
        $dictionary[$normProjectNo]['breakdown'] = is_array($breakdown[$normProjectNo] ?? null)
            ? $breakdown[$normProjectNo]
            : [
                'expected_revenue_lines' => [],
                'expected_costs_lines' => [],
                'extra_work_lines' => [],
            ];
    }

    return [
        'column' => 'planning',
        'by_project' => $dictionary,
        'warning' => null,
    ];
}
