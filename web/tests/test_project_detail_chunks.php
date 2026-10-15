<?php

/**
 * Projectdetail-chunks blijven op de volledige projectlijst staan.
 * Een krimpende "nog te doen"-lijst sloeg delen 6-8 over.
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

$numbers = [];
for ($i = 1; $i <= 1200; $i++) {
    $numbers[] = 'P' . $i;
}
$numbers[] = 'p600';
$numbers[] = '  ';

$plan = bc_fetch_project_detail_chunk_at($numbers, 5, 150);
check($plan['total_chunks'] === 8, '1200 projecten zijn 8 chunks, kreeg ' . $plan['total_chunks']);
check($plan['chunks_complete'] === false, 'chunk 6 van 8 is nog niet de laatste');
check(count($plan['chunk_projects']) === 150, 'chunk houdt 150 projecten');
check(($plan['chunk_projects'][0] ?? '') === 'P751', 'chunk 6 begint bij project 751, kreeg ' . ($plan['chunk_projects'][0] ?? ''));
check(($plan['chunk_projects'][149] ?? '') === 'P900', 'chunk 6 eindigt bij project 900, kreeg ' . ($plan['chunk_projects'][149] ?? ''));

$again = bc_fetch_project_detail_chunk_at(array_slice($numbers, 0, 1200), 5, 150);
check($again['chunk_projects'] === $plan['chunk_projects'], 'dezelfde index blijft dezelfde projecten, ook nadat eerdere chunks al binnen zijn');

$last = bc_fetch_project_detail_chunk_at($numbers, 7, 150);
check($last['chunks_complete'] === true, 'laatste chunk rondt de reeks af');
check(($last['chunk_projects'][0] ?? '') === 'P1051', 'laatste chunk begint bij 1051, kreeg ' . ($last['chunk_projects'][0] ?? ''));
check(count($last['chunk_projects']) === 150, 'laatste chunk is vol');

$past = bc_fetch_project_detail_chunk_at($numbers, 8, 150);
check($past['chunks_complete'] === true && $past['chunk_projects'] === [], 'index voorbij het einde haalt niets meer op');

$empty = bc_fetch_project_detail_chunk_at([], 0, 150);
check($empty['total_chunks'] === 1 && $empty['chunks_complete'] === true && $empty['chunk_projects'] === [], 'lege maand is meteen klaar');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} assertion(s) failed\n");
    exit(1);
}

echo "OK project detail chunks\n";
