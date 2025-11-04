<?php
// Configuration for DB and IVAO OAuth
// Copy this file to config.php and fill in your credentials

declare(strict_types=1);

// Start session for auth state
if (session_status() === PHP_SESSION_NONE) {
	session_start();
}

/*==========================================================================
IVAO connection
==========================================================================*/
// IVAO API compatibility constants (not required for this app, but provided for compatibility)
if (!defined('cookie_name')) {
	define('cookie_name', 'ivao_tokens');
}
if (!defined('client_id')) {
	define('client_id', 'YOUR_IVAO_CLIENT_ID_HERE');
}
if (!defined('client_secret')) {
	define('client_secret', 'YOUR_IVAO_CLIENT_SECRET_HERE');
}
if (!defined('redirect_uri')) {
	define('redirect_uri', 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
}

// Database credentials (phpMyAdmin / MySQL)
$DB_HOST = 'localhost';
$DB_PORT = 3306;
$DB_NAME = 'your_database_name';
$DB_USER = 'your_database_user';
$DB_PASS = 'your_database_password';

// PDO instance factory
function db(): PDO {
	static $pdo = null;
	global $DB_HOST, $DB_PORT, $DB_NAME, $DB_USER, $DB_PASS;
	if ($pdo instanceof PDO) {
		return $pdo;
	}

	$dsn = "mysql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset=utf8mb4";
	$options = [
		PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		PDO::ATTR_EMULATE_PREPARES => false,
		PDO::ATTR_PERSISTENT => false, // Don't use persistent connections
		PDO::ATTR_TIMEOUT => 5, // 5 second timeout
	];

	// Retry connection up to 3 times
	$maxRetries = 3;
	$retryDelay = 1; // seconds
	$lastError = null;

	for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
		try {
			$pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
			return $pdo;
		} catch (PDOException $e) {
			$lastError = $e;
			if ($attempt < $maxRetries) {
				sleep($retryDelay);
			}
		}
	}

	// If all retries failed, log and show user-friendly error
	error_log("Database connection failed after {$maxRetries} attempts: " . $lastError->getMessage());

	// Show user-friendly error page instead of fatal error
	if (!headers_sent()) {
		http_response_code(503);
		header('Content-Type: text/html; charset=utf-8');
	}

	die('<!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8">
	<title>Service Temporarily Unavailable</title>
	<style>
		body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; background: #0f1117; color: #f8fafc; }
		.error-box { text-align: center; padding: 2rem; max-width: 500px; }
		h1 { color: #ef4444; margin-bottom: 1rem; }
		p { color: #cbd5e1; line-height: 1.6; }
	</style>
</head>
<body>
	<div class="error-box">
		<h1>Service Temporarily Unavailable</h1>
		<p>The database service is currently unavailable. Please try again in a few moments.</p>
		<p>If the problem persists, please contact the administrator.</p>
	</div>
</body>
</html>');
}

// IVAO OpenID Discovery helper (fetch endpoints dynamically)
function ivao_endpoints(): array {
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $wellKnown = 'https://api.ivao.aero/.well-known/openid-configuration';
    $ctx = stream_context_create(['http' => ['timeout' => 5]]);
    $json = file_get_contents($wellKnown, false, $ctx);
    if ($json === false) {
        throw new Exception('OpenID discovery failed');
    }
    $data = json_decode($json, true);
    if (!is_array($data) || empty($data['authorization_endpoint']) || empty($data['token_endpoint']) || empty($data['userinfo_endpoint'])) {
        throw new Exception('OpenID discovery returned invalid data');
    }
    $cache = [
        'authorization_endpoint' => $data['authorization_endpoint'],
        'token_endpoint' => $data['token_endpoint'],
        'userinfo_endpoint' => $data['userinfo_endpoint'],
    ];
    return $cache;
}

// Base URL detection with env override for production
$envBase = getenv('APP_BASE_URL') ?: '';
if ($envBase !== '') {
	$BASE_URL = rtrim($envBase, '/');
} else {
	$BASE_URL = rtrim((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . dirname($_SERVER['SCRIPT_NAME'] ?? '/') , '/');
}

function base_url(string $path = ''): string {
	global $BASE_URL;
	return rtrim($BASE_URL, '/') . '/' . ltrim($path, '/');
}

function require_login(): void {
	if (empty($_SESSION['user'])) {
		header('Location: ' . base_url('login.php'));
		exit;
	}
}

function current_user(): array {
	return $_SESSION['user'] ?? [];
}

function is_role(string $role): bool {
	return in_array($role, $_SESSION['roles'] ?? [], true);
}

function is_admin(): bool {
    if (is_role('admin')) { return true; }
    $isStaff = (int)($_SESSION['user']['is_staff'] ?? 0);
    if ($isStaff === 1) { return true; }
    // Fallback: fetch from DB if not present in session
    $vid = (string)($_SESSION['user']['vid'] ?? '');
    if ($vid === '') { return false; }
    try {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT is_staff FROM users WHERE vid = ?');
        $stmt->execute([$vid]);
        $row = $stmt->fetch();
        $isStaffDb = (int)($row['is_staff'] ?? 0);
        if ($isStaffDb === 1) {
            $_SESSION['user']['is_staff'] = 1;
            return true;
        }
    } catch (Throwable $e) {
        // ignore and treat as non-admin
    }
    return false;
}

function is_private_admin(): bool {
	return is_role('private_admin');
}

function csrf_token(): string {
	if (empty($_SESSION['csrf'])) {
		$_SESSION['csrf'] = bin2hex(random_bytes(16));
	}
	return $_SESSION['csrf'];
}

function csrf_validate(string $token): bool {
	return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

// Meta page variables (can be overridden per page)
$MetaPageTitle = "";
$MetaPageDescription = "";
$MetaPageKeywords = "";
$MetaPageURL = "";
$MetaPageImage = "";

