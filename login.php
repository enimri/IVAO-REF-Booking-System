<?php
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/config.php';

$eps = ivao_endpoints();

$state = bin2hex(random_bytes(16));
$_SESSION['oauth2_state'] = $state;

// Build redirect URI from constant (domain/root) + callback path
$redirect = rtrim(defined('redirect_uri') ? redirect_uri : base_url(''), '/') . '/oauth_callback.php';

$params = http_build_query([
	'client_id' => client_id,
	'redirect_uri' => $redirect,
	'response_type' => 'code',
	'scope' => 'openid profile email',
	'state' => $state,
]);

header('Location: ' . $eps['authorization_endpoint'] . '?' . $params);
exit;
