<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/helpers.php';

require_login();
if (!is_admin()) {
	redirect_with_message(base_url(''), 'error', 'Admins only.');
}

$pdo = db();
$event = $pdo->query('SELECT * FROM events ORDER BY id DESC LIMIT 1')->fetch();

// System Status Statistics
$systemStats = [];
$allSystemsOperational = true;

// Database Status
try {
	$pdo->query('SELECT 1')->fetch();
	$systemStats['db_status'] = 'Connected';
	$systemStats['db_status_class'] = 'success';
	$systemStats['db_status_badge'] = 'Connected';
	$systemStats['db_description'] = 'Database connection is active';
	
	// Get database version
	try {
		$dbVersion = $pdo->query('SELECT VERSION()')->fetchColumn();
		$systemStats['db_version'] = $dbVersion ? $dbVersion : 'N/A';
	} catch (Throwable $e) {
		$systemStats['db_version'] = 'N/A';
	}
	
	// Get database name
	global $DB_NAME;
	$systemStats['db_name'] = $DB_NAME ?? 'N/A';
	
	// Get table count
	try {
		if (!empty($DB_NAME)) {
			$stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ?");
			$stmt->execute([$DB_NAME]);
			$tableCount = $stmt->fetchColumn();
			$systemStats['db_tables'] = (int)$tableCount;
		} else {
			$systemStats['db_tables'] = 0;
		}
	} catch (Throwable $e) {
		$systemStats['db_tables'] = 0;
	}
	
	// Get total rows across all tables
	$totalRows = 0;
	try {
		$stmt = $pdo->prepare("SELECT table_name FROM information_schema.tables WHERE table_schema = ?");
		$stmt->execute([$DB_NAME]);
		$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
		foreach ($tables as $table) {
			try {
				$count = $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
				$totalRows += (int)$count;
			} catch (Throwable $e) {
				// Skip if table doesn't exist or error
			}
		}
	} catch (Throwable $e) {
		// Use simple count from main tables
		$totalRows = $pdo->query('SELECT (SELECT COUNT(*) FROM users) + (SELECT COUNT(*) FROM flights) + (SELECT COUNT(*) FROM bookings)')->fetchColumn() ?? 0;
	}
	$systemStats['db_total_rows'] = $totalRows;
	
	// Database Verification - Test if we can actually query real tables
	$verificationTests = [];
	$verificationPassed = 0;
	$verificationTotal = 0;
	
	// Test 1: Check if events table exists and is accessible
	$verificationTotal++;
	try {
		$eventCheck = $pdo->query('SELECT COUNT(*) FROM events')->fetchColumn();
		$verificationTests[] = ['name' => 'Events table accessible', 'status' => 'passed'];
		$verificationPassed++;
	} catch (Throwable $e) {
		$verificationTests[] = ['name' => 'Events table accessible', 'status' => 'failed', 'error' => $e->getMessage()];
	}
	
	// Test 2: Check if users table exists and is accessible
	$verificationTotal++;
	try {
		$userCheck = $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
		$verificationTests[] = ['name' => 'Users table accessible', 'status' => 'passed'];
		$verificationPassed++;
	} catch (Throwable $e) {
		$verificationTests[] = ['name' => 'Users table accessible', 'status' => 'failed', 'error' => $e->getMessage()];
	}
	
	// Test 3: Check if flights table exists and is accessible
	$verificationTotal++;
	try {
		$flightCheck = $pdo->query('SELECT COUNT(*) FROM flights')->fetchColumn();
		$verificationTests[] = ['name' => 'Flights table accessible', 'status' => 'passed'];
		$verificationPassed++;
	} catch (Throwable $e) {
		$verificationTests[] = ['name' => 'Flights table accessible', 'status' => 'failed', 'error' => $e->getMessage()];
	}
	
	// Test 4: Verify database character set
	try {
		if (!empty($DB_NAME)) {
			$verificationTotal++;
			$stmt = $pdo->prepare("SELECT DEFAULT_CHARACTER_SET_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?");
			$stmt->execute([$DB_NAME]);
			$charset = $stmt->fetchColumn();
			if (!empty($charset)) {
				$verificationTests[] = ['name' => 'Character set configured', 'status' => 'passed', 'detail' => $charset];
				$verificationPassed++;
			} else {
				$verificationTests[] = ['name' => 'Character set configured', 'status' => 'warning'];
			}
		} else {
			$verificationTests[] = ['name' => 'Character set configured', 'status' => 'skipped'];
		}
	} catch (Throwable $e) {
		$verificationTotal++;
		$verificationTests[] = ['name' => 'Character set configured', 'status' => 'failed', 'error' => $e->getMessage()];
	}
	
	// Test 5: Verify we can use prepared statements (security check)
	$verificationTotal++;
	try {
		$stmt = $pdo->prepare('SELECT 1 WHERE 1 = ?');
		$stmt->execute([1]);
		$result = $stmt->fetchColumn();
		if ($result == 1) {
			$verificationTests[] = ['name' => 'Prepared statements working', 'status' => 'passed'];
			$verificationPassed++;
		} else {
			$verificationTests[] = ['name' => 'Prepared statements working', 'status' => 'warning'];
		}
	} catch (Throwable $e) {
		$verificationTests[] = ['name' => 'Prepared statements working', 'status' => 'failed', 'error' => $e->getMessage()];
	}
	
	$systemStats['db_verification_tests'] = $verificationTests;
	$systemStats['db_verification_passed'] = $verificationPassed;
	$systemStats['db_verification_total'] = $verificationTotal;
	$systemStats['db_verification_status'] = ($verificationPassed == $verificationTotal) ? 'All tests passed' : ($verificationPassed > 0 ? $verificationPassed . '/' . $verificationTotal . ' tests passed' : 'Verification failed');
	$systemStats['db_verification_class'] = ($verificationPassed == $verificationTotal) ? 'success' : (($verificationPassed > 0) ? 'warning' : 'danger');
	
} catch (Throwable $e) {
	$systemStats['db_status'] = 'Error';
	$systemStats['db_status_class'] = 'danger';
	$systemStats['db_status_badge'] = 'Error';
	$systemStats['db_description'] = 'Database connection failed: ' . htmlspecialchars($e->getMessage());
	$systemStats['db_version'] = 'N/A';
	$systemStats['db_name'] = 'N/A';
	$systemStats['db_tables'] = 0;
	$systemStats['db_total_rows'] = 0;
	$systemStats['db_verification_tests'] = [];
	$systemStats['db_verification_passed'] = 0;
	$systemStats['db_verification_total'] = 0;
	$systemStats['db_verification_status'] = 'Unable to verify - connection failed';
	$systemStats['db_verification_class'] = 'danger';
	$allSystemsOperational = false;
}

// API Status (IVAO API)
try {
	$wellKnown = 'https://api.ivao.aero/.well-known/openid-configuration';
	$ctx = stream_context_create(['http' => ['timeout' => 5]]);
	$json = @file_get_contents($wellKnown, false, $ctx);
	if ($json !== false) {
		$data = json_decode($json, true);
		if (is_array($data) && !empty($data['authorization_endpoint'])) {
			$systemStats['api_status'] = 'Running';
			$systemStats['api_status_class'] = 'success';
			$systemStats['api_status_badge'] = 'Running';
			
			// Count endpoints
			$endpointCount = 0;
			if (!empty($data['authorization_endpoint'])) $endpointCount++;
			if (!empty($data['token_endpoint'])) $endpointCount++;
			if (!empty($data['userinfo_endpoint'])) $endpointCount++;
			$systemStats['api_endpoints'] = $endpointCount;
			$systemStats['api_status_detail'] = 'Test endpoint available';
		} else {
			$systemStats['api_status'] = 'Error';
			$systemStats['api_status_class'] = 'danger';
			$systemStats['api_status_badge'] = 'Error';
			$systemStats['api_endpoints'] = 0;
			$systemStats['api_status_detail'] = 'Invalid response';
			$allSystemsOperational = false;
		}
	} else {
		$systemStats['api_status'] = 'Error';
		$systemStats['api_status_class'] = 'danger';
		$systemStats['api_status_badge'] = 'Error';
		$systemStats['api_endpoints'] = 0;
		$systemStats['api_status_detail'] = 'Unable to reach API';
		$allSystemsOperational = false;
	}
} catch (Throwable $e) {
	$systemStats['api_status'] = 'Error';
	$systemStats['api_status_class'] = 'danger';
	$systemStats['api_status_badge'] = 'Error';
	$systemStats['api_endpoints'] = 0;
	$systemStats['api_status_detail'] = 'Error: ' . htmlspecialchars($e->getMessage());
	$allSystemsOperational = false;
}

// Website Status - Test with cURL
try {
	$testUrl = base_url('');
	$systemStats['website_url'] = $testUrl;
	
	// Test website accessibility with cURL
	$ch = curl_init($testUrl);
	curl_setopt_array($ch, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_TIMEOUT => 5,
		CURLOPT_NOBODY => true, // HEAD request only
		CURLOPT_SSL_VERIFYPEER => false,
	]);
	$result = curl_exec($ch);
	$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	
	if ($httpCode >= 200 && $httpCode < 400) {
		$systemStats['website_status'] = 'Online';
		$systemStats['website_status_class'] = 'success';
		$systemStats['website_status_badge'] = 'Online';
		$systemStats['website_http_code'] = $httpCode;
		$systemStats['website_method'] = 'cURL';
	} else {
		$systemStats['website_status'] = 'Error';
		$systemStats['website_status_class'] = 'danger';
		$systemStats['website_status_badge'] = 'Error';
		$systemStats['website_http_code'] = $httpCode ?? 'N/A';
		$systemStats['website_method'] = 'cURL';
		$allSystemsOperational = false;
	}
} catch (Throwable $e) {
	$systemStats['website_status'] = 'Error';
	$systemStats['website_status_class'] = 'danger';
	$systemStats['website_status_badge'] = 'Error';
	$systemStats['website_url'] = base_url('');
	$systemStats['website_http_code'] = 'N/A';
	$systemStats['website_method'] = 'cURL';
	$allSystemsOperational = false;
}

// Disk Space
try {
	$bytesTotal = disk_total_space(__DIR__);
	$bytesFree = disk_free_space(__DIR__);
	$bytesUsed = $bytesTotal - $bytesFree;
	$percentUsed = ($bytesUsed / $bytesTotal) * 100;
	
	$systemStats['disk_total'] = $bytesTotal;
	$systemStats['disk_used'] = $bytesUsed;
	$systemStats['disk_percent'] = round($percentUsed, 1);
	
	if ($percentUsed < 80) {
		$systemStats['disk_status'] = 'Good';
		$systemStats['disk_status_class'] = 'success';
		$systemStats['disk_status_badge'] = 'Good';
	} else {
		$systemStats['disk_status'] = 'Warning';
		$systemStats['disk_status_class'] = 'danger';
		$systemStats['disk_status_badge'] = 'Warning';
		$allSystemsOperational = false;
	}
} catch (Throwable $e) {
	$systemStats['disk_total'] = 0;
	$systemStats['disk_used'] = 0;
	$systemStats['disk_percent'] = 0;
	$systemStats['disk_status'] = 'Error';
	$systemStats['disk_status_class'] = 'danger';
	$systemStats['disk_status_badge'] = 'Error';
}

// PHP Version
$systemStats['php_version'] = PHP_VERSION;
$phpVersionParts = explode('.', PHP_VERSION);
$phpMajor = (int)($phpVersionParts[0] ?? 0);
$phpMinor = (int)($phpVersionParts[1] ?? 0);
if ($phpMajor > 7 || ($phpMajor == 7 && $phpMinor >= 4)) {
	$systemStats['php_status'] = 'Good';
	$systemStats['php_status_class'] = 'success';
	$systemStats['php_status_badge'] = 'Good';
} else {
	$systemStats['php_status'] = 'Warning';
	$systemStats['php_status_class'] = 'danger';
	$systemStats['php_status_badge'] = 'Warning';
	$allSystemsOperational = false;
}

// Server Information
$systemStats['server_software'] = $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';
$systemStats['server_name'] = $_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? 'Unknown';
$systemStats['server_status'] = 'Info';
$systemStats['server_status_class'] = 'info';
$systemStats['server_status_badge'] = 'Info';

// Last checked timestamp
$systemStats['last_checked'] = date('Y-m-d H:i:s');

// Meta page variables
$MetaPageTitle = "System Status - IVAO Middle East Division";
$MetaPageDescription = "System status dashboard showing database, API, and website status along with comprehensive system statistics.";
$MetaPageKeywords = "IVAO, system status, admin, dashboard, statistics";
$MetaPageURL = base_url('system_status.php');
$MetaPageImage = base_url('public/uploads/logo.png');

// Helper function to format bytes
function formatBytes($bytes, $precision = 2) {
	$units = ['B', 'KB', 'MB', 'GB', 'TB'];
	$bytes = max($bytes, 0);
	$pow = floor(($bytes ? log($bytes) : 0) / log(1024));
	$pow = min($pow, count($units) - 1);
	$bytes /= pow(1024, $pow);
	return round($bytes, $precision) . ' ' . $units[$pow];
}
?>
<!doctype html>
<html>
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title>System Status - IVAO Middle East Division</title>
	<link rel="icon" type="image/x-icon" href="<?php echo e(base_url('public/uploads/favicon.ico')); ?>" />
	<link rel="stylesheet" href="<?php echo e(base_url('public/assets/styles.css')); ?>" />
	<style>
		/* System Status Dashboard Styles */
		.status-banner {
			background: linear-gradient(135deg, #10b981, #059669);
			border-radius: 10px;
			padding: 24px;
			margin-bottom: 24px;
			display: flex;
			align-items: center;
			gap: 16px;
			color: white;
		}

		.status-banner-icon {
			width: 48px;
			height: 48px;
			background: rgba(255, 255, 255, 0.2);
			border-radius: 50%;
			display: flex;
			align-items: center;
			justify-content: center;
			flex-shrink: 0;
		}

		.status-banner-content {
			flex: 1;
		}

		.status-banner-title {
			font-size: 24px;
			font-weight: 700;
			margin: 0 0 4px 0;
		}

		.status-banner-subtitle {
			font-size: 14px;
			opacity: 0.9;
			margin: 0;
		}

		.status-grid {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
			gap: 20px;
			margin-bottom: 24px;
		}

		.status-card {
			background: var(--bg-card);
			border: 1px solid var(--border);
			border-radius: 10px;
			padding: 20px;
			transition: transform 0.2s, box-shadow 0.2s;
		}

		.status-card:hover {
			transform: translateY(-2px);
			box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
		}

		.status-card-header {
			display: flex;
			align-items: flex-start;
			gap: 16px;
			margin-bottom: 16px;
		}

		.status-card-icon {
			width: 48px;
			height: 48px;
			border-radius: 50%;
			display: flex;
			align-items: center;
			justify-content: center;
			flex-shrink: 0;
		}

		.status-card-icon.success {
			background: #10b981;
		}

		.status-card-icon.info {
			background: #3b82f6;
		}

		.status-card-icon.danger {
			background: #ef4444;
		}

		.status-card-icon svg {
			width: 24px;
			height: 24px;
			color: white;
		}

		.status-card-title-section {
			flex: 1;
		}

		.status-card-title {
			font-size: 18px;
			font-weight: 600;
			color: var(--text-primary);
			margin: 0 0 4px 0;
		}

		.status-card-description {
			font-size: 14px;
			color: var(--text-secondary);
			margin: 0;
		}

		.status-badge {
			display: inline-block;
			padding: 4px 12px;
			border-radius: 12px;
			font-size: 12px;
			font-weight: 600;
			text-transform: uppercase;
			letter-spacing: 0.5px;
		}

		.status-badge.success {
			background: #10b981;
			color: white;
		}

		.status-badge.info {
			background: #3b82f6;
			color: white;
		}

		.status-badge.danger {
			background: #ef4444;
			color: white;
		}

		.status-card-details {
			border-top: 1px solid var(--border);
			padding-top: 16px;
			margin-top: 16px;
		}

		.status-detail-item {
			display: flex;
			justify-content: space-between;
			align-items: center;
			padding: 8px 0;
			border-bottom: 1px solid rgba(255, 255, 255, 0.05);
		}

		.status-detail-item:last-child {
			border-bottom: none;
		}

		.status-detail-label {
			font-size: 14px;
			color: var(--text-secondary);
		}

		.status-detail-value {
			font-size: 14px;
			font-weight: 600;
			color: var(--text-primary);
		}

		@media (max-width: 768px) {
			.status-grid {
				grid-template-columns: 1fr;
			}
		}
	</style>
</head>
<body>
	<div class="page-wrapper">
		<!-- Sidebar Navigation -->
		<div class="sidebar">
		<a class="sidebar-logo" href="<?php echo e(base_url('')); ?>" title="IVAO Middle East Division">
			<img src="<?php echo e(base_url('public/uploads/logo.png')); ?>" alt="Logo" />
		</a>
		
		<nav class="sidebar-nav">
			<a href="<?php echo e(base_url('')); ?>" class="sidebar-item" title="Home">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
						<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
						<polyline points="9 22 9 12 15 12 15 22"></polyline>
					</svg>
				</a>
				
				<a href="<?php echo e(base_url('timetable.php')); ?>" class="sidebar-item" title="Timetable">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
						<path d="M6 9h12M6 12h12M6 15h12"></path>
						<rect x="4" y="3" width="16" height="18" rx="2"></rect>
					</svg>
				</a>

				<?php if (is_admin()): ?>
					<a href="<?php echo e(base_url('admin.php')); ?>" class="sidebar-item" title="Settings">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
							<circle cx="12" cy="12" r="3"></circle>
							<path d="M12 1v6m0 6v6M4.22 4.22l4.24 4.24m4.24 4.24l4.24 4.24M1 12h6m6 0h6M4.22 19.78l4.24-4.24m4.24-4.24l4.24-4.24"></path>
						</svg>
					</a>
				<?php endif; ?>

				<?php if (is_private_admin()): ?>
					<a href="<?php echo e(base_url('private_admin.php')); ?>" class="sidebar-item" title="Private Slots">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
							<path d="M12 1v6m0 6v6M4.22 4.22l4.24 4.24m4.24 4.24l4.24 4.24M1 12h6m6 0h6M4.22 19.78l4.24-4.24m4.24-4.24l4.24-4.24"></path>
						</svg>
					</a>
				<?php endif; ?>
			</nav>
		</div>

		<!-- Main Content Area -->
		<div class="main-content">
			<!-- Navbar -->
			<nav class="navbar">
				<div class="nav-left">
					<button class="hamburger-menu" onclick="toggleSidebar()" aria-label="Toggle Menu">
						<span></span>
						<span></span>
						<span></span>
					</button>
					<button class="back-button" onclick="history.back()" aria-label="Go Back">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
							<path d="M19 12H5M12 19l-7-7 7-7"></path>
						</svg>
					</button>
					<h2 class="navbar-title">System Status</h2>
					<div class="nav-search">
						<input type="text" placeholder="Search..." />
					</div>
				</div>
				
				<div class="nav-right">
					<?php $user = current_user(); ?>
					<span class="nav-right-item" title="Profile">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
							<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
							<circle cx="12" cy="7" r="4"></circle>
						</svg>
					</span>
					<?php if (!empty($user)): ?>
						<span class="nav-username" title="<?php echo e($user['name'] ?? $user['vid']); ?>">
							Welcome, <?php echo e($user['name'] ?? $user['vid']); ?>
						</span>
					<?php endif; ?>
					<a class="btn secondary btn-small" href="<?php echo e(base_url('logout.php')); ?>" title="Logout">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 0.25rem;">
							<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
							<polyline points="16 17 21 12 16 7"></polyline>
							<line x1="21" y1="12" x2="9" y2="12"></line>
						</svg>
						Logout
					</a>
				</div>
			</nav>

			<!-- Content Area -->
			<div class="content-area">
				<div class="container full-width">
					<?php flash(); ?>
					
					<!-- All Systems Operational Banner -->
					<?php if ($allSystemsOperational): ?>
					<div class="status-banner">
						<div class="status-banner-icon">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
								<polyline points="20 6 9 17 4 12"></polyline>
							</svg>
						</div>
						<div class="status-banner-content">
							<h3 class="status-banner-title">All Systems Operational</h3>
							<p class="status-banner-subtitle">Last checked: <?php echo e($systemStats['last_checked']); ?></p>
						</div>
					</div>
					<?php else: ?>
					<div class="status-banner" style="background: linear-gradient(135deg, #ef4444, #dc2626);">
						<div class="status-banner-icon">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
								<circle cx="12" cy="12" r="10"></circle>
								<line x1="12" y1="8" x2="12" y2="12"></line>
								<line x1="12" y1="16" x2="12.01" y2="16"></line>
							</svg>
						</div>
						<div class="status-banner-content">
							<h3 class="status-banner-title">Some Systems Have Issues</h3>
							<p class="status-banner-subtitle">Last checked: <?php echo e($systemStats['last_checked']); ?></p>
						</div>
					</div>
					<?php endif; ?>

					<!-- Status Cards Grid -->
					<div class="status-grid">
						<!-- Database Card -->
						<div class="status-card">
							<div class="status-card-header">
								<div class="status-card-icon <?php echo e($systemStats['db_status_class'] ?? 'success'); ?>">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
										<ellipse cx="12" cy="5" rx="9" ry="3"></ellipse>
										<path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"></path>
										<path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"></path>
									</svg>
								</div>
								<div class="status-card-title-section">
									<h3 class="status-card-title">Database</h3>
									<p class="status-card-description"><?php echo e($systemStats['db_description'] ?? 'Database connection status'); ?></p>
								</div>
								<span class="status-badge <?php echo e($systemStats['db_status_class'] ?? 'success'); ?>">
									<?php echo e($systemStats['db_status_badge'] ?? 'Connected'); ?>
								</span>
							</div>
							<div class="status-card-details">
								<div class="status-detail-item">
									<span class="status-detail-label">Version:</span>
									<span class="status-detail-value"><?php echo e(!empty($systemStats['db_version']) ? $systemStats['db_version'] : 'N/A'); ?></span>
								</div>
								<div class="status-detail-item">
									<span class="status-detail-label">Name:</span>
									<span class="status-detail-value"><?php echo e($systemStats['db_name'] ?? 'N/A'); ?></span>
								</div>
								<div class="status-detail-item">
									<span class="status-detail-label">Tables:</span>
									<span class="status-detail-value"><?php echo e($systemStats['db_tables'] ?? 0); ?></span>
								</div>
								<div class="status-detail-item">
									<span class="status-detail-label">Total rows:</span>
									<span class="status-detail-value"><?php echo e($systemStats['db_total_rows'] ?? 0); ?></span>
								</div>
								<div class="status-detail-item" style="border-top: 1px solid var(--border); margin-top: 8px; padding-top: 12px;">
									<span class="status-detail-label">Verification:</span>
									<span class="status-detail-value <?php echo e($systemStats['db_verification_class'] ?? 'success'); ?>" style="color: <?php 
										$vClass = $systemStats['db_verification_class'] ?? 'success';
										echo $vClass === 'success' ? '#10b981' : ($vClass === 'warning' ? '#f59e0b' : '#ef4444');
									?>;">
										<?php echo e($systemStats['db_verification_status'] ?? 'N/A'); ?>
									</span>
								</div>
								<?php if (!empty($systemStats['db_verification_tests']) && is_array($systemStats['db_verification_tests'])): ?>
									<div style="margin-top: 8px; padding-top: 8px; border-top: 1px solid var(--border);">
										<details style="cursor: pointer;">
											<summary style="font-size: 12px; color: var(--text-secondary); margin-bottom: 8px;">View test details</summary>
											<div style="font-size: 11px; color: var(--text-secondary); margin-top: 8px;">
												<?php foreach ($systemStats['db_verification_tests'] as $test): ?>
													<div style="padding: 4px 0; display: flex; align-items: center; gap: 8px;">
														<span style="color: <?php 
															echo $test['status'] === 'passed' ? '#10b981' : ($test['status'] === 'warning' ? '#f59e0b' : '#ef4444');
														?>;">
															<?php echo $test['status'] === 'passed' ? '✓' : ($test['status'] === 'warning' ? '⚠' : '✗'); ?>
														</span>
														<span><?php echo e($test['name']); ?></span>
														<?php if (!empty($test['detail'])): ?>
															<span style="color: var(--text-secondary);">(<?php echo e($test['detail']); ?>)</span>
														<?php endif; ?>
													</div>
												<?php endforeach; ?>
											</div>
										</details>
									</div>
								<?php endif; ?>
							</div>
						</div>

						<!-- API Card -->
						<div class="status-card">
							<div class="status-card-header">
								<div class="status-card-icon <?php echo e($systemStats['api_status_class'] ?? 'success'); ?>">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
										<path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
									</svg>
								</div>
								<div class="status-card-title-section">
									<h3 class="status-card-title">API</h3>
									<p class="status-card-description">API is accessible</p>
								</div>
								<span class="status-badge <?php echo e($systemStats['api_status_class'] ?? 'success'); ?>">
									<?php echo e($systemStats['api_status_badge'] ?? 'Running'); ?>
								</span>
							</div>
							<div class="status-card-details">
								<div class="status-detail-item">
									<span class="status-detail-label">Endpoints:</span>
									<span class="status-detail-value"><?php echo e($systemStats['api_endpoints'] ?? 0); ?></span>
								</div>
								<div class="status-detail-item">
									<span class="status-detail-label">Status:</span>
									<span class="status-detail-value"><?php echo e($systemStats['api_status_detail'] ?? 'Test endpoint available'); ?></span>
								</div>
							</div>
						</div>

						<!-- Website Card -->
						<div class="status-card">
							<div class="status-card-header">
								<div class="status-card-icon <?php echo e($systemStats['website_status_class'] ?? 'success'); ?>">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
										<circle cx="12" cy="12" r="10"></circle>
										<line x1="2" y1="12" x2="22" y2="12"></line>
										<path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path>
									</svg>
								</div>
								<div class="status-card-title-section">
									<h3 class="status-card-title">Website</h3>
									<p class="status-card-description">Website is accessible</p>
								</div>
								<span class="status-badge <?php echo e($systemStats['website_status_class'] ?? 'success'); ?>">
									<?php echo e($systemStats['website_status_badge'] ?? 'Online'); ?>
								</span>
							</div>
							<div class="status-card-details">
								<div class="status-detail-item">
									<span class="status-detail-label">Http code:</span>
									<span class="status-detail-value"><?php echo e($systemStats['website_http_code'] ?? 'N/A'); ?></span>
								</div>
								<div class="status-detail-item">
									<span class="status-detail-label">Url:</span>
									<span class="status-detail-value" style="font-size: 12px; word-break: break-all;"><?php echo e($systemStats['website_url'] ?? 'N/A'); ?></span>
								</div>
								<div class="status-detail-item">
									<span class="status-detail-label">Method:</span>
									<span class="status-detail-value"><?php echo e($systemStats['website_method'] ?? 'cURL'); ?></span>
								</div>
							</div>
						</div>

						<!-- Disk Space Card -->
						<div class="status-card">
							<div class="status-card-header">
								<div class="status-card-icon <?php echo e($systemStats['disk_status_class'] ?? 'success'); ?>">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
										<line x1="6" y1="3" x2="6" y2="15"></line>
										<path d="M18 11V5a2 2 0 0 0-2-2h-1"></path>
										<path d="M14 11V9a2 2 0 0 1 2-2h2v4h2a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2h-8a2 2 0 0 1-2-2z"></path>
										<path d="M4 15a2 2 0 0 0-2 2v1a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2v-1a2 2 0 0 0-2-2z"></path>
									</svg>
								</div>
								<div class="status-card-title-section">
									<h3 class="status-card-title">Disk Space</h3>
									<p class="status-card-description"><?php echo e($systemStats['disk_percent'] ?? 0); ?>% disk space used</p>
								</div>
								<span class="status-badge <?php echo e($systemStats['disk_status_class'] ?? 'success'); ?>">
									<?php echo e($systemStats['disk_status_badge'] ?? 'Good'); ?>
								</span>
							</div>
							<div class="status-card-details">
								<div class="status-detail-item">
									<span class="status-detail-label">Total:</span>
									<span class="status-detail-value"><?php echo formatBytes($systemStats['disk_total'] ?? 0); ?></span>
								</div>
								<div class="status-detail-item">
									<span class="status-detail-label">Used:</span>
									<span class="status-detail-value"><?php echo formatBytes($systemStats['disk_used'] ?? 0); ?></span>
								</div>
							</div>
						</div>

						<!-- PHP Version Card -->
						<div class="status-card">
							<div class="status-card-header">
								<div class="status-card-icon <?php echo e($systemStats['php_status_class'] ?? 'success'); ?>">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
										<polyline points="16 18 22 12 16 6"></polyline>
										<polyline points="8 6 2 12 8 18"></polyline>
									</svg>
								</div>
								<div class="status-card-title-section">
									<h3 class="status-card-title">PHP Version</h3>
									<p class="status-card-description">PHP <?php echo e($systemStats['php_version'] ?? 'N/A'); ?></p>
								</div>
								<span class="status-badge <?php echo e($systemStats['php_status_class'] ?? 'success'); ?>">
									<?php echo e($systemStats['php_status_badge'] ?? 'Good'); ?>
								</span>
							</div>
							<div class="status-card-details">
								<div class="status-detail-item">
									<span class="status-detail-label">Version:</span>
									<span class="status-detail-value"><?php echo e($systemStats['php_version'] ?? 'N/A'); ?></span>
								</div>
								<div class="status-detail-item">
									<span class="status-detail-label">Recommended:</span>
									<span class="status-detail-value">7.4.0 or higher</span>
								</div>
							</div>
						</div>

						<!-- Server Card -->
						<div class="status-card">
							<div class="status-card-header">
								<div class="status-card-icon <?php echo e($systemStats['server_status_class'] ?? 'info'); ?>">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
										<rect x="2" y="3" width="20" height="4" rx="1"></rect>
										<rect x="2" y="7" width="20" height="4" rx="1"></rect>
										<rect x="2" y="11" width="20" height="4" rx="1"></rect>
										<rect x="2" y="15" width="20" height="4" rx="1"></rect>
										<rect x="2" y="19" width="20" height="4" rx="1"></rect>
									</svg>
								</div>
								<div class="status-card-title-section">
									<h3 class="status-card-title">Server</h3>
									<p class="status-card-description">Server information</p>
								</div>
								<span class="status-badge <?php echo e($systemStats['server_status_class'] ?? 'info'); ?>">
									<?php echo e($systemStats['server_status_badge'] ?? 'Info'); ?>
								</span>
							</div>
							<div class="status-card-details">
								<div class="status-detail-item">
									<span class="status-detail-label">Server software:</span>
									<span class="status-detail-value" style="font-size: 12px;"><?php echo e($systemStats['server_software'] ?? 'Unknown'); ?></span>
								</div>
								<div class="status-detail-item">
									<span class="status-detail-label">Server name:</span>
									<span class="status-detail-value" style="font-size: 12px;"><?php echo e($systemStats['server_name'] ?? 'Unknown'); ?></span>
								</div>
							</div>
						</div>
					</div>

					<!-- Back to Admin Button -->
					<div style="text-align: center; margin-top: 24px;">
						<a class="btn secondary" href="<?php echo e(base_url('admin.php')); ?>">
							<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 8px;">
								<path d="M19 12H5M12 19l-7-7 7-7"></path>
							</svg>
							Back to Admin
						</a>
					</div>
				</div>
			</div>
		</div>
	</div>
	<?php include __DIR__ . '/includes/footer.php'; ?>
	<script>
		function toggleSidebar() {
			const sidebar = document.querySelector('.sidebar');
			const hamburger = document.querySelector('.hamburger-menu');
			sidebar.classList.toggle('active');
			hamburger.classList.toggle('active');
		}
		
		// Close sidebar when clicking outside on mobile
		document.addEventListener('click', function(event) {
			const sidebar = document.querySelector('.sidebar');
			const hamburger = document.querySelector('.hamburger-menu');
			const isClickInside = sidebar.contains(event.target) || hamburger.contains(event.target);
			
			if (!isClickInside && sidebar.classList.contains('active') && window.innerWidth <= 1024) {
				sidebar.classList.remove('active');
				hamburger.classList.remove('active');
			}
		});
	</script>
</body>
</html>
