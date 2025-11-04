<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/helpers.php';

	if (!isset($_GET['state']) || !isset($_SESSION['oauth2_state']) || !hash_equals($_SESSION['oauth2_state'], (string)$_GET['state'])) {
	redirect_with_message(base_url(''), 'error', 'Invalid OAuth state.');
}
unset($_SESSION['oauth2_state']);

if (!isset($_GET['code'])) {
	redirect_with_message(base_url(''), 'error', 'Login failed: missing code.');
}

$code = (string)$_GET['code'];

// Use discovery for endpoints
$eps = ivao_endpoints();

// Exchange code for token
$ch = curl_init($eps['token_endpoint']);
$redirect = rtrim(defined('redirect_uri') ? redirect_uri : base_url(''), '/') . '/oauth_callback.php';
$postFields = http_build_query([
	'grant_type' => 'authorization_code',
	'code' => $code,
	'client_id' => client_id,
	'client_secret' => client_secret,
	'redirect_uri' => $redirect,
]);
curl_setopt_array($ch, [
	CURLOPT_RETURNTRANSFER => true,
	CURLOPT_POST => true,
	CURLOPT_POSTFIELDS => $postFields,
	CURLOPT_HTTPHEADER => [
		'Content-Type: application/x-www-form-urlencoded',
		'Accept: application/json',
	],
]);
$tokenResponse = curl_exec($ch);
if ($tokenResponse === false) {
	$err = curl_error($ch);
	curl_close($ch);
	redirect_with_message(base_url(''), 'error', 'Login failed: token request error. ' . ($err ?: ''));
}
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($httpCode !== 200) {
	// Try to surface server response for easier debugging
	$msg = 'Login failed: token response invalid.';
	if (!empty($tokenResponse)) {
		$snippet = substr($tokenResponse, 0, 200);
		$msg .= ' ' . $snippet;
	}
	redirect_with_message(base_url(''), 'error', $msg);
}
$tokens = json_decode($tokenResponse, true);
$accessToken = $tokens['access_token'] ?? '';
if ($accessToken === '') {
	redirect_with_message(base_url(''), 'error', 'Login failed: missing access token.');
}

// Fetch userinfo via discovery
$ch = curl_init($eps['userinfo_endpoint']);
curl_setopt_array($ch, [
	CURLOPT_RETURNTRANSFER => true,
	CURLOPT_HTTPHEADER => [
		'Authorization: Bearer ' . $accessToken,
		'Accept: application/json',
	],
]);
$userinfoResponse = curl_exec($ch);
if ($userinfoResponse === false) {
	$err = curl_error($ch);
	curl_close($ch);
	redirect_with_message(base_url(''), 'error', 'Login failed: userinfo error. ' . ($err ?: ''));
}
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($httpCode !== 200) {
	$snippet = substr((string)$userinfoResponse, 0, 200);
	redirect_with_message(base_url(''), 'error', 'Login failed: userinfo invalid. ' . $snippet);
}
$userinfo = json_decode($userinfoResponse, true);

$candidateVids = [
    $userinfo['ivao_vid'] ?? null,
    $userinfo['ivao_id'] ?? null,
    $userinfo['vid'] ?? null,
    $userinfo['id'] ?? null,
    $userinfo['preferred_username'] ?? null,
    $userinfo['sub'] ?? null, // often not numeric; kept as last-resort candidate
    $userinfo['nickname'] ?? null,
];
$vid = '';
foreach ($candidateVids as $val) {
    if (!is_string($val)) { continue; }
    $val = trim($val);
    if ($val === '') { continue; }
    if (preg_match('/^\d+$/', $val)) { $vid = $val; break; }
    if (preg_match('/(\d{3,10})/', $val, $m)) { $vid = $m[1]; break; }
}
// Fallback: recursively search entire payload for a digit sequence
if ($vid === '' && is_array($userinfo)) {
    $stack = [$userinfo];
    while ($vid === '' && ($curr = array_pop($stack))) {
        foreach ($curr as $v) {
            if (is_array($v)) { $stack[] = $v; continue; }
            if (is_string($v) || is_numeric($v)) {
                $s = trim((string)$v);
                if ($s === '') { continue; }
                if (preg_match('/^\d+$/', $s)) { $vid = $s; break 2; }
                if (preg_match('/(\d{3,10})/', $s, $m)) { $vid = $m[1]; break 2; }
            }
        }
    }
}
$fullName = '';
$firstName = is_string($userinfo['firstName'] ?? null) ? trim($userinfo['firstName']) : '';
$lastName = is_string($userinfo['lastName'] ?? null) ? trim($userinfo['lastName']) : '';
if ($firstName !== '' || $lastName !== '') {
    $fullName = trim($firstName . ' ' . $lastName);
}
if ($fullName === '') {
    $given = is_string($userinfo['given_name'] ?? null) ? trim($userinfo['given_name']) : '';
    $family = is_string($userinfo['family_name'] ?? null) ? trim($userinfo['family_name']) : '';
    if ($given !== '' || $family !== '') {
        $fullName = trim($given . ' ' . $family);
    }
}
if ($fullName === '') {
    $fullName = trim((string)($userinfo['name'] ?? ''));
}
$name = $fullName;
$email = (string)($userinfo['email'] ?? '');
if ($vid === '') {
    $keys = is_array($userinfo) ? implode(',', array_keys($userinfo)) : '';
    redirect_with_message(base_url(''), 'error', 'Login failed: missing numeric VID. keys=' . $keys);
}

// Upsert user
$pdo = db();
$pdo->beginTransaction();
try {
    // Do not overwrite existing name/email; DB is source of truth after first insert
    $stmt = $pdo->prepare('INSERT INTO users (vid, name, email, is_staff) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE vid = vid');
    $stmt->execute([$vid, $name !== '' ? $name : $vid, $email, 0]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    redirect_with_message(base_url(''), 'error', 'Login failed: DB error.');
}

// Load roles
$stmt = $pdo->prepare('SELECT role FROM user_roles WHERE vid = ?');
$stmt->execute([$vid]);
$roles = array_map(fn($r) => $r['role'], $stmt->fetchAll());

// Load canonical user data from DB to session
$stmt = $pdo->prepare('SELECT vid, name, email, is_staff FROM users WHERE vid = ?');
$stmt->execute([$vid]);
$userRow = $stmt->fetch();
$_SESSION['user'] = [
    'vid' => (string)($userRow['vid'] ?? $vid),
    'name' => (string)($userRow['name'] ?? $name),
    'email' => (string)($userRow['email'] ?? $email),
    'is_staff' => (int)($userRow['is_staff'] ?? 0),
];
$_SESSION['roles'] = $roles;

redirect_with_message(base_url(''), 'success', 'Logged in successfully.');
