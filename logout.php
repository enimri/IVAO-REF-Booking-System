<?php
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/config.php';

if (session_status() !== PHP_SESSION_NONE) {
	$_SESSION = [];
	session_destroy();
}

header('Location: ' . base_url(''));
exit;
