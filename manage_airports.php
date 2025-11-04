<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/helpers.php';

require_login();
if (!is_admin()) {
	redirect_with_message(base_url(''), 'error', 'Admins only.');
}

$pdo = db();

// Ensure airports table exists
try {
	$pdo->exec("CREATE TABLE IF NOT EXISTS airports (
		id INT AUTO_INCREMENT PRIMARY KEY,
		country_code VARCHAR(2) NULL,
		region_name VARCHAR(100) NULL,
		iata VARCHAR(3) NULL,
		icao VARCHAR(4) NOT NULL,
		airport_name VARCHAR(200) NOT NULL,
		created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
		updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
		UNIQUE KEY uk_icao (icao),
		INDEX idx_iata (iata),
		INDEX idx_country (country_code)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {
	// Table might already exist, continue
}

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 50;
$search = trim($_GET['search'] ?? '');

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (!csrf_validate($_POST['csrf'] ?? '')) {
		redirect_with_message(base_url('manage_airports.php'), 'error', 'Invalid CSRF token.');
	}
	
	$action = $_POST['action'] ?? '';
	
	if ($action === 'edit' || $action === 'add') {
		$countryCode = trim($_POST['country_code'] ?? '') ?: null;
		$regionName = trim($_POST['region_name'] ?? '') ?: null;
		$iata = strtoupper(trim($_POST['iata'] ?? '')) ?: null;
		$icao = strtoupper(trim($_POST['icao'] ?? ''));
		$airportName = trim($_POST['airport_name'] ?? '');
		
		if (empty($icao)) {
			redirect_with_message(base_url('manage_airports.php'), 'error', 'ICAO code is required.');
		}
		if (empty($airportName)) {
			redirect_with_message(base_url('manage_airports.php'), 'error', 'Airport name is required.');
		}
		
		if ($action === 'edit') {
			$originalIcao = strtoupper(trim($_POST['original_icao'] ?? ''));
			
			// Check if ICAO is being changed and new ICAO already exists
			if ($originalIcao !== $icao) {
				$stmt = $pdo->prepare('SELECT id FROM airports WHERE icao = ? LIMIT 1');
				$stmt->execute([$icao]);
				if ($stmt->fetch()) {
					redirect_with_message(base_url('manage_airports.php'), 'error', 'An airport with this ICAO code already exists.');
				}
			}
			
			$stmt = $pdo->prepare('UPDATE airports SET country_code = ?, region_name = ?, iata = ?, icao = ?, airport_name = ? WHERE icao = ?');
			$stmt->execute([$countryCode, $regionName, $iata, $icao, $airportName, $originalIcao]);
			
			if ($stmt->rowCount() > 0) {
				redirect_with_message(base_url('manage_airports.php'), 'success', 'Airport updated successfully.');
			} else {
				redirect_with_message(base_url('manage_airports.php'), 'error', 'Airport not found.');
			}
		} else {
			// Check if ICAO already exists
			$stmt = $pdo->prepare('SELECT id FROM airports WHERE icao = ? LIMIT 1');
			$stmt->execute([$icao]);
			if ($stmt->fetch()) {
				redirect_with_message(base_url('manage_airports.php'), 'error', 'An airport with this ICAO code already exists.');
			}
			
			// Add new airport
			$stmt = $pdo->prepare('INSERT INTO airports (country_code, region_name, iata, icao, airport_name) VALUES (?, ?, ?, ?, ?)');
			$stmt->execute([$countryCode, $regionName, $iata, $icao, $airportName]);
			
			redirect_with_message(base_url('manage_airports.php'), 'success', 'Airport added successfully.');
		}
	}
	
	if ($action === 'delete') {
		$icao = strtoupper(trim($_POST['icao'] ?? ''));
		if (empty($icao)) {
			redirect_with_message(base_url('manage_airports.php'), 'error', 'ICAO code is required.');
		}
		
		$stmt = $pdo->prepare('DELETE FROM airports WHERE icao = ?');
		$stmt->execute([$icao]);
		
		if ($stmt->rowCount() > 0) {
			redirect_with_message(base_url('manage_airports.php'), 'success', 'Airport deleted successfully.');
		} else {
			redirect_with_message(base_url('manage_airports.php'), 'error', 'Airport not found.');
		}
	}
}

// Build query for airports
$where = [];
$params = [];

if (!empty($search)) {
	$where[] = '(icao LIKE ? OR iata LIKE ? OR airport_name LIKE ? OR country_code LIKE ? OR region_name LIKE ?)';
	$searchParam = '%' . $search . '%';
	$params = [$searchParam, $searchParam, $searchParam, $searchParam, $searchParam];
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Get total count
$countStmt = $pdo->prepare("SELECT COUNT(*) as total FROM airports {$whereClause}");
$countStmt->execute($params);
$totalAirports = (int)$countStmt->fetch()['total'];

$totalPages = max(1, ceil($totalAirports / $perPage));
$offset = ($page - 1) * $perPage;

// Get airports (LIMIT and OFFSET must be integers, not bound parameters)
$limit = (int)$perPage;
$offset = (int)$offset;
$stmt = $pdo->prepare("SELECT * FROM airports {$whereClause} ORDER BY icao ASC LIMIT {$limit} OFFSET {$offset}");
$stmt->execute($params);
$airports = $stmt->fetchAll();

// Get airport for editing (if requested)
$editAirport = null;
if (isset($_GET['edit'])) {
	$editIcao = strtoupper(trim($_GET['edit']));
	$stmt = $pdo->prepare('SELECT * FROM airports WHERE icao = ? LIMIT 1');
	$stmt->execute([$editIcao]);
	$editAirport = $stmt->fetch();
}

// Meta page variables
$MetaPageTitle = "Manage Airports - IVAO Middle East Division";
$MetaPageDescription = "Administrative panel for managing airports database. Add, edit, and search airports with ICAO, IATA codes, names, and locations.";
$MetaPageKeywords = "IVAO, manage airports, airport database, ICAO, IATA, airport management";
$MetaPageURL = base_url('manage_airports.php');
$MetaPageImage = base_url('public/uploads/logo.png');
?>
<!doctype html>
<html>
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title>Manage Airports - IVAO Middle East Division</title>
	<link rel="icon" type="image/x-icon" href="<?php echo e(base_url('public/uploads/favicon.ico')); ?>" />
	<link rel="stylesheet" href="<?php echo e(base_url('public/assets/styles.css')); ?>" />
	<style>
		.search-form { display: flex; gap: 8px; margin-bottom: 16px; }
		.search-form input { flex: 1; }
		.pagination { display: flex; gap: 8px; align-items: center; margin-top: 16px; }
		.pagination a { padding: 6px 12px; text-decoration: none; border: 1px solid var(--border); border-radius: 4px; color: var(--text-primary); }
		.pagination a:hover { background: var(--primary); color: white; border-color: var(--primary); }
		.pagination .active { background: var(--primary); color: white; border-color: var(--primary); }
		.modal { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.8); z-index: 1000; align-items: center; justify-content: center; }
		.modal.active { display: flex; }
		.modal-content { background: var(--bg-card); border: 1px solid var(--border); padding: 24px; border-radius: 8px; max-width: 500px; width: 90%; max-height: 90vh; overflow-y: auto; }
		.modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
		.modal-header h3 { margin: 0; color: var(--text-primary); }
		.close-modal { background: none; border: none; font-size: 24px; cursor: pointer; padding: 0; width: 32px; height: 32px; color: var(--text-secondary); }
		.close-modal:hover { color: var(--text-primary); }
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
					<a href="<?php echo e(base_url('admin.php')); ?>" class="sidebar-item" title="Admin">
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
					<h2 class="navbar-title">Manage Airports</h2>
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
				<?php flash(); ?>
				
				<div class="card">
					<div class="card-header">
						<h2>Airports (<?php echo number_format($totalAirports); ?>)</h2>
						<div class="card-header-actions">
							<button class="btn primary" onclick="openAddModal()">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
									<line x1="12" y1="5" x2="12" y2="19"></line>
									<line x1="5" y1="12" x2="19" y2="12"></line>
								</svg>
								Add Airport
							</button>
						</div>
					</div>
					
					<form method="get" class="search-form">
						<input type="text" name="search" class="input" placeholder="Search by ICAO, IATA, name, country, or region..." value="<?php echo e($search); ?>" />
						<button type="submit" class="btn secondary">Search</button>
						<?php if (!empty($search)): ?>
							<a href="<?php echo e(base_url('manage_airports.php')); ?>" class="btn secondary">Clear</a>
						<?php endif; ?>
					</form>
					
					<div class="table-wrapper">
						<table class="table">
							<thead>
								<tr>
									<th>ICAO</th>
									<th>IATA</th>
									<th>Airport Name</th>
									<th>Country</th>
									<th>Region</th>
									<th>Actions</th>
								</tr>
							</thead>
							<tbody>
								<?php if (empty($airports)): ?>
									<tr>
										<td colspan="6" style="text-align: center; padding: 32px;">
											<?php if (!empty($search)): ?>
												No airports found matching your search.
											<?php else: ?>
												No airports found. <a href="?action=import" onclick="return confirm('Import from CSV?');">Import from CSV</a>
											<?php endif; ?>
										</td>
									</tr>
								<?php else: ?>
									<?php foreach ($airports as $airport): ?>
									<tr>
										<td data-label="ICAO"><strong><?php echo e($airport['icao']); ?></strong></td>
										<td data-label="IATA"><?php echo e($airport['iata'] ?? '-'); ?></td>
										<td data-label="Airport Name"><?php echo e($airport['airport_name']); ?></td>
										<td data-label="Country"><?php echo e($airport['country_code'] ?? '-'); ?></td>
										<td data-label="Region"><?php echo e($airport['region_name'] ?? '-'); ?></td>
										<td data-label="Actions">
											<a href="?edit=<?php echo e(urlencode($airport['icao'])); ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo $page > 1 ? '&page=' . $page : ''; ?>" class="btn btn-small secondary" style="margin-right: 4px;">Edit</a>
											<form method="post" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this airport?');">
												<input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>" />
												<input type="hidden" name="action" value="delete" />
												<input type="hidden" name="icao" value="<?php echo e($airport['icao']); ?>" />
												<button type="submit" class="btn btn-small danger">Delete</button>
											</form>
										</td>
									</tr>
									<?php endforeach; ?>
								<?php endif; ?>
							</tbody>
						</table>
					</div>
					
					<?php if ($totalPages > 1): ?>
						<div class="pagination">
							<?php if ($page > 1): ?>
								<a href="?page=<?php echo $page - 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>">Previous</a>
							<?php endif; ?>
							<span>Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
							<?php if ($page < $totalPages): ?>
								<a href="?page=<?php echo $page + 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>">Next</a>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>
	
	<!-- Edit/Add Modal -->
	<div class="modal <?php echo $editAirport !== null ? 'active' : ''; ?>" id="airportModal">
		<div class="modal-content">
			<div class="modal-header">
				<h3><?php echo $editAirport !== null ? 'Edit Airport' : 'Add Airport'; ?></h3>
				<button type="button" class="close-modal" onclick="closeModal()">&times;</button>
			</div>
			<form method="post">
				<input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>" />
				<input type="hidden" name="action" value="<?php echo $editAirport !== null ? 'edit' : 'add'; ?>" />
				<?php if ($editAirport !== null): ?>
					<input type="hidden" name="original_icao" value="<?php echo e($editAirport['icao']); ?>" />
				<?php endif; ?>
				
				<div class="form-group">
					<label class="label">ICAO Code *</label>
					<input type="text" name="icao" class="input" value="<?php echo e($editAirport['icao'] ?? ''); ?>" required maxlength="4" pattern="[A-Z]{4}" style="text-transform: uppercase;" />
				</div>
				
				<div class="form-group">
					<label class="label">IATA Code</label>
					<input type="text" name="iata" class="input" value="<?php echo e($editAirport['iata'] ?? ''); ?>" maxlength="3" pattern="[A-Z]{3}" style="text-transform: uppercase;" />
				</div>
				
				<div class="form-group">
					<label class="label">Airport Name *</label>
					<input type="text" name="airport_name" class="input" value="<?php echo e($editAirport['airport_name'] ?? ''); ?>" required />
				</div>
				
				<div class="form-group">
					<label class="label">Country Code</label>
					<input type="text" name="country_code" class="input" value="<?php echo e($editAirport['country_code'] ?? ''); ?>" maxlength="2" />
				</div>
				
				<div class="form-group">
					<label class="label">Region Name</label>
					<input type="text" name="region_name" class="input" value="<?php echo e($editAirport['region_name'] ?? ''); ?>" />
				</div>
				
				<div class="form-group" style="display: flex; gap: 8px; margin-top: 16px;">
					<button type="submit" class="btn primary" style="flex: 1;"><?php echo $editAirport !== null ? 'Update' : 'Add'; ?> Airport</button>
					<button type="button" class="btn secondary" onclick="closeModal()" style="flex: 1;">Cancel</button>
				</div>
			</form>
		</div>
	</div>
	
	<script>
		function openAddModal() {
			document.getElementById('airportModal').classList.add('active');
		}
		
		function closeModal() {
			document.getElementById('airportModal').classList.remove('active');
			<?php if ($editAirport !== null): ?>
				window.location.href = '<?php echo e(base_url('manage_airports.php')); ?>';
			<?php endif; ?>
		}
		
		// Auto-uppercase ICAO and IATA inputs
		document.querySelectorAll('input[name="icao"], input[name="iata"]').forEach(input => {
			input.addEventListener('input', function() {
				this.value = this.value.toUpperCase();
			});
		});
		
		// Close modal on outside click
		document.getElementById('airportModal').addEventListener('click', function(e) {
			if (e.target === this) {
				closeModal();
			}
		});
		
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
	
	<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
