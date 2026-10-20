<?php

/**
 * Server-herbouw pakt maanden zonder v3 en open WIP.
 * Een verse browserlock blijft van dat tabblad.
 * Publicatie schrijft alleen .v3.json; v2 en legacy blijven byte-identiek.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../month_rebuild_schedule.php';

$failures = 0;

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    global $failures;
    if ($expected !== $actual) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n  expected " . var_export($expected, true) . "\n  actual   " . var_export($actual, true) . "\n");
    }
}

function assert_true(bool $ok, string $message): void
{
    assert_same(true, $ok, $message);
}

function temp_cache(): string
{
    $dir = sys_get_temp_dir() . '/argus-rebuild-schedule-' . bin2hex(random_bytes(4));
    mkdir($dir);

    return $dir;
}

assert_same(14400, argus_rebuild_max_age('nightly'), 'nightly max_age is 4 uur');
assert_same(1800, argus_rebuild_max_age('hourly'), 'hourly max_age is 30 minuten');
assert_same(14400, ARGUS_NIGHTLY_MAX_AGE, 'nightly-constante blijft 14400');
assert_same(1800, ARGUS_HOURLY_MAX_AGE, 'hourly-constante is 1800');
$owner = argus_server_month_rebuild_owner();
assert_same($owner, argus_server_month_rebuild_owner(), 'de server-eigenaar is stabiel');
assert_true(preg_match('/^[a-f0-9]{16}$/', $owner) === 1, 'de server-eigenaar is 16 hex');

$threw = false;
try {
    argus_rebuild_max_age('weekly');
} catch (InvalidArgumentException $error) {
    $threw = true;
}
assert_true($threw, 'een onbekend schema heeft geen max_age');

$company = 'Koninklijke van Twist';
$gas = 'KVT Gas';
$dir = temp_cache();
$legacyBytes = '{"year_month":"2026-08","label":"legacy"}';
$v2Bytes = '{"year_month":"2026-09","cache_version":2,"label":"v2"}';
$augustBytes = '{"year_month":"2026-07","cache_version":3,"label":"augustus"}';
$gasBytes = '{"year_month":"2024-02","label":"gas"}';
file_put_contents($dir . '/koninklijke_van_twist_2026-08.json', $legacyBytes);
file_put_contents($dir . '/koninklijke_van_twist_2026-09.v2.json', $v2Bytes);
file_put_contents($dir . '/koninklijke_van_twist_2026-07.v3.json', $augustBytes);
file_put_contents($dir . '/kvt_gas_2024-02.json', $gasBytes);
file_put_contents($dir . '/koninklijke_van_twist_2026-11.v3.json', '{');
file_put_contents($dir . '/koninklijke_van_twist_wip_2026-09.lock', '{"not":"a snapshot"}');

$jobs = argus_month_rebuild_candidates($dir, [$company, $gas, $company]);
$jobKey = static function (array $job): string {
    return $job['company'] . '|' . $job['year_month'] . '|' . $job['reason'];
};
assert_same([
    'Koninklijke van Twist|2026-09|missing_v3',
    'Koninklijke van Twist|2026-08|missing_v3',
    'KVT Gas|2024-02|missing_v3',
], array_map($jobKey, $jobs), 'kandidaten zijn v2/legacy zonder v3, niet een kapotte of gave v3 en niet het lockbestand');

$publishDir = temp_cache();
file_put_contents($publishDir . '/koninklijke_van_twist_2026-09.v2.json', $v2Bytes);
file_put_contents($publishDir . '/koninklijke_van_twist_2026-08.json', $legacyBytes);
$calls = [];
$during = null;
$report = argus_run_scheduled_month_rebuilds(
    $publishDir,
    [$company],
    'nightly',
    static function (string $company, string $yearMonth, string $refreshId, int $generation, int $maxAge) use (&$calls, &$during, $publishDir): array {
        $calls[] = [$yearMonth, $generation, $maxAge];
        $during = argus_claim_running_month_rebuild($publishDir, $company, $yearMonth, 'bbbbbbbbbbbbbbbb', true, 5000);
        if ($yearMonth === '2026-09') {
            $v3 = argus_month_snapshot_basename($company, $yearMonth, 3);
            argus_publish_snapshot_file($publishDir . '/' . $v3, [
                'cache_version' => 3,
                'year_month' => $yearMonth,
                'label' => 'nieuw',
            ]);
        }

        return ['ok' => true];
    },
    5000
);
assert_same(true, $report['ok'], 'een geslaagde nightly is ok');
assert_same(14400, $report['max_age'], 'het verslag noemt de nightly max_age');
assert_same(['2026-09', '2026-08'], array_column($calls, 0), 'nieuwste maand eerst');
assert_same(1, $calls[0][1], 'een nieuwe server-herbouw begint bij generatie 1');
assert_same(14400, $calls[0][2], 'de stap krijgt max_age 4 uur');
assert_same('watcher', is_array($during) ? ($during['role'] ?? '') : '', 'tijdens de serverstap blijft een browser toeschouwer');
assert_same(1, is_array($during) ? ($during['generation'] ?? 0) : 0, 'de browser krijgt geen nieuwe generatie');
assert_same($v2Bytes, (string) file_get_contents($publishDir . '/koninklijke_van_twist_2026-09.v2.json'), 'publicatie laat het v2-bestand staan');
assert_same($legacyBytes, (string) file_get_contents($publishDir . '/koninklijke_van_twist_2026-08.json'), 'publicatie laat het legacy-bestand staan');
assert_true(is_file($publishDir . '/koninklijke_van_twist_2026-09.v3.json'), 'de herbouw schrijft een v3 naast de v2');

$browserDir = temp_cache();
file_put_contents($browserDir . '/koninklijke_van_twist_2026-08.json', $legacyBytes);
$browser = 'aaaaaaaaaaaaaaaa';
$held = argus_claim_running_month_rebuild($browserDir, $company, '2026-08', $browser, true, 8000);
assert_same('worker', $held['role'], 'het browsertabblad start de maand zonder v3');
assert_true(argus_month_rebuild_release($browserDir, $company, '2026-08', (string) $held['refresh_id'], (int) $held['generation']), 'het tabblad maakt de stap vrij');
$beforeLock = (string) file_get_contents($browserDir . '/koninklijke_van_twist_wip_2026-08.lock');
$skippedCalls = 0;
$skipped = argus_run_scheduled_month_rebuilds(
    $browserDir,
    [$company],
    'hourly',
    static function () use (&$skippedCalls): array {
        $skippedCalls++;

        return ['ok' => true];
    },
    8100
);
assert_same(0, $skippedCalls, 'een verse browserlock krijgt geen serverstappen');
assert_same(1800, $skipped['max_age'], 'hourly geeft 30 minuten door');
assert_same('skipped', $skipped['results'][0]['status'] ?? '', 'de server slaat de browsermaand over');
assert_same('watcher', $skipped['results'][0]['role'] ?? '', 'de server is toeschouwer');
assert_same($beforeLock, (string) file_get_contents($browserDir . '/koninklijke_van_twist_wip_2026-08.lock'), 'de server herschrijft de browserlock niet');
assert_same($legacyBytes, (string) file_get_contents($browserDir . '/koninklijke_van_twist_2026-08.json'), 'overslaan laat de legacy-snapshot staan');

$resumeDir = temp_cache();
file_put_contents($resumeDir . '/koninklijke_van_twist_2026-09.v2.json', $v2Bytes);
$firstGen = null;
argus_run_scheduled_month_rebuilds(
    $resumeDir,
    [$company],
    'nightly',
    static function (string $company, string $yearMonth, string $refreshId, int $generation) use (&$firstGen): array {
        $firstGen = $generation;

        return ['ok' => true];
    },
    1000
);
$resumeCalls = [];
$resume = argus_run_scheduled_month_rebuilds(
    $resumeDir,
    [$company],
    'nightly',
    static function (string $company, string $yearMonth, string $refreshId, int $generation, int $maxAge) use (&$resumeCalls): array {
        $resumeCalls[] = [$generation, $maxAge];

        return ['ok' => false, 'error' => 'voorcalculatie stopt'];
    },
    1100
);
assert_same(1, $firstGen, 'de eerste server-run gebruikt generatie 1');
assert_same([2, 14400], $resumeCalls[0] ?? null, 'dezelfde server hervat de open WIP met generatie 2');
assert_same(false, $resume['ok'], 'een mislukte maand telt als fout');
assert_same('open_wip', $resume['results'][0]['reason'] ?? '', 'de hervatting volgt de open WIP');
assert_same($v2Bytes, (string) file_get_contents($resumeDir . '/koninklijke_van_twist_2026-09.v2.json'), 'een mislukte hervatting laat v2 staan');

$wipWithV3 = temp_cache();
file_put_contents($wipWithV3 . '/koninklijke_van_twist_2026-10.v3.json', '{"cache_version":3,"year_month":"2026-10"}');
$seed = argus_claim_running_month_rebuild($wipWithV3, $company, '2026-10', $owner, false, 2000);
assert_same('worker', $seed['role'], 'een verversing naast een v3 maakt WIP');
argus_month_rebuild_release($wipWithV3, $company, '2026-10', (string) $seed['refresh_id'], (int) $seed['generation']);
$openKeys = array_map($jobKey, argus_month_rebuild_candidates($wipWithV3, [$company]));
assert_same(['Koninklijke van Twist|2026-10|open_wip'], $openKeys, 'een v3 met open WIP blijft een kandidaat');

$sequence = [];
$steps = argus_run_month_rebuild_steps(
    $company,
    '2026-09',
    '0123456789abcdef',
    4,
    1800,
    static function (string $company, string $yearMonth, string $columnKey, int $chunkIndex, string $refreshId, int $generation, int $maxAge) use (&$sequence): array {
        $sequence[] = ['column', $columnKey, $chunkIndex, $generation, $maxAge];
        if ($columnKey === 'project_details' && $chunkIndex === 0) {
            return ['ok' => true, 'total_chunks' => 2, 'chunks_complete' => false];
        }

        return ['ok' => true, 'total_chunks' => 1, 'chunks_complete' => true];
    },
    static function (string $company, string $yearMonth, int $chunkIndex, string $refreshId, int $generation, int $maxAge) use (&$sequence): array {
        $sequence[] = ['planning', $chunkIndex, $generation, $maxAge];
        if ($chunkIndex === 0) {
            return ['ok' => true, 'total_chunks' => 2, 'chunks_complete' => false];
        }

        return ['ok' => true, 'total_chunks' => 2, 'chunks_complete' => true];
    },
    static function (string $company, string $yearMonth, string $refreshId, int $generation, int $maxAge) use (&$sequence): array {
        $sequence[] = ['build', $generation, $maxAge];

        return ['ok' => true];
    }
);
assert_same(true, $steps['ok'], 'de stukgewijze herbouw slaagt');
assert_same([
    ['column', 'grootboekposten_ohw', 0, 4, 1800],
    ['column', 'project_details', 0, 4, 1800],
    ['column', 'project_details', 1, 4, 1800],
    ['planning', 0, 4, 1800],
    ['planning', 1, 4, 1800],
    ['build', 4, 1800],
], $sequence, 'de volgorde is OHW, projectdetails, voorcalculatie, publicatie');

$stopped = [];
$stoppedResult = argus_run_month_rebuild_steps(
    $company,
    '2026-09',
    '0123456789abcdef',
    4,
    14400,
    static function () use (&$stopped): array {
        $stopped[] = 'column';

        return ['ok' => true, 'chunks_complete' => true];
    },
    static function () use (&$stopped): array {
        $stopped[] = 'planning';

        return ['ok' => false, 'error' => 'Voorcalculatie is nog niet compleet. De vorige snapshot blijft beschikbaar.'];
    },
    static function () use (&$stopped): array {
        $stopped[] = 'build';

        return ['ok' => true];
    },
    ['grootboekposten_ohw']
);
assert_same(['column', 'planning'], $stopped, 'een fout in de voorcalculatie publiceert niet');
assert_same(false, $stoppedResult['ok'], 'de herbouw stopt bij de voorcalculatie');

$nightly = (string) file_get_contents(__DIR__ . '/../nightly.php');
$hourly = (string) file_get_contents(__DIR__ . '/../hourly.php');
$cli = (string) file_get_contents(__DIR__ . '/../month_rebuild_cli.php');
$maanden = (string) file_get_contents(__DIR__ . '/../maanden.php');
assert_true(str_contains($nightly, "argus_month_rebuild_cli_main('nightly')"), 'nightly start de herbouw');
assert_true(str_contains($hourly, "argus_month_rebuild_cli_main('hourly')"), 'hourly start dezelfde herbouw');
assert_true(str_contains($nightly, 'ARGUS_MAANDEN_LIBRARY'), 'nightly laadt de maandlogica zonder pagina');
assert_true(str_contains($cli, 'maanden_run_rebuild_pipeline'), 'de CLI gebruikt dezelfde pipeline');
$begin = strpos($maanden, "=== 'begin_month_refresh'");
$delete = strpos($maanden, "=== 'delete_month'");
assert_true($begin !== false && $delete !== false && $delete > $begin, 'begin_month_refresh staat in maanden.php');
$beginBlock = substr($maanden, (int) $begin, (int) $delete - (int) $begin);
assert_true(!str_contains($beginBlock, 'batch_wip_delete_all'), 'Verversen wist geen WIP bij de start');
assert_true(!str_contains($beginBlock, 'unlink'), 'Verversen verwijdert de oude snapshot niet');
assert_same('.v3.json', argus_month_cache_suffix(), 'een nieuwe snapshot blijft .v3.json');
assert_true(str_contains((string) file_get_contents(__DIR__ . '/../month_rebuild_steps.php'), 'collectProjectForecastForProjects'), 'voorcalculatie blijft de bron van Opbrengst t/m');

if ($failures > 0) {
    fwrite(STDERR, $failures . " failed\n");
    exit(1);
}

echo "OK month rebuild schedule\n";
