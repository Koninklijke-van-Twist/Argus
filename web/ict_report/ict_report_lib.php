<?php

declare(strict_types=1);

/**
 * "Rapporteer aan ICT" — herbruikbare server-kant voor sleutels.kvt.nl-apps.
 *
 * Maakt namens de ingelogde gebruiker een Asclepius-ticket aan in de categorie
 * "sleutels.kvt.nl web-applicatieproblemen", met de persoonlijke tijdelijke
 * API-key die de gedeelde login bij het inloggen uitgeeft
 * ($_SESSION['user']['api_key'] = sha256(oid|d-m-Y UTC), zie login/session_user.php).
 * Geen service-key nodig; de key blijft server-side.
 *
 * Gebruik in een andere app: kopieer de map ict_report/ en een endpoint zoals
 * ict_report.php, geef de pagina ict_report_csrf_token() mee en laad
 * ict_report/ict_report.js. Er is geen extra configuratie nodig.
 */

const ICT_REPORT_CATEGORY = 'sleutels.kvt.nl web-applicatieproblemen';
const ICT_REPORT_MAX_DETAILS = 20000;
const ICT_REPORT_MAX_FIELD = 500;

function ict_report_login_dir(): string
{
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'login';
}

/**
 * Gegevens van de ingelogde gebruiker, inclusief zijn persoonlijke tijdelijke API-key.
 * De key wordt bij voorkeur vers berekend uit het oid met de login-helper (zelfde
 * regel als login/session_user.php), anders uit de sessie.
 *
 * @param array<string, mixed> $sessionUser $_SESSION['user']
 * @return array{email: string, name: string, oid: string, api_key: string}
 */
function ict_report_user_credentials(array $sessionUser): array
{
    $oid = strtolower(trim((string) ($sessionUser['oid'] ?? '')));
    $apiKey = strtolower(trim((string) ($sessionUser['api_key'] ?? '')));

    $helper = ict_report_login_dir() . DIRECTORY_SEPARATOR . 'session_user.php';
    if ($oid !== '' && is_file($helper)) {
        require_once $helper;
    }
    if ($oid !== '' && function_exists('build_rotating_api_key')) {
        $fresh = build_rotating_api_key($oid);
        if ($fresh !== '') {
            $apiKey = $fresh;
        }
    }

    return [
        'email' => strtolower(trim((string) ($sessionUser['email'] ?? ''))),
        'name' => trim((string) ($sessionUser['name'] ?? '')),
        'oid' => $oid,
        'api_key' => preg_match('/^[a-f0-9]{64}$/', $apiKey) === 1 ? $apiKey : '',
    ];
}

function ict_report_api_url(): string
{
    $file = ict_report_login_dir() . DIRECTORY_SEPARATOR . 'asclepius_access.php';
    if (is_file($file)) {
        require_once $file;
        if (function_exists('resolve_asclepius_api_url')) {
            return resolve_asclepius_api_url();
        }
    }

    return 'https://sleutels.kvt.nl/asclepius/api.php';
}

/**
 * Zelfde formaat als de "Ticketlink kopiëren"-knop in Asclepius
 * (buildTicketShareUrl): <asclepius-map>/index.php?open=<id>.
 */
function ict_report_ticket_url_from_api_url(string $apiUrl, int $ticketId): string
{
    $base = preg_replace('~/api\.php(?:\?.*)?$~', '', $apiUrl) ?? $apiUrl;

    return rtrim($base, '/') . '/index.php?' . http_build_query(['open' => $ticketId]);
}

function ict_report_session_binding(): string
{
    $sid = session_id();
    if (!is_string($sid) || $sid === '') {
        $sid = (string) ($_COOKIE[session_name()] ?? '');
    }

    return $sid;
}

/**
 * Stateless CSRF-token: HMAC met de (HttpOnly) sessie-id als sleutel, gebonden aan de
 * gebruiker. Geen servergeheim of sessie-heropening nodig (geen extra Redis-lock).
 */
function ict_report_csrf_token(string $userEmail, ?string $sessionBinding = null): string
{
    $sessionBinding = $sessionBinding ?? ict_report_session_binding();
    $userEmail = strtolower(trim($userEmail));
    if ($sessionBinding === '' || $userEmail === '') {
        return '';
    }

    return hash_hmac('sha256', 'ict-report|' . $userEmail, $sessionBinding);
}

function ict_report_verify_csrf(string $token, string $userEmail, ?string $sessionBinding = null): bool
{
    $expected = ict_report_csrf_token($userEmail, $sessionBinding);

    return $expected !== '' && $token !== '' && hash_equals($expected, $token);
}

/**
 * Origin/Referer moeten (indien aanwezig) van dezelfde host komen.
 */
function ict_report_same_origin(array $server): bool
{
    $host = strtolower((string) ($server['HTTP_HOST'] ?? ''));
    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $header) {
        $value = (string) ($server[$header] ?? '');
        if ($value === '') {
            continue;
        }
        $parsed = parse_url($value);
        $originHost = strtolower((string) ($parsed['host'] ?? ''));
        if (isset($parsed['port'])) {
            $originHost .= ':' . $parsed['port'];
        }
        return $host !== '' && $originHost === $host;
    }

    return true;
}

/**
 * Nederlandse datum/tijd in Europe/Amsterdam, bijv. "8 oktober 2026, 11:14".
 */
function ict_report_format_datetime_nl(int $timestamp): string
{
    static $months = [1 => 'januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli',
        'augustus', 'september', 'oktober', 'november', 'december'];

    $dt = (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('Europe/Amsterdam'));

    return (int) $dt->format('j') . ' ' . $months[(int) $dt->format('n')] . ' ' . $dt->format('Y') . ', ' . $dt->format('H:i');
}

function ict_report_clean(mixed $value, int $max = ICT_REPORT_MAX_FIELD): string
{
    $text = is_scalar($value) ? (string) $value : '';
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
    $text = trim($text);
    if (mb_strlen($text) > $max) {
        $text = mb_substr($text, 0, $max) . "\n… (ingekort)";
    }

    return $text;
}

function ict_report_one_line(mixed $value, int $max = ICT_REPORT_MAX_FIELD): string
{
    return trim(preg_replace('/\s+/u', ' ', ict_report_clean($value, $max)) ?? '');
}

/**
 * Bouwt titel + beschrijving voor het ticket.
 *
 * @param array<string, mixed> $input velden uit de browser
 * @param array{email: string, name: string} $user ingelogde gebruiker
 * @return array{title: string, description: string}
 */
function ict_report_build_ticket(string $app, array $input, array $user, int $now): array
{
    $app = ict_report_one_line($app, 60) ?: 'Onbekende app';
    $month = ict_report_one_line($input['month'] ?? '', 60);
    $step = ict_report_one_line($input['step'] ?? '', 120);
    $message = ict_report_clean($input['message'] ?? '', ICT_REPORT_MAX_DETAILS);
    $summary = ict_report_one_line(strtok($message !== '' ? $message : 'Onbekende fout', "\n") ?: 'Onbekende fout', 120);
    $httpStatus = ict_report_one_line($input['http_status'] ?? '', 60);
    $response = ict_report_clean($input['response_text'] ?? '', ICT_REPORT_MAX_DETAILS);
    $pageUrl = ict_report_one_line($input['page_url'] ?? '', 300);
    $userAgent = ict_report_one_line($input['user_agent'] ?? '', 300);

    $occurredMs = $input['occurred_at'] ?? null;
    $occurredAt = is_numeric($occurredMs) ? (int) floor(((float) $occurredMs) / 1000) : 0;
    if ($occurredAt <= 0 || abs($now - $occurredAt) > 7 * 86400) {
        $occurredAt = $now;
    }

    $titleParts = [$app . ': fout'];
    if ($month !== '') {
        $titleParts[] = 'bij verversen ' . $month;
    }
    $title = implode(' ', $titleParts);
    if ($step !== '') {
        $title .= ' (' . $step . ')';
    }
    $title = mb_substr($title, 0, 180);

    $name = ict_report_one_line($user['name'] ?? '', 120);
    $email = strtolower(trim((string) ($user['email'] ?? '')));

    $lines = [
        'Automatisch gemeld via de knop "Rapporteer aan ICT" in ' . $app . '.',
        '',
        '- **Applicatie:** ' . $app,
        '- **Gebruiker:** ' . ($name !== '' ? $name . ' (' . $email . ')' : $email),
        '- **Tijdstip fout:** ' . ict_report_format_datetime_nl($occurredAt),
        '- **Gemeld op:** ' . ict_report_format_datetime_nl($now),
    ];
    if ($month !== '') {
        $lines[] = '- **Maand:** ' . $month;
    }
    if ($step !== '') {
        $lines[] = '- **Kolom/stap:** ' . $step;
    }
    if ($httpStatus !== '') {
        $lines[] = '- **HTTP-status:** ' . $httpStatus;
    }
    if ($pageUrl !== '') {
        $lines[] = '- **Pagina:** ' . $pageUrl;
    }
    if ($userAgent !== '') {
        $lines[] = '- **Browser:** ' . $userAgent;
    }
    $lines[] = '';
    $lines[] = '**Samenvatting:** ' . $summary;
    $lines[] = '';
    $lines[] = '**Volledige foutmelding:**';
    $lines[] = '```';
    $lines[] = str_replace('```', "'''", $message !== '' ? $message : 'Onbekende fout');
    $lines[] = '```';
    if ($response !== '' && !str_contains($message, $response)) {
        $lines[] = '';
        $lines[] = '**Respons van de server:**';
        $lines[] = '```';
        $lines[] = str_replace('```', "'''", $response);
        $lines[] = '```';
    }

    return [
        'title' => $title,
        'description' => implode("\n", $lines),
    ];
}

const ICT_REPORT_KEY_ERROR = 'Je persoonlijke inlogsleutel ontbreekt of is verlopen. Log opnieuw in (vernieuw de pagina of log uit en weer in) en probeer het daarna nog eens.';

/**
 * Doet de POST naar Asclepius met de persoonlijke key van de gebruiker.
 * $transport is injecteerbaar voor tests.
 *
 * @param array{email: string, name?: string, oid: string, api_key: string} $user
 * @param callable(string, array<int, string>, string): array{status: int, body: string}|null $transport
 * @return array{ok: bool, ticket_id?: int, ticket_url?: string, error?: string, status?: int}
 */
function ict_report_create_ticket(array $ticket, array $user, string $apiUrl, ?callable $transport = null): array
{
    $email = strtolower(trim((string) ($user['email'] ?? '')));
    $oid = strtolower(trim((string) ($user['oid'] ?? '')));
    $apiKey = strtolower(trim((string) ($user['api_key'] ?? '')));
    if ($email === '' || $oid === '' || $apiKey === '') {
        return ['ok' => false, 'error' => ICT_REPORT_KEY_ERROR, 'status' => 401];
    }

    $body = json_encode([
        'title' => $ticket['title'],
        'category' => ICT_REPORT_CATEGORY,
        'description' => $ticket['description'],
        'user_email' => $email,
        'oid' => $oid,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-API-Key: ' . $apiKey,
        'X-User-Oid: ' . $oid,
        'X-User-Email: ' . $email,
    ];

    $transport = $transport ?? 'ict_report_http_post';
    $response = $transport($apiUrl, $headers, (string) $body);
    $status = (int) ($response['status'] ?? 0);
    $decoded = json_decode((string) ($response['body'] ?? ''), true);

    if ($status === 401) {
        return ['ok' => false, 'error' => ICT_REPORT_KEY_ERROR, 'status' => 401];
    }

    if (!is_array($decoded) || empty($decoded['success'])) {
        $detail = '';
        if (is_array($decoded)) {
            $detail = implode(' ', array_map('strval', (array) ($decoded['errors'] ?? $decoded['error'] ?? [])));
        }
        return [
            'ok' => false,
            'status' => $status,
            'error' => 'Asclepius kon het ticket niet aanmaken'
                . ($status > 0 ? ' (HTTP ' . $status . ')' : '')
                . ($detail !== '' ? ': ' . $detail : '.'),
        ];
    }

    $ticketId = (int) ($decoded['ticket_id'] ?? 0);
    if ($ticketId <= 0) {
        return ['ok' => false, 'error' => 'Asclepius gaf geen ticketnummer terug.'];
    }

    $ticketUrl = (string) ($decoded['ticket_url'] ?? '');
    if ($ticketUrl === '' || !preg_match('~^https?://~i', $ticketUrl)) {
        $ticketUrl = ict_report_ticket_url_from_api_url($apiUrl, $ticketId);
    }

    return ['ok' => true, 'ticket_id' => $ticketId, 'ticket_url' => $ticketUrl];
}

/**
 * @param array<int, string> $headers
 * @return array{status: int, body: string}
 */
function ict_report_http_post(string $url, array $headers, string $body): array
{
    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ]);
        $responseBody = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        return ['status' => $status, 'body' => is_string($responseBody) ? $responseBody : ''];
    }

    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => implode("\r\n", $headers) . "\r\n",
        'content' => $body,
        'timeout' => 30,
        'ignore_errors' => true,
    ]]);
    $responseBody = @file_get_contents($url, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m)) {
            $status = (int) $m[1];
        }
    }

    return ['status' => $status, 'body' => is_string($responseBody) ? $responseBody : ''];
}
