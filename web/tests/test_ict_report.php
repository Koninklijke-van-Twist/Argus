<?php

/**
 * "Rapporteer aan ICT": ticketinhoud, Nederlandse datum, CSRF en Asclepius-call (gemockt).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../ict_report/ict_report_lib.php';

$failures = 0;

function check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

// Datum: Nederlands, Europe/Amsterdam, nooit ISO.
$ts = (new DateTimeImmutable('2026-10-08 09:14:00', new DateTimeZone('UTC')))->getTimestamp();
check(ict_report_format_datetime_nl($ts) === '8 oktober 2026, 11:14', 'datum NL zomertijd: ' . ict_report_format_datetime_nl($ts));
$tsWinter = (new DateTimeImmutable('2026-01-05 08:03:00', new DateTimeZone('UTC')))->getTimestamp();
check(ict_report_format_datetime_nl($tsWinter) === '5 januari 2026, 09:03', 'datum NL wintertijd');

// Ticketinhoud.
$message = "Netwerkfout bij projectdetails voor Augustus 2026: Server gaf geen geldige JSON terug.\n\nHTTP-status: 200 OK\n\nRespons:\n<br />\n<b>Warning</b>: session_start(): Failed to acquire session lock";
$ticket = ict_report_build_ticket('Argus', [
    'month' => 'Augustus 2026',
    'step' => 'Projectdetails (deel 16 van 27)',
    'message' => $message,
    'http_status' => '200 OK',
    'response_text' => '<br />',
    'page_url' => 'https://sleutels.kvt.nl/argus/maanden.php',
    'user_agent' => 'Mozilla/5.0',
    'occurred_at' => $ts * 1000,
], ['email' => 'Tim@KVT.nl', 'name' => 'Tim Falken'], $ts + 60);

check($ticket['title'] === 'Argus: fout bij verversen Augustus 2026 (Projectdetails (deel 16 van 27))', 'titel: ' . $ticket['title']);
check(str_contains($ticket['description'], '**Gebruiker:** Tim Falken (tim@kvt.nl)'), 'gebruiker in beschrijving');
check(str_contains($ticket['description'], '**Tijdstip fout:** 8 oktober 2026, 11:14'), 'tijdstip fout');
check(str_contains($ticket['description'], '**Gemeld op:** 8 oktober 2026, 11:15'), 'gemeld op');
check(str_contains($ticket['description'], '**Maand:** Augustus 2026'), 'maand');
check(str_contains($ticket['description'], 'Failed to acquire session lock'), 'volledige foutmelding');
check(!preg_match('/\d{4}-\d{2}-\d{2}T/', $ticket['description']), 'geen ISO-tijd');
check(!str_contains($ticket['description'], '**Respons van de server:**'), 'respons niet dubbel als hij al in de melding staat');

// CSRF (HMAC met sessie-id, geen servergeheim nodig).
$token = ict_report_csrf_token('tim@kvt.nl', 'sid123');
check(strlen($token) === 64, 'token lengte');
check(ict_report_verify_csrf($token, 'TIM@kvt.nl', 'sid123'), 'token geldig');
check(!ict_report_verify_csrf($token, 'ander@kvt.nl', 'sid123'), 'token andere gebruiker ongeldig');
check(!ict_report_verify_csrf($token, 'tim@kvt.nl', 'andere-sessie'), 'token andere sessie ongeldig');
check(!ict_report_verify_csrf('', 'tim@kvt.nl', 'sid123'), 'lege token ongeldig');
check(ict_report_csrf_token('tim@kvt.nl', '') === '', 'zonder sessie geen token');

// Persoonlijke key uit de sessie (login-helper niet aanwezig in deze testomgeving).
$oid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
$sessionKey = hash('sha256', $oid . '|' . gmdate('d-m-Y'));
$creds = ict_report_user_credentials(['email' => 'Tim@KVT.nl', 'name' => 'Tim Falken', 'oid' => strtoupper($oid), 'api_key' => $sessionKey]);
check($creds['email'] === 'tim@kvt.nl' && $creds['oid'] === $oid && $creds['api_key'] === $sessionKey, 'credentials uit sessie');
$noKey = ict_report_user_credentials(['email' => 'tim@kvt.nl', 'oid' => $oid, 'api_key' => 'rommel']);
check(function_exists('build_rotating_api_key') ? $noKey['api_key'] === $sessionKey : $noKey['api_key'] === '', 'ongeldige key genegeerd of vers berekend');

// Same-origin.
check(ict_report_same_origin(['HTTP_HOST' => 'sleutels.kvt.nl', 'HTTP_ORIGIN' => 'https://sleutels.kvt.nl']), 'zelfde origin');
check(!ict_report_same_origin(['HTTP_HOST' => 'sleutels.kvt.nl', 'HTTP_ORIGIN' => 'https://evil.example']), 'vreemde origin');
check(ict_report_same_origin(['HTTP_HOST' => 'sleutels.kvt.nl']), 'geen origin/referer toegestaan (token is leidend)');

// Ticket-URL zoals de "Ticketlink kopiëren"-knop in Asclepius.
check(ict_report_ticket_url_from_api_url('https://sleutels.kvt.nl/asclepius/api.php', 42) === 'https://sleutels.kvt.nl/asclepius/index.php?open=42', 'ticket-url');

// Asclepius-call (gemockt) met de persoonlijke key.
$user = ['email' => 'tim@kvt.nl', 'name' => 'Tim Falken', 'oid' => $oid, 'api_key' => $sessionKey];
$captured = null;
$mock = static function (string $url, array $headers, string $body) use (&$captured): array {
    $captured = ['url' => $url, 'headers' => $headers, 'body' => json_decode($body, true)];
    return ['status' => 201, 'body' => json_encode(['success' => true, 'ticket_id' => 1234])];
};
$result = ict_report_create_ticket($ticket, $user, 'https://sleutels.kvt.nl/asclepius/api.php', $mock);
check($result['ok'] === true && $result['ticket_id'] === 1234, 'ticket aangemaakt');
check($result['ticket_url'] === 'https://sleutels.kvt.nl/asclepius/index.php?open=1234', 'fallback ticket-url');
check($captured['body']['category'] === 'sleutels.kvt.nl web-applicatieproblemen', 'categorie');
check($captured['body']['user_email'] === 'tim@kvt.nl' && $captured['body']['oid'] === $oid, 'namens gebruiker (e-mail + oid)');
check(in_array('X-API-Key: ' . $sessionKey, $captured['headers'], true), 'persoonlijke key als X-API-Key');
check(in_array('X-User-Oid: ' . $oid, $captured['headers'], true) && in_array('X-User-Email: tim@kvt.nl', $captured['headers'], true), 'oid/e-mail headers');

$mockUrl = static fn(): array => ['status' => 201, 'body' => json_encode(['success' => true, 'ticket_id' => 7, 'ticket_url' => 'https://sleutels.kvt.nl/asclepius/index.php?open=7'])];
$result = ict_report_create_ticket($ticket, $user, 'https://x/asclepius/api.php', $mockUrl);
check($result['ticket_url'] === 'https://sleutels.kvt.nl/asclepius/index.php?open=7', 'ticket_url uit API');

$mockFail = static fn(): array => ['status' => 422, 'body' => json_encode(['success' => false, 'errors' => ['Categorie is ongeldig.']])];
$result = ict_report_create_ticket($ticket, $user, 'https://x/api.php', $mockFail);
check($result['ok'] === false && str_contains($result['error'], 'Categorie is ongeldig.') && str_contains($result['error'], 'HTTP 422'), 'foutmelding API');

$mock401 = static fn(): array => ['status' => 401, 'body' => json_encode(['success' => false, 'error' => 'Ongeldige API-key.'])];
$result = ict_report_create_ticket($ticket, $user, 'https://x/api.php', $mock401);
check($result['ok'] === false && $result['status'] === 401 && str_contains($result['error'], 'Log opnieuw in'), 'verlopen key → opnieuw inloggen');

$called = false;
$spy = static function () use (&$called): array { $called = true; return ['status' => 201, 'body' => '{}']; };
$result = ict_report_create_ticket($ticket, ['email' => 'tim@kvt.nl', 'oid' => $oid, 'api_key' => ''], 'https://x/api.php', $spy);
check($result['ok'] === false && !$called && str_contains($result['error'], 'Log opnieuw in'), 'zonder key geen call, wel duidelijke melding');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} test(s) gefaald\n");
    exit(1);
}
echo "test_ict_report: OK\n";
