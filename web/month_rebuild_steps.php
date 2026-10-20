<?php

/**
 * Dezelfde herbouwstappen als maanden.js: OHW, projectdetails, voorcalculatie
 * (daarin zit Opbrengst t/m) en publicatie. De HTTP-handlers en de nightly
 * roepen deze functies aan, met dezelfde lock en generatie.
 */

require_once __DIR__ . '/auth_helper.php';

/**
 * @return array<string,mixed>
 */
function maanden_rebuild_column_step(
    string $company,
    string $yearMonth,
    string $columnKey,
    int $chunkIndex,
    string $refreshId,
    int $generation,
    int $ttl,
    bool $releaseWhenDone = true
): array {
    $result = ['ok' => false, 'error' => 'Kolomstap mislukt.'];
    $targetYm = $yearMonth;
    $chunkIndex = max(0, $chunkIndex);
    try {
        $targetScopedKeys = bc_fetch_target_scoped_column_keys();
        $isTargetScoped = in_array($columnKey, $targetScopedKeys, true);
        if (!preg_match('/^\d{4}-\d{2}$/', $targetYm) || $columnKey === '') {
            $result = ['ok' => false, 'error' => 'Ongeldige parameters'];
            return $result;
        }
        if (!$isTargetScoped) {
            throw new RuntimeException('Kolom ' . $columnKey . ' wordt alleen nog per snapshot opgehaald.');
        }

        $auth = auth_get_auth_for_company($company, 300);
        maanden_rebuild_heartbeat($company, $targetYm, $refreshId, $generation);
        if (!argus_month_rebuild_generation_is_current(maanden_cache_dir(), $company, $targetYm, $refreshId, $generation)) {
            $result = maanden_rebuild_stale_result();
            return $result;
        }

        $wip = batch_wip_load($company, $targetYm, $refreshId);
        $columnDataTarget = is_array($wip['column_data_target'] ?? null) ? $wip['column_data_target'] : [];
        $response = [
            'ok' => true,
            'batch_month' => $targetYm,
            'column_key' => $columnKey,
            'target_scoped' => true,
            'chunk_index' => $chunkIndex,
            'total_chunks' => 1,
            'chunks_complete' => true,
            'warning' => null,
            'project_count' => count(wip_project_numbers($wip)),
        ];

        if ($columnKey === 'grootboekposten_ohw') {
            $existingOhw = $columnDataTarget['grootboekposten_ohw'] ?? null;
            if (is_array($existingOhw) && array_key_exists('by_project', $existingOhw)) {
                $result = $response;
                return $result;
            }

            $columnResult = bc_fetch_run_column($columnKey, $company, $targetYm, [], $auth, $ttl);
            $columnDataTarget[$columnKey] = $columnResult;
            $wip['column_data_target'] = $columnDataTarget;
            $openProjects = bc_fetch_open_projects($company, $auth, $ttl);
            $wip['open_project_numbers'] = $openProjects['numbers'];
            $wip['open_project_rows'] = $openProjects['rows_by_norm'];
            maanden_wip_sync_project_numbers_from_ohw($wip);
            $response['project_count'] = count(wip_project_numbers($wip));
            $response['open_project_count'] = count($openProjects['numbers']);
            $response['warning'] = maanden_join_warnings([
                $columnResult['warning'] ?? null,
                $openProjects['warning'] ?? null,
            ]);
        } elseif ($columnKey === 'project_details') {
            $allProjectNumbers = wip_project_numbers($wip);
            if ($allProjectNumbers === []) {
                maanden_wip_sync_project_numbers_from_ohw($wip);
                $allProjectNumbers = wip_project_numbers($wip);
            }

            maanden_seed_open_project_details($columnDataTarget, $wip);
            if (!isset($columnDataTarget[$columnKey]) || !is_array($columnDataTarget[$columnKey])) {
                $columnDataTarget[$columnKey] = [
                    'column' => 'project_details',
                    'by_project' => [],
                ];
            }
            // Chunks op de volledige projectlijst, niet op wat er nog mist.
            // Een krimpende lijst liet chunk-index 5 voorbij de nieuwe total vallen,
            // waardoor delen 6-8 nooit werden opgehaald.
            $plan = bc_fetch_project_detail_chunk_at($allProjectNumbers, $chunkIndex, PROJECT_DETAILS_FETCH_CHUNK_SIZE);
            $response['total_chunks'] = $plan['total_chunks'];
            $response['chunks_complete'] = $plan['chunks_complete'];
            $toFetch = maanden_project_numbers_missing_details($plan['chunk_projects'], $columnDataTarget);
            if ($toFetch !== []) {
                $columnResult = bc_fetch_run_column($columnKey, $company, $targetYm, $toFetch, $auth, $ttl);
                $existing = is_array($columnDataTarget[$columnKey] ?? null) ? $columnDataTarget[$columnKey] : [];
                $columnDataTarget[$columnKey] = maanden_merge_project_details_column($existing, $columnResult);
                $response['warning'] = $columnResult['warning'] ?? null;
            }

            $wip['column_data_target'] = $columnDataTarget;
        } else {
            $allProjectNumbers = wip_project_numbers($wip);
            $columnResult = bc_fetch_run_column($columnKey, $company, $targetYm, $allProjectNumbers, $auth, $ttl);
            $columnDataTarget[$columnKey] = $columnResult;
            $wip['column_data_target'] = $columnDataTarget;
            $response['warning'] = $columnResult['warning'] ?? null;
        }

        if (!maanden_rebuild_save_wip($company, $targetYm, $wip, $refreshId, $generation)) {
            $result = maanden_rebuild_stale_result();
            return $result;
        }

        $result = $response;
    } catch (Throwable $error) {
        $result = ['ok' => false, 'error' => $error->getMessage()];
    } finally {
        if ($releaseWhenDone) {
            maanden_rebuild_release($company, $targetYm, $refreshId, $generation);
        }
    }

    return $result;
}

/**
 * @return array<string,mixed>
 */
function maanden_rebuild_planning_step(
    string $company,
    string $yearMonth,
    int $chunkIndex,
    bool $hasChunkIndex,
    string $refreshId,
    int $generation,
    int $ttl,
    bool $releaseWhenDone = true
): array {
    $result = ['ok' => false, 'error' => 'Voorcalculatie mislukt.'];
    $targetYm = $yearMonth;
    $chunkIndex = max(0, $chunkIndex);
    try {
        if (!preg_match('/^\d{4}-\d{2}$/', $targetYm)) {
            $result = ['ok' => false, 'error' => 'Ongeldige maand'];
            return $result;
        }

        maanden_rebuild_heartbeat($company, $targetYm, $refreshId, $generation);
        if (!argus_month_rebuild_generation_is_current(maanden_cache_dir(), $company, $targetYm, $refreshId, $generation)) {
            $result = maanden_rebuild_stale_result();
            return $result;
        }

        $wip = batch_wip_load($company, $targetYm, $refreshId);
        maanden_wip_sync_project_numbers_from_ohw($wip);
        $projectNumbers = wip_project_numbers($wip);
        if (!$hasChunkIndex) {
            // Oude client zonder chunk_index: nog steeds de hele lijst, in één request.
            $plan = [
                'total_chunks' => 1,
                'chunk_projects' => $projectNumbers,
                'chunks_complete' => true,
            ];
        } else {
            $plan = bc_fetch_voorcalculatie_chunk_at($projectNumbers, $chunkIndex);
        }

        $planningTotalsByJob = is_array($wip['planning_totals_by_job'] ?? null)
            ? $wip['planning_totals_by_job']
            : [];
        $planningBreakdownByJob = is_array($wip['planning_breakdown_by_job'] ?? null)
            ? $wip['planning_breakdown_by_job']
            : [];
        $storedWarning = $wip['planning_warning'] ?? null;
        $planningWarning = is_string($storedWarning) && trim($storedWarning) !== ''
            ? trim($storedWarning)
            : null;
        $chunkWarning = null;
        $blockingError = null;

        if ($plan['chunk_projects'] === [] || bc_fetch_forecast_covers_projects($plan['chunk_projects'], $planningTotalsByJob)) {
            $result = [
                'ok' => true,
                'warning' => null,
                'project_count' => count($plan['chunk_projects']),
                'chunk_index' => $chunkIndex,
                'total_chunks' => $plan['total_chunks'],
                'chunks_complete' => $plan['chunks_complete'],
            ];
            return $result;
        }

        try {
            $environmentForCompany = auth_get_environment_for_company($company, AUTH_COMPANY_DISCOVERY_TTL_SECONDS);
            $financeService = new ProjectFinanceService($company, $environmentForCompany);
            $projectForecast = $financeService->collectProjectForecastForProjects($plan['chunk_projects'], $ttl, $targetYm);
            $blockingError = finance_forecast_blocks_snapshot($projectForecast);
            if (!is_string($blockingError) || $blockingError === '') {
                $chunkTotals = is_array($projectForecast['forecast_totals_by_job'] ?? null)
                    ? $projectForecast['forecast_totals_by_job']
                    : [];
                $chunkBreakdown = is_array($projectForecast['forecast_breakdown_by_job'] ?? null)
                    ? $projectForecast['forecast_breakdown_by_job']
                    : [];
                $merged = bc_fetch_merge_forecast_chunk(
                    $planningTotalsByJob,
                    $planningBreakdownByJob,
                    $chunkTotals,
                    $chunkBreakdown,
                    $plan['chunk_projects']
                );
                $planningTotalsByJob = $merged['totals'];
                $planningBreakdownByJob = $merged['breakdown'];
                $forecastWarning = $projectForecast['warning'] ?? null;
                $chunkWarning = is_string($forecastWarning) && trim($forecastWarning) !== ''
                    ? trim($forecastWarning)
                    : null;
                $planningWarning = maanden_join_warnings([$planningWarning, $chunkWarning]);
            }
        } catch (Throwable $forecastLoadError) {
            // Een echte fout (timeout, te lange filter) blokkeert de publicatie.
            // De vorige snapshot blijft staan. Alleen een lege waarschuwing deed dat niet.
            $blockingError = $forecastLoadError->getMessage();
        }

        if (is_string($blockingError) && $blockingError !== '') {
            $result = ['ok' => false, 'error' => $blockingError];
            return $result;
        }

        $wip['planning_totals_by_job'] = $planningTotalsByJob;
        $wip['planning_breakdown_by_job'] = $planningBreakdownByJob;
        $wip['planning_warning'] = $planningWarning;
        if (!maanden_rebuild_save_wip($company, $targetYm, $wip, $refreshId, $generation)) {
            $result = maanden_rebuild_stale_result();
            return $result;
        }

        $result = [
            'ok' => true,
            'warning' => $chunkWarning,
            'project_count' => count($plan['chunk_projects']),
            'chunk_index' => $chunkIndex,
            'total_chunks' => $plan['total_chunks'],
            'chunks_complete' => $plan['chunks_complete'],
        ];
    } catch (Throwable $error) {
        $result = ['ok' => false, 'error' => $error->getMessage()];
    } finally {
        if ($releaseWhenDone) {
            maanden_rebuild_release($company, $targetYm, $refreshId, $generation);
        }
    }

    return $result;
}

/**
 * @return array<string,mixed>
 */
function maanden_rebuild_build_step(
    string $company,
    string $yearMonth,
    string $refreshId,
    int $generation,
    int $ttl,
    bool $releaseWhenDone = true
): array {
    $result = ['ok' => false, 'error' => 'Publicatie mislukt.'];
    $targetYm = $yearMonth;
    try {
        if (!preg_match('/^\d{4}-\d{2}$/', $targetYm)) {
            $result = ['ok' => false, 'error' => 'Ongeldige maand'];
            return $result;
        }

        maanden_rebuild_heartbeat($company, $targetYm, $refreshId, $generation);
        if (!argus_month_rebuild_generation_is_current(maanden_cache_dir(), $company, $targetYm, $refreshId, $generation)) {
            $result = maanden_rebuild_stale_result();
            return $result;
        }
        $wip = batch_wip_load($company, $targetYm, $refreshId);

        $columnDataTarget = is_array($wip['column_data_target'] ?? null) ? $wip['column_data_target'] : [];
        $hasOhwColumn = is_array($columnDataTarget['grootboekposten_ohw'] ?? null);
        $hasLegacyFinance = is_array($wip['project_finance'] ?? null) && $wip['project_finance'] !== [];
        if (!$hasOhwColumn && !$hasLegacyFinance) {
            throw new RuntimeException('Geen opgebouwde gegevens om te publiceren. De vorige snapshot blijft beschikbaar.');
        }

        $planningTotalsForPublish = is_array($wip['planning_totals_by_job'] ?? null) ? $wip['planning_totals_by_job'] : [];
        $planningProjects = maanden_wip_sync_project_numbers_from_ohw($wip);
        if (!bc_fetch_forecast_covers_projects($planningProjects, $planningTotalsForPublish)) {
            throw new RuntimeException('Voorcalculatie is nog niet compleet. De vorige snapshot blijft beschikbaar.');
        }

        if (is_array($columnDataTarget['grootboekposten_ohw'] ?? null)) {
            $data = build_snapshot_from_column_wip($company, $targetYm, $wip);
            if (!argus_month_rebuild_generation_is_current(maanden_cache_dir(), $company, $targetYm, $refreshId, $generation)) {
                $result = maanden_rebuild_stale_result();
                return $result;
            }
            if (!maand_save($company, $targetYm, $data)) {
                throw new RuntimeException('De nieuwe snapshot kon niet worden opgeslagen. De vorige snapshot blijft beschikbaar.');
            }
            batch_wip_delete($company, $targetYm, $refreshId);
            $result = ['ok' => true, 'data' => $data];
            return $result;
        }

        $projectFinance = is_array($wip['project_finance'] ?? null) ? $wip['project_finance'] : [];
        $projectDetails = is_array($wip['project_details'] ?? null) ? $wip['project_details'] : [];
        $planningTotalsByJob = is_array($wip['planning_totals_by_job'] ?? null) ? $wip['planning_totals_by_job'] : [];
        $planningBreakdownByJob = is_array($wip['planning_breakdown_by_job'] ?? null) ? $wip['planning_breakdown_by_job'] : [];
        $projectTotalsByJob = is_array($projectFinance['project_totals_by_job'] ?? null) ? $projectFinance['project_totals_by_job'] : [];
        $data = build_month_rows(
            $company,
            $targetYm,
            $projectTotalsByJob,
            $projectDetails,
            $planningTotalsByJob,
            $planningBreakdownByJob
        );

        if (!argus_month_rebuild_generation_is_current(maanden_cache_dir(), $company, $targetYm, $refreshId, $generation)) {
            $result = maanden_rebuild_stale_result();
            return $result;
        }
        if (!maand_save($company, $targetYm, $data)) {
            throw new RuntimeException('De nieuwe snapshot kon niet worden opgeslagen. De vorige snapshot blijft beschikbaar.');
        }
        batch_wip_delete($company, $targetYm, $refreshId);
        $result = ['ok' => true, 'data' => $data];
    } catch (Throwable $error) {
        $result = ['ok' => false, 'error' => $error->getMessage()];
    } finally {
        if ($releaseWhenDone) {
            maanden_rebuild_release($company, $targetYm, $refreshId, $generation);
        }
    }

    return $result;
}

/**
 * JSON voor een herbouwstap. Een OData-fout bevat de ruwe BC-body; die is vaak
 * geen geldige UTF-8. json_encode geeft dan false, echo print niets, en de
 * handler heeft al HTTP 500 gezet. De browser ziet een lege response.
 *
 * @param array<string,mixed> $result
 */
function maanden_rebuild_json_response(array $result): string
{
    $flags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
    if (!empty($result['ok'])) {
        $flags |= JSON_UNESCAPED_SLASHES;
    }
    $json = json_encode($result, $flags);
    if (!is_string($json) || $json === '') {
        return '{"ok":false,"error":"De server kon het antwoord niet als JSON schrijven."}';
    }

    return $json;
}

/**
 * Draait OHW, projectdetails, voorcalculatie en publicatie in één proces.
 * De lock blijft bezet tot de aanroeper hem vrijgeeft, zodat een browser
 * tussendoor geen nieuwe generatie start.
 *
 * @return array<string,mixed>
 */
function maanden_run_rebuild_pipeline(string $company, string $yearMonth, string $refreshId, int $generation, int $maxAge): array
{
    $columnKeys = bc_fetch_target_scoped_column_keys();
    $previous = $GLOBALS['argus_active_month_rebuild'] ?? null;
    $GLOBALS['argus_active_month_rebuild'] = [
        'dir' => maanden_cache_dir(),
        'company' => $company,
        'year_month' => $yearMonth,
        'refresh_id' => $refreshId,
        'generation' => $generation,
    ];

    try {
        return argus_run_month_rebuild_steps(
        $company,
        $yearMonth,
        $refreshId,
        $generation,
        $maxAge,
        static function (string $company, string $yearMonth, string $columnKey, int $chunkIndex, string $refreshId, int $generation, int $maxAge): array {
            return maanden_rebuild_column_step($company, $yearMonth, $columnKey, $chunkIndex, $refreshId, $generation, $maxAge, false);
        },
        static function (string $company, string $yearMonth, int $chunkIndex, string $refreshId, int $generation, int $maxAge): array {
            return maanden_rebuild_planning_step($company, $yearMonth, $chunkIndex, true, $refreshId, $generation, $maxAge, false);
        },
        static function (string $company, string $yearMonth, string $refreshId, int $generation, int $maxAge): array {
            return maanden_rebuild_build_step($company, $yearMonth, $refreshId, $generation, $maxAge, false);
        },
        $columnKeys
    );
    } finally {
        if ($previous === null) {
            unset($GLOBALS['argus_active_month_rebuild']);
        } else {
            $GLOBALS['argus_active_month_rebuild'] = $previous;
        }
    }
}
