<?php

/**
 * Uurlijkse maandherbouw zonder open browser.
 * Zelfde stappen en dezelfde lock als nightly.php. max_age naar Mímir is 30 minuten.
 *
 *   php web/hourly.php
 *
 * HTTP alleen met header X-Argus-Secret ($argusRunnerSecret of ARGUS_RUNNER_SECRET).
 */

define('ARGUS_MAANDEN_LIBRARY', true);
require __DIR__ . '/maanden.php';
require_once __DIR__ . '/month_rebuild_cli.php';
argus_month_rebuild_cli_main('hourly');
