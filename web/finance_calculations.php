<?php
require_once __DIR__ . '/kvt_protect_dirs.php';

/**
 * Constants
 */
const FINANCE_REVENUE_GL_ACCOUNT_TYPE = 'GB-rekening';
const FINANCE_REVENUE_GL_ACCOUNT_NO = '800000';

/**
 * Genormaliseerde Type-aliassen voor de omzetrekening (G/L Account).
 * BC levert afhankelijk van taal/OData o.a. GB-rekening, Grootboekrekening, G/L Account en GLAccount.
 */
const FINANCE_REVENUE_GL_ACCOUNT_TYPE_ALIASES = [
    'glaccount',
    'glrekening',
    'grootboekrekening',
];

/**
 * Opbrengst VC (omzet voorcalculatie) op de project-totaalregel.
 * Contracttotaal op ProjectTaken (LVS_Contract_Total_Price_2), inclusief meerwerk.
 * Niet LVS_Schedule_Total_Price_2 (actueel budget; bij PRJ2602195 toevallig één
 * verkoopregel) en niet LVS_Baseline_Total_Price. Die basislijnprijs is op open
 * KVT-projecten met taak 000 bijna altijd 0 (4 van 53 gevuld) en is daarom al
 * afgewezen in #787 en #1170 (ticket #1264).
 * Het hoofdproject in de kolom is dit totaal minus de opbrengst meerwerk die
 * Argus al apart toont, zodat dat meerwerk niet dubbel telt.
 */
const FINANCE_OPBRENGST_VC_FIELD = 'LVS_Contract_Total_Price_2';

/**
 * Historisch veld op ProjectTaken. De kolom Opbrengst leest dit niet meer:
 * die somt planningsregels met No 800000. Niet Contract_Invoiced_Price en niet G/L 899901.
 *
 * ProjectTaken publiceert géén Contract_Total_Price (alleen JobTaskLines e.d. doen dat);
 * het contracttotaal staat op ProjectTaken als LVS_Contract_Total_Price_2. Live
 * kvtmdlive_aad (KVT/HVT): taak 000 LVS_Contract_Total_Price_2 == JobTaskLines.Contract_Total_Price
 * (bv. PRJ2602239: 24.297 vs basislijn 24.209). Niet LVS_Baseline_Total_Price: dat is
 * de bevroren basislijn, geen actueel contract (ticket #1170).
 * Opbrengst VC leest ditzelfde veld. De kolom Opbrengst/aanneemsom doet dat niet.
 */
const FINANCE_CONTRACT_TOTAL_PRICE_FIELD = 'LVS_Contract_Total_Price_2';

/**
 * Taaknummers voor de voorcalculatie-basislijn (LVS_Baseline_Total_Cost).
 * Nieuwe projecten hebben taak 000. Oudere SAP-projecten (15xxxx) niet;
 * die gebruiken 000-000-000 of, als die ook ontbreekt, de som van de Boeking-taken.
 * Het taaktype staat niet op ProjectTaken; dat komt uit JobTaskLines.
 */
const FINANCE_PROJECT_TOTAL_TASK_NO = '000';
const FINANCE_LEGACY_PROJECT_TOTAL_TASK_NO = '000-000-000';
const FINANCE_BASELINE_COST_FIELD = 'LVS_Baseline_Total_Cost';

/**
 * Boekingsregel op JobTaskLines. KvT/HvT publiceren Nederlandse waarden
 * (Boeking, Kop, Totaal). ProjectTaken heeft dit veld niet.
 */
const FINANCE_BOOKING_TASK_TYPE = 'Boeking';

/**
 * JobTaskLines-batches voor de Kosten VC-detailsom.
 * Eén OR-filter over alle fallback-projecten wordt bij KvT zo lang dat BC/Mímir
 * de query niet afrondt (cache blijft op 0/1 groeien, geen HTTP-fout).
 * 25 projecten of 1200 tekens filter, wat het eerst bereikt, blijft onder de
 * praktische querystring-grens en levert een af te ronden pagina.
 */
const FINANCE_JOB_TASK_LINES_MAX_PROJECTS = 25;
const FINANCE_JOB_TASK_LINES_MAX_FILTER_CHARS = 1200;

/**
 * Bovengrens voor de reeks JobTaskLines-batches in één voorcalculatie.
 * Een hangende pagina sterft eerder op de OData-timeout; dit stopt een lange
 * reeks trage maar "succesvolle" calls voordat de UI minutenlang stilvalt.
 */
const FINANCE_JOB_TASK_LINES_DEADLINE_SECONDS = 300;

/**
 * Maandsnapshot-versie. Ongenummerde bestanden (`.json`) zijn versie 1:
 * Opbrengst uit het contracttotaal, Kosten VC alleen taak 000, alleen OHW-projecten.
 * Versie 2 bewaart snapshots als `.v2.json`: Opbrengst VC uit de schedule-prijs.
 * Versie 3 bewaart snapshots als `.v3.json`. Opbrengst VC komt uit
 * LVS_Contract_Total_Price_2 minus het al getoonde meerwerk. Versie 2 blijft
 * daardoor niet hangen (ticket #1264).
 * Oudere bestanden blijven staan. De maandlijst herkent v3, v2 en legacy
 * `.json`. Ontbreekt v3, dan wordt die maand bij openen opnieuw opgebouwd;
 * lukt dat niet, dan blijft de oude snapshot leesbaar.
 */
const ARGUS_MONTH_CACHE_VERSION = 3;

/**
 * Functies
 */

/**
 * Berekent kolomwaarde Totale Kosten door alle werkorderkosten op te tellen.
 */
function finance_column_total_costs(array $workorders): float
{
    $total = 0.0;

    foreach ($workorders as $workorder) {
        if (!is_array($workorder)) {
            continue;
        }

        $total = finance_add_amount($total, $workorder['Actual_Costs'] ?? 0.0);
    }

    return $total;
}

/**
 * Berekent kolomwaarde Totale Opbrengst door alle werkorderopbrengsten op te tellen.
 */
function finance_column_total_revenue(array $workorders): float
{
    $total = 0.0;

    foreach ($workorders as $workorder) {
        if (!is_array($workorder)) {
            continue;
        }

        $total = finance_add_amount($total, $workorder['Total_Revenue'] ?? 0.0);
    }

    return $total;
}

/**
 * Berekent kolomwaarde Winst OHW met de formule: Marge Ttl x % gereed.
 */
function finance_column_winst_ohw(float $marginTotal, float $percentCompleted): float
{
    return $marginTotal * ($percentCompleted / 100.0);
}

/**
 * Berekent kolomwaarde Winst Vorige Periode als opbrengst minus kosten uit de vorige periode.
 */
function finance_column_prev_profit(?array $prevProfitData): ?float
{
    if (!is_array($prevProfitData)) {
        return null;
    }

    $revenue = finance_to_float($prevProfitData['revenue'] ?? 0.0);
    $costs = finance_to_float($prevProfitData['costs'] ?? 0.0);

    return finance_calculate_result($revenue, $costs);
}

/**
 * Berekent kolomwaarde Verschil als (huidige winst minus winst vorige periode).
 */
function finance_column_difference(float $currentRevenue, float $currentCosts, ?float $prevProfit): ?float
{
    if ($prevProfit === null) {
        return null;
    }

    $currentProfit = finance_calculate_result($currentRevenue, $currentCosts);

    return $currentProfit - $prevProfit;
}

/**
 * Berekent kolomwaarde Marge Totaal als verwachte opbrengst minus verwachte kosten VC.
 */
function finance_column_margin_total(float $expectedRevenue, float $expectedCostsVc): float
{
    return finance_calculate_result($expectedRevenue, $expectedCostsVc);
}

/**
 * Zet een inkomende waarde veilig om naar een numeriek bedrag.
 */
function finance_to_float(mixed $value): float
{
    return is_numeric($value) ? (float) $value : 0.0;
}

/**
 * Zet een bedrag om naar de absolute waarde voor consistente kosten/opbrengstvergelijking.
 */
function finance_abs_amount(mixed $value): float
{
    return abs(finance_to_float($value));
}

/**
 * Telt een nieuw bedrag op bij een bestaand financieel totaal.
 */
function finance_add_amount(float $current, mixed $value): float
{
    return $current + finance_to_float($value);
}

/**
 * Berekent financieel resultaat als opbrengst minus kosten.
 */
function finance_calculate_result(float $revenue, float $costs): float
{
    return $revenue - $costs;
}

/**
 * Bepaalt of een projectstatus financieel als afgesloten moet worden behandeld.
 */
function finance_is_closed_project_status(string $status): bool
{
    $normalized = strtolower(trim($status));

    return in_array($normalized, ['completed', 'closed', 'afgesloten', 'gereed'], true);
}

/**
 * Normaliseert row mode naar ondersteunde modi voor bedragberekening.
 */
function finance_normalize_row_mode(string $mode): string
{
    $normalized = strtolower(trim($mode));
    if ($normalized === 'sum_raw') {
        return 'sum_raw';
    }
    if ($normalized === 'sum') {
        return 'sum';
    }
    if ($normalized === 'sum_invert') {
        return 'sum_invert';
    }

    return 'first_numeric';
}

/**
 * Leest het eerste numerieke bedrag uit een prioriteitenlijst van bronkolommen.
 */
function finance_first_numeric_value(array $details, array $fields): float
{
    foreach ($fields as $field) {
        if (!is_string($field) || $field === '' || !array_key_exists($field, $details)) {
            continue;
        }

        $raw = $details[$field];
        if (!is_numeric($raw)) {
            continue;
        }

        return finance_abs_amount($raw);
    }

    return 0.0;
}

/**
 * Berekent een regelbedrag op basis van row mode.
 */
function finance_extract_row_amount(array $row, array $fields, string $mode): float
{
    $normalizedMode = finance_normalize_row_mode($mode);

    if ($normalizedMode === 'sum_raw') {
        $sum = 0.0;

        foreach ($fields as $field) {
            if (!is_string($field) || $field === '' || !array_key_exists($field, $row)) {
                continue;
            }

            $raw = $row[$field];
            if (!is_numeric($raw)) {
                continue;
            }

            $sum += (float) $raw;
        }

        return $sum;
    }

    if ($normalizedMode === 'sum_invert') {
        $sum = 0.0;
        $hasNegativeValue = false;

        foreach ($fields as $field) {
            if (!is_string($field) || $field === '' || !array_key_exists($field, $row)) {
                continue;
            }

            $raw = $row[$field];
            if (!is_numeric($raw)) {
                continue;
            }

            $numeric = (float) $raw;
            if ($numeric < 0.0) {
                $hasNegativeValue = true;
            }

            $sum += $numeric;
        }

        return $hasNegativeValue ? -$sum : $sum;
    }

    if ($normalizedMode === 'sum') {
        $sum = 0.0;
        foreach ($fields as $field) {
            if (!is_string($field) || $field === '' || !array_key_exists($field, $row)) {
                continue;
            }

            $raw = $row[$field];
            if (!is_numeric($raw)) {
                continue;
            }

            $sum += finance_abs_amount($raw);
        }

        return $sum;
    }

    return finance_first_numeric_value($row, $fields);
}

/**
 * Berekent kolomwaarde Actual_Costs voor één werkorderregel uit de twee BC kostenvelden.
 */
function finance_workorder_actual_costs(array $workorder): float
{
    $costItems = finance_to_float($workorder['KVT_Sum_Work_Order_Cost_Items'] ?? 0);
    $costOther = finance_to_float($workorder['KVT_Sum_Work_Order_Cost_Other'] ?? 0);

    return $costItems + $costOther;
}

/**
 * Berekent kolomwaarde Total_Revenue voor één werkorderregel uit het BC opbrengstveld.
 */
function finance_workorder_total_revenue(array $workorder): float
{
    return finance_abs_amount($workorder['KVT_Sum_Work_Order_Revenue'] ?? 0);
}

/**
 * Normaliseert een BC-code voor vergelijking: hoofdletters, spaties en leestekens vallen weg.
 */
function finance_normalize_code(string $value): string
{
    $normalized = strtolower(trim($value));

    return str_replace([' ', '-', '_', '/', '\\', '.'], '', $normalized);
}

/**
 * Bepaalt of Type een G/L-rekening is (GB-rekening, Grootboekrekening, G/L Account, GLAccount en gelijkwaardige aliassen).
 */
function finance_is_revenue_gl_account_type(string $type): bool
{
    $normalized = finance_normalize_code($type);
    if ($normalized === '') {
        return false;
    }

    if ($normalized === finance_normalize_code(FINANCE_REVENUE_GL_ACCOUNT_TYPE)) {
        return true;
    }

    return in_array($normalized, FINANCE_REVENUE_GL_ACCOUNT_TYPE_ALIASES, true);
}

/**
 * Bepaalt of een BC-projectplanningsregel op omzetrekening 800000 staat.
 * Alleen G/L-type (GB-rekening / Grootboekrekening / G/L Account / GLAccount) en No = 800000 telt mee;
 * resource-/artikelboekingen op dezelfde planning blijven buiten deze som.
 * Opbrengst VC gebruikt dit filter niet; die kolom leest LVS_Contract_Total_Price_2.
 */
function finance_is_revenue_gl_account_line(array $row): bool
{
    $type = trim((string) ($row['Type'] ?? ''));
    $no = trim((string) ($row['No'] ?? ''));

    return finance_is_revenue_gl_account_type($type)
        && $no === FINANCE_REVENUE_GL_ACCOUNT_NO;
}

/**
 * Bepaalt of Line_Type factureerbaar/billable is en geen prognose/forecast.
 */
function finance_is_billable_planning_line_type(string $lineType): bool
{
    $normalized = strtolower(trim($lineType));
    if ($normalized === '') {
        return false;
    }

    if (str_contains($normalized, 'prognose') || str_contains($normalized, 'forecast')) {
        return false;
    }

    return str_contains($normalized, 'factureer') || str_contains($normalized, 'billable');
}

/**
 * Leest project subordernr. (BC-veld LVS_Job_Change_Order_No). Leeg of alleen witruimte telt als geen meerwerk.
 */
function finance_planning_change_order_no(array $row): string
{
    return trim((string) ($row['LVS_Job_Change_Order_No'] ?? ''));
}

/**
 * Bepaalt of een planningsregel een geannuleerde originele regel is.
 * Ontbreekt het veld, dan telt de regel gewoon mee.
 */
function finance_is_cancelled_planning_line(array $row): bool
{
    if (!array_key_exists('LVS_Cancelled_Original_Line', $row)) {
        return false;
    }

    $value = $row['LVS_Cancelled_Original_Line'];

    return $value === true || $value === 1 || $value === '1' || $value === 'true';
}

/**
 * Bepaalt of Line_Type een prognose/forecast is.
 * Leeg telt niet als prognose: een regel zonder line type blijft beoordeelbaar op No.
 */
function finance_line_type_is_forecast(string $lineType): bool
{
    $normalized = strtolower(trim($lineType));
    if ($normalized === '') {
        return false;
    }

    return str_contains($normalized, 'prognose') || str_contains($normalized, 'forecast');
}

/**
 * Bepaalt of een planningsregel meetelt voor opbrengst of opbrengst meerwerk.
 * Zelfde regel als FinRap: G/L-rekening én No 800000, geen prognose en geen geannuleerde regel.
 * Line_Type factureerbaar is geen eis. Regels staan soms ten onrechte factureerbaar;
 * een andere rekening die dan meetelt hoort niet in Opbrengst. Budgetregels op 800000 wel.
 * Een gevuld subordernummer maakt de regel meerwerk, maar haalt hem niet uit de opbrengst.
 */
function finance_is_contract_revenue_planning_line(array $row): bool
{
    if (finance_is_cancelled_planning_line($row)) {
        return false;
    }

    if (!finance_is_revenue_gl_account_line($row)) {
        return false;
    }

    return !finance_line_type_is_forecast((string) ($row['Line_Type'] ?? ''));
}

/**
 * Berekent kolomwaarde Opbrengst voor één planningsregel.
 * G/L 800000, geen prognose, met leeg én gevuld LVS_Job_Change_Order_No.
 * Meerwerk blijft in dit totaal; finance_opbrengst_meerwerk_amount is alleen extra informatie.
 * Q002 in Description zonder subordernummer telt gewoon mee.
 */
function finance_opbrengst_amount(array $row): float
{
    if (!finance_is_contract_revenue_planning_line($row)) {
        return 0.0;
    }

    return finance_to_float($row['Line_Amount_LCY'] ?? 0.0);
}

/**
 * Berekent de opbrengst/aanneemsom voor één planningsregel.
 * Zelfde totaal als finance_opbrengst_amount: leeg en gevuld subordernummer tellen allebei mee.
 */
function finance_aanneemsom_amount(array $row): float
{
    return finance_opbrengst_amount($row);
}

/**
 * Berekent kolomwaarde Opbrengst meerwerk voor één planningsregel.
 * G/L 800000-regel (geen prognose) waarvan LVS_Job_Change_Order_No gevuld is; som van Line_Amount_LCY.
 * Q002 alleen in Description telt niet als meerwerk.
 */
function finance_opbrengst_meerwerk_amount(array $row): float
{
    if (!finance_is_contract_revenue_planning_line($row)) {
        return 0.0;
    }

    if (finance_planning_change_order_no($row) === '') {
        return 0.0;
    }

    return finance_to_float($row['Line_Amount_LCY'] ?? 0.0);
}

/**
 * Telt opbrengst (leeg én gevuld subordernr.) en het meerwerk-deel (alleen gevuld).
 * Opbrengst meerwerk zit in de opbrengst en wordt daar niet afgetrokken.
 *
 * @param array<int,mixed> $rows
 * @return array{opbrengst:float,aanneemsom:float,opbrengst_meerwerk:float}
 */
function finance_split_contract_revenue(array $rows): array
{
    $opbrengst = 0.0;
    $meerwerk = 0.0;

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $opbrengst = finance_add_amount($opbrengst, finance_opbrengst_amount($row));
        $meerwerk = finance_add_amount($meerwerk, finance_opbrengst_meerwerk_amount($row));
    }

    return [
        'opbrengst' => $opbrengst,
        'aanneemsom' => $opbrengst,
        'opbrengst_meerwerk' => $meerwerk,
    ];
}

/**
 * Leest het contracttotaal van één ProjectTaken-regel voor Opbrengst VC.
 * Alleen Job_Task_No 000 telt; andere taken leveren 0.
 * Ontbreekt het taaknummer, dan telt de regel wel (één projectkaartwaarde).
 * De kolom zelf gebruikt finance_opbrengst_vc_from_task_rows: taak 000, anders
 * 000-000-000, anders de detailtaken. LVS_Schedule_Total_Price_2 en
 * LVS_Baseline_Total_Price worden niet gelezen.
 * Line_Amount_LCY en G/L 800000 (aanneemsom, opbrengst meerwerk) worden genegeerd.
 */
function finance_opbrengst_vc_amount(array $row, string $projectTotaalTaskNo = '000'): float
{
    if (array_key_exists('Job_Task_No', $row)) {
        $taskNo = trim((string) $row['Job_Task_No']);
        if ($taskNo !== $projectTotaalTaskNo) {
            return 0.0;
        }
    }

    if (!array_key_exists(FINANCE_OPBRENGST_VC_FIELD, $row)) {
        return 0.0;
    }

    return finance_to_float($row[FINANCE_OPBRENGST_VC_FIELD]);
}

/**
 * Berekent het contracttotaal voor Opbrengst VC uit ProjectTaken-rijen van één project.
 * Zelfde taakkeuze als Kosten VC (finance_baseline_cost_pick_rows): taak 000 als die
 * bestaat, ook bij bedrag 0; anders taak 000-000-000; anders de detailtaken zonder
 * kop- en totaalregels. Het bedrag is LVS_Contract_Total_Price_2 en bevat het meerwerk.
 * finance_opbrengst_vc_hoofdproject haalt dat meerwerk eraf voor de kolom.
 *
 * @param array<int,mixed> $rows
 * @return array{amount:float,lines:array<int,array<string,mixed>>}
 */
function finance_opbrengst_vc_from_task_rows(array $rows): array
{
    $amount = 0.0;
    $lines = [];

    foreach (finance_baseline_cost_pick_rows($rows) as $row) {
        $lineAmount = finance_to_float($row[FINANCE_OPBRENGST_VC_FIELD] ?? 0.0);
        $amount = finance_add_amount($amount, $lineAmount);
        if ($lineAmount === 0.0) {
            continue;
        }

        $lines[] = [
            'Job_Task_No' => trim((string) ($row['Job_Task_No'] ?? '')),
            'Line_No' => 0,
            'Type' => (string) ($row['Job_Task_Type'] ?? ''),
            'No' => '',
            'Description' => (string) ($row['Description'] ?? ''),
            'Line_Amount' => $lineAmount,
            'Line_Type' => '',
        ];
    }

    return [
        'amount' => $amount,
        'lines' => $lines,
    ];
}

/**
 * Hoofdproject voor de kolom Opbrengst VC.
 * Het contracttotaal (taak 000 of de fallback) bevat het meerwerk al. De kolom
 * Opbrengst meerwerk toont dat deel apart, uit planningsregels met gevuld
 * subordernummer, en blijft zelf ongewijzigd. Het hoofdproject is het
 * contracttotaal minus dat meerwerk.
 * Lager dan 0 wordt 0: een contract van 0 bevat het meerwerk niet, en een
 * groter meerwerk blijft volledig in zijn eigen kolom.
 * Breakdownregels worden vanaf het einde verlaagd tot ze samen het hoofdproject
 * zijn. De weergave somt die regels; zonder deze verlaging zou het meerwerk
 * alsnog in Opbrengst VC meetellen.
 *
 * @param array<int,mixed> $lines
 * @return array{amount:float,lines:array<int,array<string,mixed>>}
 */
function finance_opbrengst_vc_hoofdproject(float $contractTotal, float $meerwerk, array $lines = []): array
{
    $contractTotal = finance_to_float($contractTotal);
    $meerwerk = finance_to_float($meerwerk);
    if ($meerwerk < 0.0) {
        $meerwerk = 0.0;
    }

    $hoofdproject = $contractTotal - $meerwerk;
    if ($hoofdproject < 0.0) {
        $hoofdproject = 0.0;
    }

    $work = [];
    $lineSum = 0.0;
    foreach ($lines as $line) {
        if (!is_array($line)) {
            continue;
        }

        $work[] = $line;
        $lineSum = finance_add_amount($lineSum, $line['Line_Amount'] ?? 0.0);
    }

    $excess = $lineSum - $hoofdproject;
    if ($excess < 0.0) {
        $excess = 0.0;
    }

    for ($index = count($work) - 1; $index >= 0 && $excess > 0.0; $index--) {
        $lineAmount = finance_to_float($work[$index]['Line_Amount'] ?? 0.0);
        $cut = $lineAmount < $excess ? $lineAmount : $excess;
        $work[$index]['Line_Amount'] = $lineAmount - $cut;
        $excess -= $cut;
    }

    $adjusted = [];
    foreach ($work as $line) {
        $lineAmount = finance_to_float($line['Line_Amount'] ?? 0.0);
        if ($lineAmount === 0.0) {
            continue;
        }

        $line['Line_Amount'] = $lineAmount;
        $adjusted[] = $line;
    }

    return [
        'amount' => $hoofdproject,
        'lines' => $adjusted,
    ];
}

/**
 * Leest het contracttotaal uit LVS_Contract_Total_Price_2 (ProjectTaken, taak 000).
 * Alleen Job_Task_No 000 (Project TOTAAL) telt; andere taken leveren 0.
 * Ontbreekt het taaknummer, dan telt de regel wel (één projectkaartwaarde).
 * Contract_Invoiced_Price wordt niet gelezen.
 * Dit is de actuele BC-stand, niet een periode-snapshot t/m de gekozen maand.
 */
function finance_contract_total_price_amount(array $row, string $projectTotaalTaskNo = '000'): float
{
    if (array_key_exists('Job_Task_No', $row)) {
        $taskNo = trim((string) $row['Job_Task_No']);
        if ($taskNo !== $projectTotaalTaskNo) {
            return 0.0;
        }
    }

    if (!array_key_exists(FINANCE_CONTRACT_TOTAL_PRICE_FIELD, $row)) {
        return 0.0;
    }

    return finance_to_float($row[FINANCE_CONTRACT_TOTAL_PRICE_FIELD]);
}

/**
 * Bestandssuffix van een nieuw geschreven maandsnapshot.
 * Lezen herkent ook `.v2.json` en legacy `.json`; die paden worden niet overschreven.
 */
function argus_month_cache_suffix(): string
{
    return '.v' . ARGUS_MONTH_CACHE_VERSION . '.json';
}

/**
 * Bepaalt of een geladen maandsnapshot bij de huidige berekening hoort.
 */
function argus_month_cache_is_current(array $data): bool
{
    return (int) ($data['cache_version'] ?? 0) === ARGUS_MONTH_CACHE_VERSION;
}

/**
 * Bestandsnaam-slug van een bedrijf. Zelfde regels als de bestaande maandcache.
 */
function argus_month_company_slug(string $company): string
{
    $slug = preg_replace('/[^a-z0-9_-]/i', '_', strtolower(trim($company)));

    return is_string($slug) ? $slug : '';
}

/**
 * Bestandsnaam van één snapshotversie. Versie 1 is het ongenummerde `.json`-bestand.
 */
function argus_month_snapshot_basename(string $company, string $yearMonth, int $version): ?string
{
    $slug = argus_month_company_slug($company);
    if ($slug === '' || preg_match('/^\d{4}-\d{2}$/', $yearMonth) !== 1) {
        return null;
    }

    if ($version === 3) {
        return $slug . '_' . $yearMonth . '.v3.json';
    }
    if ($version === 2) {
        return $slug . '_' . $yearMonth . '.v2.json';
    }
    if ($version === 1) {
        return $slug . '_' . $yearMonth . '.json';
    }

    return null;
}

/**
 * Leest jaar-maand en versie uit een cachebestandsnaam.
 * WIP-bestanden en tijdelijke schrijfsels horen niet in de maandlijst.
 * Voorrang later: v3, dan v2, dan legacy `.json`.
 *
 * @return array{year_month:string,version:int}|null
 */
function argus_parse_month_snapshot_filename(string $company, string $filename): ?array
{
    $slug = argus_month_company_slug($company);
    if ($slug === '') {
        return null;
    }

    $prefix = $slug . '_';
    if (!str_starts_with($filename, $prefix)) {
        return null;
    }

    $rest = substr($filename, strlen($prefix));
    if (str_starts_with($rest, 'wip_')) {
        return null;
    }

    if (preg_match('/^(\d{4}-\d{2})\.v([23])\.json$/', $rest, $matches) === 1) {
        return [
            'year_month' => $matches[1],
            'version' => (int) $matches[2],
        ];
    }

    if (preg_match('/^(\d{4}-\d{2})\.json$/', $rest, $matches) === 1) {
        return [
            'year_month' => $matches[1],
            'version' => 1,
        ];
    }

    return null;
}

/**
 * Unieke opgeslagen maanden, nieuwste eerst. Een maand telt één keer,
 * ook als v3, v2 én legacy naast elkaar staan.
 *
 * @param array<int,mixed> $filenames
 * @return list<string>
 */
function argus_list_saved_months_from_names(string $company, array $filenames): array
{
    $found = [];
    foreach ($filenames as $filename) {
        if (!is_string($filename)) {
            continue;
        }

        $parsed = argus_parse_month_snapshot_filename($company, $filename);
        if ($parsed === null) {
            continue;
        }

        $yearMonth = $parsed['year_month'];
        $version = $parsed['version'];
        if (!isset($found[$yearMonth]) || $version > $found[$yearMonth]) {
            $found[$yearMonth] = $version;
        }
    }

    $months = array_keys($found);
    rsort($months);

    return $months;
}

/**
 * Maanden in een cachemap. Alleen echte bestanden; geen WIP en geen mappen.
 *
 * @return list<string>
 */
function argus_list_saved_months(string $dir, string $company): array
{
    if (!is_dir($dir)) {
        return [];
    }

    $entries = @scandir($dir);
    if (!is_array($entries)) {
        return [];
    }

    $filenames = [];
    foreach ($entries as $entry) {
        if (!is_string($entry) || $entry === '.' || $entry === '..') {
            continue;
        }
        if (!is_file($dir . DIRECTORY_SEPARATOR . $entry)) {
            continue;
        }
        $filenames[] = $entry;
    }

    return argus_list_saved_months_from_names($company, $filenames);
}

/**
 * Leest een snapshotbestand. Ongeldige JSON telt niet als bruikbare maand.
 */
function argus_read_month_snapshot_file(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return null;
    }

    $data = json_decode($raw, true);

    return is_array($data) ? $data : null;
}

/**
 * Hoogste leesbare snapshot voor een maand: v3, anders v2, anders legacy.
 *
 * @return array{data:?array,version:int,path:?string}
 */
function argus_load_best_month_snapshot(string $dir, string $company, string $yearMonth): array
{
    $empty = [
        'data' => null,
        'version' => 0,
        'path' => null,
    ];
    if (preg_match('/^\d{4}-\d{2}$/', $yearMonth) !== 1) {
        return $empty;
    }

    foreach ([3, 2, 1] as $version) {
        $basename = argus_month_snapshot_basename($company, $yearMonth, $version);
        if ($basename === null) {
            continue;
        }

        $path = $dir . DIRECTORY_SEPARATOR . $basename;
        $data = argus_read_month_snapshot_file($path);
        if ($data === null) {
            continue;
        }

        return [
            'data' => $data,
            'version' => $version,
            'path' => $path,
        ];
    }

    return $empty;
}

/**
 * Peildatum/periode uit een oude snapshot. De bestandsmaand is de reserve.
 */
function argus_snapshot_period(array $snapshot, string $fallbackYearMonth): string
{
    $yearMonth = trim((string) ($snapshot['year_month'] ?? ''));
    if (preg_match('/^\d{4}-\d{2}$/', $yearMonth) === 1) {
        return $yearMonth;
    }

    return $fallbackYearMonth;
}

/**
 * OHW-peildatum die bij de oude snapshot hoort.
 *
 * @return array<string,mixed>|null
 */
function argus_snapshot_bounds(array $snapshot): ?array
{
    $debug = $snapshot['ohw_debug'] ?? null;
    if (!is_array($debug)) {
        return null;
    }

    $bounds = $debug['snapshot_bounds'] ?? null;

    return is_array($bounds) ? $bounds : null;
}

/**
 * Melding wanneer herbouw naar v3 niet lukt. De oude snapshot blijft de bron.
 */
function argus_month_rebuild_failure_notice(string $reason = ''): string
{
    $notice = 'Deze maand kon niet opnieuw worden opgebouwd met de nieuwe Opbrengst VC-berekening. U ziet de eerder opgeslagen snapshot.';
    $reason = trim($reason);
    if ($reason !== '') {
        $notice .= ' ' . $reason;
    }

    return $notice;
}

/**
 * Melding terwijl een oude maand nog naar v3 wordt opgebouwd.
 */
function argus_month_rebuild_pending_notice(): string
{
    return 'Deze maand wordt opnieuw opgebouwd met de nieuwe Opbrengst VC-berekening, met dezelfde peildatum als de opgeslagen snapshot. Lukt de herbouw niet, dan blijft deze vorige snapshot zichtbaar.';
}

/**
 * @return array{data:?array,notice:?string,version:int,rebuilt:bool,period:string,needs_rebuild:bool}
 */
function argus_month_snapshot_view(?array $data, int $version, string $period, ?string $notice, bool $rebuilt, bool $needsRebuild): array
{
    return [
        'data' => $data,
        'notice' => $notice,
        'version' => $version,
        'rebuilt' => $rebuilt,
        'period' => $period,
        'needs_rebuild' => $needsRebuild,
    ];
}

/**
 * Opent een maand.
 * Een leesbaar v3-bestand wint. Zonder v3 wordt herbouwd met de peildatum uit
 * de beste oudere snapshot (v2 boven legacy). De herbouw schrijft alleen `.v3.json`.
 * Zonder herbouw-callback blijft de oude snapshot staan en mag de aanroeper
 * de opbouw zelf starten. Een mislukte herbouw toont de oude snapshot met een melding.
 *
 * @param callable(string,string,array):array|null $rebuild
 * @return array{data:?array,notice:?string,version:int,rebuilt:bool,period:string,needs_rebuild:bool}
 */
function argus_open_month_snapshot(string $dir, string $company, string $yearMonth, ?callable $rebuild = null): array
{
    if (preg_match('/^\d{4}-\d{2}$/', $yearMonth) !== 1) {
        return argus_month_snapshot_view(null, 0, $yearMonth, 'Ongeldige maand.', false, false);
    }

    $loaded = argus_load_best_month_snapshot($dir, $company, $yearMonth);
    if ($loaded['version'] === 3 && is_array($loaded['data'])) {
        return argus_month_snapshot_view(
            $loaded['data'],
            3,
            argus_snapshot_period($loaded['data'], $yearMonth),
            null,
            false,
            false
        );
    }

    $v3Basename = argus_month_snapshot_basename($company, $yearMonth, 3);
    $v3Path = $v3Basename !== null ? ($dir . DIRECTORY_SEPARATOR . $v3Basename) : '';
    if ($v3Path !== '' && is_file($v3Path)) {
        $period = is_array($loaded['data'])
            ? argus_snapshot_period($loaded['data'], $yearMonth)
            : $yearMonth;

        return argus_month_snapshot_view(
            is_array($loaded['data']) ? $loaded['data'] : null,
            (int) $loaded['version'],
            $period,
            argus_month_rebuild_failure_notice('Het v3-bestand is onleesbaar en is niet overschreven.'),
            false,
            false
        );
    }

    if (!is_array($loaded['data'])) {
        return argus_month_snapshot_view(null, 0, $yearMonth, null, false, false);
    }

    $period = argus_snapshot_period($loaded['data'], $yearMonth);
    $bounds = argus_snapshot_bounds($loaded['data']);
    if ($rebuild === null) {
        return argus_month_snapshot_view(
            $loaded['data'],
            (int) $loaded['version'],
            $period,
            argus_month_rebuild_pending_notice(),
            false,
            true
        );
    }

    try {
        $built = $rebuild($company, $period, $loaded['data']);
    } catch (Throwable $error) {
        return argus_month_snapshot_view(
            $loaded['data'],
            (int) $loaded['version'],
            $period,
            argus_month_rebuild_failure_notice($error->getMessage()),
            false,
            false
        );
    }

    if (!is_array($built)) {
        return argus_month_snapshot_view(
            $loaded['data'],
            (int) $loaded['version'],
            $period,
            argus_month_rebuild_failure_notice(''),
            false,
            false
        );
    }

    $built['cache_version'] = ARGUS_MONTH_CACHE_VERSION;
    $built['year_month'] = $period;
    if ($bounds !== null) {
        if (!isset($built['ohw_debug']) || !is_array($built['ohw_debug'])) {
            $built['ohw_debug'] = [];
        }
        if (!is_array($built['ohw_debug']['snapshot_bounds'] ?? null)) {
            $built['ohw_debug']['snapshot_bounds'] = $bounds;
        }
    }

    if ($v3Path === '' || !argus_publish_snapshot_file(
        $v3Path,
        $built,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    )) {
        return argus_month_snapshot_view(
            $loaded['data'],
            (int) $loaded['version'],
            $period,
            argus_month_rebuild_failure_notice('De nieuwe snapshot kon niet worden opgeslagen.'),
            false,
            false
        );
    }

    return argus_month_snapshot_view($built, 3, $period, null, true, false);
}

/**
 * Schrijft een bestand via een tijdelijk bestand en rename.
 * De bestaande inhoud blijft leesbaar tot de nieuwe versie compleet is.
 */
function argus_write_file_atomically(string $path, string $contents): bool
{
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
        return false;
    }

    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    $written = @file_put_contents($tmp, $contents, LOCK_EX);
    if ($written === false) {
        @unlink($tmp);
        return false;
    }

    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }

    return true;
}

/**
 * Publiceert een maandsnapshot pas als de JSON compleet is.
 * Een mislukte encode laat het vorige bestand staan.
 */
function argus_publish_snapshot_file(string $path, array $data, int $jsonFlags = 0): bool
{
    if (!isset($data['cache_version'])) {
        $data['cache_version'] = ARGUS_MONTH_CACHE_VERSION;
    }

    $json = json_encode($data, $jsonFlags);
    if (!is_string($json)) {
        return false;
    }

    return argus_write_file_atomically($path, $json);
}

/**
 * Normaliseert het refresh-id waarmee een lopende opbouw zijn eigen WIP-bestand schrijft.
 * Ongeldige waarden (padtekens) vallen terug op leeg, nooit op een ander bestand.
 */
function argus_month_refresh_id(string $refreshId): string
{
    $refreshId = strtolower(trim($refreshId));
    if (preg_match('/^[a-f0-9]{16}$/', $refreshId) !== 1) {
        return '';
    }

    return $refreshId;
}

/**
 * Refresh-id van het nieuwste WIP-bestand van deze maand.
 * Leeg betekent dat er nog geen lopende herbouw is.
 * Legacy-WIP zonder id, tijdelijke bestanden en andere maanden tellen niet mee.
 */
function argus_active_month_wip_refresh_id(string $dir, string $company, string $yearMonth): string
{
    $slug = argus_month_company_slug($company);
    $safeYearMonth = preg_replace('/[^0-9-]/', '', $yearMonth);
    if ($slug === '' || !is_string($safeYearMonth) || preg_match('/^\d{4}-\d{2}$/', $safeYearMonth) !== 1) {
        return '';
    }

    if (!is_dir($dir)) {
        return '';
    }

    $prefix = $slug . '_wip_' . $safeYearMonth . '_';
    $suffix = argus_month_cache_suffix();
    $entries = @scandir($dir);
    if (!is_array($entries)) {
        return '';
    }

    $bestId = '';
    $bestMtime = -1;
    foreach ($entries as $entry) {
        if (!is_string($entry) || !str_starts_with($entry, $prefix) || !str_ends_with($entry, $suffix)) {
            continue;
        }

        $refreshId = argus_month_refresh_id(substr($entry, strlen($prefix), -strlen($suffix)));
        if ($refreshId === '') {
            continue;
        }

        $path = $dir . DIRECTORY_SEPARATOR . $entry;
        if (!is_file($path)) {
            continue;
        }

        $mtime = @filemtime($path);
        $mtime = $mtime === false ? 0 : $mtime;
        if ($mtime < $bestMtime) {
            continue;
        }
        if ($mtime === $bestMtime && strcmp($refreshId, $bestId) < 0) {
            continue;
        }

        $bestMtime = $mtime;
        $bestId = $refreshId;
    }

    return $bestId;
}

/**
 * Eén OHW- of voorcalculatiestap kan minuten duren. Een kortere grens liet een
 * tweede tabblad dezelfde maand nog een keer starten terwijl de eerste stap liep.
 */
const ARGUS_MONTH_REBUILD_LOCK_TTL_SECONDS = 1200;

/**
 * Normaliseert de tab-eigenaar van een herbouw. Ongeldige waarden worden leeg.
 */
function argus_month_rebuild_owner(string $owner): string
{
    $owner = strtolower(trim($owner));
    if (preg_match('/^[a-f0-9]{8,32}$/', $owner) !== 1) {
        return '';
    }

    return $owner;
}

/**
 * Lockbestand naast de WIP. Geen snapshot en geen WIP-bestand.
 */
function argus_month_wip_lock_path(string $dir, string $company, string $yearMonth): ?string
{
    $slug = argus_month_company_slug($company);
    if ($slug === '' || preg_match('/^\d{4}-\d{2}$/', $yearMonth) !== 1) {
        return null;
    }

    return $dir . DIRECTORY_SEPARATOR . $slug . '_wip_' . $yearMonth . '.lock';
}

function argus_month_wip_refresh_path(string $dir, string $company, string $yearMonth, string $refreshId): ?string
{
    $slug = argus_month_company_slug($company);
    $refreshId = argus_month_refresh_id($refreshId);
    if ($slug === '' || $refreshId === '' || preg_match('/^\d{4}-\d{2}$/', $yearMonth) !== 1) {
        return null;
    }

    return $dir . DIRECTORY_SEPARATOR . $slug . '_wip_' . $yearMonth . '_' . $refreshId . argus_month_cache_suffix();
}

function argus_month_published_v3_exists(string $dir, string $company, string $yearMonth): bool
{
    $basename = argus_month_snapshot_basename($company, $yearMonth, ARGUS_MONTH_CACHE_VERSION);
    if ($basename === null) {
        return false;
    }

    $data = argus_read_month_snapshot_file($dir . DIRECTORY_SEPARATOR . $basename);

    return is_array($data) && argus_month_cache_is_current($data);
}

/**
 * @param array<string,mixed> $extra
 * @return array{ok:bool,refresh_id:string,role:string,resumed:bool,owner:string,published:bool,generation:int,error?:string}
 */
function argus_month_rebuild_claim_response(bool $ok, string $refreshId, string $role, bool $resumed, string $owner, bool $published, string $error = '', int $generation = 0): array
{
    $response = [
        'ok' => $ok,
        'refresh_id' => $refreshId,
        'role' => $role,
        'resumed' => $resumed,
        'owner' => $owner,
        'published' => $published,
        'generation' => $generation,
    ];
    if ($error !== '') {
        $response['error'] = $error;
    }

    return $response;
}

/**
 * Claimt de enige herbouw voor dit bedrijf en deze maand.
 * Een verse lock van een andere tab blijft staan: die aanroep wordt toeschouwer.
 * Dezelfde tab of een verlopen lock hervat de bestaande WIP. Er wordt niets gewist.
 * Zonder WIP en met een actuele v3 stopt een automatische herbouw (stopWhenPublished).
 *
 * @return array{ok:bool,refresh_id:string,role:string,resumed:bool,owner:string,published:bool,error?:string}
 */
function argus_claim_running_month_rebuild(string $dir, string $company, string $yearMonth, string $owner, bool $stopWhenPublished, ?int $now = null, int $ttlSeconds = ARGUS_MONTH_REBUILD_LOCK_TTL_SECONDS): array
{
    $failed = argus_month_rebuild_claim_response(false, '', '', false, '', false, 'Ongeldige maand.');
    $lockPath = argus_month_wip_lock_path($dir, $company, $yearMonth);
    if ($lockPath === null) {
        return $failed;
    }

    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
        return argus_month_rebuild_claim_response(false, '', '', false, '', false, 'Herbouw-lock kon niet worden gemaakt.');
    }

    $handle = @fopen($lockPath, 'c+');
    if ($handle === false) {
        return argus_month_rebuild_claim_response(false, '', '', false, '', false, 'Herbouw-lock kon niet worden geopend.');
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            return argus_month_rebuild_claim_response(false, '', '', false, '', false, 'Herbouw-lock is bezet.');
        }

        $now = $now ?? time();
        $ttlSeconds = max(1, $ttlSeconds);
        $requestedOwner = argus_month_rebuild_owner($owner);
        rewind($handle);
        $raw = stream_get_contents($handle);
        $state = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $existingId = argus_active_month_wip_refresh_id($dir, $company, $yearMonth);

        if ($existingId === '') {
            if ($stopWhenPublished && argus_month_published_v3_exists($dir, $company, $yearMonth)) {
                return argus_month_rebuild_claim_response(true, '', 'watcher', true, $requestedOwner, true);
            }

            $refreshId = bin2hex(random_bytes(8));
            $workerOwner = $requestedOwner !== '' ? $requestedOwner : bin2hex(random_bytes(8));
            $wipPath = argus_month_wip_refresh_path($dir, $company, $yearMonth, $refreshId);
            $wipJson = json_encode([
                'cache_version' => ARGUS_MONTH_CACHE_VERSION,
                'refresh_id' => $refreshId,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($wipPath === null || !is_string($wipJson) || !argus_write_file_atomically($wipPath, $wipJson)) {
                return argus_month_rebuild_claim_response(false, '', '', false, '', false, 'WIP kon niet worden aangemaakt.');
            }

            argus_month_rebuild_write_state($handle, $refreshId, $workerOwner, $now, true, 1);

            return argus_month_rebuild_claim_response(true, $refreshId, 'worker', false, $workerOwner, false, '', 1);
        }

        $storedId = is_array($state) ? argus_month_refresh_id((string) ($state['refresh_id'] ?? '')) : '';
        $storedOwner = is_array($state) ? argus_month_rebuild_owner((string) ($state['owner'] ?? '')) : '';
        $age = $now - (int) (is_array($state) ? ($state['updated_at'] ?? 0) : 0);
        $lockMatchesWip = $storedId === $existingId && $storedOwner !== '';
        $sameOwner = $lockMatchesWip && $requestedOwner !== '' && hash_equals($storedOwner, $requestedOwner);
        $fresh = $lockMatchesWip && $age < $ttlSeconds;
        $busy = $lockMatchesWip && !empty($state['busy']);
        $storedGeneration = is_array($state) ? (int) ($state['generation'] ?? 0) : 0;
        if ($storedGeneration < 1) {
            $storedGeneration = 1;
        }
        // Een verse stap, ook van hetzelfde tabblad, mag niet opnieuw starten.
        // Die schrijft de hele WIP terug en zou nieuwere details of voorcalculatie wissen.
        if ($fresh && ($busy || !$sameOwner)) {
            return argus_month_rebuild_claim_response(true, $existingId, 'watcher', true, $storedOwner, false, '', $storedGeneration);
        }

        $workerOwner = $requestedOwner !== '' ? $requestedOwner : ($storedOwner !== '' ? $storedOwner : bin2hex(random_bytes(8)));
        $generation = $storedGeneration + 1;
        argus_month_rebuild_write_state($handle, $existingId, $workerOwner, $now, true, $generation);

        return argus_month_rebuild_claim_response(true, $existingId, 'worker', true, $workerOwner, false, '', $generation);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/**
 * Houdt een lopende herbouw vers. Een andere refresh-id wijzigt de lock niet.
 */
function argus_month_rebuild_heartbeat(string $dir, string $company, string $yearMonth, string $refreshId, int $generation, ?int $now = null): bool
{
    $refreshId = argus_month_refresh_id($refreshId);
    $lockPath = argus_month_wip_lock_path($dir, $company, $yearMonth);
    if ($refreshId === '' || $lockPath === null || !is_file($lockPath)) {
        return false;
    }

    $handle = @fopen($lockPath, 'c+');
    if ($handle === false) {
        return false;
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            return false;
        }

        rewind($handle);
        $raw = stream_get_contents($handle);
        $state = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($state) || argus_month_refresh_id((string) ($state['refresh_id'] ?? '')) !== $refreshId) {
            return false;
        }

        $owner = argus_month_rebuild_owner((string) ($state['owner'] ?? ''));
        $lockedGeneration = (int) ($state['generation'] ?? 0);
        if ($owner === '' || $generation < 1 || $lockedGeneration !== $generation) {
            return false;
        }

        argus_month_rebuild_write_state($handle, $refreshId, $owner, $now ?? time(), true, $lockedGeneration);

        return true;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/**
 * De stap is klaar met schrijven. Hetzelfde tabblad mag daarna verder; een ander tabblad niet.
 * Een oudere generatie laat de lock van de nieuwe run met rust.
 */
function argus_month_rebuild_release(string $dir, string $company, string $yearMonth, string $refreshId, int $generation): bool
{
    $refreshId = argus_month_refresh_id($refreshId);
    $lockPath = argus_month_wip_lock_path($dir, $company, $yearMonth);
    if ($refreshId === '' || $lockPath === null || !is_file($lockPath)) {
        return false;
    }

    $handle = @fopen($lockPath, 'c+');
    if ($handle === false) {
        return false;
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            return false;
        }

        rewind($handle);
        $raw = stream_get_contents($handle);
        $state = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($state) || argus_month_refresh_id((string) ($state['refresh_id'] ?? '')) !== $refreshId) {
            return false;
        }

        $owner = argus_month_rebuild_owner((string) ($state['owner'] ?? ''));
        $lockedGeneration = (int) ($state['generation'] ?? 0);
        if ($owner === '' || $generation < 1 || $lockedGeneration !== $generation) {
            return false;
        }

        argus_month_rebuild_write_state($handle, $refreshId, $owner, (int) ($state['updated_at'] ?? time()), false, $lockedGeneration);

        return true;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/**
 * Voegt een late WIP-schrijfactie samen met wat er nu op schijf staat.
 * Lege waarden vervangen geen al opgeslagen details of voorcalculatie.
 *
 * @param array<string,mixed> $base
 * @param array<string,mixed> $overlay
 * @return array<string,mixed>
 */
function argus_merge_month_wip(array $base, array $overlay): array
{
    foreach ($overlay as $key => $value) {
        if (!array_key_exists($key, $base)) {
            $base[$key] = $value;
            continue;
        }

        $current = $base[$key];
        if (is_array($value) && is_array($current)) {
            if ($value === []) {
                continue;
            }
            if (array_is_list($value) && array_is_list($current)) {
                foreach ($value as $item) {
                    if (!in_array($item, $current, true)) {
                        $current[] = $item;
                    }
                }
                $base[$key] = $current;
                continue;
            }
            $base[$key] = argus_merge_month_wip($current, $value);
            continue;
        }

        if ($value === null || $value === '') {
            continue;
        }

        $base[$key] = $value;
    }

    return $base;
}

/**
 * Schrijft WIP zonder nieuwere sleutels van een parallelle stap te wissen.
 *
 * @param array<string,mixed> $data
 */
function argus_save_month_wip_merged(string $path, array $data): bool
{
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
        return false;
    }

    $lockHandle = @fopen($path . '.merge.lock', 'c');
    if ($lockHandle === false) {
        return false;
    }

    try {
        if (!flock($lockHandle, LOCK_EX)) {
            return false;
        }

        $latest = [];
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                $latest = $decoded;
            }
        }

        if ($latest !== []) {
            $data = argus_merge_month_wip($latest, $data);
        }
        $data['cache_version'] = ARGUS_MONTH_CACHE_VERSION;
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return false;
        }

        return argus_write_file_atomically($path, $json);
    } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

/**
 * @param resource $handle
 */
function argus_month_rebuild_generation_is_current(string $dir, string $company, string $yearMonth, string $refreshId, int $generation): bool
{
    $refreshId = argus_month_refresh_id($refreshId);
    $lockPath = argus_month_wip_lock_path($dir, $company, $yearMonth);
    if ($refreshId === '' || $generation < 1 || $lockPath === null || !is_file($lockPath)) {
        return false;
    }

    $handle = @fopen($lockPath, 'c+');
    if ($handle === false) {
        return false;
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            return false;
        }

        rewind($handle);
        $raw = stream_get_contents($handle);
        $state = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        return is_array($state)
            && argus_month_refresh_id((string) ($state['refresh_id'] ?? '')) === $refreshId
            && (int) ($state['generation'] ?? 0) === $generation;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/**
 * Schrijft WIP alleen als deze stap nog de huidige generatie is.
 * Een oudere in-flight request laat het bestand onaangeroerd.
 *
 * @param array<string,mixed> $data
 */
function argus_save_month_wip_if_current(string $dir, string $company, string $yearMonth, string $refreshId, int $generation, array $data): bool
{
    $refreshId = argus_month_refresh_id($refreshId);
    $lockPath = argus_month_wip_lock_path($dir, $company, $yearMonth);
    $wipPath = argus_month_wip_refresh_path($dir, $company, $yearMonth, $refreshId);
    if ($refreshId === '' || $generation < 1 || $lockPath === null || $wipPath === null || !is_file($lockPath)) {
        return false;
    }

    $handle = @fopen($lockPath, 'c+');
    if ($handle === false) {
        return false;
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            return false;
        }

        rewind($handle);
        $raw = stream_get_contents($handle);
        $state = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($state)
            || argus_month_refresh_id((string) ($state['refresh_id'] ?? '')) !== $refreshId
            || (int) ($state['generation'] ?? 0) !== $generation) {
            return false;
        }

        return argus_save_month_wip_merged($wipPath, $data);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/**
 * @param resource $handle
 */
function argus_month_rebuild_write_state($handle, string $refreshId, string $owner, int $now, bool $busy, int $generation): bool
{
    $json = json_encode([
        'refresh_id' => $refreshId,
        'owner' => $owner,
        'updated_at' => $now,
        'busy' => $busy,
        'generation' => $generation,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return false;
    }

    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, $json);
    fflush($handle);

    return true;
}

/**
 * Fout die de vorige maandsnapshot moet laten staan.
 * Een JobTaskLines-timeout of filterfout mag de maand niet vervangen door een halve voorcalculatie.
 * Een ontbrekend taaktype (schema) blijft een waarschuwing: die som wordt nog gepubliceerd.
 */
function finance_forecast_blocks_snapshot(array $forecast): ?string
{
    if (empty($forecast['task_type_error'])) {
        return null;
    }

    $message = trim((string) ($forecast['warning'] ?? ''));
    if ($message === '') {
        return 'JobTaskLines ophalen mislukt.';
    }

    return $message;
}

/**
 * OData-clause Job_No eq '…' met geëscapete quotes.
 */
function finance_odata_job_no_clause(string $projectNo): string
{
    return "Job_No eq '" . str_replace("'", "''", $projectNo) . "'";
}

/**
 * OR-filter over projectnummers: (Job_No eq 'A' or Job_No eq 'B').
 *
 * @param array<int,mixed> $projectNumbers
 */
function finance_job_no_or_filter(array $projectNumbers): string
{
    $parts = [];
    foreach ($projectNumbers as $projectNo) {
        $text = trim((string) $projectNo);
        if ($text === '') {
            continue;
        }

        $parts[] = finance_odata_job_no_clause($text);
    }

    if ($parts === []) {
        return '';
    }

    return '(' . implode(' or ', $parts) . ')';
}

/**
 * JobTaskLines-filter: projecten én alleen Boeking-taken.
 *
 * @param array<int,mixed> $projectNumbers
 */
function finance_job_task_lines_filter(array $projectNumbers): string
{
    $projects = finance_job_no_or_filter($projectNumbers);
    if ($projects === '') {
        return '';
    }

    return $projects . " and Job_Task_Type eq '" . str_replace("'", "''", FINANCE_BOOKING_TASK_TYPE) . "'";
}

/**
 * Splitst fallback-projecten in JobTaskLines-batches.
 * Elke batch blijft binnen het maximum aantal projecten en de maximale filterlengte.
 * Eén project dat zelf al te lang is, blijft één batch: verder splitsen kan niet.
 *
 * @param array<int,mixed> $projectNumbers
 * @return list<list<string>>
 */
function finance_job_task_lines_chunks(
    array $projectNumbers,
    int $maxProjects = FINANCE_JOB_TASK_LINES_MAX_PROJECTS,
    int $maxFilterChars = FINANCE_JOB_TASK_LINES_MAX_FILTER_CHARS
): array {
    $unique = [];
    $seen = [];
    foreach ($projectNumbers as $projectNo) {
        $text = trim((string) $projectNo);
        if ($text === '') {
            continue;
        }

        $key = strtolower($text);
        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $unique[] = $text;
    }

    $maxProjects = max(1, $maxProjects);
    $maxFilterChars = max(1, $maxFilterChars);
    $chunks = [];
    $current = [];
    foreach ($unique as $projectNo) {
        $candidate = $current;
        $candidate[] = $projectNo;
        $filter = finance_job_task_lines_filter($candidate);
        if ($current !== [] && (count($candidate) > $maxProjects || strlen($filter) > $maxFilterChars)) {
            $chunks[] = $current;
            $current = [$projectNo];
            continue;
        }

        $current = $candidate;
    }

    if ($current !== []) {
        $chunks[] = $current;
    }

    return $chunks;
}

/**
 * Bepaalt of een projecttaaktype een totaal- of kopregel is.
 * Die regels herhalen de detailtaken en mogen in een som niet nog eens meetellen.
 * Leeg type is geen totaal: dan is het type niet beschikbaar en telt de regel wel mee.
 * Herkent NL (totaal, kop) en EN (total, heading, Begin-Total, End-Total).
 */
function finance_task_type_is_total_or_heading(string $taskType): bool
{
    $compact = str_replace([' ', '-', '_'], '', strtolower(trim($taskType)));
    if ($compact === '') {
        return false;
    }

    if (str_contains($compact, 'totaal') || str_contains($compact, 'total')) {
        return true;
    }

    if (str_contains($compact, 'heading') || str_contains($compact, 'kop')) {
        return true;
    }

    return $compact === 'head';
}

/**
 * Bepaalt of een taaktype een boekingsregel is.
 * JobTaskLines levert 'Boeking' (KvT/HvT). Alleen die regels tellen mee in de detailsom.
 */
function finance_task_type_is_booking(string $taskType): bool
{
    $compact = str_replace([' ', '-', '_'], '', strtolower(trim($taskType)));

    return $compact === strtolower(FINANCE_BOOKING_TASK_TYPE);
}

/**
 * Bepaalt of Kosten VC voor deze ProjectTaken-rijen de detailsom is.
 * Taak 000 of 000-000-000 wint; dan is het taaktype uit JobTaskLines niet nodig.
 * Zonder taaknummer is er niets te sommen.
 */
function finance_baseline_cost_uses_detail_sum(array $rows): bool
{
    $hasDetail = false;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $taskNo = trim((string) ($row['Job_Task_No'] ?? ''));
        if ($taskNo === '') {
            continue;
        }

        if ($taskNo === FINANCE_PROJECT_TOTAL_TASK_NO || $taskNo === FINANCE_LEGACY_PROJECT_TOTAL_TASK_NO) {
            return false;
        }

        $hasDetail = true;
    }

    return $hasDetail;
}

/**
 * Kiest de ProjectTaken-regels die de voorcalculatie-kosten (basislijn) vormen.
 * 1. Taak 000 als die bestaat, ook bij bedrag 0 (dan geen fallback).
 * 2. Anders taak 000-000-000 (oudere SAP-projecten, bijvoorbeeld 159927).
 * 3. Anders de detailtaken zonder kop- en totaalregels (bijvoorbeeld 150933 en 153390).
 *    Job_Task_Type komt dan uit JobTaskLines; de aanroeper houdt alleen Boeking over.
 *    Een leeg type telt mee, want dan is het type niet bekend.
 *
 * @param array<int,mixed> $rows
 * @return array<int,array<string,mixed>>
 */
function finance_baseline_cost_pick_rows(array $rows): array
{
    $tasks = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $taskNo = trim((string) ($row['Job_Task_No'] ?? ''));
        if ($taskNo === '') {
            continue;
        }

        $tasks[] = $row;
    }

    $pickExact = static function (string $taskNo) use ($tasks): array {
        $found = [];
        foreach ($tasks as $row) {
            if (trim((string) ($row['Job_Task_No'] ?? '')) === $taskNo) {
                $found[] = $row;
            }
        }

        return $found;
    };

    $projectTotal = $pickExact(FINANCE_PROJECT_TOTAL_TASK_NO);
    if ($projectTotal !== []) {
        return $projectTotal;
    }

    $legacyTotal = $pickExact(FINANCE_LEGACY_PROJECT_TOTAL_TASK_NO);
    if ($legacyTotal !== []) {
        return $legacyTotal;
    }

    $detail = [];
    foreach ($tasks as $row) {
        if (finance_task_type_is_total_or_heading((string) ($row['Job_Task_Type'] ?? ''))) {
            continue;
        }

        $detail[] = $row;
    }

    return $detail;
}

/**
 * Berekent Kosten VC uit ProjectTaken-rijen van één project.
 * Bedrag is LVS_Baseline_Total_Cost van de gekozen taken (zie finance_baseline_cost_pick_rows).
 *
 * @param array<int,mixed> $rows
 * @return array{amount:float,lines:array<int,array<string,mixed>>}
 */
function finance_baseline_cost_from_task_rows(array $rows): array
{
    $amount = 0.0;
    $lines = [];

    foreach (finance_baseline_cost_pick_rows($rows) as $row) {
        $lineAmount = finance_to_float($row[FINANCE_BASELINE_COST_FIELD] ?? 0.0);
        $amount = finance_add_amount($amount, $lineAmount);
        if ($lineAmount === 0.0) {
            continue;
        }

        $lines[] = [
            'Job_Task_No' => trim((string) ($row['Job_Task_No'] ?? '')),
            'Line_No' => 0,
            'Type' => (string) ($row['Job_Task_Type'] ?? ''),
            'No' => '',
            'Description' => (string) ($row['Description'] ?? ''),
            'Line_Amount' => $lineAmount,
            'Line_Type' => '',
        ];
    }

    return [
        'amount' => $amount,
        'lines' => $lines,
    ];
}

/**
 * Herkent een OData-schemafout (veld/entity bestaat niet in de webservice).
 * Zo'n fout geldt voor elke batch en elk project; opnieuw proberen per project
 * levert alleen tientallen identieke meldingen op (ticket #1170).
 */
function finance_is_odata_schema_error(string $message): bool
{
    return preg_match(
        "/Could not find a property named|Could not find a (?:segment|type|navigation property)|The query specified in the URI is not valid|Resource not found for the segment|Kan geen eigenschap met de naam/i",
        $message
    ) === 1;
}

/**
 * Haalt de per verzoek verschillende CorrelationId uit een BC-foutmelding,
 * zodat identieke fouten als één melding getoond worden.
 */
function finance_strip_odata_correlation_id(string $message): string
{
    $message = (string) preg_replace('/\s*CorrelationId:\s*[0-9a-f-]+\.?/i', '', $message);

    return trim((string) preg_replace('/\s+/', ' ', $message));
}

/**
 * Naam van het ontbrekende veld uit een OData-schemafout, of null.
 */
function finance_odata_missing_property(string $message): ?string
{
    if (preg_match("/Could not find a property named '([^']+)'/i", $message, $m) === 1) {
        return $m[1];
    }

    return null;
}
