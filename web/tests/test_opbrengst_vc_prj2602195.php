<?php

/**
 * PRJ2602195 (Koninklijke van Twist): Opbrengst VC toont het hoofdproject
 * 685.000 en laat het meerwerk 58.300 in zijn eigen kolom (ticket #1264).
 * Zonder taak 000 geldt dezelfde fallback als Kosten VC, en daarna dezelfde split.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$GLOBALS['projecttaken_selects'] = [];
$GLOBALS['jobtask_filters'] = [];

function odata_get_all(string $url, array $auth, int $ttl): array
{
    $path = (string) parse_url($url, PHP_URL_PATH);
    $entity = rawurldecode(basename($path));
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $select = (string) ($query['$select'] ?? '');
    $filter = (string) ($query['$filter'] ?? '');
    preg_match_all("/Job_No eq '([^']+)'/", $filter, $matches);
    $jobs = $matches[1];

    if ($entity === 'ProjectTaken') {
        $GLOBALS['projecttaken_selects'][] = $select;
        if (str_contains($select, 'LVS_Schedule_Total_Price_2') || str_contains($select, 'LVS_Baseline_Total_Price')) {
            throw new RuntimeException('Opbrengst VC mag schedule- of basislijnprijs niet opvragen: ' . $select);
        }

        $rows = [];
        foreach ($jobs as $job) {
            if (str_contains($select, 'LVS_Baseline_Total_Cost')) {
                if ($job === 'PRJLEGACY') {
                    $rows[] = [
                        'Job_No' => $job,
                        'Job_Task_No' => '000-000-010',
                        'Description' => 'Hoofdproject',
                        'LVS_Baseline_Total_Cost' => 100,
                    ];
                    $rows[] = [
                        'Job_No' => $job,
                        'Job_Task_No' => 'KOP',
                        'Description' => 'Kop',
                        'LVS_Baseline_Total_Cost' => 999,
                    ];
                    continue;
                }

                $amount = $job === 'PRJ2602195' ? 589296.04 : 10;
                $rows[] = [
                    'Job_No' => $job,
                    'Job_Task_No' => '000',
                    'Description' => 'Project TOTAAL',
                    'LVS_Baseline_Total_Cost' => $amount,
                ];
                continue;
            }

            if ($job === 'PRJ2602195') {
                $rows[] = [
                    'Job_No' => $job,
                    'Job_Task_No' => '000',
                    'Description' => 'Project TOTAAL',
                    'LVS_Contract_Total_Price_2' => 743300,
                    'LVS_Schedule_Total_Price_2' => 598238.42,
                    'LVS_Baseline_Total_Price' => 0,
                ];
                $rows[] = [
                    'Job_No' => $job,
                    'Job_Task_No' => '000-000-010',
                    'Description' => 'Hoofdproject',
                    'LVS_Contract_Total_Price_2' => 685000,
                ];
                $rows[] = [
                    'Job_No' => $job,
                    'Job_Task_No' => '000-000-011',
                    'Description' => 'Meerwerk',
                    'LVS_Contract_Total_Price_2' => 58300,
                ];
                $rows[] = [
                    'Job_No' => $job,
                    'Job_Task_No' => '200-000-070',
                    'Description' => '2x generator 23-GE1650-29',
                    'LVS_Schedule_Total_Price_2' => 598238.42,
                ];
                continue;
            }

            if ($job === 'PRJLEGACY') {
                $rows[] = [
                    'Job_No' => $job,
                    'Job_Task_No' => 'KOP',
                    'Description' => 'Kop',
                    'LVS_Contract_Total_Price_2' => 743300,
                ];
                $rows[] = [
                    'Job_No' => $job,
                    'Job_Task_No' => '000-000-010',
                    'Description' => 'Hoofdproject',
                    'LVS_Contract_Total_Price_2' => 685000,
                ];
                $rows[] = [
                    'Job_No' => $job,
                    'Job_Task_No' => '000-000-011',
                    'Description' => 'Meerwerk',
                    'LVS_Contract_Total_Price_2' => 58300,
                ];
                $rows[] = [
                    'Job_No' => $job,
                    'Job_Task_No' => 'TOT',
                    'Description' => 'Totaal',
                    'LVS_Contract_Total_Price_2' => 743300,
                ];
                continue;
            }

            if ($job === 'PRJ000WIN') {
                $rows[] = [
                    'Job_No' => $job,
                    'Job_Task_No' => '000-000-000',
                    'Description' => 'Project TOTAAL',
                    'LVS_Contract_Total_Price_2' => 500000,
                ];
                $rows[] = [
                    'Job_No' => $job,
                    'Job_Task_No' => '010',
                    'Description' => 'Detail',
                    'LVS_Contract_Total_Price_2' => 100000,
                ];
            }
        }

        return $rows;
    }

    if ($entity === 'JobTaskLines') {
        $GLOBALS['jobtask_filters'][] = $filter;
        $rows = [];
        foreach ($jobs as $job) {
            foreach (['000-000-010' => 'Boeking', '000-000-011' => 'Boeking', 'KOP' => 'Kop', 'TOT' => 'Totaal', '010' => 'Boeking'] as $taskNo => $type) {
                $rows[] = [
                    'Job_No' => $job,
                    'Job_Task_No' => $taskNo,
                    'Job_Task_Type' => $type,
                ];
            }
        }

        return $rows;
    }

    if ($entity === 'FactureerbareProjectPlanningsRegels') {
        $rows = [];
        foreach ($jobs as $job) {
            if ($job !== 'PRJ2602195' && $job !== 'PRJLEGACY') {
                continue;
            }

            foreach ([200000, 200000, 285000] as $index => $amount) {
                $rows[] = [
                    'Job_No' => $job,
                    'Job_Task_No' => '000-000-010',
                    'Line_No' => $index + 1,
                    'Line_Type' => 'Factureerbaar',
                    'Type' => 'Grootboekrekening',
                    'No' => '800000',
                    'Description' => 'Termijn ' . ($index + 1),
                    'Line_Amount_LCY' => $amount,
                    'LVS_Job_Change_Order_No' => '',
                ];
            }
            $rows[] = [
                'Job_No' => $job,
                'Job_Task_No' => '000-000-011',
                'Line_No' => 4,
                'Line_Type' => 'Factureerbaar',
                'Type' => 'Grootboekrekening',
                'No' => '800000',
                'Description' => 'Meerwerk',
                'Line_Amount_LCY' => 58300,
                'LVS_Job_Change_Order_No' => 'SO-1',
            ];
        }

        return $rows;
    }

    throw new RuntimeException('Onverwachte entity ' . $entity);
}

require_once __DIR__ . '/../project_finance.php';

$failures = 0;

function check(bool $ok, string $message): void
{
    global $failures;
    if (!$ok) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

function line_sum(array $lines): float
{
    $sum = 0.0;
    foreach ($lines as $line) {
        $sum += (float) ($line['Line_Amount'] ?? 0);
    }

    return $sum;
}

$ref = new ReflectionClass(ProjectFinanceService::class);
$service = $ref->newInstanceWithoutConstructor();
foreach (['auth' => [], 'baseUrl' => 'https://bc.invalid:7148', 'environment' => 'kvtmdlive_aad', 'company' => 'Koninklijke van Twist'] as $name => $value) {
    $prop = $ref->getProperty($name);
    $prop->setAccessible(true);
    $prop->setValue($service, $value);
}

$forecast = $service->collectProjectForecastForProjects(['PRJ2602195', 'PRJLEGACY', 'PRJ000WIN'], 60);
$totals = $forecast['forecast_totals_by_job'];
$breakdown = $forecast['forecast_breakdown_by_job'];

check(($totals['prj2602195']['expected_revenue'] ?? null) === 685000.0, 'PRJ2602195 hoofdproject Opbrengst VC is 685.000, kreeg ' . var_export($totals['prj2602195']['expected_revenue'] ?? null, true));
check(($totals['prj2602195']['extra_work'] ?? null) === 58300.0, 'PRJ2602195 meerwerk blijft 58.300');
check(($totals['prj2602195']['aanneemsom'] ?? null) === 743300.0, 'aanneemsom blijft hoofdproject plus meerwerk');
check(($totals['prj2602195']['contract_total_price'] ?? null) === 743300.0, 'opgeslagen contracttotaal van taak 000 blijft 743.300');
check(($totals['prj2602195']['expected_costs'] ?? null) === 589296.04, 'Kosten VC blijft de basislijnkosten');
check(line_sum($breakdown['prj2602195']['expected_revenue_lines'] ?? []) === 685000.0, 'weergaveregels Opbrengst VC sommeren tot het hoofdproject');
check(line_sum($breakdown['prj2602195']['extra_work_lines'] ?? []) === 58300.0, 'meerwerkregels blijven 58.300');
check(count($breakdown['prj2602195']['aanneemsom_lines'] ?? []) === 4, 'aanneemsom houdt de drie termijnen en het meerwerk');

check(($totals['prjlegacy']['expected_revenue'] ?? null) === 685000.0, 'zonder taak 000 is het hoofdproject ook 685.000, kreeg ' . var_export($totals['prjlegacy']['expected_revenue'] ?? null, true));
check(($totals['prjlegacy']['extra_work'] ?? null) === 58300.0, 'zonder taak 000 blijft het meerwerk 58.300');
check(($totals['prjlegacy']['aanneemsom'] ?? null) === 743300.0, 'zonder taak 000 blijft de aanneemsom inclusief meerwerk');
check(!isset($totals['prjlegacy']['contract_total_price']) || $totals['prjlegacy']['contract_total_price'] === 0.0, 'zonder taak 000 is er geen contracttotaal van taak 000');
check(line_sum($breakdown['prjlegacy']['expected_revenue_lines'] ?? []) === 685000.0, 'fallback-regels sommeren tot het hoofdproject');

check(($totals['prj000win']['expected_revenue'] ?? null) === 500000.0, 'taak 000-000-000 wint van de detailtaak');
check(($totals['prj000win']['extra_work'] ?? null) === 0.0, 'project zonder suborder heeft geen meerwerk');

$revenueSelects = array_values(array_filter(
    $GLOBALS['projecttaken_selects'],
    static fn (string $select): bool => str_contains($select, 'LVS_Contract_Total_Price_2')
));
check($revenueSelects !== [], 'Opbrengst VC vraagt LVS_Contract_Total_Price_2');
foreach ($GLOBALS['projecttaken_selects'] as $select) {
    check(!str_contains($select, 'LVS_Schedule_Total_Price_2'), 'schedule-prijs wordt niet opgevraagd: ' . $select);
    check(!str_contains($select, 'LVS_Baseline_Total_Price'), 'basislijnprijs wordt niet opgevraagd: ' . $select);
}
check(count($GLOBALS['jobtask_filters']) === 1, 'Kosten VC en Opbrengst VC delen één JobTaskLines-ronde, kreeg ' . count($GLOBALS['jobtask_filters']));
check(($totals['prjlegacy']['expected_costs'] ?? null) === 100.0, 'Kosten VC van het legacy-project blijft de Boeking-taak');
foreach ($GLOBALS['jobtask_filters'] as $jobFilter) {
    check(str_contains($jobFilter, 'PRJLEGACY'), 'JobTaskLines beperkt tot het project zonder totaaltaak: ' . $jobFilter);
    check(!str_contains($jobFilter, 'PRJ2602195'), 'project met taak 000 zit niet in JobTaskLines: ' . $jobFilter);
    check(!str_contains($jobFilter, 'PRJ000WIN'), 'project met taak 000-000-000 zit niet in JobTaskLines: ' . $jobFilter);
}
check(empty($forecast['task_type_error']), 'geslaagde JobTaskLines blokkeert de snapshot niet');
check($forecast['warning'] === null, 'geen melding: ' . var_export($forecast['warning'], true));

if ($failures > 0) {
    fwrite(STDERR, "{$failures} assertion(s) failed\n");
    exit(1);
}

echo "OK opbrengst VC PRJ2602195\n";
