<?php

/**
 * Eén herbouw per bedrijf en maand.
 * Een tweede tabblad wordt toeschouwer en laat WIP en oude snapshots staan.
 * Een herlaadbeurt volgt een verse lock en vervangt die niet.
 * Alleen Verversen neemt een verse herbouw over. De server hervat de eigen lock.
 * Een automatische herbouw start niet opnieuw als de v3 al gepubliceerd is.
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
$ym = '2026-09';
$ownerA = 'aaaaaaaaaaaaaaaa';
$ownerB = 'bbbbbbbbbbbbbbbb';
$dir = sys_get_temp_dir() . '/argus-rebuild-lock-' . bin2hex(random_bytes(4));
mkdir($dir);

$legacyName = 'koninklijke_van_twist_2026-09.json';
$v2Name = 'koninklijke_van_twist_2026-09.v2.json';
$unrelatedName = 'kvt_gas_2024-02.json';
file_put_contents($dir . '/' . $legacyName, '{"year_month":"2026-09","label":"legacy"}');
file_put_contents($dir . '/' . $v2Name, '{"year_month":"2026-09","cache_version":2,"label":"v2"}');
file_put_contents($dir . '/' . $unrelatedName, '{"year_month":"2024-02","label":"gas"}');
$original = [
    $legacyName => (string) file_get_contents($dir . '/' . $legacyName),
    $v2Name => (string) file_get_contents($dir . '/' . $v2Name),
    $unrelatedName => (string) file_get_contents($dir . '/' . $unrelatedName),
];

$invalid = argus_claim_running_month_rebuild($dir, $company, '2026', $ownerA, true, 1000, 10);
assert_same(false, $invalid['ok'], 'een ongeldige maand krijgt geen herbouw');
assert_same(false, is_file($dir . '/koninklijke_van_twist_wip_2026.lock'), 'een ongeldige maand maakt geen lock');

$first = argus_claim_running_month_rebuild($dir, $company, $ym, $ownerA, true, 1000, 10);
assert_same(true, $first['ok'], 'eerste claim slaagt');
assert_same('worker', $first['role'], 'eerste tabblad is de bouwer');
assert_same(false, $first['resumed'], 'een nieuwe herbouw is geen hervatting');
assert_same(false, $first['published'], 'zonder v3 is de herbouw niet klaar');
assert_same($ownerA, $first['owner'], 'de lock onthoudt het tabblad');
assert_same(1, $first['generation'], 'een nieuwe herbouw begint bij generatie 1');
assert_true(preg_match('/^[a-f0-9]{16}$/', (string) $first['refresh_id']) === 1, 'refresh-id is 16 hex');
$refreshId = (string) $first['refresh_id'];
$wipName = 'koninklijke_van_twist_wip_' . $ym . '_' . $refreshId . '.v3.json';
assert_true(is_file($dir . '/' . $wipName), 'de claim maakt een WIP-bestand');
$wipBytes = (string) file_get_contents($dir . '/' . $wipName);
$wipData = json_decode($wipBytes, true);
assert_same(3, is_array($wipData) ? ($wipData['cache_version'] ?? null) : null, 'nieuwe WIP hoort bij cacheversie 3');
file_put_contents($dir . '/' . $wipName, '{"cache_version":3,"progress":1,"refresh_id":"' . $refreshId . '"}');
$wipBytes = (string) file_get_contents($dir . '/' . $wipName);

$watcher = argus_claim_running_month_rebuild($dir, $company, $ym, $ownerB, true, 1005, 10);
assert_same('watcher', $watcher['role'], 'een tweede tabblad wordt toeschouwer');
assert_same(true, $watcher['resumed'], 'de toeschouwer ziet een lopende herbouw');
assert_same(false, $watcher['published'], 'een lopende herbouw is nog niet gepubliceerd');
assert_same($refreshId, $watcher['refresh_id'], 'de toeschouwer krijgt dezelfde refresh-id');
assert_same($wipBytes, (string) file_get_contents($dir . '/' . $wipName), 'een tweede claim wist de WIP niet');

$lockName = 'koninklijke_van_twist_wip_' . $ym . '.lock';
$lockBefore = (string) file_get_contents($dir . '/' . $lockName);
$emptyOwner = argus_claim_running_month_rebuild($dir, $company, $ym, '', true, 1006, 10);
assert_same('watcher', $emptyOwner['role'], 'een lege eigenaar pikt een verse lock niet in');
assert_same($refreshId, $emptyOwner['refresh_id'], 'een lege eigenaar hergebruikt de lopende id');
assert_same($lockBefore, (string) file_get_contents($dir . '/' . $lockName), 'een toeschouwer verlengt de lock niet');

assert_true(argus_month_rebuild_heartbeat($dir, $company, $ym, $refreshId, 1, 1004), 'een gestarte stap zet de lock op bezet');
$sameWhileBusy = argus_claim_running_month_rebuild($dir, $company, $ym, $ownerA, true, 1006, 10);
assert_same('watcher', $sameWhileBusy['role'], 'een herlaadbeurt wacht tot de lopende stap de WIP heeft weggeschreven');
assert_same(1, $sameWhileBusy['generation'], 'wachten verhoogt de generatie niet');
assert_same($refreshId, $sameWhileBusy['refresh_id'], 'de herlaadbeurt houdt dezelfde refresh-id');
assert_same($wipBytes, (string) file_get_contents($dir . '/' . $wipName), 'de herlaadbeurt wist de WIP niet');

assert_true(argus_month_rebuild_release($dir, $company, $ym, $refreshId, 1), 'een afgeronde stap geeft de lock vrij');
$lockAfterRelease = (string) file_get_contents($dir . '/' . $lockName);
$pageReload = argus_claim_running_month_rebuild($dir, $company, $ym, $ownerA, true, 1007, 10, true);
assert_same('watcher', $pageReload['role'], 'een herlaadbeurt volgt een verse herbouw');
assert_same(true, $pageReload['resumed'], 'een herlaadbeurt ziet de lopende herbouw');
assert_same(1, $pageReload['generation'], 'een herlaadbeurt verhoogt de generatie niet');
assert_same($refreshId, $pageReload['refresh_id'], 'een herlaadbeurt houdt dezelfde refresh-id');
assert_same($wipBytes, (string) file_get_contents($dir . '/' . $wipName), 'een herlaadbeurt wist de WIP niet');
assert_same($lockAfterRelease, (string) file_get_contents($dir . '/' . $lockName), 'een herlaadbeurt herschrijft de lock niet');
$same = argus_claim_running_month_rebuild($dir, $company, $ym, $ownerA, true, 1007, 10, false);
assert_same('worker', $same['role'], 'de server hervat de eigen vrijgegeven herbouw');
assert_same(true, $same['resumed'], 'de server hervat');
assert_same(2, $same['generation'], 'hervatten door dezelfde eigenaar krijgt een nieuwe generatie');
assert_same($refreshId, $same['refresh_id'], 'hervatten houdt dezelfde refresh-id');
assert_same($wipBytes, (string) file_get_contents($dir . '/' . $wipName), 'hervatten wist de WIP niet');
assert_same(false, argus_month_rebuild_release($dir, $company, $ym, $refreshId, 1), 'een oude generatie geeft de nieuwe lock niet vrij');
assert_same(
    false,
    argus_save_month_wip_if_current($dir, $company, $ym, $refreshId, 1, [
        'column_data_target' => ['project_details' => ['by_project' => []]],
        'planning_totals_by_job' => ['oud' => 1],
    ]),
    'een oude generatie schrijft de WIP niet terug'
);
assert_same($wipBytes, (string) file_get_contents($dir . '/' . $wipName), 'een oude OHW-schrijfactie laat de WIP bytegelijk');

assert_true(argus_month_rebuild_heartbeat($dir, $company, $ym, $refreshId, 2, 1008), 'een hartslag houdt de lopende herbouw vers');
assert_same(false, argus_month_rebuild_heartbeat($dir, $company, $ym, '0123456789abcdef', 2, 1010), 'een vreemde refresh-id verlengt de lock niet');
assert_same(false, argus_month_rebuild_heartbeat($dir, $company, $ym, $refreshId, 1, 1009), 'een oude generatie verlengt de lock niet');
$seesCursor = argus_claim_running_month_rebuild($dir, $company, $ym, $ownerB, false, 1009, 10, false);
assert_same('worker', $seesCursor['role'], 'Verversen neemt een verse herbouw over');
assert_same(3, $seesCursor['generation'], 'Verversen krijgt een nieuwe generatie');
assert_same($refreshId, $seesCursor['refresh_id'], 'Verversen houdt dezelfde refresh-id');
assert_same($ownerB, $seesCursor['owner'], 'Verversen zet de nieuwe eigenaar');
assert_same($wipBytes, (string) file_get_contents($dir . '/' . $wipName), 'Verversen laat de WIP bytegelijk');

$taken = argus_claim_running_month_rebuild($dir, $company, $ym, $ownerB, false, 1009 + 10, 10);
assert_same('worker', $taken['role'], 'een verlopen lock gaat naar het andere tabblad');
assert_same(true, $taken['resumed'], 'een verlopen lock hervat de herbouw');
assert_same($refreshId, $taken['refresh_id'], 'overnemen houdt dezelfde refresh-id');
assert_same($ownerB, $taken['owner'], 'overnemen zet de nieuwe eigenaar');
assert_same($wipBytes, (string) file_get_contents($dir . '/' . $wipName), 'overnemen wist de WIP niet');

$otherMonth = argus_claim_running_month_rebuild($dir, $company, '2026-08', $ownerA, false, 2000, 10);
assert_same('worker', $otherMonth['role'], 'een andere maand heeft een eigen herbouw');
assert_same(false, $otherMonth['refresh_id'] === $refreshId, 'een andere maand krijgt een andere refresh-id');

$v3Name = 'koninklijke_van_twist_2026-09.v3.json';
$v3Bytes = '{"year_month":"2026-09","cache_version":3,"label":"v3"}';
file_put_contents($dir . '/' . $v3Name, $v3Bytes);
$whileRunning = argus_claim_running_month_rebuild($dir, $company, $ym, $ownerA, true, 2010, 10);
assert_same(false, $whileRunning['published'], 'een v3 naast lopende WIP stopt de herbouw niet');
assert_same($refreshId, $whileRunning['refresh_id'], 'een v3 naast WIP houdt de lopende id');
assert_same($v3Bytes, (string) file_get_contents($dir . '/' . $v3Name), 'de claim overschrijft de v3 niet');

unlink($dir . '/' . $wipName);
$published = argus_claim_running_month_rebuild($dir, $company, $ym, $ownerB, true, 3000, 10);
assert_same(true, $published['published'], 'automatisch hervatten stopt als de v3 af is');
assert_same('', $published['refresh_id'], 'een afgeronde herbouw start geen nieuwe refresh-id');
assert_same(false, is_file($dir . '/koninklijke_van_twist_wip_' . $ym . '_' . $published['refresh_id'] . '.v3.json'), 'een afgeronde herbouw maakt geen WIP');
$wipLeft = array_values(array_filter(scandir($dir) ?: [], static function ($entry) use ($ym): bool {
    return is_string($entry) && str_contains($entry, '_wip_' . $ym . '_') && str_ends_with($entry, '.v3.json');
}));
assert_same([], $wipLeft, 'na publicatie blijft er geen nieuwe WIP achter');

$explicit = argus_claim_running_month_rebuild($dir, $company, $ym, $ownerA, false, 3010, 10);
assert_same('worker', $explicit['role'], 'Verversen zonder WIP start een nieuwe herbouw');
assert_same(false, $explicit['published'], 'Verversen gaat door als de v3 al bestaat');
assert_same(false, $explicit['resumed'], 'Verversen zonder WIP is geen hervatting');
assert_true($explicit['refresh_id'] !== '' && $explicit['refresh_id'] !== $refreshId, 'Verversen zonder WIP krijgt een nieuwe id');

assert_same(['2026-09'], argus_list_saved_months($dir, $company), 'lock en WIP staan niet in de maandlijst');
assert_same(['2024-02'], argus_list_saved_months($dir, 'KVT Gas'), 'een lock van KvT voegt geen maand toe bij KVT Gas');

$mergePath = $dir . '/merge-wip.json';
assert_true(argus_save_month_wip_merged($mergePath, [
    'column_data_target' => [
        'grootboekposten_ohw' => [
            'by_project' => ['p1' => ['no' => 'p1']],
            'rows' => [
                ['entry' => 'a', 'amount' => 1],
                ['entry' => 'b', 'amount' => 2],
            ],
        ],
        'project_details' => ['by_project' => ['p1' => ['name' => 'Een']]],
    ],
    'planning_totals_by_job' => ['p1' => 10],
    'planning_breakdown_by_job' => [
        'p1' => [
            ['no' => '800000', 'amount' => 5],
            ['no' => '800000', 'amount' => 4],
        ],
    ],
    'open_project_numbers' => ['p1', 'p3'],
]), 'de eerste WIP-schrijfactie slaagt');
assert_true(argus_save_month_wip_merged($mergePath, [
    'column_data_target' => [
        'grootboekposten_ohw' => [
            'by_project' => ['p1' => ['no' => 'p1'], 'p2' => ['no' => 'p2']],
            'rows' => [
                ['entry' => 'a', 'amount' => 3],
            ],
        ],
    ],
    'planning_totals_by_job' => [],
    'planning_breakdown_by_job' => [
        'p1' => [
            ['no' => '800000', 'amount' => 5],
        ],
    ],
    'open_project_numbers' => ['p1', 'p2'],
]), 'een late OHW-schrijfactie slaagt');
$merged = json_decode((string) file_get_contents($mergePath), true);
assert_true(is_array($merged), 'de samengevoegde WIP is JSON');
assert_same('Een', $merged['column_data_target']['project_details']['by_project']['p1']['name'] ?? null, 'een late schrijfactie laat projectdetails staan');
assert_same(10, $merged['planning_totals_by_job']['p1'] ?? null, 'een late schrijfactie laat voorcalculatie staan');
assert_same('p2', $merged['column_data_target']['grootboekposten_ohw']['by_project']['p2']['no'] ?? null, 'een late schrijfactie houdt haar eigen OHW');
assert_same(['p1', 'p3', 'p2'], $merged['open_project_numbers'] ?? null, 'projectnummers worden samengevoegd');
assert_same(
    [['entry' => 'a', 'amount' => 3]],
    $merged['column_data_target']['grootboekposten_ohw']['rows'] ?? null,
    'OHW-rijen worden vervangen en niet samengevoegd'
);
assert_same(
    [['no' => '800000', 'amount' => 5]],
    $merged['planning_breakdown_by_job']['p1'] ?? null,
    'voorcalculatieregels worden vervangen en niet samengevoegd'
);

foreach ($original as $name => $bytes) {
    assert_same($bytes, (string) file_get_contents($dir . '/' . $name), 'herbouw laat ' . $name . ' bytegelijk');
}

$stuck = sys_get_temp_dir() . '/argus-stuck-rebuild-' . bin2hex(random_bytes(4));
mkdir($stuck);
$stuckLegacy = $stuck . '/koninklijke_van_twist_2026-09.json';
$stuckV2 = $stuck . '/koninklijke_van_twist_2026-09.v2.json';
file_put_contents($stuckLegacy, '{"year_month":"2026-09","label":"legacy"}');
file_put_contents($stuckV2, '{"year_month":"2026-09","cache_version":2,"label":"v2"}');
$stuckLegacyBytes = (string) file_get_contents($stuckLegacy);
$stuckV2Bytes = (string) file_get_contents($stuckV2);
$stuckRefresh = 'abcdef0123456789';
$stuckLegacyWip = $stuck . '/koninklijke_van_twist_wip_2026-09.v3.json';
$stuckLiveWip = $stuck . '/koninklijke_van_twist_wip_2026-09_' . $stuckRefresh . '.v3.json';
$stuckLiveBytes = '{"cache_version":3,"refresh_id":"' . $stuckRefresh . '","progress":1}';
file_put_contents($stuckLegacyWip, '{"cache_version":3,"legacy":true}');
file_put_contents($stuckLiveWip, $stuckLiveBytes);
$stuckLock = $stuck . '/koninklijke_van_twist_wip_2026-09.lock';
$stuckNow = 1760954820;
file_put_contents($stuckLock, json_encode([
    'refresh_id' => $stuckRefresh,
    'owner' => $ownerA,
    'updated_at' => 1760950000000,
    'busy' => true,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$stuckTaken = argus_claim_running_month_rebuild($stuck, $company, $ym, $ownerB, true, $stuckNow, 1200);
assert_same(true, $stuckTaken['ok'], 'een lock in milliseconden wordt overgenomen');
assert_same('worker', $stuckTaken['role'], 'een lock die niet verloopt wordt bouwer in plaats van toeschouwer');
assert_same($stuckRefresh, $stuckTaken['refresh_id'], 'overnemen houdt de bestaande refresh-id');
assert_same(2, $stuckTaken['generation'], 'een lock zonder generatie gaat naar generatie 2');
assert_same(false, is_file($stuckLegacyWip), 'legacy-WIP zonder refresh-id wordt opgeruimd');
assert_same($stuckLiveBytes, (string) file_get_contents($stuckLiveWip), 'de geldige WIP blijft bytegelijk');
assert_same($stuckLegacyBytes, (string) file_get_contents($stuckLegacy), 'de legacy-snapshot blijft staan');
assert_same($stuckV2Bytes, (string) file_get_contents($stuckV2), 'de v2-snapshot blijft staan');
assert_same(false, is_file($stuck . '/koninklijke_van_twist_2026-09.v3.json'), 'overnemen publiceert nog geen v3');

file_put_contents($stuckLock, json_encode([
    'refresh_id' => $stuckRefresh,
    'owner' => $ownerA,
    'updated_at' => $stuckNow,
    'busy' => true,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$stuckLegacyLock = argus_claim_running_month_rebuild($stuck, $company, $ym, $ownerA, true, $stuckNow + 30, 1200);
assert_same('worker', $stuckLegacyLock['role'], 'een verse lock zonder generatie wordt overgenomen');
assert_same($stuckRefresh, $stuckLegacyLock['refresh_id'], 'een lock zonder generatie houdt dezelfde WIP');
assert_same(2, $stuckLegacyLock['generation'], 'een lock zonder generatie begint opnieuw bij generatie 2');

file_put_contents($stuckLock, json_encode([
    'refresh_id' => $stuckRefresh,
    'owner' => $ownerA,
    'updated_at' => $stuckNow + 86400,
    'busy' => true,
    'generation' => 4,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$stuckFuture = argus_claim_running_month_rebuild($stuck, $company, $ym, $ownerB, true, $stuckNow, 1200);
assert_same('worker', $stuckFuture['role'], 'een lock in de toekomst wordt overgenomen');
assert_same(5, $stuckFuture['generation'], 'een toekomstige lock verhoogt de generatie');
assert_same($stuckRefresh, $stuckFuture['refresh_id'], 'een toekomstige lock houdt dezelfde WIP');

$stuckIdle = argus_claim_running_month_rebuild($stuck, $company, $ym, $ownerB, false, $stuckNow + 5, 1200);
assert_same('worker', $stuckIdle['role'], 'dezelfde tab pakt een claim zonder gestarte stap meteen op');
assert_same(6, $stuckIdle['generation'], 'een idle lock krijgt een nieuwe generatie');
assert_same($stuckRefresh, $stuckIdle['refresh_id'], 'een idle lock houdt dezelfde refresh-id');
assert_same($stuckLiveBytes, (string) file_get_contents($stuckLiveWip), 'een idle overname wist de WIP niet');
$stuckIdleGeneration = (int) $stuckIdle['generation'];
assert_true(
    argus_month_rebuild_heartbeat($stuck, $company, $ym, $stuckRefresh, $stuckIdleGeneration, $stuckNow + 6),
    'een echte stap zet de lock op bezet'
);
$stuckWaiting = argus_claim_running_month_rebuild($stuck, $company, $ym, $ownerA, true, $stuckNow + 7, 1200);
assert_same('watcher', $stuckWaiting['role'], 'tijdens een verse stap blijft een ander tabblad toeschouwer');
assert_same($stuckIdleGeneration, $stuckWaiting['generation'], 'wachten op een verse stap verhoogt de generatie niet');
assert_same($stuckLiveBytes, (string) file_get_contents($stuckLiveWip), 'wachten laat de WIP bytegelijk');

foreach (scandir($stuck) ?: [] as $entry) {
    if ($entry === '.' || $entry === '..') {
        continue;
    }
    $path = $stuck . '/' . $entry;
    if (is_file($path)) {
        unlink($path);
    }
}
rmdir($stuck);

foreach (scandir($dir) ?: [] as $entry) {
    if ($entry === '.' || $entry === '..') {
        continue;
    }
    $path = $dir . '/' . $entry;
    if (is_file($path)) {
        unlink($path);
    }
}
rmdir($dir);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} assertion(s) failed\n");
    exit(1);
}

echo "OK month rebuild lock\n";
