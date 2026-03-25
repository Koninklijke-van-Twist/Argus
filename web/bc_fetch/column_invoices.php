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
 * Haalt factuurdata op voor de projectnummers van de maand.
 */
function bc_fetch_column_invoices(string $company, string $yearMonth, array $projectNumbers, array $auth, int $ttl): array
{
    $dictionary = bc_fetch_seed_project_dictionary($projectNumbers);
    if ($projectNumbers === []) {
        return [
            'column' => 'invoices',
            'by_project' => $dictionary,
            'invoice_details_by_id' => [],
            'warning' => null,
        ];
    }

    try {
        $financeService = new ProjectFinanceService($company);
        $invoiceData = $financeService->collectProjectInvoicesForProjects($projectNumbers, $ttl);
    } catch (Throwable $e) {
        return [
            'column' => 'invoices',
            'by_project' => $dictionary,
            'invoice_details_by_id' => [],
            'warning' => $e->getMessage(),
        ];
    }

    $invoiceIdsByJob = is_array($invoiceData['project_invoice_ids_by_job'] ?? null)
        ? $invoiceData['project_invoice_ids_by_job']
        : [];
    $invoicedTotalByJob = is_array($invoiceData['project_invoiced_total_by_job'] ?? null)
        ? $invoiceData['project_invoiced_total_by_job']
        : [];
    $invoiceDetailsById = is_array($invoiceData['invoice_details_by_id'] ?? null)
        ? $invoiceData['invoice_details_by_id']
        : [];

    foreach ($invoiceIdsByJob as $normProjectNo => $ids) {
        if (!isset($dictionary[$normProjectNo])) {
            $dictionary[$normProjectNo] = [];
        }

        $dictionary[$normProjectNo]['invoice_ids'] = is_array($ids) ? array_values($ids) : [];
        $dictionary[$normProjectNo]['invoiced_total'] = finance_to_float($invoicedTotalByJob[$normProjectNo] ?? 0.0);
    }

    return [
        'column' => 'invoices',
        'by_project' => $dictionary,
        'invoice_details_by_id' => $invoiceDetailsById,
        'warning' => null,
    ];
}
