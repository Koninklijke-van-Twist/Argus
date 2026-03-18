<?php
require_once __DIR__ . '/logincheck.php';

$query = trim((string) ($_SERVER['QUERY_STRING'] ?? ''));
$target = 'maanden.php' . ($query !== '' ? ('?' . $query) : '');

header('Location: ' . $target, true, 302);
exit;
