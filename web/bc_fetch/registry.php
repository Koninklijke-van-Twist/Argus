<?php

/**
 * Includes/requires
 */
require_once __DIR__ . '/project_numbers.php';
require_once __DIR__ . '/column_workorders.php';
require_once __DIR__ . '/column_projectposten.php';
require_once __DIR__ . '/column_project_details.php';
require_once __DIR__ . '/column_planning.php';
require_once __DIR__ . '/column_invoices.php';

/**
 * Functies
 */
/**
 * Centrale kolomdefinitie voor de maand-loader.
 */
function bc_fetch_column_registry(): array
{
    return [
        'workorders' => [
            'label' => 'Werkorders',
            'function' => 'bc_fetch_column_workorders',
        ],
        'projectposten' => [
            'label' => 'ProjectPosten',
            'function' => 'bc_fetch_column_projectposten',
        ],
        'project_details' => [
            'label' => 'Projectdetails',
            'function' => 'bc_fetch_column_project_details',
        ],
        'planning' => [
            'label' => 'Planningsregels',
            'function' => 'bc_fetch_column_planning',
        ],
        'invoices' => [
            'label' => 'Facturen',
            'function' => 'bc_fetch_column_invoices',
        ],
    ];
}

/**
 * Voert een kolomfetch uit via de centrale registry.
 */
function bc_fetch_run_column(string $columnKey, string $company, string $yearMonth, array $projectNumbers, array $auth, int $ttl): array
{
    $registry = bc_fetch_column_registry();
    $column = $registry[$columnKey] ?? null;
    if (!is_array($column)) {
        throw new RuntimeException('Onbekende kolom: ' . $columnKey);
    }

    $functionName = (string) ($column['function'] ?? '');
    if ($functionName === '' || !function_exists($functionName)) {
        throw new RuntimeException('Kolomfunctie ontbreekt voor: ' . $columnKey);
    }

    return $functionName($company, $yearMonth, $projectNumbers, $auth, $ttl);
}
