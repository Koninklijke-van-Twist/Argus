<?php

/**
 * JobTaskLines-batches blijven kort, en een mislukte voorcalculatie
 * vervangt de maandsnapshot niet.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../finance_calculations.php';

$failures = 0;

function check(bool $ok, string $message): void
{
    global $failures;
    if (!$ok) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$normal = [];
for ($i = 0; $i < 30; $i++) {
    $normal[] = 'PRJ' . str_pad((string) $i, 7, '0', STR_PAD_LEFT);
}
$normal[] = 'PRJ0000000';
$normal[] = '  ';
$chunks = finance_job_task_lines_chunks($normal);
check(count($chunks) === 4, '30 projecten en een duplicaat worden vier batches, kreeg ' . count($chunks));
$seen = [];
foreach ($chunks as $chunk) {
    $filter = finance_job_task_lines_filter($chunk);
    check(strlen($filter) <= FINANCE_JOB_TASK_LINES_MAX_FILTER_CHARS, 'filterlengte ' . strlen($filter));
    check(count($chunk) <= FINANCE_JOB_TASK_LINES_MAX_PROJECTS, 'batchgrootte ' . count($chunk));
    check(str_contains($filter, "Job_Task_Type eq 'Boeking'"), 'filter op Boeking');
    foreach ($chunk as $projectNo) {
        check(!isset($seen[$projectNo]), 'project komt één keer voor: ' . $projectNo);
        $seen[$projectNo] = true;
    }
}
check(count($seen) === 30, 'duplicaat en lege waarde vallen weg, kreeg ' . count($seen));

$long = [];
for ($i = 0; $i < 15; $i++) {
    $long[] = 'P' . str_pad((string) $i, 80, 'X');
}
$longChunks = finance_job_task_lines_chunks($long);
check(count($longChunks) > 1, 'lange projectnummers splitsen op filterlengte, kreeg ' . count($longChunks));
foreach ($longChunks as $chunk) {
    check(count($chunk) < 15, 'niet alles in één batch: ' . count($chunk));
    check(strlen(finance_job_task_lines_filter($chunk)) <= FINANCE_JOB_TASK_LINES_MAX_FILTER_CHARS, 'lange batch blijft binnen de grens');
}

$quoted = finance_job_task_lines_filter(["O'Brien"]);
check(str_contains($quoted, "Job_No eq 'O''Brien'"), 'quote in projectnummer wordt geëscapet: ' . $quoted);
check(count(finance_job_task_lines_chunks([])) === 0, 'geen projecten, geen query');

$singleTooLong = finance_job_task_lines_chunks([str_repeat('Z', 2000)]);
check(count($singleTooLong) === 1 && count($singleTooLong[0]) === 1, 'één te lang project blijft één batch');

check(finance_forecast_blocks_snapshot(['task_type_error' => true, 'warning' => 'JobTaskLines ophalen mislukt: timed out']) === 'JobTaskLines ophalen mislukt: timed out', 'timeout blokkeert publicatie');
check(finance_forecast_blocks_snapshot(['task_type_error' => true, 'warning' => '  ']) === 'JobTaskLines ophalen mislukt.', 'lege fout krijgt een vaste melding');
check(finance_forecast_blocks_snapshot(['warning' => 'Veld Job_Task_Type bestaat niet']) === null, 'schema-waarschuwing blokkeert niet');

$dir = sys_get_temp_dir() . '/argus-snapshot-' . bin2hex(random_bytes(4));
check(mkdir($dir), 'tijdelijke map');
$path = $dir . '/maand.v2.json';
$wip = $dir . '/maand.wip.json';
check(argus_publish_snapshot_file($path, ['cache_version' => 2, 'label' => 'oud']), 'eerste snapshot');
$previous = file_get_contents($path);
check(is_string($previous) && str_contains($previous, 'oud'), 'oude snapshot is leesbaar');
check(file_put_contents($wip, '{"bezig":true}') !== false, 'lopende refresh schrijft alleen WIP');
check(file_get_contents($path) === $previous, 'tijdens de refresh blijft de oude snapshot staan');
check(argus_publish_snapshot_file($path, ['cache_version' => INF]) === false, 'ongeldige JSON publiceert niet');
check(file_get_contents($path) === $previous, 'mislukte publicatie laat de oude snapshot intact');
check(argus_publish_snapshot_file($path, ['cache_version' => 2, 'label' => 'nieuw']), 'complete snapshot');
$published = file_get_contents($path);
check(is_string($published) && str_contains($published, 'nieuw') && !str_contains($published, 'oud'), 'nieuwe snapshot vervangt de oude in één keer');
$leftover = glob($dir . '/*.tmp.*');
check($leftover === [] || $leftover === false, 'geen tijdelijk bestand blijft achter');
check(argus_month_refresh_id('../etc/passwd') === '', 'padtekens zijn geen refresh-id');
check(argus_month_refresh_id('abc') === '', 'te kort id valt weg');
check(argus_month_refresh_id('0123456789ABCDEF') === '0123456789abcdef', 'hex-id wordt bewaard');
@unlink($path);
@unlink($wip);
@rmdir($dir);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} assertion(s) failed\n");
    exit(1);
}

echo "OK job task line batches\n";
