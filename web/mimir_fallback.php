<?php

/**
 * Directe Business Central-fallback als Mímir faalt.
 *
 * web/odata.php houdt alleen een hook; dit bestand is de goedgekeurde plek
 * voor circuit breaker, timeouts en de pre-Mímir OData-route.
 */

/**
 * Functies
 */

/**
 * @return array{open: bool, error: ?Throwable}
 */
function &odata_mimir_circuit_state(): array
{
    static $state = [
        'open' => false,
        'error' => null,
    ];
    return $state;
}

function odata_mimir_circuit_open(): bool
{
    $state = &odata_mimir_circuit_state();
    return $state['open'] === true;
}

function odata_mimir_last_error(): ?Throwable
{
    $state = &odata_mimir_circuit_state();
    return $state['error'] instanceof Throwable ? $state['error'] : null;
}

function odata_mimir_trip(Throwable $exception): void
{
    $state = &odata_mimir_circuit_state();
    if ($state['open'] === true) {
        return;
    }
    $state['open'] = true;
    $state['error'] = $exception;
}

function odata_mimir_circuit_reset(): void
{
    $state = &odata_mimir_circuit_state();
    $state['open'] = false;
    $state['error'] = null;
}

function odata_mimir_connect_timeout_seconds(): int
{
    return 10;
}

function odata_mimir_timeout_seconds_for_sapi(string $sapi): int
{
    return strtolower($sapi) === 'cli' ? 600 : 90;
}

function odata_mimir_timeout_seconds(): int
{
    return odata_mimir_timeout_seconds_for_sapi(PHP_SAPI);
}

function odata_mimir_fail(Exception $exception): void
{
    odata_mimir_trip($exception);
    throw $exception;
}

function odata_auth_is_usable($auth): bool
{
    if (!is_array($auth)) {
        return false;
    }
    $user = trim((string) ($auth['user'] ?? ''));
    if ($user === '') {
        return false;
    }
    $mode = (string) ($auth['mode'] ?? '');
    if ($mode !== 'basic' && $mode !== 'ntlm') {
        return false;
    }
    return array_key_exists('pass', $auth);
}

function odata_bc_auth_php_path(): string
{
    $override = $GLOBALS['ARGUS_AUTH_PHP_PATH'] ?? null;
    if (is_string($override) && $override !== '') {
        return $override;
    }
    return __DIR__ . '/auth.php';
}

function odata_bc_ensure_auth_loaded(): void
{
    if (!empty($GLOBALS['ARGUS_BC_AUTH_LOAD_TRIED'])) {
        return;
    }
    if (odata_bc_credentials_configured_from_globals()) {
        $GLOBALS['ARGUS_BC_AUTH_LOAD_TRIED'] = true;
        return;
    }
    $GLOBALS['ARGUS_BC_AUTH_LOAD_TRIED'] = true;
    $path = odata_bc_auth_php_path();
    if (!is_file($path)) {
        return;
    }
    $loaded = (static function (string $__path): array {
        require $__path;
        unset($__path);
        return get_defined_vars();
    })($path);
    foreach (['baseUrl', 'auth', 'auth_list', 'environment', 'mimirApi', 'mimirBase'] as $name) {
        if (array_key_exists($name, $loaded)) {
            $GLOBALS[$name] = $loaded[$name];
        }
    }
}

function odata_bc_base_url(): ?string
{
    global $baseUrl;
    if (!isset($baseUrl) || !is_string($baseUrl)) {
        return null;
    }
    $base = trim($baseUrl);
    if ($base === '' || stripos($base, 'mimir.invalid') !== false) {
        return null;
    }
    return $base;
}

function odata_bc_environment(): ?string
{
    global $environment, $auth_list;

    $candidates = [];
    if (isset($environment) && is_string($environment)) {
        $candidates[] = $environment;
    } elseif (isset($environment) && is_array($environment)) {
        foreach ($environment as $item) {
            if (is_string($item) || is_int($item)) {
                $candidates[] = (string) $item;
            }
        }
    }

    foreach ($candidates as $candidate) {
        $env = trim($candidate);
        if ($env === '' || strcasecmp($env, 'mimir') === 0) {
            continue;
        }
        return $env;
    }

    if (isset($auth_list) && is_array($auth_list)) {
        foreach ($auth_list as $key => $entry) {
            if (!odata_auth_is_usable($entry)) {
                continue;
            }
            $env = trim((string) $key);
            if ($env === '' || strcasecmp($env, 'mimir') === 0) {
                continue;
            }
            return $env;
        }
    }

    return null;
}

function odata_bc_mapped_environment(string $company): ?string
{
    $company = trim($company);
    $map = $GLOBALS['demeter_company_environment_map'] ?? null;
    if ($company === '' || !is_array($map)) {
        return null;
    }

    $pairs = [];
    if (isset($map[$company])) {
        $pairs[] = $map[$company];
    }
    foreach ($map as $name => $env) {
        if (strcasecmp((string) $name, $company) === 0) {
            $pairs[] = $env;
        }
    }
    foreach ($pairs as $env) {
        $envName = trim((string) $env);
        if ($envName !== '' && strcasecmp($envName, 'mimir') !== 0) {
            return $envName;
        }
    }
    return null;
}

function odata_bc_environment_for_company(string $company): ?string
{
    $mapped = odata_bc_mapped_environment($company);
    if ($mapped !== null) {
        return $mapped;
    }
    return odata_bc_environment();
}

function odata_bc_environment_from_odata_url(string $url): ?string
{
    $parts = parse_url($url);
    $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
    if (preg_match('#^/([^/]+)/#', $path, $match) === 1) {
        $segment = trim(rawurldecode($match[1]));
        if ($segment !== '' && strcasecmp($segment, 'mimir') !== 0) {
            return $segment;
        }
    }
    if (function_exists('odata_mimir_parse_entity_url')) {
        $parsed = odata_mimir_parse_entity_url($url);
        if (is_array($parsed) && isset($parsed['company'])) {
            return odata_bc_environment_for_company((string) $parsed['company']);
        }
    }
    return odata_bc_environment();
}

/**
 * @return list<string>
 */
function odata_bc_environment_list(?string $environmentFilter = null): array
{
    $filter = $environmentFilter !== null ? trim($environmentFilter) : '';
    if ($filter !== '' && strcasecmp($filter, 'mimir') !== 0) {
        return [$filter];
    }

    $envs = [];
    global $auth_list;
    if (isset($auth_list) && is_array($auth_list)) {
        foreach (array_keys($auth_list) as $key) {
            $env = trim((string) $key);
            if ($env === '' || strcasecmp($env, 'mimir') === 0) {
                continue;
            }
            $envs[] = $env;
        }
    }
    if ($envs === []) {
        $env = odata_bc_environment();
        if ($env !== null) {
            $envs[] = $env;
        }
    }
    return $envs;
}

function odata_bc_auth_for_environment(?string $env): ?array
{
    if ($env === null) {
        return null;
    }
    $env = trim($env);
    if ($env === '' || strcasecmp($env, 'mimir') === 0) {
        return null;
    }
    global $auth_list;
    if (!isset($auth_list) || !is_array($auth_list)) {
        return null;
    }
    if (isset($auth_list[$env]) && odata_auth_is_usable($auth_list[$env])) {
        return $auth_list[$env];
    }
    foreach ($auth_list as $key => $entry) {
        if (strcasecmp((string) $key, $env) === 0 && odata_auth_is_usable($entry)) {
            return $entry;
        }
    }
    return null;
}

function odata_bc_auth_for_fallback(array $passed): ?array
{
    if (odata_auth_is_usable($passed)) {
        return $passed;
    }
    global $auth, $auth_list;
    if (isset($auth) && odata_auth_is_usable($auth)) {
        return $auth;
    }
    $env = odata_bc_environment();
    $fromEnv = odata_bc_auth_for_environment($env);
    if ($fromEnv !== null) {
        return $fromEnv;
    }
    if (isset($auth_list) && is_array($auth_list)) {
        foreach ($auth_list as $entry) {
            if (odata_auth_is_usable($entry)) {
                return $entry;
            }
        }
    }
    return null;
}

function odata_bc_auth_for_company_env(?string $env, array $passed): ?array
{
    $fromEnv = odata_bc_auth_for_environment($env);
    if ($fromEnv !== null) {
        return $fromEnv;
    }
    return odata_bc_auth_for_fallback($passed);
}

function odata_bc_credentials_configured_from_globals(): bool
{
    if (odata_bc_base_url() === null || odata_bc_environment() === null) {
        return false;
    }
    return odata_bc_auth_for_fallback([]) !== null;
}

function odata_bc_credentials_configured(): bool
{
    odata_bc_ensure_auth_loaded();
    return odata_bc_credentials_configured_from_globals();
}

function odata_mimir_log_fallback(Throwable $exception): void
{
    $message = $exception->getMessage();
    $redactions = [];
    $apiKey = function_exists('odata_mimir_api_key') ? odata_mimir_api_key() : '';
    if ($apiKey !== '') {
        $redactions[] = $apiKey;
    }
    global $auth, $auth_list;
    if (isset($auth) && is_array($auth) && isset($auth['pass']) && is_string($auth['pass']) && $auth['pass'] !== '') {
        $redactions[] = $auth['pass'];
    }
    if (isset($auth_list) && is_array($auth_list)) {
        foreach ($auth_list as $entry) {
            if (is_array($entry) && isset($entry['pass']) && is_string($entry['pass']) && $entry['pass'] !== '') {
                $redactions[] = $entry['pass'];
            }
        }
    }
    foreach ($redactions as $secret) {
        $message = str_replace($secret, '[redacted]', $message);
    }
    $sanitized = preg_replace('/(Bearer\s+)\S+/i', '$1[redacted]', $message);
    if (is_string($sanitized)) {
        $message = $sanitized;
    }
    error_log('[Argus] Mímir failed, falling back to direct OData: ' . $message);
}

/**
 * @template T
 * @param callable(): T $viaMimir
 * @param callable(): T $viaDirect
 * @return T
 */
function odata_mimir_or_direct(callable $viaMimir, callable $viaDirect)
{
    if (odata_mimir_circuit_open()) {
        $original = odata_mimir_last_error();
        if (!odata_bc_credentials_configured()) {
            if ($original instanceof Throwable) {
                throw $original;
            }
            throw new Exception('Mímir eerder mislukt.');
        }
        odata_mimir_log_fallback($original instanceof Throwable ? $original : new Exception('Mímir overgeslagen na eerdere fout.'));
        return $viaDirect();
    }

    try {
        return $viaMimir();
    } catch (Throwable $exception) {
        odata_mimir_trip($exception);
        if (!odata_bc_credentials_configured()) {
            throw $exception;
        }
        odata_mimir_log_fallback($exception);
        return $viaDirect();
    }
}

function odata_bc_url_from_odata_url(string $url): string
{
    odata_bc_ensure_auth_loaded();
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return $url;
    }
    $host = strtolower((string) ($parts['host'] ?? ''));
    if ($host !== 'mimir.invalid') {
        return $url;
    }
    $base = odata_bc_base_url();
    $env = odata_bc_environment_from_odata_url($url);
    if ($base === null || $env === null) {
        return $url;
    }
    $path = (string) ($parts['path'] ?? '');
    if (preg_match('#^/[^/]+(/.+)$#', $path, $match) !== 1) {
        return $url;
    }
    $rebuilt = rtrim($base, '/') . '/' . rawurlencode($env) . $match[1];
    if (isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '') {
        $rebuilt .= '?' . $parts['query'];
    }
    return $rebuilt;
}

function odata_bc_fetch_direct(string $url, array $auth, $ttlSeconds): array
{
    $GLOBALS['ARGUS_ODATA_DIRECT'] = true;
    try {
        return odata_get_all($url, $auth, $ttlSeconds);
    } finally {
        $GLOBALS['ARGUS_ODATA_DIRECT'] = false;
    }
}

/**
 * @return list<array<string, mixed>>
 */
function odata_direct_companies_as_rows(?string $environmentFilter = null): array
{
    odata_bc_ensure_auth_loaded();
    $envs = odata_bc_environment_list($environmentFilter);
    $base = odata_bc_base_url();
    if ($base === null || $envs === []) {
        $previous = odata_mimir_last_error();
        if ($previous instanceof Throwable) {
            throw $previous;
        }
        throw new Exception('Mímir mislukt.');
    }

    $out = [];
    foreach ($envs as $env) {
        $auth = odata_bc_auth_for_company_env($env, []);
        if ($auth === null) {
            continue;
        }
        $url = rtrim($base, '/') . '/' . rawurlencode($env) . '/ODataV4/Company';
        $rows = odata_bc_fetch_direct($url, $auth, 300);
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['Name'] ?? $row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $out[] = ['Name' => $name, 'environment' => $env];
        }
    }
    return $out;
}

/**
 * @return list<array<string, mixed>>
 */
function odata_mimir_companies_guard(?string $environment): array
{
    return odata_mimir_or_direct(
        static function () use ($environment): array {
            $GLOBALS['ARGUS_MIMIR_IN_IMPL'] = true;
            try {
                return odata_mimir_companies_as_rows($environment);
            } finally {
                $GLOBALS['ARGUS_MIMIR_IN_IMPL'] = false;
            }
        },
        static function () use ($environment): array {
            return odata_direct_companies_as_rows($environment);
        }
    );
}

/**
 * @param array<string, mixed> $odataQuery
 * @return list<array<string, mixed>>
 */
function odata_direct_query(string $company, string $table, array $odataQuery, int $ttlSeconds): array
{
    odata_bc_ensure_auth_loaded();
    $env = odata_bc_environment_for_company($company);
    $base = odata_bc_base_url();
    $auth = $env !== null ? odata_bc_auth_for_company_env($env, []) : null;
    if ($env === null || $base === null || $auth === null) {
        $previous = odata_mimir_last_error();
        if ($previous instanceof Throwable) {
            throw $previous;
        }
        throw new Exception('Mímir mislukt.');
    }

    $params = [];
    foreach (['$select', '$filter', '$orderby', '$expand', '$top', '$skip', 'select', 'filter'] as $key) {
        if (!array_key_exists($key, $odataQuery)) {
            continue;
        }
        $value = trim((string) $odataQuery[$key]);
        if ($value === '') {
            continue;
        }
        $odataKey = ($key === 'select' || $key === 'filter') ? ('$' . $key) : $key;
        $params[$odataKey] = $value;
    }

    $safeCompany = str_replace("'", "''", trim($company));
    $url = rtrim($base, '/') . '/' . rawurlencode($env) . "/ODataV4/Company('" . rawurlencode($safeCompany) . "')/" . rawurlencode($table);
    if ($params !== []) {
        $url .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    return odata_bc_fetch_direct($url, $auth, $ttlSeconds);
}

/**
 * @param array<string, mixed> $odataQuery
 * @return list<array<string, mixed>>
 */
function odata_mimir_query_guard(string $company, string $table, array $odataQuery, int $ttlSeconds): array
{
    return odata_mimir_or_direct(
        static function () use ($company, $table, $odataQuery, $ttlSeconds): array {
            $GLOBALS['ARGUS_MIMIR_IN_IMPL'] = true;
            try {
                return odata_mimir_query($company, $table, $odataQuery, $ttlSeconds);
            } finally {
                $GLOBALS['ARGUS_MIMIR_IN_IMPL'] = false;
            }
        },
        static function () use ($company, $table, $odataQuery, $ttlSeconds): array {
            return odata_direct_query($company, $table, $odataQuery, $ttlSeconds);
        }
    );
}

/**
 * @return list<array<string, mixed>>
 */
function odata_mimir_fetch_all_guard(string $url, int $ttlSeconds): array
{
    return odata_mimir_or_direct(
        static function () use ($url, $ttlSeconds): array {
            $GLOBALS['ARGUS_MIMIR_IN_IMPL'] = true;
            try {
                return odata_mimir_fetch_all($url, $ttlSeconds);
            } finally {
                $GLOBALS['ARGUS_MIMIR_IN_IMPL'] = false;
            }
        },
        static function () use ($url, $ttlSeconds): array {
            $env = odata_bc_environment_from_odata_url($url);
            $auth = odata_bc_auth_for_company_env($env, []);
            if ($auth === null) {
                $previous = odata_mimir_last_error();
                if ($previous instanceof Throwable) {
                    throw $previous;
                }
                throw new Exception('Mímir mislukt.');
            }
            return odata_bc_fetch_direct(odata_bc_url_from_odata_url($url), $auth, $ttlSeconds);
        }
    );
}

/**
 * @return list<array<string, mixed>>
 */
function odata_get_all_guard(string $url, array $auth, $ttlSeconds): array
{
    $ttlSeconds = max(0, (int) $ttlSeconds);
    return odata_mimir_or_direct(
        static function () use ($url, $ttlSeconds): array {
            $GLOBALS['ARGUS_MIMIR_IN_IMPL'] = true;
            try {
                return odata_mimir_fetch_all($url, $ttlSeconds === 0 ? 3600 : $ttlSeconds);
            } finally {
                $GLOBALS['ARGUS_MIMIR_IN_IMPL'] = false;
            }
        },
        static function () use ($url, $auth, $ttlSeconds): array {
            $env = odata_bc_environment_from_odata_url($url);
            $directAuth = odata_bc_auth_for_company_env($env, $auth) ?? $auth;
            return odata_bc_fetch_direct(odata_bc_url_from_odata_url($url), $directAuth, $ttlSeconds);
        }
    );
}
