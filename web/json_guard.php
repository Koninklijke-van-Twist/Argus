<?php

/**
 * JSON-endpoints (alles met ?action=...) mogen nooit HTML-warnings of andere
 * losse output vóór de JSON zetten. Voorbeeld: phpredis kan bij een bezet
 * sessie-lock in login/lib.php "Failed to acquire session lock" waarschuwen;
 * met display_errors=1 belandt dat als HTML vóór de JSON en breekt de frontend.
 *
 * - Voor JSON-verzoeken: display_errors uit, log_errors aan (warnings gaan naar de log).
 * - Een outputbuffer vangt eventuele losse output van includes op; die wordt
 *   vlak vóór de JSON weggegooid (en gelogd) via argus_json_discard_preamble().
 * - Gewone HTML-pagina's houden display_errors aan zoals voorheen.
 */

function argus_is_json_request(): bool
{
    $action = $_GET['action'] ?? '';

    return is_string($action) && $action !== '';
}

function argus_configure_error_display(?bool $isJson = null): void
{
    static $bufferLevel = null;

    $isJson = $isJson ?? argus_is_json_request();
    error_reporting(E_ALL);

    if (!$isJson) {
        ini_set('display_errors', '1');
        ini_set('display_startup_errors', '1');
        return;
    }

    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    ini_set('html_errors', '0');

    if ($bufferLevel === null) {
        ob_start();
        $bufferLevel = ob_get_level();
        $GLOBALS['argus_json_buffer_level'] = $bufferLevel;
    }
}

/**
 * Gooit alles weg wat sinds argus_configure_error_display() in de buffer staat
 * (bijv. warnings of losse echo's van includes) en logt het. Daarna kan de
 * endpoint veilig zijn JSON echoën.
 */
function argus_json_discard_preamble(): string
{
    $level = (int) ($GLOBALS['argus_json_buffer_level'] ?? 0);
    if ($level <= 0 || ob_get_level() < $level) {
        return '';
    }

    $stray = '';
    while (ob_get_level() > $level) {
        $stray = (string) ob_get_clean() . $stray;
    }
    $stray = (string) ob_get_contents() . $stray;
    ob_clean();

    if (trim($stray) !== '') {
        $clean = trim(preg_replace('/\s+/', ' ', strip_tags($stray)) ?? '');
        error_log('[Argus] Onverwachte output vóór JSON weggegooid: ' . substr($clean, 0, 1000));
    }

    return $stray;
}
