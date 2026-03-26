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
     * Initialiseert de service voor een specifiek bedrijf op basis van auth.php context.
     */
    public function __construct(string $company)
    {
        $context = self::getOdataContext();
        $this->company = trim($company);
        $this->baseUrl = (string) $context['base_url'];
        $this->environment = (string) $context['environment'];
        $this->auth = is_array($context['auth']) ? $context['auth'] : [];
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
            'project_forecast' => [
                'entity_set' => 'JobBaselineLines',
                'project_key_field' => 'Job_No',
                'line_type_field' => 'Line_Type',
                'revenue_fields' => [
                    'Line_Amount',
                ],
                'cost_fields' => [
                    'Total_Cost',
                ],
                'select_fields' => [
                    'Job_No',
                    'Job_Task_No',
                    'Line_No',
                    'Type',
                    'No',
                    'Description',
                    'Description_2',
                    'Line_Type',
                    'Line_Amount',
                    'Total_Cost',
                ],
                'filter' => '',
            ],
            'project' => [
                'cost_source' => [
                    'entity_set' => 'ProjectPosten',
                    'key_field' => 'Job_No',
                    'fields' => [
                        'Total_Cost',
                    ],
                    'filter' => '',
                    'row_mode' => self::ROW_MODE_SUM_RAW,
                ],
                'revenue_source' => [
                    'entity_set' => 'ProjectPosten',
                    'key_field' => 'Job_No',
                    'fields' => [
                        'Line_Amount',
                    ],
                    'filter' => '',
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
                    'filter' => '',
                    'row_mode' => self::ROW_MODE_SUM_RAW,
                ],
                'revenue_source' => [
                    'entity_set' => 'ProjectPosten',
                    'key_field' => 'Job_Task_No',
                    'project_field' => 'Job_No',
                    'fields' => [
                        'Line_Amount',
                    ],
                    'filter' => '',
                    'row_mode' => self::ROW_MODE_SUM_INVERT,
                ],
            ],
        ];
    }

    /**
     * Leest en valideert forecast-configuratie voor voorcalculatie per project.
     */
    private static function getProjectForecastConfig(): array
    {
        $config = self::financeDataConfig();
        $selected = is_array($config['project_forecast'] ?? null) ? $config['project_forecast'] : [];

        $entitySet = trim((string) ($selected['entity_set'] ?? ''));
        $projectKeyField = trim((string) ($selected['project_key_field'] ?? ''));
        $lineTypeField = trim((string) ($selected['line_type_field'] ?? ''));

        if ($entitySet === '' || $projectKeyField === '' || $lineTypeField === '') {
            throw new RuntimeException('Forecast configuratie mist entity_set, project_key_field of line_type_field.');
        }

        $selected['entity_set'] = $entitySet;
        $selected['project_key_field'] = $projectKeyField;
        $selected['line_type_field'] = $lineTypeField;
        $selected['revenue_fields'] = is_array($selected['revenue_fields'] ?? null) ? $selected['revenue_fields'] : [];
        $selected['cost_fields'] = is_array($selected['cost_fields'] ?? null) ? $selected['cost_fields'] : [];
        $selected['select_fields'] = is_array($selected['select_fields'] ?? null) ? $selected['select_fields'] : [];
        $selected['filter'] = trim((string) ($selected['filter'] ?? ''));

        return $selected;
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

        $projectTotalsByJob = self::combineTotalsByKey(
            self::aggregateAmountByKey(
                $rows,
                $projectKeyField,
                is_array($projectCostSource['fields'] ?? null) ? $projectCostSource['fields'] : [],
                (string) ($projectCostSource['row_mode'] ?? self::ROW_MODE_FIRST_NUMERIC)
            ),
            self::aggregateAmountByKey(
                $rows,
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
                $rows,
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
     * Haalt verwachte omzet/kosten op uit de voorcalculatiebron per project.
     */
    public function collectProjectForecastForProjects(array $projectNumbers, int $ttl = 3600): array
    {
        $forecastConfig = self::getProjectForecastConfig();
        $entitySet = (string) ($forecastConfig['entity_set'] ?? '');
        $projectKeyField = (string) ($forecastConfig['project_key_field'] ?? '');
        $lineTypeField = (string) ($forecastConfig['line_type_field'] ?? '');
        $revenueFields = is_array($forecastConfig['revenue_fields'] ?? null) ? $forecastConfig['revenue_fields'] : [];
        $costFields = is_array($forecastConfig['cost_fields'] ?? null) ? $forecastConfig['cost_fields'] : [];
        $sourceFilter = trim((string) ($forecastConfig['filter'] ?? ''));
        $baseSelectFields = is_array($forecastConfig['select_fields'] ?? null) ? $forecastConfig['select_fields'] : [];

        $selectFields = array_values(array_unique(array_filter(array_merge(
            [$projectKeyField, $lineTypeField],
            $baseSelectFields,
            $revenueFields,
            $costFields
        ), static function ($field): bool {
            return is_string($field) && trim($field) !== '';
        })));

        $totalsByProject = [];
        $breakdownByProject = [];
        $projectChunks = self::chunkValues($projectNumbers, 25);

        foreach ($projectChunks as $chunk) {
            $projectFilters = [];
            foreach ($chunk as $projectNo) {
                $projectNoText = trim((string) $projectNo);
                if ($projectNoText === '') {
                    continue;
                }

                $projectFilters[] = $projectKeyField . " eq '" . self::escapeOdataString($projectNoText) . "'";
            }

            if ($projectFilters === []) {
                continue;
            }

            $queryFilter = '(' . implode(' or ', $projectFilters) . ')';
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

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $projectNo = trim((string) ($row[$projectKeyField] ?? ''));
                if ($projectNo === '') {
                    continue;
                }

                $normalizedProject = self::normalizeMatchValue($projectNo);
                if (!isset($totalsByProject[$normalizedProject])) {
                    $totalsByProject[$normalizedProject] = [
                        'expected_revenue' => 0.0,
                        'expected_costs' => 0.0,
                    ];
                }
                if (!isset($breakdownByProject[$normalizedProject])) {
                    $breakdownByProject[$normalizedProject] = [
                        'expected_revenue_lines' => [],
                        'expected_costs_lines' => [],
                        'extra_work_lines' => [],
                    ];
                }

                $lineType = trim((string) ($row[$lineTypeField] ?? ''));

                $revenueAmount = self::firstNumericValue($row, $revenueFields);
                $costAmount = self::firstNumericValue($row, $costFields);

                $lineDescription = trim((string) ($row['Description'] ?? ''));
                $lineDescription2 = trim((string) ($row['Description_2'] ?? ''));
                if ($lineDescription2 !== '') {
                    $lineDescription = trim($lineDescription . ' / ' . $lineDescription2);
                }

                if ($revenueAmount !== 0.0) {
                    $totalsByProject[$normalizedProject]['expected_revenue'] = finance_add_amount(
                        (float) ($totalsByProject[$normalizedProject]['expected_revenue'] ?? 0.0),
                        $revenueAmount
                    );

                    $breakdownByProject[$normalizedProject]['expected_revenue_lines'][] = [
                        'Job_Task_No' => (string) ($row['Job_Task_No'] ?? ''),
                        'Line_No' => (int) ($row['Line_No'] ?? 0),
                        'Type' => (string) ($row['Type'] ?? ''),
                        'No' => (string) ($row['No'] ?? ''),
                        'Description' => $lineDescription,
                        'Line_Amount' => $revenueAmount,
                        'Line_Type' => $lineType,
                    ];
                }

                if ($costAmount !== 0.0) {
                    $totalsByProject[$normalizedProject]['expected_costs'] = finance_add_amount(
                        (float) ($totalsByProject[$normalizedProject]['expected_costs'] ?? 0.0),
                        $costAmount
                    );

                    $breakdownByProject[$normalizedProject]['expected_costs_lines'][] = [
                        'Job_Task_No' => (string) ($row['Job_Task_No'] ?? ''),
                        'Line_No' => (int) ($row['Line_No'] ?? 0),
                        'Type' => (string) ($row['Type'] ?? ''),
                        'No' => (string) ($row['No'] ?? ''),
                        'Description' => $lineDescription,
                        'Line_Amount' => $costAmount,
                        'Line_Type' => $lineType,
                    ];
                }
            }
        }

        return [
            'forecast_totals_by_job' => $totalsByProject,
            'forecast_breakdown_by_job' => $breakdownByProject,
        ];
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
    private static function getOdataContext(): array
    {
        global $baseUrl, $environment, $auth;

        if (!isset($baseUrl, $environment, $auth) || !is_array($auth)) {
            throw new RuntimeException('OData context ontbreekt. Zorg dat auth.php geladen is.');
        }

        return [
            'base_url' => (string) $baseUrl,
            'environment' => (string) $environment,
            'auth' => $auth,
        ];
    }

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
