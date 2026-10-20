<?php

/**
 * De maandlijst herkent v3, v2 en legacy `.json`.
 * Elke maand komt één keer voor, met voorrang v3 > v2 > legacy.
 * Herbouw schrijft alleen `.v3.json`. Mislukt die, dan blijft de oude snapshot
 * staan, inclusief de melding. Oude bestanden worden niet overschreven.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../finance_calculations.php';

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

$company = 'Koninklijke van Twist';
$boundsV2 = [
    'posting_before' => '2026-10-01',
    'reverse_after' => '2026-09-30',
    'blank_reverse' => '0001-01-01',
];
$boundsLegacy = [
    'posting_before' => '1999-01-01',
    'reverse_after' => '1998-12-31',
    'blank_reverse' => '0001-01-01',
];
$boundsAugust = [
    'posting_before' => '2026-09-01',
    'reverse_after' => '2026-08-31',
    'blank_reverse' => '0001-01-01',
];

$legacySeptember = [
    'year_month' => '2026-09',
    'label' => 'legacy',
    'total_revenue' => 1,
    'ohw_debug' => ['snapshot_bounds' => $boundsLegacy],
];
$v2September = [
    'year_month' => '2026-09',
    'cache_version' => 2,
    'label' => 'v2',
    'total_revenue' => 10,
    'ohw_debug' => ['snapshot_bounds' => $boundsV2],
];
$legacyAugust = [
    'year_month' => '2026-08',
    'label' => 'augustus',
    'total_revenue' => 8,
    'ohw_debug' => ['snapshot_bounds' => $boundsAugust],
];
$v3October = [
    'year_month' => '2026-10',
    'cache_version' => 3,
    'label' => 'v3',
    'total_revenue' => 99,
    'ohw_debug' => [
        'snapshot_bounds' => [
            'posting_before' => '2026-11-01',
            'reverse_after' => '2026-10-31',
            'blank_reverse' => '0001-01-01',
        ],
    ],
];
$legacyOctober = [
    'year_month' => '2026-10',
    'label' => 'oktober-legacy',
    'total_revenue' => 3,
];

$dir = sys_get_temp_dir() . '/argus-maanden-' . bin2hex(random_bytes(4));
mkdir($dir);
mkdir($dir . '/koninklijke_van_twist_2026-04.json');

$raw = [];
$files = [
    'koninklijke_van_twist_2026-09.json' => $legacySeptember,
    'koninklijke_van_twist_2026-09.v2.json' => $v2September,
    'koninklijke_van_twist_2026-08.json' => $legacyAugust,
    'koninklijke_van_twist_2026-10.v2.json' => $legacyOctober,
    'koninklijke_van_twist_2026-10.v3.json' => $v3October,
    'hunter_van_twist_2026-01.json' => ['year_month' => '2026-01', 'label' => 'hunter'],
    'kvt_germany_2026-01.json' => ['year_month' => '2026-01', 'label' => 'germany'],
    'kvt_gas_2024-02.json' => ['year_month' => '2024-02', 'label' => 'gas', 'total_revenue' => 4],
];
foreach ($files as $name => $payload) {
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    assert_true(is_string($json), 'fixture encode ' . $name);
    $raw[$name] = $json;
    file_put_contents($dir . '/' . $name, $json);
}
file_put_contents($dir . '/koninklijke_van_twist_wip_2026-07.v2.json', '{"year_month":"2026-07"}');
file_put_contents($dir . '/koninklijke_van_twist_wip_2026-07_abcdef0123456789.v3.json', '{"year_month":"2026-07"}');
file_put_contents($dir . '/koninklijke_van_twist_2026-06.json.tmp.abcd', '{"year_month":"2026-06"}');
file_put_contents($dir . '/koninklijke_van_twist_2026-11.v3.json', '{');

$listed = argus_list_saved_months($dir, $company);
assert_same(['2026-11', '2026-10', '2026-09', '2026-08'], $listed, 'elke maand één keer, nieuwste eerst; ook een onleesbaar v3-bestand telt mee');
assert_same(
    ['2026-10', '2026-09', '2026-08'],
    argus_list_saved_months_from_names($company, array_keys($files)),
    'dubbele 2026-09 valt samen'
);

$bestSeptember = argus_load_best_month_snapshot($dir, $company, '2026-09');
assert_same(2, $bestSeptember['version'], '2026-09 kiest v2 boven legacy');
assert_same('v2', $bestSeptember['data']['label'] ?? '', 'de v2-inhoud wint van het oudere json-bestand');

$bestOctober = argus_load_best_month_snapshot($dir, $company, '2026-10');
assert_same(3, $bestOctober['version'], '2026-10 kiest v3 boven v2');
assert_same('v3', $bestOctober['data']['label'] ?? '', 'v3-inhoud wint');

assert_same([], argus_list_saved_months($dir . '-ontbreekt', 'KVT Gas'), 'een ontbrekende map is een lege lijst');
$gasOnlyOthers = argus_list_saved_months($dir, 'KVT Gas');
assert_same(['2024-02'], $gasOnlyOthers, 'KVT Gas ziet alleen kvt_gas_*.json, niet KVT Germany of Koninklijke');
assert_same('kvt_gas', argus_month_company_slug('KVT Gas'), 'spatie in KVT Gas wordt een underscore');

$emptyGas = sys_get_temp_dir() . '/argus-gas-' . bin2hex(random_bytes(4));
mkdir($emptyGas);
file_put_contents($emptyGas . '/koninklijke_van_twist_2026-09.json', $raw['koninklijke_van_twist_2026-09.json']);
file_put_contents($emptyGas . '/kvt_germany_2026-01.json', '{}');
assert_same([], argus_list_saved_months($emptyGas, 'KVT Gas'), 'KVT Gas zonder eigen snapshot blijft leeg');

$calls = 0;
$pending = argus_open_month_snapshot($dir, $company, '2026-09', null);
assert_same(true, $pending['needs_rebuild'], 'zonder v3 mag de pagina herbouwen');
assert_same(false, $pending['rebuilt'], 'zonder callback wordt er niet geschreven');
assert_same('v2', $pending['data']['label'] ?? '', 'in afwachting blijft de v2-snapshot zichtbaar');
assert_same('2026-09', $pending['period'], 'peildatum komt uit de oude snapshot');
assert_same(false, is_file($dir . '/koninklijke_van_twist_2026-09.v3.json'), 'wachten maakt nog geen v3-bestand');
assert_true(str_contains((string) $pending['notice'], 'opnieuw opgebouwd'), 'de gebruiker ziet dat herbouw loopt');

$failed = argus_open_month_snapshot(
    $dir,
    $company,
    '2026-09',
    static function (string $openedCompany, string $period, array $legacy) use (&$calls, $company, $boundsV2): array {
        $calls++;
        assert_same($company, $openedCompany, 'herbouw blijft bij hetzelfde bedrijf');
        assert_same('2026-09', $period, 'herbouw gebruikt de peildatum uit v2');
        assert_same($boundsV2, $legacy['ohw_debug']['snapshot_bounds'] ?? null, 'peildatum-bounds komen uit v2, niet uit legacy');
        assert_same('v2', $legacy['label'] ?? '', 'de bron van de herbouw is v2');
        throw new RuntimeException('Business Central reageert niet');
    }
);
assert_same(1, $calls, 'mislukte herbouw is wel geprobeerd');
assert_same(false, $failed['rebuilt'], 'mislukte herbouw publiceert niets');
assert_same(false, $failed['needs_rebuild'], 'na een poging niet nog een keer automatisch');
assert_same('v2', $failed['data']['label'] ?? '', 'fallback toont de oude snapshot');
assert_same(10, $failed['data']['total_revenue'] ?? null, 'de v2-totalen blijven beschikbaar');
assert_true(
    str_contains((string) $failed['notice'], 'U ziet de eerder opgeslagen snapshot'),
    'de melding zegt dat de oude snapshot zichtbaar blijft'
);
assert_true(
    str_contains((string) $failed['notice'], 'Business Central reageert niet'),
    'de oorzaak staat in de melding'
);
assert_same(false, is_file($dir . '/koninklijke_van_twist_2026-09.v3.json'), 'mislukking laat geen v3-bestand achter');
foreach ($raw as $name => $json) {
    assert_same($json, file_get_contents($dir . '/' . $name), 'mislukte herbouw laat ' . $name . ' ongemoeid');
}

$rebuiltLabel = '';
$opened = argus_open_month_snapshot(
    $dir,
    $company,
    '2026-09',
    static function (string $openedCompany, string $period, array $legacy) use (&$rebuiltLabel): array {
        $rebuiltLabel = (string) ($legacy['label'] ?? '');
        return [
            'label' => 'nieuw',
            'total_revenue' => 685000,
            'project_summaries' => [
                ['Job_No' => 'PRJ2602195', 'Expected_Revenue' => 685000],
            ],
        ];
    }
);
assert_same('v2', $rebuiltLabel, 'geslaagde herbouw leest de v2-snapshot als bron');
assert_same(true, $opened['rebuilt'], 'herbouw publiceert v3');
assert_same(3, $opened['version'], 'geopende maand is daarna versie 3');
assert_same('nieuw', $opened['data']['label'] ?? '', 'de nieuwe Opbrengst VC-snapshot wordt getoond');
assert_same('2026-09', $opened['data']['year_month'] ?? '', 'de nieuwe snapshot houdt de oude periode');
assert_same($boundsV2, $opened['data']['ohw_debug']['snapshot_bounds'] ?? null, 'de oude peildatum blijft op de v3-snapshot');
assert_same(3, $opened['data']['cache_version'] ?? null, 'cacheversie is 3');
assert_true(is_file($dir . '/koninklijke_van_twist_2026-09.v3.json'), 'alleen een v3-bestand is toegevoegd');
foreach ($raw as $name => $json) {
    assert_same($json, file_get_contents($dir . '/' . $name), 'geslaagde herbouw overschrijft ' . $name . ' niet');
}
$stored = json_decode((string) file_get_contents($dir . '/koninklijke_van_twist_2026-09.v3.json'), true);
assert_same(685000, $stored['total_revenue'] ?? null, 'v3-bestand bevat de nieuwe totalen');
assert_same($boundsV2, $stored['ohw_debug']['snapshot_bounds'] ?? null, 'v3-bestand bewaart de peildatum');

$augustCalls = 0;
$august = argus_open_month_snapshot(
    $dir,
    $company,
    '2026-08',
    static function () use (&$augustCalls) {
        $augustCalls++;
        return null;
    }
);
assert_same(1, $augustCalls, 'een maand met alleen legacy probeert herbouw');
assert_same('augustus', $august['data']['label'] ?? '', 'lege herbouw valt terug op legacy');
assert_same(false, is_file($dir . '/koninklijke_van_twist_2026-08.v3.json'), 'lege herbouw schrijft geen v3');
assert_same($raw['koninklijke_van_twist_2026-08.json'], file_get_contents($dir . '/koninklijke_van_twist_2026-08.json'), 'legacy augustus blijft bytegelijk');

$skipped = 0;
$current = argus_open_month_snapshot(
    $dir,
    $company,
    '2026-10',
    static function () use (&$skipped): array {
        $skipped++;
        return ['label' => 'mag-niet'];
    }
);
assert_same(0, $skipped, 'een bestaande v3 wordt niet opnieuw opgebouwd');
assert_same('v3', $current['data']['label'] ?? '', 'openen toont de v3-snapshot');
assert_same($raw['koninklijke_van_twist_2026-10.v2.json'], file_get_contents($dir . '/koninklijke_van_twist_2026-10.v2.json'), 'v2 naast v3 blijft staan');

$brokenBefore = file_get_contents($dir . '/koninklijke_van_twist_2026-11.v3.json');
$broken = argus_open_month_snapshot(
    $dir,
    $company,
    '2026-11',
    static function (): array {
        return ['label' => 'overschrijf-niet'];
    }
);
assert_same(false, $broken['rebuilt'], 'onleesbaar v3 wordt niet overschreven');
assert_same(null, $broken['data'], 'zonder oudere snapshot is er niets om te tonen');
assert_true(str_contains((string) $broken['notice'], 'niet overschreven'), 'de melding noemt het onleesbare v3-bestand');
assert_same($brokenBefore, file_get_contents($dir . '/koninklijke_van_twist_2026-11.v3.json'), 'het kapotte v3-bestand blijft bytegelijk');

$names = scandir($dir);
$v3Names = [];
foreach ($names as $name) {
    if (is_string($name) && str_ends_with($name, '.v3.json') && !str_contains($name, 'wip_') && !str_contains($name, '.tmp.')) {
        $v3Names[] = $name;
    }
}
sort($v3Names);
assert_same(
    ['koninklijke_van_twist_2026-09.v3.json', 'koninklijke_van_twist_2026-10.v3.json', 'koninklijke_van_twist_2026-11.v3.json'],
    $v3Names,
    'herbouw voegt alleen het ontbrekende v3-bestand toe'
);

foreach (scandir($dir) as $entry) {
    if ($entry === '.' || $entry === '..') {
        continue;
    }
    $path = $dir . '/' . $entry;
    if (is_dir($path)) {
        rmdir($path);
        continue;
    }
    unlink($path);
}
rmdir($dir);
foreach (scandir($emptyGas) as $entry) {
    if ($entry === '.' || $entry === '..') {
        continue;
    }
    unlink($emptyGas . '/' . $entry);
}
rmdir($emptyGas);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} assertion(s) failed\n");
    exit(1);
}

echo "OK month snapshot fallback\n";
