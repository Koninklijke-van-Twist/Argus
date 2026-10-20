<?php

/**
 * Kiest maanden die zonder open browser herbouwd moeten worden en claimt
 * daarvoor dezelfde lock en generatie als de pagina.
 * Een verse lock van een ander tabblad blijft staan: deze run slaat die maand over.
 */

require_once __DIR__ . '/finance_calculations.php';

if (!defined('ARGUS_NIGHTLY_MAX_AGE')) {
    define('ARGUS_NIGHTLY_MAX_AGE', 14400);
}
if (!defined('ARGUS_HOURLY_MAX_AGE')) {
    define('ARGUS_HOURLY_MAX_AGE', 1800);
}

/**
 * max_age naar Mímir. Nightly is 4 uur, hourly is 30 minuten.
 */
function argus_rebuild_max_age(string $schedule): int
{
    if ($schedule === 'nightly') {
        return (int) ARGUS_NIGHTLY_MAX_AGE;
    }
    if ($schedule === 'hourly') {
        return (int) ARGUS_HOURLY_MAX_AGE;
    }

    throw new InvalidArgumentException('Onbekend herbouwschema: ' . $schedule);
}

/**
 * Vaste eigenaar van de server-herbouw. Een volgende nightly of hourly hervat
 * dezelfde lock zodra die niet bezet is. Een browsertabblad heeft een andere eigenaar.
 */
function argus_server_month_rebuild_owner(): string
{
    return substr(hash('sha256', 'argus-server-month-rebuild'), 0, 16);
}

/**
 * Maanden met een open refresh-WIP. Legacy-WIP zonder id telt niet mee.
 *
 * @return list<string>
 */
function argus_company_open_wip_months(string $dir, string $company): array
{
    $slug = argus_month_company_slug($company);
    if ($slug === '' || !is_dir($dir)) {
        return [];
    }

    $prefix = $slug . '_wip_';
    $suffix = argus_month_cache_suffix();
    $entries = @scandir($dir);
    if (!is_array($entries)) {
        return [];
    }

    $months = [];
    foreach ($entries as $entry) {
        if (!is_string($entry) || !str_starts_with($entry, $prefix) || !str_ends_with($entry, $suffix)) {
            continue;
        }

        $path = $dir . DIRECTORY_SEPARATOR . $entry;
        if (!is_file($path)) {
            continue;
        }

        $rest = substr($entry, strlen($prefix), -strlen($suffix));
        if (preg_match('/^(\d{4}-\d{2})_[a-f0-9]{16}$/', $rest, $matches) !== 1) {
            continue;
        }

        $months[$matches[1]] = true;
    }

    $list = array_keys($months);
    rsort($list);

    return $list;
}

/**
 * Maanden zonder .v3.json, of met een open Verversen-WIP.
 * Een leesbare of onleesbare v3 zonder WIP blijft staan.
 * Elk bedrijfsslug komt één keer voor.
 *
 * @param array<int,mixed> $companies
 * @return list<array{company:string,year_month:string,reason:string}>
 */
function argus_month_rebuild_candidates(string $dir, array $companies): array
{
    $jobs = [];
    $seenSlugs = [];
    foreach ($companies as $company) {
        if (!is_string($company)) {
            continue;
        }
        $company = trim($company);
        $slug = argus_month_company_slug($company);
        if ($company === '' || $slug === '' || isset($seenSlugs[$slug])) {
            continue;
        }
        $seenSlugs[$slug] = true;

        $months = [];
        foreach (argus_list_saved_months($dir, $company) as $yearMonth) {
            $months[$yearMonth] = true;
        }
        foreach (argus_company_open_wip_months($dir, $company) as $yearMonth) {
            $months[$yearMonth] = true;
        }

        foreach (array_keys($months) as $yearMonth) {
            $openWip = argus_active_month_wip_refresh_id($dir, $company, $yearMonth) !== '';
            $v3Name = argus_month_snapshot_basename($company, $yearMonth, 3);
            $hasV3 = $v3Name !== null && is_file($dir . DIRECTORY_SEPARATOR . $v3Name);
            if ($openWip) {
                $jobs[] = [
                    'company' => $company,
                    'year_month' => $yearMonth,
                    'reason' => 'open_wip',
                ];
                continue;
            }
            if (!$hasV3) {
                $jobs[] = [
                    'company' => $company,
                    'year_month' => $yearMonth,
                    'reason' => 'missing_v3',
                ];
            }
        }
    }

    usort($jobs, static function (array $left, array $right): int {
        $company = strnatcasecmp($left['company'], $right['company']);
        if ($company !== 0) {
            return $company;
        }

        return strcmp($right['year_month'], $left['year_month']);
    });

    return $jobs;
}

/**
 * Voert de browserstappen uit: doelkolommen (OHW, daarna projectdetails in chunks),
 * daarna voorcalculatie in chunks, daarna publicatie.
 * Een fout stopt vóór de publicatie.
 *
 * @param callable $columnStep function(string,string,string,int,string,int,int):array
 * @param callable $planningStep function(string,string,int,string,int,int):array
 * @param callable $buildStep function(string,string,string,int,int):array
 * @param array<int,mixed> $columnKeys
 * @return array<string,mixed>
 */
function argus_run_month_rebuild_steps(
    string $company,
    string $yearMonth,
    string $refreshId,
    int $generation,
    int $maxAge,
    callable $columnStep,
    callable $planningStep,
    callable $buildStep,
    array $columnKeys = ['grootboekposten_ohw', 'project_details'],
    int $maxChunks = 10000
): array {
    foreach ($columnKeys as $columnKey) {
        $columnKey = (string) $columnKey;
        $chunkIndex = 0;
        while (true) {
            if ($chunkIndex >= $maxChunks) {
                return ['ok' => false, 'error' => 'Te veel kolomstappen voor ' . $columnKey . '.'];
            }

            $result = $columnStep($company, $yearMonth, $columnKey, $chunkIndex, $refreshId, $generation, $maxAge);
            if (!is_array($result) || empty($result['ok'])) {
                return is_array($result) ? $result : ['ok' => false, 'error' => 'Kolomstap gaf geen antwoord.'];
            }
            if ($columnKey !== 'project_details') {
                break;
            }

            $total = max(1, (int) ($result['total_chunks'] ?? 1));
            if (!empty($result['chunks_complete']) || ($chunkIndex + 1) >= $total) {
                break;
            }
            $chunkIndex++;
        }
    }

    $chunkIndex = 0;
    while (true) {
        if ($chunkIndex >= $maxChunks) {
            return ['ok' => false, 'error' => 'Te veel voorcalculatiestappen.'];
        }

        $result = $planningStep($company, $yearMonth, $chunkIndex, $refreshId, $generation, $maxAge);
        if (!is_array($result) || empty($result['ok'])) {
            return is_array($result) ? $result : ['ok' => false, 'error' => 'Voorcalculatie gaf geen antwoord.'];
        }

        $total = max(1, (int) ($result['total_chunks'] ?? 1));
        if (!empty($result['chunks_complete']) || ($chunkIndex + 1) >= $total) {
            break;
        }
        $chunkIndex++;
    }

    $built = $buildStep($company, $yearMonth, $refreshId, $generation, $maxAge);

    return is_array($built) ? $built : ['ok' => false, 'error' => 'Publicatie gaf geen antwoord.'];
}

/**
 * Claimt elke kandidaat met de server-eigenaar.
 * Een toeschouwer (browser of bezette stap) krijgt geen tweede herbouw.
 * Na de stappen gaat de lock weer vrij, met dezelfde generatie.
 *
 * @param array<int,mixed> $companies
 * @param callable $steps function(string,string,string,int,int):array
 * @return array{ok:bool,schedule:string,max_age:int,failed:int,results:list<array<string,mixed>>}
 */
function argus_run_scheduled_month_rebuilds(
    string $dir,
    array $companies,
    string $schedule,
    callable $steps,
    ?int $now = null
): array {
    $maxAge = argus_rebuild_max_age($schedule);
    $owner = argus_server_month_rebuild_owner();
    $results = [];
    $failed = 0;

    foreach (argus_month_rebuild_candidates($dir, $companies) as $job) {
        $company = $job['company'];
        $yearMonth = $job['year_month'];
        $claim = argus_claim_running_month_rebuild($dir, $company, $yearMonth, $owner, true, $now);
        $base = [
            'company' => $company,
            'year_month' => $yearMonth,
            'reason' => $job['reason'],
            'role' => (string) ($claim['role'] ?? ''),
            'refresh_id' => (string) ($claim['refresh_id'] ?? ''),
            'generation' => (int) ($claim['generation'] ?? 0),
            'published' => !empty($claim['published']),
        ];
        if (empty($claim['ok'])) {
            $failed++;
            $results[] = $base + [
                'status' => 'error',
                'error' => (string) ($claim['error'] ?? 'Herbouw kon niet worden geclaimd.'),
            ];
            continue;
        }

        $refreshId = (string) ($claim['refresh_id'] ?? '');
        $generation = (int) ($claim['generation'] ?? 0);
        $isWorker = ($claim['role'] ?? '') === 'worker' && $refreshId !== '' && $generation >= 1 && empty($claim['published']);
        if (!$isWorker) {
            $results[] = $base + [
                'status' => 'skipped',
                'error' => '',
            ];
            continue;
        }

        // De claim zelf is nog niet bezet. Zonder deze hartslag pakt een tweede
        // nightly of hourly, met dezelfde server-eigenaar, de lock over terwijl
        // de eerste run nog in de login zit, en die run faalt daarna op de generatie.
        if (!argus_month_rebuild_heartbeat($dir, $company, $yearMonth, $refreshId, $generation, $now)) {
            $failed++;
            $results[] = $base + [
                'status' => 'error',
                'error' => 'Herbouw-lock was niet meer van deze run.',
            ];
            continue;
        }

        try {
            $stepResult = $steps($company, $yearMonth, $refreshId, $generation, $maxAge);
        } catch (Throwable $error) {
            $stepResult = ['ok' => false, 'error' => $error->getMessage()];
        } finally {
            argus_month_rebuild_release($dir, $company, $yearMonth, $refreshId, $generation);
        }

        $ok = is_array($stepResult) && !empty($stepResult['ok']);
        if (!$ok) {
            $failed++;
        }
        $results[] = $base + [
            'status' => $ok ? 'rebuilt' : 'error',
            'error' => $ok ? '' : (string) (is_array($stepResult) ? ($stepResult['error'] ?? 'Herbouw mislukt.') : 'Herbouw mislukt.'),
        ];
    }

    return [
        'ok' => $failed === 0,
        'schedule' => $schedule,
        'max_age' => $maxAge,
        'failed' => $failed,
        'results' => $results,
    ];
}
