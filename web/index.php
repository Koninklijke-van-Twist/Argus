<?php
require_once __DIR__ . '/json_guard.php';
argus_configure_error_display();

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';

$query = trim((string) ($_SERVER['QUERY_STRING'] ?? ''));
$target = 'maanden.php' . ($query !== '' ? ('?' . $query) : '');

header('Location: ' . $target, true, 302);
exit;
