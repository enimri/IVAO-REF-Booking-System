<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/email.php';

require_login();
if (!is_private_admin() && !is_admin()) {
	redirect_with_message(base_url(''), 'error', 'Private Slot Admins only.');
}
$pdo = db();

// Ensure rejection_reason and cancellation_reason columns exist
try {
	$pdo->exec('ALTER TABLE private_slot_requests ADD COLUMN rejection_reason TEXT NULL AFTER status');
} catch (Throwable $e) {
	// Column might already exist, ignore
}
try {
	$pdo->exec('ALTER TABLE private_slot_requests ADD COLUMN cancellation_reason TEXT NULL AFTER rejection_reason');
} catch (Throwable $e) {
	// Column might already exist, ignore
}

// Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (!csrf_validate($_POST['csrf'] ?? '')) {
		redirect_with_message(base_url('private_admin.php'), 'error', 'Invalid CSRF token.');
	}
	$action = $_POST['action'] ?? '';
	$id = (int)($_POST['id'] ?? 0);
	if ($action === 'clear_all') {
		$pdo->exec('DELETE FROM private_slot_requests');
		redirect_with_message(base_url('private_admin.php'), 'success', 'All private slot requests cleared.');
	} elseif ($action === 'clear' && $id > 0) {
		// Get request details before deletion (for potential email notification)
		$stmt = $pdo->prepare('SELECT * FROM private_slot_requests WHERE id = ?');
		$stmt->execute([$id]);
		$request = $stmt->fetch();
		
		// Delete the request
		$stmt = $pdo->prepare('DELETE FROM private_slot_requests WHERE id = ?');
		$stmt->execute([$id]);
		
		if ($stmt->rowCount() > 0) {
			redirect_with_message(base_url('private_admin.php'), 'success', 'Private slot request deleted.');
		} else {
			redirect_with_message(base_url('private_admin.php'), 'error', 'Request not found.');
		}
	} elseif (in_array($action, ['approve','reject','cancel'], true)) {
		$status = $action === 'approve' ? 'approved' : ($action === 'reject' ? 'rejected' : 'cancelled');
		$rejectionReason = '';
		$cancellationReason = '';
		
		// Get rejection reason if rejecting
		if ($action === 'reject') {
			$rejectionReason = trim($_POST['rejection_reason'] ?? '');
		}
		
		// Get cancellation reason if cancelling
		if ($action === 'cancel') {
			$cancellationReason = trim($_POST['cancellation_reason'] ?? '');
		}
		
		// Update status, rejection reason, and cancellation reason
		$stmt = $pdo->prepare('UPDATE private_slot_requests SET status=?, rejection_reason=?, cancellation_reason=?, updated_at=NOW() WHERE id=?');
		$stmt->execute([$status, $rejectionReason, $cancellationReason, $id]);
		
		// Get request details for email
		$stmt = $pdo->prepare('SELECT * FROM private_slot_requests WHERE id = ?');
		$stmt->execute([$id]);
		$request = $stmt->fetch();
		
		if ($request) {
			// Get user details
			$stmt = $pdo->prepare('SELECT vid, name, email FROM users WHERE vid = ?');
			$stmt->execute([$request['vid']]);
			$user = $stmt->fetch();
			
			if ($user) {
				// Send email based on action
				if ($action === 'approve') {
					send_private_slot_approval_email($user, $request);
				} elseif ($action === 'reject') {
					send_private_slot_rejection_email($user, $request, $rejectionReason);
				} elseif ($action === 'cancel') {
					send_private_slot_cancellation_email($user, $request, $cancellationReason);
				}
			}
		}
		
		redirect_with_message(base_url('private_admin.php'), 'success', 'Updated request.');
	}
}

// CSV export
if (($_GET['export'] ?? '') === 'csv') {
	$status = $_GET['status'] ?? 'all';
	$sql = 'SELECT id, vid, flight_number, aircraft_type, origin_icao, destination_icao, departure_time_zulu, status, created_at, updated_at FROM private_slot_requests';
	$args = [];
	if (in_array($status, ['pending','approved','rejected','cancelled'], true)) {
		$sql .= ' WHERE status=?';
		$args[] = $status;
	}
	$sql .= ' ORDER BY created_at DESC';
	$stmt = $pdo->prepare($sql);
	$stmt->execute($args);
	$rows = $stmt->fetchAll();
	header('Content-Type: text/csv');
	header('Content-Disposition: attachment; filename="private_slots_' . $status . '.csv"');
	$out = fopen('php://output', 'w');
	fputcsv($out, ['ID','VID','Flight Number','Aircraft','Departure','Destination','Departure (Z)','Status','Created','Updated']);
	foreach ($rows as $r) { fputcsv($out, array_values($r)); }
	fclose($out);
	exit;
}

// Stats
$stats = $pdo->query("SELECT status, COUNT(*) c FROM private_slot_requests GROUP BY status")->fetchAll();
$totals = ['pending'=>0,'approved'=>0,'rejected'=>0,'cancelled'=>0];
foreach ($stats as $s) { $totals[$s['status']] = (int)$s['c']; }
$totals['total'] = array_sum($totals);

$filter = $_GET['status'] ?? 'pending';
$sql = 'SELECT * FROM private_slot_requests';
$args = [];
if (in_array($filter, ['pending','approved','rejected','cancelled'], true)) {
	$sql .= ' WHERE status=?';
	$args[] = $filter;
}
$sql .= ' ORDER BY created_at DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($args);
$rows = $stmt->fetchAll();

// Meta page variables
$MetaPageTitle = "Private Slot Admin - IVAO Middle East Division";
$MetaPageDescription = "Administrative panel for managing private slot requests. Review, approve, or reject private flight slot submissions.";
$MetaPageKeywords = "IVAO, private slot admin, private flights, administration";
$MetaPageURL = base_url('private_admin.php');
$MetaPageImage = base_url('public/uploads/logo.png');
?>
<!doctype html>
<html>
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title>Private Slot Admin - IVAO Middle East Division</title>
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
					<a href="<?php echo e(base_url('admin.php')); ?>" class="sidebar-item" title="Admin">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
							<circle cx="12" cy="12" r="3"></circle>
							<path d="M12 1v6m0 6v6M4.22 4.22l4.24 4.24m4.24 4.24l4.24 4.24M1 12h6m6 0h6M4.22 19.78l4.24-4.24m4.24-4.24l4.24-4.24"></path>
						</svg>
					</a>
				<?php endif; ?>

				<?php if (is_private_admin()): ?>
					<a href="<?php echo e(base_url('private_admin.php')); ?>" class="sidebar-item active" title="Private Slots">
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
					<h2 class="navbar-title">Private Slot Admin</h2>
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
					
					<!-- Stats Cards -->
					<div class="form-grid cols-3" style="margin-bottom: 1.5rem;">
						<div class="card">
							<div class="label" style="margin: 0 0 8px 0; opacity: 0.7;">Total Requested</div>
							<div style="font-size: 28px; font-weight: 700; color: #93c5fd;"><?php echo e((string)$totals['total']); ?></div>
						</div>
						<div class="card">
							<div class="label" style="margin: 0 0 8px 0; opacity: 0.7;">Pending</div>
							<div style="font-size: 28px; font-weight: 700; color: #f59e0b;"><?php echo e((string)$totals['pending']); ?></div>
						</div>
						<div class="card">
							<div class="label" style="margin: 0 0 8px 0; opacity: 0.7;">Approved</div>
							<div style="font-size: 28px; font-weight: 700; color: #10b981;"><?php echo e((string)$totals['approved']); ?></div>
						</div>
					</div>

					<div class="form-grid cols-3" style="margin-bottom: 1.5rem;">
						<div class="card">
							<div class="label" style="margin: 0 0 8px 0; opacity: 0.7;">Rejected</div>
							<div style="font-size: 28px; font-weight: 700; color: #ef4444;"><?php echo e((string)$totals['rejected']); ?></div>
						</div>
						<div class="card">
							<div class="label" style="margin: 0 0 8px 0; opacity: 0.7;">Cancelled</div>
							<div style="font-size: 28px; font-weight: 700; color: #94a3b8;"><?php echo e((string)$totals['cancelled']); ?></div>
						</div>
						<div class="card" style="display: flex; align-items: center; justify-content: center;">
							<a class="btn primary" href="?export=csv&status=<?php echo e($filter); ?>">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
									<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
									<polyline points="7 10 12 15 17 10"></polyline>
									<line x1="12" y1="15" x2="12" y2="3"></line>
								</svg>
								Export CSV (<?php echo e($filter); ?>)
							</a>
						</div>
					</div>

					<!-- Tabs -->
					<div class="tabs" style="margin-bottom: 1.5rem;">
						<a class="tab <?php echo $filter==='pending'?'active':''; ?>" href="?status=pending">Pending</a>
						<a class="tab <?php echo $filter==='approved'?'active':''; ?>" href="?status=approved">Approved</a>
						<a class="tab <?php echo $filter==='rejected'?'active':''; ?>" href="?status=rejected">Rejected</a>
						<a class="tab <?php echo $filter==='cancelled'?'active':''; ?>" href="?status=cancelled">Cancelled</a>
					</div>

					<!-- Requests Table -->
					<div class="card">
						<div class="card-header">
							<h2>Private Slot Requests</h2>
							<div class="card-header-actions">
								<form method="post" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete ALL private slot requests? This action cannot be undone.');">
									<input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>" />
									<input type="hidden" name="action" value="clear_all" />
									<button class="btn danger btn-small" type="submit">
										<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
											<polyline points="3 6 5 6 21 6"></polyline>
											<path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
											<line x1="10" y1="11" x2="10" y2="17"></line>
											<line x1="14" y1="11" x2="14" y2="17"></line>
										</svg>
										Clear All
									</button>
								</form>
							</div>
						</div>
						<?php if (empty($rows)): ?>
							<div style="padding: 2rem; text-align: center; color: var(--text-secondary);">
								<p>No <?php echo e($filter === 'all' ? '' : $filter . ' '); ?>requests found.</p>
							</div>
						<?php else: ?>
							<div class="table-wrapper" style="max-height: none;">
								<table class="table">
									<thead>
										<tr>
											<th>ID</th>
											<th>VID</th>
											<th>Flight</th>
											<th>Aircraft</th>
											<th>Route</th>
											<th>Departure (Z)</th>
											<th>Status</th>
											<th>Actions</th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ($rows as $r): ?>
										<tr>
											<td data-label="ID"><?php echo (int)$r['id']; ?></td>
											<td data-label="VID"><?php echo e($r['vid']); ?></td>
											<td data-label="Flight"><?php echo e($r['flight_number']); ?></td>
											<td data-label="Aircraft"><?php echo e($r['aircraft_type']); ?></td>
											<td data-label="Route"><?php echo e($r['origin_icao'] . ' → ' . $r['destination_icao']); ?></td>
											<td data-label="Departure (Z)"><?php echo e($r['departure_time_zulu']); ?></td>
											<td data-label="Status">
												<span class="badge <?php echo e($r['status']); ?>">
													<?php echo e(ucfirst($r['status'])); ?>
												</span>
											</td>
											<td data-label="Actions" style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap;">
													<?php if ($r['status'] === 'pending'): ?>
														<form method="post" style="display: inline;">
															<input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>" />
															<input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>" />
															<button class="btn success btn-small" name="action" value="approve" type="submit">Approve</button>
														</form>
														<button class="btn danger btn-small" onclick="openRejectModal(<?php echo (int)$r['id']; ?>, '<?php echo e($r['flight_number']); ?>')">Reject</button>
														<button class="btn warning btn-small" onclick="openCancelModal(<?php echo (int)$r['id']; ?>, '<?php echo e($r['flight_number']); ?>')">Cancel</button>
														<form method="post" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this private slot request? This action cannot be undone.');">
															<input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>" />
															<input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>" />
															<button class="btn danger btn-small" name="action" value="clear" type="submit" title="Delete Request">
																<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
																	<polyline points="3 6 5 6 21 6"></polyline>
																	<path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
																</svg>
															</button>
														</form>
													<?php elseif ($r['status'] === 'approved'): ?>
														<button class="btn warning btn-small" onclick="openCancelModal(<?php echo (int)$r['id']; ?>, '<?php echo e($r['flight_number']); ?>')">Cancel</button>
														<form method="post" style="display: inline;">
															<input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>" />
															<input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>" />
															<button class="btn success btn-small" name="action" value="approve" type="submit" title="Re-approve">Approve</button>
														</form>
														<button class="btn danger btn-small" onclick="openRejectModal(<?php echo (int)$r['id']; ?>, '<?php echo e($r['flight_number']); ?>')">Reject</button>
														<form method="post" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this private slot request? This action cannot be undone.');">
															<input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>" />
															<input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>" />
															<button class="btn danger btn-small" name="action" value="clear" type="submit" title="Delete Request">
																<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
																	<polyline points="3 6 5 6 21 6"></polyline>
																	<path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
																</svg>
															</button>
														</form>
													<?php elseif ($r['status'] === 'rejected'): ?>
														<form method="post" style="display: inline;">
															<input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>" />
															<input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>" />
															<button class="btn success btn-small" name="action" value="approve" type="submit">Approve</button>
														</form>
														<button class="btn danger btn-small" onclick="openRejectModal(<?php echo (int)$r['id']; ?>, '<?php echo e($r['flight_number']); ?>')" title="Re-reject">Reject</button>
														<button class="btn warning btn-small" onclick="openCancelModal(<?php echo (int)$r['id']; ?>, '<?php echo e($r['flight_number']); ?>')">Cancel</button>
														<form method="post" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this private slot request? This action cannot be undone.');">
															<input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>" />
															<input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>" />
															<button class="btn danger btn-small" name="action" value="clear" type="submit" title="Delete Request">
																<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
																	<polyline points="3 6 5 6 21 6"></polyline>
																	<path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
																</svg>
															</button>
														</form>
													<?php elseif ($r['status'] === 'cancelled'): ?>
														<form method="post" style="display: inline;">
															<input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>" />
															<input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>" />
															<button class="btn success btn-small" name="action" value="approve" type="submit">Approve</button>
														</form>
														<button class="btn danger btn-small" onclick="openRejectModal(<?php echo (int)$r['id']; ?>, '<?php echo e($r['flight_number']); ?>')">Reject</button>
														<button class="btn warning btn-small" onclick="openCancelModal(<?php echo (int)$r['id']; ?>, '<?php echo e($r['flight_number']); ?>')" title="Re-cancel">Cancel</button>
														<form method="post" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this private slot request? This action cannot be undone.');">
															<input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>" />
															<input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>" />
															<button class="btn danger btn-small" name="action" value="clear" type="submit" title="Delete Request">
																<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
																	<polyline points="3 6 5 6 21 6"></polyline>
																	<path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
																</svg>
															</button>
														</form>
													<?php else: ?>
														<span class="badge">No actions</span>
													<?php endif; ?>
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
	<!-- Rejection Reason Modal -->
	<div id="rejectModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); z-index: 1000; align-items: center; justify-content: center;">
		<div style="background: var(--bg-card); border: 1px solid var(--border); border-radius: 8px; padding: 24px; max-width: 500px; width: 90%; max-height: 90vh; overflow-y: auto;">
			<h3 style="margin-top: 0;">Reject Private Slot Request</h3>
			<p style="color: var(--text-secondary); margin-bottom: 16px;">Flight: <strong id="rejectModalFlight"></strong></p>
			<form method="post" id="rejectForm">
				<input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>" />
				<input type="hidden" name="id" id="rejectModalId" />
				<input type="hidden" name="action" value="reject" />
				<div class="form-group">
					<label class="label">Rejection Reason (Optional)</label>
					<textarea class="input" name="rejection_reason" id="rejectModalReason" rows="4" placeholder="Enter reason for rejection..."></textarea>
				</div>
				<div style="display: flex; gap: 12px; margin-top: 20px;">
					<button type="button" class="btn secondary" onclick="closeRejectModal()" style="flex: 1;">Cancel</button>
					<button type="submit" class="btn danger" style="flex: 1;">Reject Request</button>
				</div>
			</form>
		</div>
	</div>
	
	<!-- Cancellation Reason Modal -->
	<div id="cancelModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); z-index: 1000; align-items: center; justify-content: center;">
		<div style="background: var(--bg-card); border: 1px solid var(--border); border-radius: 8px; padding: 24px; max-width: 500px; width: 90%; max-height: 90vh; overflow-y: auto;">
			<h3 style="margin-top: 0;">Cancel Private Slot Request</h3>
			<p style="color: var(--text-secondary); margin-bottom: 16px;">Flight: <strong id="cancelModalFlight"></strong></p>
			<form method="post" id="cancelForm">
				<input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>" />
				<input type="hidden" name="id" id="cancelModalId" />
				<input type="hidden" name="action" value="cancel" />
				<div class="form-group">
					<label class="label">Cancellation Reason (Optional)</label>
					<textarea class="input" name="cancellation_reason" id="cancelModalReason" rows="4" placeholder="Enter reason for cancellation..."></textarea>
				</div>
				<div style="display: flex; gap: 12px; margin-top: 20px;">
					<button type="button" class="btn secondary" onclick="closeCancelModal()" style="flex: 1;">Close</button>
					<button type="submit" class="btn warning" style="flex: 1;">Cancel Request</button>
				</div>
			</form>
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
		
		// Rejection modal functions
		function openRejectModal(id, flightNumber) {
			document.getElementById('rejectModalId').value = id;
			document.getElementById('rejectModalFlight').textContent = flightNumber;
			document.getElementById('rejectModalReason').value = '';
			document.getElementById('rejectModal').style.display = 'flex';
		}
		
		function closeRejectModal() {
			document.getElementById('rejectModal').style.display = 'none';
		}
		
		// Cancellation modal functions
		function openCancelModal(id, flightNumber) {
			document.getElementById('cancelModalId').value = id;
			document.getElementById('cancelModalFlight').textContent = flightNumber;
			document.getElementById('cancelModalReason').value = '';
			document.getElementById('cancelModal').style.display = 'flex';
		}
		
		function closeCancelModal() {
			document.getElementById('cancelModal').style.display = 'none';
		}
		
		// Close modals when clicking outside
		document.getElementById('rejectModal').addEventListener('click', function(e) {
			if (e.target === this) {
				closeRejectModal();
			}
		});
		
		document.getElementById('cancelModal').addEventListener('click', function(e) {
			if (e.target === this) {
				closeCancelModal();
			}
		});
		
		// Close modals on Escape key
		document.addEventListener('keydown', function(e) {
			if (e.key === 'Escape') {
				closeRejectModal();
				closeCancelModal();
			}
		});
	</script>
</body>
</html>
