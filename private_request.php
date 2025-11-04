<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/helpers.php';

require_login();
$pdo = db();

// Ensure private_slots_enabled column exists
try {
	$pdo->exec('ALTER TABLE events ADD COLUMN private_slots_enabled TINYINT(1) NOT NULL DEFAULT 0');
} catch (Throwable $e) {
	// Column might already exist, ignore
}

// Get event status
$event = $pdo->query('SELECT is_open, private_slots_enabled FROM events ORDER BY id DESC LIMIT 1')->fetch();
$isOpen = !empty($event['is_open']);
$privateSlotsEnabled = !empty($event['private_slots_enabled'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (!csrf_validate($_POST['csrf'] ?? '')) {
		redirect_with_message(base_url('private_request.php'), 'error', 'Invalid CSRF token.');
	}
	
	// Check if private slots are enabled
	if (!$privateSlotsEnabled) {
		redirect_with_message(base_url('private_request.php'), 'error', 'Private slot requests are currently disabled by administrators.');
	}
	
	// Check if system is open for booking
	if (!$isOpen) {
		redirect_with_message(base_url('private_request.php'), 'error', 'Private slot requests are currently not available. The system is closed.');
	}
	
	$vid = current_user()['vid'] ?? '';
	$flight_number = strtoupper(trim($_POST['flight_number'] ?? ''));
	$aircraft_type = strtoupper(trim($_POST['aircraft_type'] ?? ''));
	$origin_icao = strtoupper(trim($_POST['origin_icao'] ?? ''));
	$destination_icao = strtoupper(trim($_POST['destination_icao'] ?? ''));
	$departure_time_zulu = trim($_POST['departure_time'] ?? '');

	if (!is_valid_flight_number_private($flight_number)) {
		redirect_with_message(base_url('private_request.php'), 'error', 'Flight number max 6 alnum.');
	}
	if (!is_valid_icao($origin_icao) || !is_valid_icao($destination_icao)) {
		redirect_with_message(base_url('private_request.php'), 'error', 'ICAO must be 4 letters.');
	}
	if (!is_valid_time_zulu($departure_time_zulu)) {
		redirect_with_message(base_url('private_request.php'), 'error', 'Time must be HH:MM Z.');
	}
	$stmt = $pdo->prepare('INSERT INTO private_slot_requests (vid, flight_number, aircraft_type, origin_icao, destination_icao, departure_time_zulu) VALUES (?,?,?,?,?,?)');
	$stmt->execute([$vid,$flight_number,$aircraft_type,$origin_icao,$destination_icao,$departure_time_zulu]);
	redirect_with_message(base_url('timetable.php?tab=private'), 'success', 'Private slot request submitted.');
}

// Meta page variables
$MetaPageTitle = "Request Private Slot - IVAO Middle East Division";
$MetaPageDescription = "Submit a request for a private flight slot. Customize your flight details including departure, destination, and departure time.";
$MetaPageKeywords = "IVAO, private slot, private flight request, custom flight slot";
$MetaPageURL = base_url('private_request.php');
$MetaPageImage = base_url('public/uploads/logo.png');
?>
<!doctype html>
<html>
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title>Request Private Slot - IVAO Middle East Division</title>
	<link rel="icon" type="image/x-icon" href="<?php echo e(base_url('public/uploads/favicon.ico')); ?>" />
	<link rel="stylesheet" href="<?php echo e(base_url('public/assets/styles.css')); ?>" />
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
				
				<a href="<?php echo e(base_url('timetable.php')); ?>" class="sidebar-item active" title="Timetable">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
						<path d="M6 9h12M6 12h12M6 15h12"></path>
						<rect x="4" y="3" width="16" height="18" rx="2"></rect>
					</svg>
				</a>

				<?php if (is_admin()): ?>
					<a href="<?php echo e(base_url('admin.php')); ?>" class="sidebar-item" title="Admin">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
							<circle cx="12" cy="12" r="1"></circle>
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
					<h2 class="navbar-title">Private Slot Request</h2>
					<div class="nav-search">
						<input type="text" placeholder="Search..." />
					</div>
				</div>
				
				<div class="nav-right">
					<span class="nav-right-item" title="Profile">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
							<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
							<circle cx="12" cy="7" r="4"></circle>
						</svg>
					</span>
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
					<?php if (!$privateSlotsEnabled): ?>
						<div class="card" style="max-width: 600px; margin: 0 auto;">
							<div class="card-header">
								<h2>Private Slots Disabled</h2>
							</div>
							<div style="padding: 1.5rem; text-align: center;">
								<p style="color: var(--text-secondary); margin-bottom: 1.5rem;">Private slot requests are currently disabled by administrators.</p>
								<a class="btn secondary btn-small" href="<?php echo e(base_url('timetable.php?tab=private')); ?>">
									<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
										<line x1="19" y1="12" x2="5" y2="12"></line>
										<polyline points="12 19 5 12 12 5"></polyline>
									</svg>
									Back to Timetable
								</a>
							</div>
						</div>
					<?php elseif (!$isOpen): ?>
						<div class="card" style="max-width: 600px; margin: 0 auto;">
							<div class="card-header">
								<h2>System Closed</h2>
							</div>
							<div style="padding: 1.5rem; text-align: center;">
								<p style="color: var(--text-secondary); margin-bottom: 1.5rem;">Private slot requests are currently not available. The booking system is closed.</p>
								<a class="btn secondary btn-small" href="<?php echo e(base_url('timetable.php?tab=private')); ?>">
									<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
										<line x1="19" y1="12" x2="5" y2="12"></line>
										<polyline points="12 19 5 12 12 5"></polyline>
									</svg>
									Back to Timetable
								</a>
							</div>
						</div>
					<?php else: ?>
						<div class="card" style="max-width: 600px; margin: 0 auto;">
							<div class="card-header">
								<h2>Request a Private Slot</h2>
							</div>
							<div style="margin-bottom: 1rem;">
								<a class="btn secondary btn-small" href="<?php echo e(base_url('timetable.php?tab=private')); ?>" style="font-size: 0.8125rem; padding: 0.3125rem 0.625rem; opacity: 0.85;">
									<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
										<line x1="19" y1="12" x2="5" y2="12"></line>
										<polyline points="12 19 5 12 12 5"></polyline>
									</svg>
									Back to Timetable
								</a>
							</div>
							<form method="post" class="form-grid">
								<input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>" />
								<div class="form-group">
									<label class="label">Flight Number (max 6 characters)</label>
									<input class="input" name="flight_number" maxlength="6" placeholder="e.g., TEST01" required />
								</div>
								<div class="form-group">
									<label class="label">Aircraft Type</label>
									<input class="input" name="aircraft_type" placeholder="e.g., B737" required />
								</div>
								<div class="form-group">
									<label class="label">Departure Airport (ICAO)</label>
									<input class="input" name="origin_icao" maxlength="4" placeholder="e.g., OMDB" required />
								</div>
								<div class="form-group">
									<label class="label">Destination Airport (ICAO)</label>
									<input class="input" name="destination_icao" maxlength="4" placeholder="e.g., OMDW" required />
								</div>
								<div class="form-group full">
									<label class="label">Departure Time (Zulu HH:MM)</label>
									<input class="input" name="departure_time" placeholder="e.g., 14:30" required />
								</div>
								<div class="form-group full">
									<button class="btn primary btn-block" type="submit">
										<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
											<polyline points="12 5 19 12 12 19"></polyline>
											<polyline points="19 12 7 12"></polyline>
										</svg>
										Submit Request
									</button>
								</div>
							</form>
						</div>
					<?php endif; ?>
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
