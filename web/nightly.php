<?php

/**
 * Nachtelijke maandherbouw zonder open browser.
 * Maanden zonder .v3.json en maanden met een open Verversen-WIP doorlopen
 * dezelfde stappen als maanden.js: OHW, projectdetails, voorcalculatie
 * (Opbrengst t/m) en publicatie. max_age naar Mímir is 4 uur.
 *
 *   php web/nightly.php
 *
 * HTTP alleen met header X-Argus-Secret ($argusRunnerSecret of ARGUS_RUNNER_SECRET).
 */

define('ARGUS_MAANDEN_LIBRARY', true);
require __DIR__ . '/maanden.php';
require_once __DIR__ . '/month_rebuild_cli.php';
argus_month_rebuild_cli_main('nightly');
