<?php

require_once __DIR__ . '/finance_calculations.php';

/**
 * Usage summary:
 * - Laad eerst auth.php en odata.php.
 * - Gebruik deze class als enige bron voor kosten/opbrengsten/facturen.
 * - Instantieer per bedrijf om naam-overlaps en losse company-argumenten te voorkomen.
 * - Pas tabellen/kolommen voor kosten-opbrengst aan in financeDataConfig().
 * - Voor zowel project als werkorder kun je kosten en opbrengst op aparte tabellen zetten.
 * - revenue_row_mode accepteert waarden uit REVENUE_ROW_MODE_OPTIONS.
 * - Zowel 1-regel als meer-regel bronnen worden geaggregeerd naar 1 totaal per project/werkorder.
 * - Voorbeelden:
 *   $finance = new ProjectFinanceService($company);
 *   $project = $finance->getProjectCostsAndRevenue($projectNumber);
 *   $workorder = $finance->getWorkorderCostsAndRevenue($workorderNumber);
 *   $invoices = $finance->getProjectInvoices($projectNumber);
 *   $withWorkorders = $finance->getProjectFinanceWithWorkorders($projectNumber);
 */
class ProjectFinanceService
{
    public const ROW_MODE_FIRST_NUMERIC = 'first_numeric';
    public const ROW_MODE_SUM_RAW = 'sum_raw';
    public const ROW_MODE_SUM = 'sum';
    public const ROW_MODE_SUM_INVERT = 'sum_invert';
    public const REVENUE_ROW_MODE_FIRST_NUMERIC = self::ROW_MODE_FIRST_NUMERIC;
    public const REVENUE_ROW_MODE_SUM_RAW = self::ROW_MODE_SUM_RAW;
    public const REVENUE_ROW_MODE_SUM = self::ROW_MODE_SUM;
    public const REVENUE_ROW_MODE_SUM_INVERT = self::ROW_MODE_SUM_INVERT;
    public const REVENUE_ROW_MODE_OPTIONS = [
        self::REVENUE_ROW_MODE_FIRST_NUMERIC,
        self::REVENUE_ROW_MODE_SUM_RAW,
        self::REVENUE_ROW_MODE_SUM,
        self::REVENUE_ROW_MODE_SUM_INVERT,
    ];

    private string $company;
    private string $baseUrl;
    private string $environment;
    private array $auth;

    /**
     * Initialiseert de service voor een specifiek bedrijf en environment.
     * Als environment leeg is, gebruikt het de primaire environment.
     */
    public function __construct(string $company, string $environment = '')
    {
        global $baseUrl, $auth_list;

        $this->company = trim($company);
        $this->baseUrl = trim((string) $baseUrl);

        if ($this->baseUrl === '') {
            throw new RuntimeException('baseUrl ontbreekt in auth.php.');
        }

        // Bepaal environment
        $environmentToUse = trim($environment);
        if ($environmentToUse === '') {
            $environmentToUse = auth_get_primary_environment();
        }

        if ($environmentToUse === '') {
            throw new RuntimeException('Geen environment beschikbaar.');
        }

        $this->environment = $environmentToUse;
        $this->auth = auth_get_auth_for_environment($this->environment);
    }

    /**
     * Interne tabel/kolom-configuratie voor kosten en opbrengst.
     *
     * Aanpassen:
     * - project en workorder hebben elk een cost_source en revenue_source.
     * - Per source stel je in:
     *   - entity_set: OData tabelnaam.
     *   - key_field: sleutel voor 1 project/werkorder.
     *   - project_field (alleen workorder): koppeling naar projectnummer.
     *   - fields: kolommen waar waarden uit komen.
     *   - row_mode, cost_row_mode of revenue_row_mode: ROW_MODE_FIRST_NUMERIC, ROW_MODE_SUM_RAW, ROW_MODE_SUM of ROW_MODE_SUM_INVERT.
     *
     * Gedrag:
     * - Meerdere regels met dezelfde sleutel worden opgeteld.
     * - Per regel wordt bedrag bepaald via de ingestelde row mode.
     */
    private static function financeDataConfig(): array
    {
        return [
            'invoice_sources' => [
                [
                    'entity' => 'SalesInvoiceLines',
                    'select' => 'Document_No,Sell_to_Customer_No,Variant_Code,Description,Amount,Amount_Including_VAT,Line_Discount_Percent,Line_Discount_Amount,Job_No,Type',
                    'amount_field' => 'Amount',
                    'amount_incl_field' => 'Amount_Including_VAT',
                ],
                [
                    'entity' => 'SalesLines',
                    'select' => 'Document_No,Sell_to_Customer_No,Variant_Code,Description,Line_Amount,Line_Discount_Percent,Job_No,Type',
                    'amount_field' => 'Line_Amount',
                    'amount_incl_field' => 'Line_Amount',
                ],
            ],
            // Voorcalculatie (statisch, huidige BC-stand):
            // - kosten: ProjectTaken.LVS_Baseline_Total_Cost voor Job_Task_No '000' (Project TOTAAL / basislijn)
            // - opbrengst: ProjectTaken.LVS_Schedule_Total_Price_2 voor Job_Task_No '000' (schedule/budget)
            //   Aanneemsom en opbrengst meerwerk blijven FactureerbareProjectPlanningsRegels G/L 800000
            //   en horen niet in Opbrengst VC. Meerwerk = gevuld LVS_Job_Change_Order_No.
            'project_forecast' => [
                'cost_entity_set' => 'ProjectTaken',
                'revenue_entity_set' => 'ProjectTaken',
                'project_key_field' => 'Job_No',
            ],
            'project' => [
                'cost_source' => [
                    'entity_set' => 'ProjectPosten',
                    'key_field' => 'Job_No',
                    'fields' => [
                        'Total_Cost',
                    ],
                    'filter' => "Entry_Type eq 'Gebruik'",
                    'row_mode' => self::ROW_MODE_SUM_RAW,
                ],
                'revenue_source' => [
                    'entity_set' => 'ProjectPosten',
                    'key_field' => 'Job_No',
                    'fields' => [
                        'Line_Amount',
                    ],
                    'filter' => "Entry_Type ne 'Gebruik'",
                    'row_mode' => self::ROW_MODE_SUM_INVERT,
                ],
            ],
            'workorder' => [
                'cost_source' => [
                    'entity_set' => 'ProjectPosten',
                    'key_field' => 'Job_Task_No',
                    'project_field' => 'Job_No',
                    'fields' => [
                        'Total_Cost',
                    ],
                    'filter' => "Entry_Type eq 'Gebruik'",
                    'row_mode' => self::ROW_MODE_SUM_RAW,
                ],
                'revenue_source' => [
                    'entity_set' => 'ProjectPosten',
                    'key_field' => 'Job_Task_No',
                    'project_field' => 'Job_No',
                    'fields' => [
                        'Line_Amount',
                    ],
                    'filter' => "Entry_Type ne 'Gebruik'",
                    'row_mode' => self::ROW_MODE_SUM_INVERT,
                ],
            ],
        ];
    }

    /**
     * Leest geconfigureerde factuurbronnen.
     */
    private static function getInvoiceSourcesConfig(): array
    {
        $config = self::financeDataConfig();
        $invoiceSources = is_array($config['invoice_sources'] ?? null) ? $config['invoice_sources'] : [];

        return array_values(array_filter($invoiceSources, static function ($source): bool {
            return is_array($source);
        }));
    }

    /**
     * Leest en valideert een geconfigureerde amount source op naam.
     */
    private function getAmountSourceConfig(string $scope, string $sourceName): array
    {
        $config = self::financeDataConfig();
        $scopeConfig = $config[$scope] ?? null;

        if (!is_array($scopeConfig)) {
            throw new RuntimeException('Onbekende finance configuratiescope: ' . $scope);
        }

        $selected = $scopeConfig[$sourceName] ?? null;

        if (!is_array($selected)) {
            throw new RuntimeException('Onbekende finance configuratiebron: ' . $scope . '.' . $sourceName);
        }

        $entitySet = trim((string) ($selected['entity_set'] ?? ''));
        $keyField = trim((string) ($selected['key_field'] ?? ''));

        if ($entitySet === '' || $keyField === '') {
            throw new RuntimeException('Finance configuratie mist entity_set of key_field voor bron: ' . $scope . '.' . $sourceName);
        }

        $selected['entity_set'] = $entitySet;
        $selected['key_field'] = $keyField;
        $selected['fields'] = is_array($selected['fields'] ?? null) ? $selected['fields'] : [];
        $selected['filter'] = trim((string) ($selected['filter'] ?? ''));
        $modeValue = $selected['row_mode'] ?? null;
        if ($modeValue === null && $sourceName === 'cost_source') {
            $modeValue = $selected['cost_row_mode'] ?? null;
        }
        if ($modeValue === null && $sourceName === 'revenue_source') {
            $modeValue = $selected['revenue_row_mode'] ?? null;
        }
        $selected['row_mode'] = self::normalizeRowMode((string) ($modeValue ?? self::ROW_MODE_FIRST_NUMERIC));

        if ($scope === 'workorder') {
            $projectField = trim((string) ($selected['project_field'] ?? ''));
            if ($projectField === '') {
                throw new RuntimeException('Finance configuratie mist project_field voor workorder bron.');
            }
            $selected['project_field'] = $projectField;
        }

        return $selected;
    }

    /**
     * Haalt projecttotalen, factuurdetails en factuursommen op voor meerdere projecten.
     */
    public function collectProjectFinanceForProjects(array $projectNumbers, int $ttl = 3600): array
    {
        $projectCostSource = $this->getAmountSourceConfig('project', 'cost_source');
        $projectRevenueSource = $this->getAmountSourceConfig('project', 'revenue_source');

        $projectTotalsByJob = self::combineTotalsByKey(
            $this->fetchTotalsForKeys($projectCostSource, $projectNumbers, $ttl),
            $this->fetchTotalsForKeys($projectRevenueSource, $projectNumbers, $ttl)
        );
        $invoiceData = $this->collectProjectInvoicesForProjects($projectNumbers, $ttl);

        return [
            'project_totals_by_job' => $projectTotalsByJob,
            'invoice_details_by_id' => is_array($invoiceData['invoice_details_by_id'] ?? null) ? $invoiceData['invoice_details_by_id'] : [],
            'project_invoice_ids_by_job' => is_array($invoiceData['project_invoice_ids_by_job'] ?? null) ? $invoiceData['project_invoice_ids_by_job'] : [],
            'project_invoiced_total_by_job' => is_array($invoiceData['project_invoiced_total_by_job'] ?? null) ? $invoiceData['project_invoiced_total_by_job'] : [],
        ];
    }

    /**
     * Haalt factuurdata op voor meerdere projecten zonder kosten/opbrengst-query op ProjectPosten.
     */
    public function collectProjectInvoicesForProjects(array $projectNumbers, int $ttl = 3600): array
    {
        $invoiceDetailsById = [];
        $projectInvoiceIdsByJob = [];
        $projectInvoicedTotalByJob = [];

        $projectNumberChunks = self::chunkValues($projectNumbers, 25);
        $invoiceSources = self::getInvoiceSourcesConfig();

        foreach ($invoiceSources as $invoiceSource) {
            $entity = trim((string) ($invoiceSource['entity'] ?? ''));
            $selectFields = trim((string) ($invoiceSource['select'] ?? ''));
            $amountField = trim((string) ($invoiceSource['amount_field'] ?? ''));
            $amountInclField = trim((string) ($invoiceSource['amount_incl_field'] ?? ''));

            if ($entity === '' || $selectFields === '' || $amountField === '') {
                continue;
            }

            foreach ($projectNumberChunks as $projectChunk) {
                $jobFilters = [];
                foreach ($projectChunk as $projectNo) {
                    $projectNoText = trim((string) $projectNo);
                    if ($projectNoText === '') {
                        continue;
                    }
                    $jobFilters[] = "Job_No eq '" . self::escapeOdataString($projectNoText) . "'";
                }

                if ($jobFilters === []) {
                    continue;
                }

                try {
                    $invoiceUrl = $this->companyEntityUrlWithQuery($entity, [
                        '$select' => $selectFields,
                        '$filter' => implode(' or ', $jobFilters),
                    ]);
                    $invoiceRows = odata_get_all($invoiceUrl, $this->auth, $ttl);
                } catch (Throwable $ignoredSalesInvoiceSourceError) {
                    continue;
                }

                foreach ($invoiceRows as $invoiceRow) {
                    if (!is_array($invoiceRow)) {
                        continue;
                    }

                    $jobNo = trim((string) ($invoiceRow['Job_No'] ?? ''));
                    $invoiceId = trim((string) ($invoiceRow['Document_No'] ?? ''));
                    if ($jobNo === '' || $invoiceId === '') {
                        continue;
                    }

                    $normalizedJobNo = self::normalizeMatchValue($jobNo);
                    $amountRaw = $invoiceRow[$amountField] ?? null;
                    $amount = is_numeric($amountRaw) ? abs((float) $amountRaw) : 0.0;

                    $amountInclRaw = $amountInclField !== '' ? ($invoiceRow[$amountInclField] ?? null) : null;
                    $amountIncludingVat = is_numeric($amountInclRaw) ? abs((float) $amountInclRaw) : $amount;

                    $lineDiscountPercentRaw = $invoiceRow['Line_Discount_Percent'] ?? null;
                    $lineDiscountPercent = is_numeric($lineDiscountPercentRaw) ? (float) $lineDiscountPercentRaw : 0.0;

                    $lineDiscountAmountRaw = $invoiceRow['Line_Discount_Amount'] ?? null;
                    $lineDiscountAmount = is_numeric($lineDiscountAmountRaw) ? abs((float) $lineDiscountAmountRaw) : 0.0;

                    $customerNo = trim((string) ($invoiceRow['Sell_to_Customer_No'] ?? ''));
                    $variantCode = trim((string) ($invoiceRow['Variant_Code'] ?? ''));
                    $description = trim((string) ($invoiceRow['Description'] ?? ''));

                    if (!isset($invoiceDetailsById[$invoiceId])) {
                        $invoiceDetailsById[$invoiceId] = [
                            'Invoice_Id' => $invoiceId,
                            'Source_Entity' => $entity,
                            'Source_Entities' => [],
                            'Lines' => [],
                            '_seen_lines' => [],
                        ];
                    }

                    $invoiceDetailsById[$invoiceId]['Source_Entities'][$entity] = true;

                    $linePayload = [
                        'Source_Entity' => $entity,
                        'Customer_No' => $customerNo,
                        'Variant_Code' => $variantCode,
                        'Description' => $description,
                        'Amount' => $amount,
                        'Amount_Including_Vat' => $amountIncludingVat,
                        'Line_Discount_Percent' => $lineDiscountPercent,
                        'Line_Discount_Amount' => $lineDiscountAmount,
                    ];
                    $lineDedupKey = implode('|', [
                        $customerNo,
                        $variantCode,
                        $description,
                        (string) $amount,
                        (string) $amountIncludingVat,
                        (string) $lineDiscountPercent,
                        (string) $lineDiscountAmount,
                    ]);

                    if (!isset($invoiceDetailsById[$invoiceId]['_seen_lines'][$lineDedupKey])) {
                        $invoiceDetailsById[$invoiceId]['_seen_lines'][$lineDedupKey] = true;
                        $invoiceDetailsById[$invoiceId]['Lines'][] = $linePayload;
                    }

                    if (!isset($projectInvoiceIdsByJob[$normalizedJobNo])) {
                        $projectInvoiceIdsByJob[$normalizedJobNo] = [];
                    }
                    $projectInvoiceIdsByJob[$normalizedJobNo][$invoiceId] = true;

                    if (!isset($projectInvoicedTotalByJob[$normalizedJobNo])) {
                        $projectInvoicedTotalByJob[$normalizedJobNo] = 0.0;
                    }
                    $projectInvoicedTotalByJob[$normalizedJobNo] += $amount;
                }
            }
        }

        foreach ($invoiceDetailsById as $invoiceId => $details) {
            if (!is_array($details)) {
                continue;
            }

            $sourceEntitiesMap = is_array($details['Source_Entities'] ?? null)
                ? $details['Source_Entities']
                : [];
            $sourceEntities = array_keys($sourceEntitiesMap);
            usort($sourceEntities, static function (string $left, string $right): int {
                return strnatcasecmp($left, $right);
            });

            $invoiceDetailsById[$invoiceId]['Source_Entities'] = $sourceEntities;
            if ($sourceEntities !== []) {
                $invoiceDetailsById[$invoiceId]['Source_Entity'] = $sourceEntities[0];
            }

            unset($invoiceDetailsById[$invoiceId]['_seen_lines']);
        }

        foreach ($projectInvoiceIdsByJob as $normalizedJobNo => $invoiceIdMap) {
            if (!is_array($invoiceIdMap)) {
                continue;
            }

            $invoiceIds = array_keys($invoiceIdMap);
            usort($invoiceIds, static function (string $left, string $right): int {
                return strnatcasecmp($left, $right);
            });
            $projectInvoiceIdsByJob[$normalizedJobNo] = $invoiceIds;
        }

        return [
            'invoice_details_by_id' => $invoiceDetailsById,
            'project_invoice_ids_by_job' => $projectInvoiceIdsByJob,
            'project_invoiced_total_by_job' => $projectInvoicedTotalByJob,
        ];
    }

    /**
     * Haalt ProjectPosten exact één keer op binnen een datumrange en aggregeert daarna project- en werkordertotalen.
     */
    public function collectProjectAndWorkorderFinanceFromProjectPostenRange(string $fromDate, string $toDateExclusive, int $ttl = 3600): array
    {
        $projectCostSource = $this->getAmountSourceConfig('project', 'cost_source');
        $projectRevenueSource = $this->getAmountSourceConfig('project', 'revenue_source');
        $workorderCostSource = $this->getAmountSourceConfig('workorder', 'cost_source');
        $workorderRevenueSource = $this->getAmountSourceConfig('workorder', 'revenue_source');

        $entitySet = (string) ($projectCostSource['entity_set'] ?? '');
        $projectKeyField = (string) ($projectCostSource['key_field'] ?? 'Job_No');
        $workorderKeyField = (string) ($workorderCostSource['key_field'] ?? 'Job_Task_No');
        $dateField = 'Posting_Date';

        $selectFields = array_values(array_unique(array_filter(array_merge(
            [$projectKeyField, $workorderKeyField, $dateField],
            is_array($projectCostSource['fields'] ?? null) ? $projectCostSource['fields'] : [],
            is_array($projectRevenueSource['fields'] ?? null) ? $projectRevenueSource['fields'] : [],
            is_array($workorderCostSource['fields'] ?? null) ? $workorderCostSource['fields'] : [],
            is_array($workorderRevenueSource['fields'] ?? null) ? $workorderRevenueSource['fields'] : []
        ), static function ($field): bool {
            return is_string($field) && trim($field) !== '';
        })));

        $queryFilter = $dateField . ' ge ' . $fromDate . ' and ' . $dateField . ' lt ' . $toDateExclusive;

        try {
            $url = $this->companyEntityUrlWithQuery($entitySet, [
                '$select' => implode(',', $selectFields),
                '$filter' => $queryFilter,
            ]);
            $rows = odata_get_all($url, $this->auth, $ttl);
        } catch (Throwable $loadError) {
            throw new RuntimeException(
                'Finance bron ophalen mislukt voor ' . $entitySet . ' met daterange-filter: ' . $queryFilter,
                0,
                $loadError
            );
        }

        $revenueRows = array_values(array_filter($rows, static function ($row): bool {
            if (!is_array($row)) {
                return false;
            }
            $entryType = strtolower(trim((string) ($row['Entry_Type'] ?? $row['Type'] ?? '')));
            return $entryType !== 'gebruik';
        }));

        $projectTotalsByJob = self::combineTotalsByKey(
            self::aggregateAmountByKey(
                $rows,
                $projectKeyField,
                is_array($projectCostSource['fields'] ?? null) ? $projectCostSource['fields'] : [],
                (string) ($projectCostSource['row_mode'] ?? self::ROW_MODE_FIRST_NUMERIC)
            ),
            self::aggregateAmountByKey(
                $revenueRows,
                $projectKeyField,
                is_array($projectRevenueSource['fields'] ?? null) ? $projectRevenueSource['fields'] : [],
                (string) ($projectRevenueSource['row_mode'] ?? self::ROW_MODE_FIRST_NUMERIC)
            )
        );

        $workorderTotalsByNumber = self::combineTotalsByKey(
            self::aggregateAmountByKey(
                $rows,
                $workorderKeyField,
                is_array($workorderCostSource['fields'] ?? null) ? $workorderCostSource['fields'] : [],
                (string) ($workorderCostSource['row_mode'] ?? self::ROW_MODE_FIRST_NUMERIC)
            ),
            self::aggregateAmountByKey(
                $revenueRows,
                $workorderKeyField,
                is_array($workorderRevenueSource['fields'] ?? null) ? $workorderRevenueSource['fields'] : [],
                (string) ($workorderRevenueSource['row_mode'] ?? self::ROW_MODE_FIRST_NUMERIC)
            )
        );

        $projectNumbers = [];
        $workorderNumbers = [];
        $seenProjectNos = [];
        $seenWorkorderNos = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $jobNo = trim((string) ($row[$projectKeyField] ?? ''));
            if ($jobNo !== '' && !isset($seenProjectNos[$jobNo])) {
                $seenProjectNos[$jobNo] = true;
                $projectNumbers[] = $jobNo;
            }

            $jobTaskNo = trim((string) ($row[$workorderKeyField] ?? ''));
            if ($jobTaskNo !== '' && !isset($seenWorkorderNos[$jobTaskNo])) {
                $seenWorkorderNos[$jobTaskNo] = true;
                $workorderNumbers[] = $jobTaskNo;
            }
        }

        return [
            'project_totals_by_job' => $projectTotalsByJob,
            'workorder_totals_by_number' => $workorderTotalsByNumber,
            'project_numbers' => $projectNumbers,
            'workorder_numbers' => $workorderNumbers,
        ];
    }

    /**
     * Haalt voorcalculatie op per project (huidige BC-stand, geen historie).
     *
     * Kosten: ProjectTaken.LVS_Baseline_Total_Cost (Job_Task_No 000 / Project TOTAAL).
     * Opbrengst: ProjectTaken.LVS_Schedule_Total_Price_2 (Job_Task_No 000 / schedule-prijs).
     * Aanneemsom en opbrengst meerwerk: FactureerbareProjectPlanningsRegels, G/L 800000, factureerbaar.
     * Meerwerk is het deel met gevuld LVS_Job_Change_Order_No; aanneemsom is het deel zonder.
     */
    public function collectProjectForecastForProjects(array $projectNumbers, int $ttl = 3600): array
    {
        $totalsByProject = [];
        $breakdownByProject = [];
        foreach (self::chunkValues($projectNumbers, 25) as $chunk) {
            foreach ($chunk as $projectNo) {
                $normalizedProject = self::normalizeMatchValue((string) $projectNo);
                if ($normalizedProject === '') {
                    continue;
                }

                if (!isset($totalsByProject[$normalizedProject])) {
                    $totalsByProject[$normalizedProject] = [
                        'expected_revenue' => 0.0,
                        'expected_costs' => 0.0,
                        'extra_work' => 0.0,
                        'aanneemsom' => 0.0,
                    ];
                    $breakdownByProject[$normalizedProject] = [
                        'expected_revenue_lines' => [],
                        'expected_costs_lines' => [],
                        'extra_work_lines' => [],
                        'aanneemsom_lines' => [],
                    ];
                }
            }
        }

        $costData = $this->fetchVoorcalculatieCostsForProjects($projectNumbers, $ttl);
        $revenueData = $this->fetchVoorcalculatieRevenueForProjects($projectNumbers, $ttl);
        $contractRevenueData = $this->fetchContractRevenueForProjects($projectNumbers, $ttl);
        $forecastWarning = self::joinForecastWarnings([
            $costData['warning'] ?? null,
            $revenueData['warning'] ?? null,
            $contractRevenueData['warning'] ?? null,
        ]);

        foreach ($costData['totals'] as $normalizedProject => $amount) {
            if (!isset($totalsByProject[$normalizedProject])) {
                $totalsByProject[$normalizedProject] = [
                    'expected_revenue' => 0.0,
                    'expected_costs' => 0.0,
                    'extra_work' => 0.0,
                    'aanneemsom' => 0.0,
                ];
                $breakdownByProject[$normalizedProject] = [
                    'expected_revenue_lines' => [],
                    'expected_costs_lines' => [],
                    'extra_work_lines' => [],
                    'aanneemsom_lines' => [],
                ];
            }

            $totalsByProject[$normalizedProject]['expected_costs'] = finance_add_amount(
                (float) ($totalsByProject[$normalizedProject]['expected_costs'] ?? 0.0),
                (float) $amount
            );
        }

        foreach ($costData['breakdown'] as $normalizedProject => $lines) {
            if (!isset($breakdownByProject[$normalizedProject])) {
                $breakdownByProject[$normalizedProject] = [
                    'expected_revenue_lines' => [],
                    'expected_costs_lines' => [],
                    'extra_work_lines' => [],
                    'aanneemsom_lines' => [],
                ];
            }

            $breakdownByProject[$normalizedProject]['expected_costs_lines'] = array_merge(
                $breakdownByProject[$normalizedProject]['expected_costs_lines'],
                is_array($lines) ? $lines : []
            );
        }

        foreach ($revenueData['totals'] as $normalizedProject => $amount) {
            if (!isset($totalsByProject[$normalizedProject])) {
                $totalsByProject[$normalizedProject] = [
                    'expected_revenue' => 0.0,
                    'expected_costs' => 0.0,
                    'extra_work' => 0.0,
                    'aanneemsom' => 0.0,
                ];
                $breakdownByProject[$normalizedProject] = [
                    'expected_revenue_lines' => [],
                    'expected_costs_lines' => [],
                    'extra_work_lines' => [],
                    'aanneemsom_lines' => [],
                ];
            }

            $totalsByProject[$normalizedProject]['expected_revenue'] = finance_add_amount(
                (float) ($totalsByProject[$normalizedProject]['expected_revenue'] ?? 0.0),
                (float) $amount
            );
        }

        foreach ($revenueData['breakdown'] as $normalizedProject => $lines) {
            if (!isset($breakdownByProject[$normalizedProject])) {
                $breakdownByProject[$normalizedProject] = [
                    'expected_revenue_lines' => [],
                    'expected_costs_lines' => [],
                    'extra_work_lines' => [],
                    'aanneemsom_lines' => [],
                ];
            }

            $breakdownByProject[$normalizedProject]['expected_revenue_lines'] = array_merge(
                $breakdownByProject[$normalizedProject]['expected_revenue_lines'],
                is_array($lines) ? $lines : []
            );
        }

        foreach (['aanneemsom', 'extra_work'] as $totalKey) {
            $sourceTotals = is_array($contractRevenueData[$totalKey]['totals'] ?? null)
                ? $contractRevenueData[$totalKey]['totals']
                : [];
            foreach ($sourceTotals as $normalizedProject => $amount) {
                if (!isset($totalsByProject[$normalizedProject])) {
                    $totalsByProject[$normalizedProject] = [
                        'expected_revenue' => 0.0,
                        'expected_costs' => 0.0,
                        'extra_work' => 0.0,
                        'aanneemsom' => 0.0,
                    ];
                    $breakdownByProject[$normalizedProject] = [
                        'expected_revenue_lines' => [],
                        'expected_costs_lines' => [],
                        'extra_work_lines' => [],
                        'aanneemsom_lines' => [],
                    ];
                }

                $totalsByProject[$normalizedProject][$totalKey] = finance_add_amount(
                    (float) ($totalsByProject[$normalizedProject][$totalKey] ?? 0.0),
                    (float) $amount
                );
            }

            $lineKey = $totalKey === 'aanneemsom' ? 'aanneemsom_lines' : 'extra_work_lines';
            $sourceLines = is_array($contractRevenueData[$totalKey]['breakdown'] ?? null)
                ? $contractRevenueData[$totalKey]['breakdown']
                : [];
            foreach ($sourceLines as $normalizedProject => $lines) {
                if (!isset($breakdownByProject[$normalizedProject])) {
                    $breakdownByProject[$normalizedProject] = [
                        'expected_revenue_lines' => [],
                        'expected_costs_lines' => [],
                        'extra_work_lines' => [],
                        'aanneemsom_lines' => [],
                    ];
                }

                $breakdownByProject[$normalizedProject][$lineKey] = array_merge(
                    $breakdownByProject[$normalizedProject][$lineKey],
                    is_array($lines) ? $lines : []
                );
            }
        }

        return [
            'forecast_totals_by_job' => $totalsByProject,
            'forecast_breakdown_by_job' => $breakdownByProject,
            'warning' => $forecastWarning,
        ];
    }

    /**
     * Voorcalculatie kosten uit ProjectTaken (LVS_Baseline_Total_Cost van taak 000 / Project TOTAAL).
     *
     * Eén basislijnwaarde per project; niet sommeren over taakregels of LVS_Baseline_*.
     *
     * @return array{totals:array<string,float>,breakdown:array<string,array<int,array<string,mixed>>>,warning:?string}
     */
    private function fetchVoorcalculatieCostsForProjects(array $projectNumbers, int $ttl): array
    {
        $totals = [];
        $breakdown = [];
        $projectTotaalTaskNo = '000';
        $loaded = $this->fetchRowsForJobChunks(
            $projectNumbers,
            $ttl,
            8,
            function (string $projectFilter) use ($projectTotaalTaskNo): string {
                return $this->companyEntityUrlWithQuery('ProjectTaken', [
                    '$select' => 'Job_No,Job_Task_No,Description,LVS_Baseline_Total_Cost',
                    '$filter' => $projectFilter . " and Job_Task_No eq '" . self::escapeOdataString($projectTotaalTaskNo) . "'",
                ]);
            },
            'Voorcalculatie kosten ophalen mislukt (ProjectTaken)'
        );

        foreach ($loaded['rows'] as $row) {
            if (!is_array($row)) {
                continue;
            }

            $projectNo = trim((string) ($row['Job_No'] ?? ''));
            $taskNo = trim((string) ($row['Job_Task_No'] ?? ''));
            if ($projectNo === '' || $taskNo === '') {
                continue;
            }

            if ($taskNo !== $projectTotaalTaskNo) {
                continue;
            }

            $amount = finance_to_float($row['LVS_Baseline_Total_Cost'] ?? 0.0);
            if ($amount === 0.0) {
                continue;
            }

            $normalizedProject = self::normalizeMatchValue($projectNo);
            $totals[$normalizedProject] = $amount;

            $breakdown[$normalizedProject] = [
                [
                    'Job_Task_No' => $taskNo,
                    'Line_No' => 0,
                    'Type' => (string) ($row['Job_Task_Type'] ?? ''),
                    'No' => '',
                    'Description' => (string) ($row['Description'] ?? ''),
                    'Line_Amount' => $amount,
                    'Line_Type' => '',
                ],
            ];
        }

        return [
            'totals' => $totals,
            'breakdown' => $breakdown,
            'warning' => $loaded['warning'],
        ];
    }

    /**
     * Voorcalculatie opbrengst uit ProjectTaken (LVS_Schedule_Total_Price_2 van taak 000).
     *
     * Eén schedule/budgetprijs per project. Niet LVS_Baseline_Total_Price,
     * niet de aanneemsom (G/L 800000 / Line_Amount_LCY) en niet de som van
     * factureerbare planningsregels.
     *
     * @return array{totals:array<string,float>,breakdown:array<string,array<int,array<string,mixed>>>,warning:?string}
     */
    private function fetchVoorcalculatieRevenueForProjects(array $projectNumbers, int $ttl): array
    {
        $totals = [];
        $breakdown = [];
        $projectTotaalTaskNo = '000';
        $schedulePriceField = FINANCE_OPBRENGST_VC_FIELD;
        $loaded = $this->fetchRowsForJobChunks(
            $projectNumbers,
            $ttl,
            8,
            function (string $projectFilter) use ($projectTotaalTaskNo, $schedulePriceField): string {
                return $this->companyEntityUrlWithQuery('ProjectTaken', [
                    '$select' => 'Job_No,Job_Task_No,Description,' . $schedulePriceField,
                    '$filter' => $projectFilter . " and Job_Task_No eq '" . self::escapeOdataString($projectTotaalTaskNo) . "'",
                ]);
            },
            'Voorcalculatie opbrengst ophalen mislukt (ProjectTaken)'
        );

        foreach ($loaded['rows'] as $row) {
            if (!is_array($row)) {
                continue;
            }

            $projectNo = trim((string) ($row['Job_No'] ?? ''));
            if ($projectNo === '') {
                continue;
            }

            $amount = finance_opbrengst_vc_amount($row, $projectTotaalTaskNo);
            if ($amount === 0.0) {
                continue;
            }

            $normalizedProject = self::normalizeMatchValue($projectNo);
            $totals[$normalizedProject] = $amount;
            $breakdown[$normalizedProject] = [
                [
                    'Job_Task_No' => (string) ($row['Job_Task_No'] ?? $projectTotaalTaskNo),
                    'Line_No' => 0,
                    'Type' => '',
                    'No' => '',
                    'Description' => (string) ($row['Description'] ?? ''),
                    'Line_Amount' => $amount,
                    'Line_Type' => '',
                ],
            ];
        }

        return [
            'totals' => $totals,
            'breakdown' => $breakdown,
            'warning' => $loaded['warning'],
        ];
    }

    /**
     * Aanneemsom en opbrengst meerwerk uit FactureerbareProjectPlanningsRegels.
     *
     * Beide gebruiken Type G/L (GB-rekening / Grootboekrekening / GLAccount / G/L Account), No 800000,
     * Line_Type factureerbaar/billable (niet prognose/forecast) en Line_Amount_LCY.
     * LVS_Job_Change_Order_No leeg = aanneemsom; gevuld = opbrengst meerwerk (extra_work).
     * Q002 alleen in Description wordt niet als meerwerk herkend.
     *
     * @return array{
     *   aanneemsom:array{totals:array<string,float>,breakdown:array<string,array<int,array<string,mixed>>>},
     *   extra_work:array{totals:array<string,float>,breakdown:array<string,array<int,array<string,mixed>>>},
     *   warning:?string
     * }
     */
    private function fetchContractRevenueForProjects(array $projectNumbers, int $ttl): array
    {
        $aanneemsomTotals = [];
        $aanneemsomBreakdown = [];
        $meerwerkTotals = [];
        $meerwerkBreakdown = [];
        $accountNo = FINANCE_REVENUE_GL_ACCOUNT_NO;
        $loaded = $this->fetchRowsForJobChunks(
            $projectNumbers,
            $ttl,
            8,
            function (string $projectFilter) use ($accountNo): string {
                return $this->companyEntityUrlWithQuery('FactureerbareProjectPlanningsRegels', [
                    '$select' => 'Job_No,Job_Task_No,Line_No,Line_Type,Type,No,Description,Description_2,Line_Amount_LCY,LVS_Job_Change_Order_No,LVS_Cancelled_Original_Line',
                    '$filter' => $projectFilter . " and No eq '" . self::escapeOdataString($accountNo) . "'",
                ]);
            },
            'Aanneemsom/meerwerk ophalen mislukt (FactureerbareProjectPlanningsRegels)'
        );

        foreach ($loaded['rows'] as $row) {
            if (!is_array($row)) {
                continue;
            }

            $projectNo = trim((string) ($row['Job_No'] ?? ''));
            if ($projectNo === '') {
                continue;
            }

            $aanneemsomAmount = finance_aanneemsom_amount($row);
            $meerwerkAmount = finance_opbrengst_meerwerk_amount($row);
            if ($aanneemsomAmount === 0.0 && $meerwerkAmount === 0.0) {
                continue;
            }

            $normalizedProject = self::normalizeMatchValue($projectNo);
            $lineDescription = trim((string) ($row['Description'] ?? ''));
            $lineDescription2 = trim((string) ($row['Description_2'] ?? ''));
            if ($lineDescription2 !== '') {
                $lineDescription = trim($lineDescription . ' / ' . $lineDescription2);
            }

            $line = [
                'Job_Task_No' => (string) ($row['Job_Task_No'] ?? ''),
                'Line_No' => (int) ($row['Line_No'] ?? 0),
                'Type' => (string) ($row['Type'] ?? ''),
                'No' => (string) ($row['No'] ?? ''),
                'Description' => $lineDescription,
                'Line_Amount' => $aanneemsomAmount !== 0.0 ? $aanneemsomAmount : $meerwerkAmount,
                'Line_Type' => (string) ($row['Line_Type'] ?? ''),
                'Change_Order_No' => finance_planning_change_order_no($row),
            ];

            if ($aanneemsomAmount !== 0.0) {
                $aanneemsomTotals[$normalizedProject] = finance_add_amount(
                    (float) ($aanneemsomTotals[$normalizedProject] ?? 0.0),
                    $aanneemsomAmount
                );
                $aanneemsomBreakdown[$normalizedProject][] = $line;
            }

            if ($meerwerkAmount !== 0.0) {
                $meerwerkTotals[$normalizedProject] = finance_add_amount(
                    (float) ($meerwerkTotals[$normalizedProject] ?? 0.0),
                    $meerwerkAmount
                );
                $meerwerkBreakdown[$normalizedProject][] = $line;
            }
        }

        return [
            'aanneemsom' => [
                'totals' => $aanneemsomTotals,
                'breakdown' => $aanneemsomBreakdown,
            ],
            'extra_work' => [
                'totals' => $meerwerkTotals,
                'breakdown' => $meerwerkBreakdown,
            ],
            'warning' => $loaded['warning'],
        ];
    }

    /**
     * Haalt OData-rijen op in Job_No-chunks; bij een mislukte batch opnieuw per project.
     *
     * @param callable(string):string $urlBuilder
     * @return array{rows:array<int,mixed>,warning:?string}
     */
    private function fetchRowsForJobChunks(
        array $projectNumbers,
        int $ttl,
        int $chunkSize,
        callable $urlBuilder,
        string $errorPrefix
    ): array {
        $rows = [];
        $warnings = [];

        foreach (self::chunkValues($projectNumbers, $chunkSize) as $chunk) {
            $chunkResult = $this->fetchRowsForJobChunk($chunk, $ttl, $urlBuilder, $errorPrefix);
            foreach ($chunkResult['rows'] as $row) {
                $rows[] = $row;
            }
            if (is_string($chunkResult['warning']) && $chunkResult['warning'] !== '') {
                $warnings[] = $chunkResult['warning'];
            }
        }

        return [
            'rows' => $rows,
            'warning' => self::joinForecastWarnings($warnings),
        ];
    }

    /**
     * @param callable(string):string $urlBuilder
     * @return array{rows:array<int,mixed>,warning:?string}
     */
    private function fetchRowsForJobChunk(
        array $chunk,
        int $ttl,
        callable $urlBuilder,
        string $errorPrefix
    ): array {
        $projectFilter = self::buildJobNoOrFilter($chunk);
        if ($projectFilter === '') {
            return ['rows' => [], 'warning' => null];
        }

        try {
            $url = $urlBuilder($projectFilter);
            return [
                'rows' => odata_get_all($url, $this->auth, $ttl),
                'warning' => null,
            ];
        } catch (Throwable $loadError) {
            if (count($chunk) <= 1) {
                return [
                    'rows' => [],
                    'warning' => self::formatForecastLoadError($errorPrefix, $loadError),
                ];
            }

            $rows = [];
            $failedCount = 0;
            foreach ($chunk as $projectNo) {
                $singleResult = $this->fetchRowsForJobChunk([$projectNo], $ttl, $urlBuilder, $errorPrefix);
                foreach ($singleResult['rows'] as $row) {
                    $rows[] = $row;
                }
                if (is_string($singleResult['warning']) && $singleResult['warning'] !== '') {
                    $failedCount++;
                }
            }

            if ($failedCount === 0) {
                return ['rows' => $rows, 'warning' => null];
            }

            return [
                'rows' => $rows,
                'warning' => self::formatForecastLoadError($errorPrefix, $loadError),
            ];
        }
    }

    /**
     * Korte, toonbare OData-fout zonder het ruwe $filter in de toast.
     */
    private static function formatForecastLoadError(string $prefix, Throwable $error): string
    {
        return $prefix . ': ' . self::summarizeOdataThrowable($error);
    }

    /**
     * @param array<int,mixed> $warnings
     */
    private static function joinForecastWarnings(array $warnings): ?string
    {
        $clean = [];
        foreach ($warnings as $warning) {
            if (!is_string($warning)) {
                continue;
            }

            $warning = trim($warning);
            if ($warning === '') {
                continue;
            }

            $clean[] = $warning;
        }

        $clean = array_values(array_unique($clean));
        if ($clean === []) {
            return null;
        }

        return implode(' | ', $clean);
    }

    /**
     * Haalt de leesbare BC/OData-fout uit een exception-keten.
     */
    private static function summarizeOdataThrowable(Throwable $error): string
    {
        $best = trim($error->getMessage());
        $current = $error;
        $depth = 0;

        while ($current !== null && $depth < 4) {
            $extracted = self::extractOdataErrorText($current->getMessage());
            if ($extracted !== '') {
                $best = $extracted;
            }

            $current = $current->getPrevious();
            $depth++;
        }

        $best = trim((string) preg_replace('/\s+/', ' ', $best));
        if (strlen($best) > 280) {
            return substr($best, 0, 277) . '...';
        }

        return $best;
    }

    private static function extractOdataErrorText(string $message): string
    {
        $message = trim($message);
        if ($message === '') {
            return '';
        }

        if (preg_match('/HTTP\s+(\d+)\s+from OData:\s*(.*)$/s', $message, $matches) !== 1) {
            return $message;
        }

        $httpCode = $matches[1];
        $raw = trim($matches[2]);
        $decoded = json_decode($raw, true);
        $detail = self::extractOdataErrorMessageFromPayload($decoded);
        if ($detail !== '') {
            return 'HTTP ' . $httpCode . ': ' . $detail;
        }

        if ($raw !== '' && $raw[0] !== '{' && $raw[0] !== '<') {
            $raw = trim((string) preg_replace('/\s+/', ' ', $raw));
            if (strlen($raw) > 200) {
                $raw = substr($raw, 0, 197) . '...';
            }

            return 'HTTP ' . $httpCode . ': ' . $raw;
        }

        return 'HTTP ' . $httpCode;
    }

    /**
     * @param mixed $payload
     */
    private static function extractOdataErrorMessageFromPayload($payload): string
    {
        if (!is_array($payload)) {
            return '';
        }

        $error = $payload['error'] ?? $payload['odata.error'] ?? null;
        if (!is_array($error)) {
            return '';
        }

        $message = $error['message'] ?? '';
        if (is_array($message)) {
            $message = $message['value'] ?? '';
        }

        return trim((string) $message);
    }

    /**
     * OData-filter: Job_No eq 'P1' or Job_No eq 'P2' ...
     */
    private static function buildJobNoOrFilter(array $projectNumbers): string
    {
        $filters = [];
        foreach ($projectNumbers as $projectNo) {
            $projectNoText = trim((string) $projectNo);
            if ($projectNoText === '') {
                continue;
            }

            $filters[] = "Job_No eq '" . self::escapeOdataString($projectNoText) . "'";
        }

        if ($filters === []) {
            return '';
        }

        return '(' . implode(' or ', $filters) . ')';
    }

    private static function isTotalJobTaskType(string $taskType): bool
    {
        return str_contains(strtolower(trim($taskType)), 'totaal');
    }

    /**
     * Geeft kosten, opbrengst en resultaat terug voor een projectnummer.
     */
    public function getProjectCostsAndRevenue(string $projectNumber, int $ttl = 3600): array
    {
        $projectNumber = trim($projectNumber);
        if ($projectNumber === '') {
            return [
                'project_number' => '',
                'costs' => 0.0,
                'revenue' => 0.0,
                'resultaat' => 0.0,
            ];
        }

        $finance = $this->collectProjectFinanceForProjects([$projectNumber], $ttl);
        $totals = $finance['project_totals_by_job'][self::normalizeMatchValue($projectNumber)] ?? [
            'costs' => 0.0,
            'revenue' => 0.0,
            'resultaat' => 0.0,
        ];

        $costs = (float) ($totals['costs'] ?? 0.0);
        $revenue = (float) ($totals['revenue'] ?? 0.0);

        return [
            'project_number' => $projectNumber,
            'costs' => $costs,
            'revenue' => $revenue,
            'resultaat' => finance_calculate_result($revenue, $costs),
        ];
    }

    /**
     * Geeft kosten, opbrengst en resultaat terug voor een werkordernummer.
     */
    public function getWorkorderCostsAndRevenue(string $workorderNumber, int $ttl = 3600): array
    {
        $workorderCostSource = $this->getAmountSourceConfig('workorder', 'cost_source');
        $workorderRevenueSource = $this->getAmountSourceConfig('workorder', 'revenue_source');

        $workorderNumber = trim($workorderNumber);
        if ($workorderNumber === '') {
            return [
                'workorder_number' => '',
                'project_number' => '',
                'costs' => 0.0,
                'revenue' => 0.0,
                'resultaat' => 0.0,
            ];
        }

        $costTotalsByWorkorder = $this->fetchTotalsForKeys($workorderCostSource, [$workorderNumber], $ttl);
        $revenueTotalsByWorkorder = $this->fetchTotalsForKeys($workorderRevenueSource, [$workorderNumber], $ttl);
        $totalsByWorkorder = self::combineTotalsByKey($costTotalsByWorkorder, $revenueTotalsByWorkorder);

        $normalizedWorkorderNo = self::normalizeMatchValue($workorderNumber);
        $totals = $totalsByWorkorder[$normalizedWorkorderNo] ?? [
            'costs' => 0.0,
            'revenue' => 0.0,
            'resultaat' => 0.0,
        ];

        $projectNumber = $this->resolveWorkorderProjectNumber($workorderNumber, [$workorderCostSource, $workorderRevenueSource], $ttl);

        $costs = (float) ($totals['costs'] ?? 0.0);
        $revenue = (float) ($totals['revenue'] ?? 0.0);

        return [
            'workorder_number' => $workorderNumber,
            'project_number' => $projectNumber,
            'costs' => $costs,
            'revenue' => $revenue,
            'resultaat' => finance_calculate_result($revenue, $costs),
        ];
    }

    /**
     * Haalt kosten, opbrengst en resultaat op voor meerdere werkordernummers.
     */
    public function collectWorkorderFinanceForWorkorders(array $workorderNumbers, int $ttl = 3600): array
    {
        $workorderCostSource = $this->getAmountSourceConfig('workorder', 'cost_source');
        $workorderRevenueSource = $this->getAmountSourceConfig('workorder', 'revenue_source');

        return [
            'workorder_totals_by_number' => self::combineTotalsByKey(
                $this->fetchTotalsForKeys($workorderCostSource, $workorderNumbers, $ttl),
                $this->fetchTotalsForKeys($workorderRevenueSource, $workorderNumbers, $ttl)
            ),
        ];
    }

    /**
     * Geeft alle gevonden factuurdetails terug die aan een project gekoppeld zijn.
     */
    public function getProjectInvoices(string $projectNumber, int $ttl = 3600): array
    {
        $projectNumber = trim($projectNumber);
        if ($projectNumber === '') {
            return [];
        }

        $finance = $this->collectProjectFinanceForProjects([$projectNumber], $ttl);
        $normalizedProjectNo = self::normalizeMatchValue($projectNumber);
        $invoiceIds = $finance['project_invoice_ids_by_job'][$normalizedProjectNo] ?? [];
        $invoiceDetailsById = $finance['invoice_details_by_id'] ?? [];

        $result = [];
        foreach ($invoiceIds as $invoiceId) {
            if (isset($invoiceDetailsById[$invoiceId]) && is_array($invoiceDetailsById[$invoiceId])) {
                $result[] = $invoiceDetailsById[$invoiceId];
            }
        }

        return $result;
    }

    /**
     * Geeft projectkosten/opbrengst/resultaat terug plus werkorders met kosten/opbrengst/resultaat.
     */
    public function getProjectFinanceWithWorkorders(string $projectNumber, int $ttl = 3600): array
    {
        $workorderCostSource = $this->getAmountSourceConfig('workorder', 'cost_source');
        $workorderRevenueSource = $this->getAmountSourceConfig('workorder', 'revenue_source');

        $projectNumber = trim($projectNumber);
        if ($projectNumber === '') {
            return [
                'project_number' => '',
                'project_costs' => 0.0,
                'project_revenue' => 0.0,
                'resultaat' => 0.0,
                'workorders' => [],
            ];
        }

        $costTotalsByWorkorder = $this->fetchTotalsForProject(
            $workorderCostSource,
            $projectNumber,
            $ttl
        );
        $revenueTotalsByWorkorder = $this->fetchTotalsForProject(
            $workorderRevenueSource,
            $projectNumber,
            $ttl
        );
        $totalsByWorkorder = self::combineTotalsByKey($costTotalsByWorkorder, $revenueTotalsByWorkorder);

        $workorders = [];
        foreach ($totalsByWorkorder as $normalizedWorkorderNo => $totals) {
            $workorderNo = self::displayKeyFromNormalized($normalizedWorkorderNo);

            $costsWo = (float) ($totals['costs'] ?? 0.0);
            $revenueWo = (float) ($totals['revenue'] ?? 0.0);
            $workorders[] = [
                'number' => $workorderNo,
                'revenue_wo' => $revenueWo,
                'costs_wo' => $costsWo,
                'resultaat' => finance_calculate_result($revenueWo, $costsWo),
            ];
        }

        usort($workorders, static function (array $left, array $right): int {
            return strnatcasecmp((string) ($left['number'] ?? ''), (string) ($right['number'] ?? ''));
        });

        $projectTotals = $this->getProjectCostsAndRevenue($projectNumber, $ttl);

        $projectCosts = (float) ($projectTotals['costs'] ?? 0.0);
        $projectRevenue = (float) ($projectTotals['revenue'] ?? 0.0);

        return [
            'project_number' => $projectNumber,
            'project_costs' => $projectCosts,
            'project_revenue' => $projectRevenue,
            'resultaat' => finance_calculate_result($projectRevenue, $projectCosts),
            'workorders' => $workorders,
        ];
    }

    /**
     * Leest de OData context uit globale configuratie die via auth.php gezet wordt.
     */
    /**
     * Bouwt een OData entity URL met query parameters voor de geconfigureerde company.
     */
    private function companyEntityUrlWithQuery(string $entitySet, array $query): string
    {
        $safeCompany = str_replace("'", "''", $this->company);
        $companySegment = "Company('" . rawurlencode($safeCompany) . "')";
        $url = rtrim($this->baseUrl, '/') . '/' . rawurlencode($this->environment) . '/ODataV4/' . $companySegment . '/' . rawurlencode($entitySet);

        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $url;
    }

    /**
     * Escapet quotes voor veilig gebruik in OData filter strings.
     */
    private static function escapeOdataString(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    /**
     * Normaliseert keys voor consistente case-insensitive matching.
     */
    private static function normalizeMatchValue(string $value): string
    {
        return strtolower(trim($value));
    }

    /**
     * Leest het eerste numerieke veld uit een prioriteitenlijst van kolommen.
     */
    private static function firstNumericValue(array $details, array $fields): float
    {
        return finance_first_numeric_value($details, $fields);
    }

    /**
     * Haalt en aggregeert een bedragssource op voor een lijst met sleutels.
     */
    private function fetchTotalsForKeys(array $sourceConfig, array $keys, int $ttl): array
    {
        $entitySet = (string) ($sourceConfig['entity_set'] ?? '');
        $keyField = (string) ($sourceConfig['key_field'] ?? '');
        $sourceFilter = trim((string) ($sourceConfig['filter'] ?? ''));
        $fields = is_array($sourceConfig['fields'] ?? null) ? $sourceConfig['fields'] : [];
        $rowMode = (string) ($sourceConfig['row_mode'] ?? self::ROW_MODE_FIRST_NUMERIC);

        $selectFields = array_values(array_unique(array_filter(array_merge([$keyField], $fields), static function ($field): bool {
            return is_string($field) && trim($field) !== '';
        })));

        $totalsByKey = [];
        $keyChunks = self::chunkValues($keys, 25);
        foreach ($keyChunks as $chunk) {
            $filterParts = [];
            foreach ($chunk as $keyValue) {
                $filterParts[] = $keyField . " eq '" . self::escapeOdataString($keyValue) . "'";
            }

            if ($filterParts === []) {
                continue;
            }

            $queryFilter = '(' . implode(' or ', $filterParts) . ')';
            if ($sourceFilter !== '') {
                $queryFilter .= ' and (' . $sourceFilter . ')';
            }

            try {
                $url = $this->companyEntityUrlWithQuery($entitySet, [
                    '$select' => implode(',', $selectFields),
                    '$filter' => $queryFilter,
                ]);
                $rows = odata_get_all($url, $this->auth, $ttl);
            } catch (Throwable $loadError) {
                throw new RuntimeException(
                    'Finance bron ophalen mislukt voor ' . $entitySet . ' met filter: ' . $queryFilter,
                    0,
                    $loadError
                );
            }

            $chunkTotals = self::aggregateAmountByKey($rows, $keyField, $fields, $rowMode);
            foreach ($chunkTotals as $normalizedKey => $amount) {
                if (!isset($totalsByKey[$normalizedKey])) {
                    $totalsByKey[$normalizedKey] = 0.0;
                }
                $totalsByKey[$normalizedKey] += (float) $amount;
            }
        }

        return $totalsByKey;
    }

    /**
     * Haalt en aggregeert een bedragssource op voor alle regels van een projectnummer.
     */
    private function fetchTotalsForProject(array $sourceConfig, string $projectNumber, int $ttl): array
    {
        $entitySet = (string) ($sourceConfig['entity_set'] ?? '');
        $keyField = (string) ($sourceConfig['key_field'] ?? '');
        $projectField = trim((string) ($sourceConfig['project_field'] ?? ''));
        $sourceFilter = trim((string) ($sourceConfig['filter'] ?? ''));
        $fields = is_array($sourceConfig['fields'] ?? null) ? $sourceConfig['fields'] : [];
        $rowMode = (string) ($sourceConfig['row_mode'] ?? self::ROW_MODE_FIRST_NUMERIC);

        if ($projectField === '') {
            return [];
        }

        $selectFields = array_values(array_unique(array_filter(array_merge([$keyField], $fields), static function ($field): bool {
            return is_string($field) && trim($field) !== '';
        })));

        try {
            $queryFilter = $projectField . " eq '" . self::escapeOdataString($projectNumber) . "'";
            if ($sourceFilter !== '') {
                $queryFilter = '(' . $queryFilter . ') and (' . $sourceFilter . ')';
            }

            $url = $this->companyEntityUrlWithQuery($entitySet, [
                '$select' => implode(',', $selectFields),
                '$filter' => $queryFilter,
            ]);
            $rows = odata_get_all($url, $this->auth, $ttl);
        } catch (Throwable $loadError) {
            throw new RuntimeException(
                'Finance bron ophalen mislukt voor ' . $entitySet . ' met projectfilter: ' . ($queryFilter ?? ''),
                0,
                $loadError
            );
        }

        return self::aggregateAmountByKey($rows, $keyField, $fields, $rowMode);
    }

    /**
     * Bepaalt projectnummer van werkordernummer via geconfigureerde sources.
     */
    private function resolveWorkorderProjectNumber(string $workorderNumber, array $sources, int $ttl): string
    {
        $normalizedWorkorderNo = self::normalizeMatchValue($workorderNumber);

        foreach ($sources as $sourceConfig) {
            if (!is_array($sourceConfig)) {
                continue;
            }

            $projectField = trim((string) ($sourceConfig['project_field'] ?? ''));
            $entitySet = trim((string) ($sourceConfig['entity_set'] ?? ''));
            $keyField = trim((string) ($sourceConfig['key_field'] ?? ''));
            if ($projectField === '' || $entitySet === '' || $keyField === '') {
                continue;
            }

            try {
                $url = $this->companyEntityUrlWithQuery($entitySet, [
                    '$select' => implode(',', [$keyField, $projectField]),
                    '$filter' => $keyField . " eq '" . self::escapeOdataString($workorderNumber) . "'",
                ]);
                $rows = odata_get_all($url, $this->auth, $ttl);
            } catch (Throwable $ignoredLoadError) {
                continue;
            }

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $rowWorkorderNo = trim((string) ($row[$keyField] ?? ''));
                if (self::normalizeMatchValue($rowWorkorderNo) !== $normalizedWorkorderNo) {
                    continue;
                }

                $projectNumber = trim((string) ($row[$projectField] ?? ''));
                if ($projectNumber !== '') {
                    return $projectNumber;
                }
            }
        }

        return '';
    }

    /**
     * Combineert kosten- en opbrengsttotalen tot een uniforme totaalstructuur per sleutel.
     */
    private static function combineTotalsByKey(array $costTotalsByKey, array $revenueTotalsByKey): array
    {
        $result = [];
        $keys = array_values(array_unique(array_merge(array_keys($costTotalsByKey), array_keys($revenueTotalsByKey))));

        foreach ($keys as $normalizedKey) {
            if (!is_string($normalizedKey) || $normalizedKey === '') {
                continue;
            }

            $costs = (float) ($costTotalsByKey[$normalizedKey] ?? 0.0);
            $revenue = (float) ($revenueTotalsByKey[$normalizedKey] ?? 0.0);

            $result[$normalizedKey] = [
                'costs' => $costs,
                'revenue' => $revenue,
                'resultaat' => finance_calculate_result($revenue, $costs),
            ];
        }

        return $result;
    }

    /**
     * Groepeert en telt een enkel bedragstype op per sleutel over alle regels heen.
     */
    private static function aggregateAmountByKey(array $rows, string $keyField, array $fields, string $rowMode): array
    {
        $result = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $keyRaw = trim((string) ($row[$keyField] ?? ''));
            if ($keyRaw === '') {
                continue;
            }

            $normalizedKey = self::normalizeMatchValue($keyRaw);
            if (!isset($result[$normalizedKey])) {
                $result[$normalizedKey] = 0.0;
            }

            $result[$normalizedKey] += self::extractRowAmount($row, $fields, $rowMode);
        }

        return $result;
    }

    /**
     * Maakt een leesbare sleutel terug uit een genormaliseerde key.
     */
    private static function displayKeyFromNormalized(string $normalizedKey): string
    {
        return strtoupper($normalizedKey);
    }

    /**
     * Splitst unieke, niet-lege waarden op in chunks voor OData OR-filters.
     */
    private static function chunkValues(array $values, int $size): array
    {
        $clean = [];
        foreach ($values as $value) {
            $text = trim((string) $value);
            if ($text === '') {
                continue;
            }

            $clean[] = $text;
        }

        if ($clean === []) {
            return [];
        }

        return array_chunk(array_values(array_unique($clean)), max(1, $size));
    }

    /**
     * Normaliseert row mode naar ondersteunde waardes.
     */
    private static function normalizeRowMode(string $mode): string
    {
        return finance_normalize_row_mode($mode);
    }

    /**
     * Leest een bedrag per regel op basis van ingestelde row mode.
     */
    private static function extractRowAmount(array $row, array $fields, string $mode): float
    {
        return finance_extract_row_amount($row, $fields, $mode);
    }

    /**
     * Bepaalt of Line_Type de billable-status bevat.
     */
    private static function baselineLineTypeHasBillable(string $lineType): bool
    {
        $normalized = strtolower(trim($lineType));
        if ($normalized === '') {
            return false;
        }

        return str_contains($normalized, 'billable');
    }

    /**
     * Bepaalt of Line_Type de budget-status bevat.
     */
    private static function baselineLineTypeHasBudget(string $lineType): bool
    {
        $normalized = strtolower(trim($lineType));
        if ($normalized === '') {
            return false;
        }

        return str_contains($normalized, 'budget');
    }
}
