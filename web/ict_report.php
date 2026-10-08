<?php
/**
 * POST-endpoint "Rapporteer aan ICT": maakt namens de ingelogde gebruiker een
 * Asclepius-ticket aan en geeft ticketnummer + ticketlink terug.
 */
require_once __DIR__ . '/json_guard.php';
argus_configure_error_display(true);

require __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/ict_report/ict_report_lib.php';

argus_json_discard_preamble();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function ict_report_respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    ict_report_respond(405, ['ok' => false, 'error' => 'Alleen POST is toegestaan.']);
}

$userEmail = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
$userName = trim((string) ($_SESSION['user']['name'] ?? ''));
if ($userEmail === '' || !filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
    ict_report_respond(401, ['ok' => false, 'error' => 'Je bent niet (meer) ingelogd. Vernieuw de pagina en probeer opnieuw.']);
}

$raw = (string) file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    $input = $_POST;
}

$token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? ''));
if (!ict_report_same_origin($_SERVER) || !ict_report_verify_csrf($token, $userEmail)) {
    ict_report_respond(403, ['ok' => false, 'error' => 'Ongeldige beveiligingstoken. Vernieuw de pagina en probeer opnieuw.']);
}

$ticket = ict_report_build_ticket('Argus', $input, ['email' => $userEmail, 'name' => $userName], time());
$result = ict_report_create_ticket($ticket, $userEmail, ict_report_api_url(), ict_report_api_key());

if (empty($result['ok'])) {
    error_log('[Argus] Rapporteer aan ICT mislukt: ' . ($result['error'] ?? 'onbekend'));
    ict_report_respond(502, ['ok' => false, 'error' => (string) ($result['error'] ?? 'Ticket aanmaken mislukt.')]);
}

ict_report_respond(200, [
    'ok' => true,
    'ticket_id' => $result['ticket_id'],
    'ticket_url' => $result['ticket_url'],
]);
