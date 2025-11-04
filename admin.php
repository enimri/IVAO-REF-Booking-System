<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/helpers.php';

require_login();
if (!is_admin()) {
	redirect_with_message(base_url(''), 'error', 'Admins only.');
}

$pdo = db();
$event = $pdo->query('SELECT * FROM events ORDER BY id DESC LIMIT 1')->fetch();

// Handle submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (!csrf_validate($_POST['csrf'] ?? '')) {
		redirect_with_message(base_url('admin.php'), 'error', 'Invalid CSRF token.');
	}
	$action = $_POST['action'] ?? '';

	if ($action === 'save_event') {
		$division = trim($_POST['division'] ?? '');
		$other = trim($_POST['other_divisions'] ?? '');
		$is_hq = isset($_POST['is_hq']) ? 1 : 0;
		$title = trim($_POST['title'] ?? '');
		$desc = trim($_POST['description'] ?? '');
		$start = trim($_POST['start_zulu'] ?? '');
		$end = trim($_POST['end_zulu'] ?? '');
		$airport = strtoupper(trim($_POST['event_airport'] ?? ''));
		$points = trim($_POST['points_criteria'] ?? '');
		$banner = trim($_POST['banner_url'] ?? '');
		$ann = trim($_POST['announcement_links'] ?? '');
		$is_open = isset($_POST['is_open']) ? 1 : 0;
		$private_slots_enabled = isset($_POST['private_slots_enabled']) ? 1 : 0;

		// Ensure private_slots_enabled column exists
		try {
			$pdo->exec('ALTER TABLE events ADD COLUMN private_slots_enabled TINYINT(1) NOT NULL DEFAULT 0');
		} catch (Throwable $e) {
			// Column might already exist, ignore
		}

		if ($event) {
			$stmt = $pdo->prepare('UPDATE events SET division=?, other_divisions=?, is_hq_approved=?, title=?, description=?, start_zulu=?, end_zulu=?, event_airport=?, points_criteria=?, banner_url=?, announcement_links=?, is_open=?, private_slots_enabled=? WHERE id=?');
			$stmt->execute([$division,$other,$is_hq,$title,$desc,$start,$end,$airport,$points,$banner,$ann,$is_open,$private_slots_enabled,$event['id']]);
		} else {
			$stmt = $pdo->prepare('INSERT INTO events (division, other_divisions, is_hq_approved, title, description, start_zulu, end_zulu, event_airport, points_criteria, banner_url, announcement_links, is_open, private_slots_enabled) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
			$stmt->execute([$division,$other,$is_hq,$title,$desc,$start,$end,$airport,$points,$banner,$ann,$is_open,$private_slots_enabled]);
		}
		redirect_with_message(base_url('admin.php'), 'success', 'Event saved.');
	}

	if ($action === 'add_admin_role') {
		$vid = trim($_POST['vid'] ?? '');
		$role = $_POST['role'] ?? 'admin';
		$is_staff = isset($_POST['is_staff']) ? 1 : 0; // Staff requirement
		if ($is_staff !== 1) {
			redirect_with_message(base_url('admin.php'), 'error', 'Staff status is required to assign roles.');
		}
		if ($vid === '' || !in_array($role, ['admin','private_admin'], true)) {
			redirect_with_message(base_url('admin.php'), 'error', 'Invalid VID or role.');
		}
		$pdo->beginTransaction();
		try {
			$pdo->prepare('INSERT INTO users (vid, name, is_staff) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE is_staff = VALUES(is_staff)')->execute([$vid, $vid, $is_staff]);
			$pdo->prepare('INSERT IGNORE INTO user_roles (vid, role) VALUES (?, ?)')->execute([$vid, $role]);
			$pdo->commit();
			redirect_with_message(base_url('admin.php'), 'success', 'Role assigned.');
		} catch (Throwable $e) {
			$pdo->rollBack();
			redirect_with_message(base_url('admin.php'), 'error', 'Failed to assign role.');
		}
	}

	if ($action === 'remove_admin_role') {
		$vid = trim($_POST['vid'] ?? '');
		$role = $_POST['role'] ?? 'admin';
		$pdo->prepare('DELETE FROM user_roles WHERE vid=? AND role=?')->execute([$vid, $role]);
		redirect_with_message(base_url('admin.php'), 'success', 'Role removed.');
	}
}

$roles = $pdo->query('SELECT ur.vid, u.is_staff, GROUP_CONCAT(ur.role ORDER BY ur.role SEPARATOR ", ") roles FROM user_roles ur INNER JOIN users u ON u.vid=ur.vid GROUP BY ur.vid, u.is_staff ORDER BY ur.vid')->fetchAll();
$event = $pdo->query('SELECT * FROM events ORDER BY id DESC LIMIT 1')->fetch();

// Meta page variables
$MetaPageTitle = "Admin - IVAO Middle East Division";
$MetaPageDescription = "Administrative panel for managing events, settings, and user roles for IVAO Middle East Division.";
$MetaPageKeywords = "IVAO, admin, administration, settings, events, user roles";
$MetaPageURL = base_url('admin.php');
$MetaPageImage = base_url('public/uploads/logo.png');
?>
<!doctype html>
<html>
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title>Admin - IVAO Middle East Division</title>
	<link rel="icon" type="image/x-icon" href="<?php echo e(base_url('public/uploads/favicon.ico')); ?>" />
	<link rel="stylesheet" href="<?php echo e(base_url('public/assets/styles.css')); ?>" />
	<style>
		/* Toggle Switch Styles */
		.toggle-switch {
			position: relative;
			display: inline-block;
			width: 56px;
			height: 30px;
			cursor: pointer;
		}

		.toggle-switch input {
			opacity: 0;
			width: 0;
			height: 0;
		}

		.toggle-slider {
			position: absolute;
			cursor: pointer;
			top: 0;
			left: 0;
			right: 0;
			bottom: 0;
			background-color: rgba(52, 65, 84, 0.8);
			border: 1px solid var(--border);
			transition: var(--transition);
			border-radius: 30px;
		}

		.toggle-slider:before {
			position: absolute;
			content: "";
			height: 22px;
			width: 22px;
			left: 3px;
			bottom: 3px;
			background-color: var(--text-secondary);
			transition: var(--transition);
			border-radius: 50%;
		}

		.toggle-switch input:checked + .toggle-slider {
			background: linear-gradient(135deg, var(--primary), var(--primary-dark));
			border-color: var(--primary);
			box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.15);
		}

		.toggle-switch input:checked + .toggle-slider:before {
			transform: translateX(26px);
			background-color: white;
		}

		.toggle-switch:hover .toggle-slider {
			border-color: var(--border-light);
		}

		.toggle-switch input:focus + .toggle-slider {
			box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.15);
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
					<a href="<?php echo e(base_url('admin.php')); ?>" class="sidebar-item active" title="Settings">
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
					<h2 class="navbar-title">Admin Panel</h2>
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
					<div class="card" style="margin-bottom:16px;">
						<div class="card-header">
							<h2>Event Configuration</h2>
							<div class="card-header-actions" style="display: flex; gap: 8px; flex-wrap: wrap;">
								<a class="btn primary" href="<?php echo e(base_url('manage_timetable.php')); ?>">
									<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
										<polyline points="19 3 5 3 5 21 19 21"></polyline>
										<line x1="12" y1="7" x2="12" y2="17"></line>
										<line x1="8" y1="12" x2="16" y2="12"></line>
									</svg>
									Manage Timetable
								</a>
								<a class="btn secondary" href="<?php echo e(base_url('manage_airports.php')); ?>">
									<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
										<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
										<circle cx="12" cy="10" r="3"></circle>
									</svg>
									Manage Airports
								</a>
								<a class="btn secondary" href="<?php echo e(base_url('manage_airlines.php')); ?>">
									<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
										<path d="M21 16v-2l-8-5V3.5c0-.83-.67-1.5-1.5-1.5S10 2.67 10 3.5V9l-8 5v2l8-2.5V19l-2 1.5V22l3.5-1 3.5 1v-1.5L13 19v-5.5l8 2.5z"></path>
									</svg>
									Manage Airlines
								</a>
								<a class="btn secondary" href="<?php echo e(base_url('system_status.php')); ?>">
									<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
										<circle cx="12" cy="12" r="10"></circle>
										<path d="M12 6v6l4 2"></path>
									</svg>
									System Status
								</a>
								<?php if (is_private_admin() || is_admin()): ?>
									<a class="btn secondary" href="<?php echo e(base_url('private_admin.php')); ?>">
										<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
											<path d="M12 1v6m0 6v6M4.22 4.22l4.24 4.24m4.24 4.24l4.24 4.24M1 12h6m6 0h6M4.22 19.78l4.24-4.24m4.24-4.24l4.24-4.24"></path>
										</svg>
										Private Slots
									</a>
								<?php endif; ?>
							</div>
						</div>
						<form method="post" class="form-grid" style="margin-top:12px;">
							<input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>" />
							<input type="hidden" name="action" value="save_event" />
							<div class="form-group">
								<label class="label">Division</label>
								<input class="input" name="division" value="<?php echo e($event['division'] ?? 'XM'); ?>" required />
							</div>
							<div class="form-group">
								<label class="label">Other Divisions</label>
								<input class="input" name="other_divisions" value="<?php echo e($event['other_divisions'] ?? ''); ?>" placeholder="EG, SA, IR, ..." />
							</div>
							<div class="form-group">
								<label class="label">Approved ESA (HQ)</label>
								<input type="checkbox" name="is_hq" <?php echo !empty($event['is_hq_approved'])?'checked':''; ?> />
							</div>
							<div class="form-group full">
								<label class="label">Event Title</label>
								<input class="input" name="title" value="<?php echo e($event['title'] ?? 'IVAO Middle East Division'); ?>" required />
							</div>
							<div class="form-group">
								<label class="label">Event Start (Zulu)</label>
								<input class="input" type="datetime-local" name="start_zulu" value="<?php echo e(isset($event['start_zulu']) ? date('Y-m-d\TH:i', strtotime($event['start_zulu'])) : ''); ?>" required />
							</div>
							<div class="form-group">
								<label class="label">Event End (Zulu)</label>
								<input class="input" type="datetime-local" name="end_zulu" value="<?php echo e(isset($event['end_zulu']) ? date('Y-m-d\TH:i', strtotime($event['end_zulu'])) : ''); ?>" required />
							</div>
							<div class="form-group">
								<label class="label">Event Airport</label>
								<input class="input" name="event_airport" value="<?php echo e($event['event_airport'] ?? ''); ?>" placeholder="OMDB" />
							</div>
							<div class="form-group">
								<label class="label">Points Criteria</label>
								<input class="input" name="points_criteria" value="<?php echo e($event['points_criteria'] ?? ''); ?>" />
							</div>
							<div class="form-group full">
								<label class="label">Banner Link</label>
								<input class="input" name="banner_url" value="<?php echo e($event['banner_url'] ?? ''); ?>" placeholder="https://..." />
							</div>
							<div class="form-group full">
								<label class="label">Event Announcement Links</label>
								<input class="input" name="announcement_links" value="<?php echo e($event['announcement_links'] ?? ''); ?>" placeholder="https://... , https://..." />
							</div>
						<div class="form-group full">
							<label class="label">System Open for booking?</label>
							<label class="toggle-switch">
								<input type="checkbox" name="is_open" <?php echo !empty($event['is_open'])?'checked':''; ?> />
								<span class="toggle-slider"></span>
							</label>
						</div>
						<div class="form-group full">
							<label class="label">Enable Private Slots Feature</label>
							<label class="toggle-switch">
								<input type="checkbox" name="private_slots_enabled" <?php echo !empty($event['private_slots_enabled'] ?? 0)?'checked':''; ?> />
								<span class="toggle-slider"></span>
							</label>
							<small style="color: var(--text-secondary); display: block; margin-top: 4px;">Allow users to request private slots (requires system to be open)</small>
						</div>
						<div class="form-group full">
							<button class="btn primary btn-block" type="submit">Save Configuration</button>
						</div>
						</form>
					</div>

					<div class="card">
						<div class="card-header">
							<h2>Admin Management</h2>
						</div>
						<form method="post" class="flex gap-2" style="flex-wrap:wrap; align-items:flex-end; margin-bottom:20px;">
							<input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>" />
							<input type="hidden" name="action" value="add_admin_role" />
							<div class="form-group" style="flex: 1; min-width: 200px; margin-bottom:0;">
								<label class="label">IVAO VID</label>
								<input class="input" name="vid" placeholder="123456" required />
							</div>
							<div class="form-group" style="flex: 1; min-width: 200px; margin-bottom:0;">
								<label class="label">Role</label>
								<select class="input" name="role">
									<option value="admin">Admin</option>
									<option value="private_admin">Private Slot Admin</option>
								</select>
							</div>
							<div class="form-group" style="flex: 1; min-width: 200px; margin-bottom:0;">
								<label class="label">Is Staff (required)</label>
								<input type="checkbox" name="is_staff" />
							</div>
							<div>
								<button class="btn primary" type="submit">Add Role</button>
							</div>
						</form>
						<div class="table-wrapper" style="max-height: none;">
							<table class="table" style="margin-top:12px;">
								<thead><tr><th>VID</th><th>Staff</th><th>Roles</th><th>Actions</th></tr></thead>
								<tbody>
								<?php foreach ($roles as $r): ?>
									<tr>
										<td><?php echo e($r['vid']); ?></td>
										<td><?php echo !empty($r['is_staff']) ? '<span class="badge success">Yes</span>' : '<span class="badge">No</span>'; ?></td>
										<td><?php echo e($r['roles']); ?></td>
										<td style="display:flex; gap:6px; align-items: center;">
											<form method="post" style="display:flex; gap:6px; align-items: flex-end;">
												<input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>" />
												<input type="hidden" name="action" value="remove_admin_role" />
												<input type="hidden" name="vid" value="<?php echo e($r['vid']); ?>" />
												<select class="input" name="role" style="min-width: 150px;">
													<option value="admin">admin</option>
													<option value="private_admin">private_admin</option>
												</select>
												<button class="btn danger btn-small" type="submit">Remove</button>
											</form>
										</td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
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
