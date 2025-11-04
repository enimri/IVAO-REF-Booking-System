<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/helpers.php';

require_login();
$user = current_user();
$pdo = db();

// Handle unbook action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (!csrf_validate($_POST['csrf'] ?? '')) {
		redirect_with_message(base_url('my_bookings.php'), 'error', 'Invalid CSRF token.');
	}
	$action = $_POST['action'] ?? '';
	$flightId = (int)($_POST['flight_id'] ?? 0);
	
	if ($action === 'unbook' && $flightId > 0) {
		// Only allow unbooking own flights (or admin)
		if (is_admin()) {
			$stmt = $pdo->prepare('DELETE FROM bookings WHERE flight_id = ?');
			$stmt->execute([$flightId]);
			redirect_with_message(base_url('my_bookings.php'), 'success', 'Booking cleared.');
		} else {
			$stmt = $pdo->prepare('DELETE FROM bookings WHERE flight_id = ? AND booked_by_vid = ?');
			$stmt->execute([$flightId, $user['vid']]);
			if ($stmt->rowCount() > 0) {
				redirect_with_message(base_url('my_bookings.php'), 'success', 'Your booking has been cancelled.');
			} else {
				redirect_with_message(base_url('my_bookings.php'), 'error', 'Unable to cancel booking.');
			}
		}
	}
}

// Get user's bookings
$stmt = $pdo->prepare('
	SELECT 
		f.*, 
		b.booked_by_vid, 
		b.created_at as booked_at
	FROM bookings b
	INNER JOIN flights f ON f.id = b.flight_id
	WHERE b.booked_by_vid = ?
	ORDER BY f.departure_time_zulu ASC, f.category ASC
');
$stmt->execute([$user['vid']]);
$bookings = $stmt->fetchAll();

// Get user's private slot requests
$stmt = $pdo->prepare('
	SELECT * 
	FROM private_slot_requests
	WHERE vid = ?
	ORDER BY created_at DESC
');
$stmt->execute([$user['vid']]);
$privateRequests = $stmt->fetchAll();

// Meta page variables
$MetaPageTitle = "My Bookings - IVAO Middle East Division";
$MetaPageDescription = "View and manage your booked flight slots and private slot requests on IVAO Middle East Division.";
$MetaPageKeywords = "IVAO, bookings, my bookings, flight slots, reservations";
$MetaPageURL = base_url('my_bookings.php');
$MetaPageImage = base_url('public/uploads/logo.png');
?>
<!doctype html>
<html>
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title>My Bookings - IVAO Middle East Division</title>
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
					<h2 class="navbar-title">My Bookings</h2>
				</div>
				
				<div class="nav-right">
					<?php if (!empty(current_user())): ?>
						<?php $user = current_user(); ?>
						<span class="nav-right-item" title="Profile">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
								<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
								<circle cx="12" cy="7" r="4"></circle>
							</svg>
						</span>
						<span class="nav-username" title="<?php echo e($user['name'] ?? $user['vid']); ?>">
							Welcome, <?php echo e($user['name'] ?? $user['vid']); ?>
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
					<?php flash(); ?>
					
					<div class="card">
						<div class="card-header">
							<h2>My Bookings</h2>
							<div class="card-header-actions">
								<a class="btn secondary btn-small" href="<?php echo e(base_url('timetable.php')); ?>">
									<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
										<path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"></path>
										<polyline points="16 3 16 7 8 7 8 3"></polyline>
										<line x1="3" y1="11" x2="21" y2="11"></line>
									</svg>
									View Timetable
								</a>
							</div>
						</div>
						
						<?php if (empty($bookings)): ?>
							<div style="padding: 2rem; text-align: center; color: var(--text-secondary);">
								<p style="margin-bottom: 1rem;">You don't have any bookings yet.</p>
								<a class="btn primary" href="<?php echo e(base_url('timetable.php')); ?>">
									<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
										<path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"></path>
										<polyline points="16 3 16 7 8 7 8 3"></polyline>
										<line x1="3" y1="11" x2="21" y2="11"></line>
									</svg>
									Browse Flights
								</a>
							</div>
						<?php else: ?>
							<div class="table-wrapper" style="max-height: none;">
								<table class="table">
									<thead>
										<tr>
											<th>Category</th>
											<th>Flight Number</th>
											<th>Airline</th>
											<th>Aircraft</th>
											<th>Departure</th>
											<th>Destination</th>
											<th>Departure (Z)</th>
											<th>Gate</th>
											<th>Booked At</th>
											<th>Actions</th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ($bookings as $b): ?>
										<tr>
											<td data-label="Category"><span class="badge info"><?php echo e($b['category']); ?></span></td>
											<td data-label="Flight Number"><?php echo e($b['flight_number']); ?></td>
											<td data-label="Airline"><?php 
												// Use stored airline_name from database, fallback to auto-detection if not set
												$airlineName = isset($b['airline_name']) && trim($b['airline_name']) !== '' ? trim($b['airline_name']) : get_airline_name_from_flight($b['flight_number']);
												echo e($airlineName ?: '-');
											?></td>
											<td data-label="Aircraft"><?php echo e($b['aircraft']); ?></td>
											<td data-label="Departure"><?php echo e($b['origin_icao']); ?></td>
											<td data-label="Destination"><?php echo e($b['destination_icao']); ?></td>
											<td data-label="Departure (Z)"><?php echo e($b['departure_time_zulu']); ?></td>
											<td data-label="Gate"><?php echo e($b['gate'] ?? '-'); ?></td>
											<td data-label="Booked At">
												<?php 
													if (!empty($b['booked_at'])) {
														$bookedDate = new DateTime($b['booked_at']);
														echo e($bookedDate->format('M j, H:i'));
													} else {
														echo '-';
													}
												?>
											</td>
											<td data-label="Actions">
												<form method="post" style="display:inline;">
													<input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>" />
													<input type="hidden" name="flight_id" value="<?php echo (int)$b['id']; ?>" />
													<button class="btn danger btn-small" name="action" value="unbook" onclick="return confirm('Cancel this booking?')">Cancel</button>
												</form>
											</td>
										</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>
						<?php endif; ?>
					</div>
					
					<!-- Private Slot Requests Section -->
					<div class="card" style="margin-top: 2rem;">
						<div class="card-header">
							<h2>My Private Slot Requests</h2>
							<div class="card-header-actions">
								<a class="btn secondary btn-small" href="<?php echo e(base_url('private_request.php')); ?>">
									<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
										<path d="M12 1v6m0 6v6M4.22 4.22l4.24 4.24m4.24 4.24l4.24 4.24M1 12h6m6 0h6M4.22 19.78l4.24-4.24m4.24-4.24l4.24-4.24"></path>
									</svg>
									Request New Slot
								</a>
							</div>
						</div>
						
						<?php if (empty($privateRequests)): ?>
							<div style="padding: 2rem; text-align: center; color: var(--text-secondary);">
								<p style="margin-bottom: 1rem;">You haven't submitted any private slot requests yet.</p>
								<a class="btn primary" href="<?php echo e(base_url('private_request.php')); ?>">
									<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
										<path d="M12 1v6m0 6v6M4.22 4.22l4.24 4.24m4.24 4.24l4.24 4.24M1 12h6m6 0h6M4.22 19.78l4.24-4.24m4.24-4.24l4.24-4.24"></path>
									</svg>
									Request Private Slot
								</a>
							</div>
						<?php else: ?>
							<div class="table-wrapper" style="max-height: none;">
								<table class="table">
									<thead>
										<tr>
											<th>Flight Number</th>
											<th>Aircraft</th>
											<th>Route</th>
											<th>Departure Time (Z)</th>
											<th>Status</th>
											<th>Submitted</th>
											<th>Updated</th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ($privateRequests as $req): ?>
										<tr>
											<td data-label="Flight Number"><?php echo e($req['flight_number']); ?></td>
											<td data-label="Aircraft"><?php echo e($req['aircraft_type']); ?></td>
											<td data-label="Route"><?php echo e($req['origin_icao'] . ' → ' . $req['destination_icao']); ?></td>
											<td data-label="Departure Time (Z)"><?php echo e($req['departure_time_zulu']); ?></td>
											<td data-label="Status">
												<span class="badge <?php echo e($req['status']); ?>">
													<?php 
														$statusLabels = [
															'pending' => 'Pending',
															'approved' => 'Approved',
															'rejected' => 'Rejected',
															'cancelled' => 'Cancelled'
														];
														echo e($statusLabels[$req['status']] ?? ucfirst($req['status'])); 
													?>
												</span>
											</td>
											<td data-label="Submitted">
												<?php 
													if (!empty($req['created_at'])) {
														$createdDate = new DateTime($req['created_at']);
														echo e($createdDate->format('M j, Y H:i'));
													} else {
														echo '-';
													}
												?>
											</td>
											<td data-label="Updated">
												<?php 
													if (!empty($req['updated_at'])) {
														$updatedDate = new DateTime($req['updated_at']);
														echo e($updatedDate->format('M j, Y H:i'));
													} else {
														echo '-';
													}
												?>
											</td>
										</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>
						<?php endif; ?>
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

