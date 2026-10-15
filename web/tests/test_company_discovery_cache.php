<?php

/**
 * De bedrijfslijst komt uit een bestand zolang die vers is,
 * zodat maand-detail niet op Mímir wacht tijdens een voorcalculatie.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../auth_helper.php';

$failures = 0;

function check(bool $ok, string $message): void
{
    global $failures;
    if (!$ok) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$GLOBALS['argus_company_discovery_cache_path'] = sys_get_temp_dir() . '/argus_company_discovery_' . bin2hex(random_bytes(4)) . '.json';
$path = auth_company_discovery_cache_path();

$result = [
    'companies' => ['Koninklijke van Twist'],
    'map' => ['Koninklijke van Twist' => 'Production'],
    'by_environment' => ['Production' => ['Koninklijke van Twist']],
    'errors' => [],
    'active_environments' => ['Production'],
    'primary_environment' => 'Production',
];

check(auth_company_discovery_cache_store($result) === true, 'een gevulde lijst wordt bewaard');
check(auth_company_discovery_cache_store(['companies' => []]) === false, 'een lege lijst wordt niet bewaard');

$GLOBALS['demeter_company_environment_map'] = [];
$loaded = auth_company_discovery_cache_load(3600);
check(is_array($loaded), 'een verse cache wordt gelezen');
check(($loaded['companies'][0] ?? '') === 'Koninklijke van Twist', 'bedrijfsnaam blijft staan');
check(($GLOBALS['demeter_company_environment_map']['Koninklijke van Twist'] ?? '') === 'Production', 'de map wordt gezet zonder live fetch');
check(auth_company_discovery_cache_load(0) === null, 'ttl 0 slaat de cache over');

touch($path, time() - 120);
check(auth_company_discovery_cache_load(30) === null, 'een te oude cache telt niet');

@unlink($path);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} assertion(s) failed\n");
    exit(1);
}

echo "OK company discovery cache\n";
