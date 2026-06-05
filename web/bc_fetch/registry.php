<?php

/**
 * Includes/requires
 */
require_once __DIR__ . '/project_numbers.php';
require_once __DIR__ . '/column_grootboekposten_ohw.php';
require_once __DIR__ . '/column_project_details.php';
require_once __DIR__ . '/column_planning.php';

/**
 * Functies
 */
/**
 * Centrale kolomdefinitie voor de maand-loader.
 */
function bc_fetch_column_registry(): array
{
    return [
        'grootboekposten_ohw' => [
            'label' => 'Grootboekposten OHW',
            'function' => 'bc_fetch_column_grootboekposten_ohw',
            'target_scoped' => true,
        ],
        'project_details' => [
            'label' => 'Projectdetails',
            'function' => 'bc_fetch_column_project_details',
            'target_scoped' => true,
        ],
        'planning' => [
            'label' => 'Planningsregels',
            'function' => 'bc_fetch_column_planning',
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

/**
 * Kolomkeys die één keer per snapshot (doelmaand) worden opgehaald, niet per batch-maand.
 */
function bc_fetch_target_scoped_column_keys(): array
{
    $keys = [];
    foreach (bc_fetch_column_registry() as $key => $cfg) {
        if (!empty($cfg['target_scoped'])) {
            $keys[] = (string) $key;
        }
    }

    return $keys;
}
