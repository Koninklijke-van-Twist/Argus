<?php

/**
 * Voorcalculatie gaat in vaste stappen over de volledige projectlijst.
 * Een chunk opnieuw ophalen vervangt de totalen en telt ze niet op.
 * Publiceren mag pas als elk project een totaal heeft.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../bc_fetch/helpers.php';

$failures = 0;

function check(bool $ok, string $message): void
{
    global $failures;
    if (!$ok) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

check(VOORCALCULATIE_PROJECT_CHUNK_SIZE === 40, 'een stap blijft klein genoeg voor de proxy');

$numbers = [];
for ($i = 1; $i <= 3730; $i++) {
    $numbers[] = 'P' . $i;
}

$first = bc_fetch_voorcalculatie_chunk_at($numbers, 0);
check($first['total_chunks'] === 94, '3730 projecten zijn 94 stappen, kreeg ' . $first['total_chunks']);
check($first['chunks_complete'] === false, 'de eerste stap is niet de laatste');
check(count($first['chunk_projects']) === 40, 'eerste stap heeft 40 projecten');
check(($first['chunk_projects'][0] ?? '') === 'P1', 'eerste stap begint bij project 1, kreeg ' . ($first['chunk_projects'][0] ?? ''));

$again = bc_fetch_voorcalculatie_chunk_at($numbers, 0);
check($again['chunk_projects'] === $first['chunk_projects'], 'dezelfde index blijft dezelfde projecten');

$last = bc_fetch_voorcalculatie_chunk_at($numbers, 93);
check($last['chunks_complete'] === true, 'stap 94 rondt de reeks af');
check(count($last['chunk_projects']) === 10, 'laatste stap heeft de resterende 10 projecten, kreeg ' . count($last['chunk_projects']));
check(($last['chunk_projects'][0] ?? '') === 'P3721', 'laatste stap begint bij P3721, kreeg ' . ($last['chunk_projects'][0] ?? ''));

$withProject = $numbers;
$withProject[] = '162659';
$index = array_search('162659', $withProject, true);
check($index === 3730, '162659 komt achter de bestaande lijst te staan als het wordt toegevoegd');
$chunkForProject = intdiv((int) $index, VOORCALCULATIE_PROJECT_CHUNK_SIZE);
$slice = bc_fetch_voorcalculatie_chunk_at($withProject, $chunkForProject);
check(in_array('162659', $slice['chunk_projects'], true), '162659 valt in een voorcalculatie-stap als het in de maandlijst staat');

$merged = bc_fetch_merge_forecast_chunk(
    [],
    [],
    ['1' => ['expected_costs' => 10.0]],
    ['1' => ['expected_costs_lines' => [['No' => 'A']]]],
    ['1', '2']
);
check(($merged['totals']['1']['expected_costs'] ?? null) === 10.0, 'eerste chunk schrijft het totaal');
check(!isset($merged['totals']['2']), 'een project zonder antwoord krijgt geen verzonnen totaal');

$retried = bc_fetch_merge_forecast_chunk(
    $merged['totals'],
    $merged['breakdown'],
    ['1' => ['expected_costs' => 12.0]],
    ['1' => ['expected_costs_lines' => [['No' => 'B']]]],
    ['1']
);
check(($retried['totals']['1']['expected_costs'] ?? null) === 12.0, 'opnieuw ophalen vervangt 10 door 12');
check(count($retried['breakdown']['1']['expected_costs_lines'] ?? []) === 1, 'regels worden vervangen, niet verdubbeld');

$withSecond = bc_fetch_merge_forecast_chunk(
    $retried['totals'],
    $retried['breakdown'],
    ['2' => ['expected_costs' => 3.0]],
    ['2' => ['expected_costs_lines' => []]],
    ['2']
);
check(($withSecond['totals']['1']['expected_costs'] ?? null) === 12.0, 'een latere stap laat eerdere projecten staan');
check(($withSecond['totals']['2']['expected_costs'] ?? null) === 3.0, 'tweede stap voegt zijn eigen project toe');

check(bc_fetch_forecast_covers_projects(['1', '2'], $withSecond['totals']) === true, 'beide projecten zijn compleet');
check(bc_fetch_forecast_covers_projects(['1', '2', '3'], $withSecond['totals']) === false, 'een ontbrekend project blokkeert publicatie');
check(bc_fetch_forecast_covers_projects([], []) === true, 'een maand zonder projecten is compleet');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} assertion(s) failed\n");
    exit(1);
}

echo "OK voorcalculatie chunks\n";
