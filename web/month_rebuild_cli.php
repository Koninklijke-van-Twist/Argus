<?php

/**
 * Gedeelde ingang voor nightly.php en hourly.php.
 * CLI mag zonder browsersessie. Via HTTP telt alleen header X-Argus-Secret.
 */

function argus_month_rebuild_cli_allowed(): bool
{
    if (PHP_SAPI === 'cli') {
        return true;
    }
    if (!function_exists('odata_direct_action_skips_login') || !function_exists('odata_presented_runner_secret')) {
        return false;
    }

    $presented = odata_presented_runner_secret();
    $authVars = function_exists('odata_read_auth_file_vars') ? odata_read_auth_file_vars() : [];
    $serverSecret = function_exists('odata_configured_runner_secret')
        ? odata_configured_runner_secret($authVars)
        : '';

    return odata_direct_action_skips_login(PHP_SAPI, $presented, $serverSecret);
}

/**
 * @return list<string>
 */
function argus_month_rebuild_cli_companies(): array
{
    $companies = [];
    try {
        require_once __DIR__ . '/auth_helper.php';
        $discoveryResult = auth_discover_companies_across_active_environments(AUTH_COMPANY_DISCOVERY_TTL_SECONDS);
        $companies = is_array($discoveryResult['companies'] ?? null) ? $discoveryResult['companies'] : [];
    } catch (Throwable $error) {
        $companies = [];
    }

    if ($companies === []) {
        $companies = [
            'Koninklijke van Twist',
            'Hunter van Twist',
            'KVT Gas',
        ];
    }

    $clean = [];
    foreach ($companies as $company) {
        if (!is_string($company)) {
            continue;
        }
        $company = trim($company);
        if ($company !== '') {
            $clean[] = $company;
        }
    }

    return $clean;
}

function argus_month_rebuild_cli_main(string $schedule): void
{
    if (!argus_month_rebuild_cli_allowed()) {
        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        }
        echo json_encode(['ok' => false, 'error' => 'Geen toegang'], JSON_UNESCAPED_UNICODE) . "\n";
        exit(1);
    }

    @set_time_limit(0);
    @ini_set('max_execution_time', '0');

    $report = argus_run_scheduled_month_rebuilds(
        maanden_cache_dir(),
        argus_month_rebuild_cli_companies(),
        $schedule,
        static function (string $company, string $yearMonth, string $refreshId, int $generation, int $maxAge): array {
            return maanden_run_rebuild_pipeline($company, $yearMonth, $refreshId, $generation, $maxAge);
        }
    );

    if (function_exists('argus_json_discard_preamble')) {
        argus_json_discard_preamble();
    }
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }
    echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    exit(empty($report['ok']) ? 1 : 0);
}
