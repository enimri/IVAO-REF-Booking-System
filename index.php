<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/helpers.php';

$pdo = db();
$event = $pdo->query('SELECT * FROM events ORDER BY id DESC LIMIT 1')->fetch();
$now = new DateTime('now', new DateTimeZone('UTC'));
$month = $now->format('F');
$day = $now->format('d');

// Get flight stats
$depCount = $pdo->query('SELECT COUNT(*) FROM flights WHERE category="departure"')->fetchColumn();
$arrCount = $pdo->query('SELECT COUNT(*) FROM flights WHERE category="arrival"')->fetchColumn();
$privateCount = $pdo->query('SELECT COUNT(*) FROM flights WHERE category="private"')->fetchColumn();

// Meta page variables
$MetaPageTitle = "Home - IVAO Middle East Division";
$MetaPageDescription = "Welcome to IVAO Middle East Division. Experience cross-middle-east flights with organized slots and private bookings.";
$MetaPageKeywords = "IVAO, Middle East Division, aviation, flight simulator, flight slots, bookings";
$MetaPageURL = base_url('');
$MetaPageImage = base_url('public/uploads/logo.png');
?>
<!doctype html>
<html>
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title>Home - IVAO Middle East Division</title>
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
			<a href="<?php echo e(base_url('')); ?>" class="sidebar-item active" title="Home">
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
					<h2 class="navbar-title">IVAO Middle East Division</h2>
				</div>
				
				<div class="nav-right">
					<?php if (!empty(current_user())): ?>
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
					<?php else: ?>
						<a class="btn primary btn-small" href="<?php echo e(base_url('login.php')); ?>">Login</a>
					<?php endif; ?>
				</div>
			</nav>

			<!-- Content Area -->
			<div class="content-area">
				<div class="container">
					<!-- Main Panel -->
					<div class="main-panel">
						<?php flash(); ?>
						
						<!-- Enhanced Hero Section -->
						<div class="hero-section">
							<?php if (!empty($event['banner_url'])): ?>
								<div class="hero-visual-large">
									<img src="<?php echo e($event['banner_url']); ?>" alt="Event banner" />
									<div class="hero-overlay-bottom">
										<div class="hero-overlay-top-left">
											<div class="date-content">
												<div class="month"><?php echo e(strtoupper(substr($month,0,3))); ?></div>
												<div class="day"><?php echo e($day); ?></div>
											</div>
										</div>
										<div class="hero-overlay-bottom-center">
											<div class="pill-group">
												<?php if (!empty($event['is_hq_approved'])): ?>
													<span class="pill"><span class="icon">✈️</span>HQ EVENT</span>
												<?php endif; ?>
												<span class="pill"><span class="icon">📅</span><?php
													if ($event && !empty($event['start_zulu'])) {
														$startDate = new DateTime($event['start_zulu']);
														echo e($startDate->format('j M Y'));
													} else { echo 'TBA'; }
												?></span>
												<span class="pill"><span class="icon">⏱️</span><?php
													if ($event && !empty($event['start_zulu']) && !empty($event['end_zulu'])) {
														$start = new DateTime($event['start_zulu']);
														$end = new DateTime($event['end_zulu']);
														echo e($start->format('H\z')) . ' - ' . e($end->format('H\z'));
													} else { echo 'TBA'; }
												?></span>
												<span class="pill"><span class="icon">📍</span><?php echo e($event['event_airport'] ?? 'TBA'); ?></span>
											</div>
										</div>
									</div>
								</div>
							<?php else: ?>
								<div class="hero-card">
									<div class="hero-top">
										<div class="hero-date">
											<div class="date-content">
												<div class="month"><?php echo e(strtoupper(substr($month,0,3))); ?></div>
												<div class="day"><?php echo e($day); ?></div>
											</div>
										</div>
										<div class="hero-main">
											<div class="hero-text">
												<h1 class="hero-title">
													<?php echo e($event['title'] ?? 'IVAO Middle East Division'); ?>
												</h1>
												<p class="hero-description">
													<?php echo e($event['description'] ?? 'Experience cross-middle-east with organized slots and private bookings.'); ?>
												</p>
											</div>
										</div>
									</div>
								</div>
							<?php endif; ?>
						</div>

						<!-- Enhanced Info Section -->
						<div class="info-section">
							<?php if (!empty($event['announcement_links'])): ?>
								<div class="info-card">
									<div class="info-header">
										<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
											<circle cx="12" cy="12" r="10"></circle>
											<line x1="12" y1="16" x2="12" y2="12"></line>
											<line x1="12" y1="8" x2="12.01" y2="8"></line>
										</svg>
										<h3>Event Information</h3>
									</div>
									<div class="info-content">
										<?php
											$links = array_filter(array_map('trim', explode(',', (string)$event['announcement_links'])));
											if (!empty($links)) {
												echo '<a href="' . e($links[0]) . '" target="_blank" rel="noopener" class="info-link">
													<span>For more information, visit the official event page</span>
													<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
														<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
														<polyline points="15 3 21 3 21 9"></polyline>
														<line x1="10" y1="14" x2="21" y2="3"></line>
													</svg>
												</a>';
											}
										?>
									</div>
								</div>
							<?php endif; ?>
						</div>
					</div>

					<!-- Side Panel - Booking Stats -->
					<div class="side-panel">
						<div class="booking-panel">
							<div class="booking-header">Flight Stats</div>
							
							<div class="stat-box">
								<div>
									<div class="stat-label">Total Arrivals</div>
									<div class="stat-value"><?php echo (int)$arrCount; ?></div>
								</div>
								<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="color: var(--accent-green);">
									<path d="M20 6L9 17l-5-5"></path>
								</svg>
							</div>

							<div class="stat-box">
								<div>
									<div class="stat-label">Total Departures</div>
									<div class="stat-value"><?php echo (int)$depCount; ?></div>
								</div>
								<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="color: var(--accent-gold);">
									<path d="M12 5v14M5 12h14"></path>
								</svg>
							</div>

							<div class="stat-box">
								<div>
									<div class="stat-label">Private Slots</div>
									<div class="stat-value"><?php echo (int)$privateCount; ?></div>
								</div>
								<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="color: var(--primary);">
									<circle cx="12" cy="12" r="1"></circle>
									<path d="M12 1a11 11 0 110 22 11 11 0 010-22z"></path>
								</svg>
							</div>
						</div>

						<div class="booking-panel">
							<div class="booking-header">Quick Actions</div>
							<?php if (!empty(current_user())): ?>
								<a href="<?php echo e(base_url('my_bookings.php')); ?>" class="btn primary btn-block" style="margin-bottom: 0.75rem;">
									<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
										<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
										<circle cx="12" cy="7" r="4"></circle>
									</svg>
									My Bookings
								</a>
							<?php endif; ?>
							<a href="<?php echo e(base_url('timetable.php')); ?>" class="btn primary btn-block" style="margin-bottom: 0.75rem;">
								<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
									<path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"></path>
									<polyline points="16 3 16 7 8 7 8 3"></polyline>
									<line x1="3" y1="11" x2="21" y2="11"></line>
								</svg>
								Book Flight
							</a>
							<a href="<?php echo e(base_url('private_request.php')); ?>" class="btn secondary btn-block">
								<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
									<path d="M16 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"></path>
									<circle cx="12" cy="7" r="4"></circle>
									<polyline points="17 11 19 13 23 9"></polyline>
								</svg>
								Private Request
							</a>
						</div>
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