<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/email.php';

$pdo = db();
$tab = isset($_GET['tab']) && in_array($_GET['tab'], ['departure','arrival','private'], true) ? $_GET['tab'] : 'departure';

// Ensure private_slots_enabled column exists
try {
	$pdo->exec('ALTER TABLE events ADD COLUMN private_slots_enabled TINYINT(1) NOT NULL DEFAULT 0');
} catch (Throwable $e) {
	// Column might already exist, ignore
}

// Get event status
$event = $pdo->query('SELECT is_open, private_slots_enabled, points_criteria FROM events ORDER BY id DESC LIMIT 1')->fetch();
$isOpen = !empty($event['is_open']);
$privateSlotsEnabled = !empty($event['private_slots_enabled'] ?? 0);
$pointsCriteria = $event['points_criteria'] ?? '';

// Handle booking action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (!csrf_validate($_POST['csrf'] ?? '')) {
		redirect_with_message(base_url('timetable.php?tab=' . $tab), 'error', 'Invalid CSRF token.');
	}
	require_login();
	$user = current_user();
	$action = $_POST['action'] ?? '';
	$flightId = (int)($_POST['flight_id'] ?? 0);
	if ($flightId > 0) {
		if ($action === 'book') {
			// Check if system is open for booking
			if (!$isOpen) {
				redirect_with_message(base_url('timetable.php?tab=' . $tab), 'error', 'Booking is currently not available. The system is closed.');
			}
			// Ensure not already booked
			$stmt = $pdo->prepare('SELECT id FROM bookings WHERE flight_id = ?');
			$stmt->execute([$flightId]);
			if ($stmt->fetch()) {
				redirect_with_message(base_url('timetable.php?tab=' . $tab), 'error', 'This slot is already booked.');
			}
			$stmt = $pdo->prepare('INSERT INTO bookings (flight_id, booked_by_vid) VALUES (?, ?)');
			$stmt->execute([$flightId, $user['vid']]);
			
			// Get flight details for email
			$stmt = $pdo->prepare('SELECT * FROM flights WHERE id = ?');
			$stmt->execute([$flightId]);
			$flight = $stmt->fetch();
			
			// Send booking confirmation email
			if ($flight) {
				send_booking_confirmation_email($user, $flight);
			}
			
			redirect_with_message(base_url('timetable.php?tab=' . $tab), 'success', 'Booked successfully.');
		} elseif ($action === 'unbook') {
			// Get flight and booking details before deletion
			$stmt = $pdo->prepare('SELECT f.*, b.booked_by_vid FROM flights f INNER JOIN bookings b ON b.flight_id = f.id WHERE f.id = ?');
			$stmt->execute([$flightId]);
			$bookingData = $stmt->fetch();
			
			// Allow only the same user (or admin) to unbook
			if (is_admin()) {
				$stmt = $pdo->prepare('DELETE FROM bookings WHERE flight_id = ?');
				$stmt->execute([$flightId]);
				
				// Send cancellation email if booking existed
				if ($bookingData) {
					$stmt = $pdo->prepare('SELECT vid, name, email FROM users WHERE vid = ?');
					$stmt->execute([$bookingData['booked_by_vid']]);
					$bookedUser = $stmt->fetch();
					if ($bookedUser && $bookingData) {
						send_booking_cancellation_email($bookedUser, $bookingData);
					}
				}
				
				redirect_with_message(base_url('timetable.php?tab=' . $tab), 'success', 'Booking cleared.');
			} else {
				$stmt = $pdo->prepare('DELETE FROM bookings WHERE flight_id = ? AND booked_by_vid = ?');
				$stmt->execute([$flightId, $user['vid']]);
				
				if ($stmt->rowCount() > 0 && $bookingData) {
					// Send cancellation email
					send_booking_cancellation_email($user, $bookingData);
				}
				
				redirect_with_message(base_url('timetable.php?tab=' . $tab), 'success', 'Your booking has been cancelled.');
			}
		}
	}
}

$stmt = $pdo->prepare('SELECT f.*, b.booked_by_vid FROM flights f LEFT JOIN bookings b ON b.flight_id = f.id WHERE f.category = ? ORDER BY f.departure_time_zulu ASC');
$stmt->execute([$tab === 'private' ? 'private' : $tab]);
$flights = $stmt->fetchAll();

// Meta page variables
$MetaPageTitle = "Timetable - IVAO Middle East Division";
$MetaPageDescription = "View and book flight slots for departures, arrivals, and private flights. Browse available flights and reserve your slot.";
$MetaPageKeywords = "IVAO, timetable, flight slots, departures, arrivals, private flights, bookings";
$MetaPageURL = base_url('timetable.php');
$MetaPageImage = base_url('public/uploads/logo.png');
?>
<!doctype html>
<html>
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title>Timetable - IVAO Middle East Division</title>
	<link rel="icon" type="image/x-icon" href="<?php echo e(base_url('public/uploads/favicon.ico')); ?>" />
	<link rel="stylesheet" href="<?php echo e(base_url('public/assets/styles.css')); ?>" />
	<style>
		.flight-modal { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: linear-gradient(135deg, #05060b 0%, #0a0e1a 50%, #0f1117 100%); z-index: 1000; align-items: center; justify-content: center; padding: 20px; backdrop-filter: blur(6px); }
		.flight-modal.active { display: flex; }
		.flight-modal-content { background: linear-gradient(135deg, var(--bg-card) 0%, rgba(37, 45, 61, 0.5) 100%); border: 1px solid var(--border); border-radius: 12px; max-width: 500px; width: 100%; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,0.5); position: relative; backdrop-filter: blur(10px); }
		.flight-modal-header { padding: 20px 24px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
		.flight-modal-close { background: none; border: none; font-size: 24px; cursor: pointer; padding: 0; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; color: var(--text-secondary); transition: color 0.2s; }
		.flight-modal-close:hover { color: var(--text-primary); }
		.flight-modal-body { padding: 24px; }
		.flight-header { display: flex; align-items: center; gap: 12px; margin-bottom: 24px; }
		.airline-logo { min-width: 64px; height: 64px; border-radius: 8px; background: transparent; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 12px; flex-shrink: 0; padding: 0 8px; white-space: nowrap; }
		.flight-info { display: flex; flex-direction: column; gap: 4px; }
		.flight-number { font-size: 24px; font-weight: 700; color: var(--text-primary); }
		.airline-name { font-size: 16px; color: var(--text-secondary); font-weight: 500; }
		.flight-route { display: grid; grid-template-columns: 1fr auto 1fr; gap: 16px; align-items: center; margin-bottom: 24px; }
		.airport-section { text-align: center; }
		.airport-flag { font-size: 32px; margin-bottom: 8px; }
		.airport-icao { font-size: 20px; font-weight: 700; color: var(--text-primary); margin-bottom: 4px; }
		.airport-name { font-size: 14px; color: var(--text-secondary); }
		.aircraft-section { text-align: center; }
		.aircraft-icon { font-size: 28px; margin-bottom: 8px; }
		.aircraft-type { font-size: 16px; font-weight: 600; color: var(--text-primary); }
		.departure-time { text-align: center; margin: 16px 0; }
		.time-value { font-size: 32px; font-weight: 700; color: var(--text-primary); }
		.time-label { font-size: 12px; color: var(--text-secondary); text-transform: uppercase; margin-top: 4px; }
		.award-points { background: rgba(52, 65, 84, 0.3); border: 1px solid var(--border); padding: 16px; border-radius: 8px; margin: 24px 0; text-align: center; font-size: 14px; color: var(--text-secondary); }
		.award-points strong { color: var(--text-primary); font-weight: 700; }
		.flight-modal-actions { display: flex; gap: 12px; margin-top: 24px; }
		.flight-modal-actions button { flex: 1; }
		
		@media (max-width: 480px) {
			.flight-modal { padding: 10px; }
			.flight-modal-content { max-width: 100%; border-radius: 8px; }
			.flight-modal-header { padding: 16px; }
			.flight-modal-body { padding: 16px; }
			.flight-header { flex-direction: column; align-items: flex-start; gap: 8px; }
			.flight-route { grid-template-columns: 1fr; gap: 16px; }
			.flight-modal-actions { flex-direction: column; gap: 8px; }
			.flight-number { font-size: 20px; }
			.airport-flag { font-size: 24px; }
			.airport-icao { font-size: 18px; }
			.time-value { font-size: 28px; }
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
					<h2 class="navbar-title">Flight Bookings</h2>
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
				<?php flash(); ?>
				
				<div class="tabs">
					<a class="tab <?php echo $tab==='departure'?'active':''; ?>" href="?tab=departure">Departure</a>
					<a class="tab <?php echo $tab==='arrival'?'active':''; ?>" href="?tab=arrival">Arrival</a>
					<a class="tab <?php echo $tab==='private'?'active':''; ?>" href="?tab=private">Private Slots</a>
					<div class="tabs-spacer"></div>
					<?php if (!empty(current_user())): ?>
						<a class="btn secondary btn-small" href="<?php echo e(base_url('my_bookings.php')); ?>">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
								<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
								<circle cx="12" cy="7" r="4"></circle>
							</svg>
							My Bookings
						</a>
					<?php endif; ?>
					<?php if ($privateSlotsEnabled && $isOpen): ?>
						<a class="btn primary btn-small" href="<?php echo e(base_url('private_request.php')); ?>">Request Slot</a>
					<?php elseif (!$privateSlotsEnabled): ?>
						<a class="btn secondary btn-small" href="<?php echo e(base_url('private_request.php')); ?>" style="opacity: 0.6; cursor: not-allowed;" onclick="return false;" title="Private slots feature is disabled">Request Slot</a>
					<?php else: ?>
						<a class="btn secondary btn-small" href="<?php echo e(base_url('private_request.php')); ?>" style="opacity: 0.6; cursor: not-allowed;" onclick="return false;" title="Booking system is closed">Request Slot</a>
					<?php endif; ?>
				</div>
				
				<div class="card">
					<div class="table-wrapper">
						<table class="table">
							<thead>
								<tr>
									<th>Flight Number</th>
									<th>Airline Name</th>
									<th>Aircraft</th>
									<th>Departure Airport</th>
									<th>Destination</th>
									<th>Departure Time</th>
									<th>Gate</th>
									<th>Booking</th>
								</tr>
							</thead>
							<tbody>
							<?php foreach ($flights as $f): 
								// Get airport details for modal
								$originIcao = $f['origin_icao'];
								$destIcao = $f['destination_icao'];
								$originName = get_airport_name_from_icao($originIcao);
								$destName = get_airport_name_from_icao($destIcao);
								
								// Get country codes from airports table
								$originCountry = get_airport_country_code($originIcao);
								$destCountry = get_airport_country_code($destIcao);
								
								// Get airline IATA/ICAO codes from flight record (if available)
								$airlineIata = $f['airline_iata'] ?? null;
								$airlineIcao = $f['airline_icao'] ?? null;
								
								// Always get airline name from airlines table to ensure consistency
								// This ensures the name matches what's in manage_airlines.php
								// Priority: 1) airlines table by IATA/ICAO, 2) airlines table by flight number, 3) stored name
								$airlineName = get_canonical_airline_name(
									$f['airline_name'] ?? '', 
									$airlineIata, 
									$airlineIcao,
									$f['flight_number'] ?? null
								);
								
								// If canonical name found, use it (from airlines table)
								// Otherwise fallback to stored name or lookup from flight number
								if (empty($airlineName)) {
									// Try lookup from flight number first (might find in airlines table)
									$airlineName = get_airline_name_from_flight($f['flight_number']);
									// Last resort: use stored name from flights table
									if (empty($airlineName)) {
										$airlineName = isset($f['airline_name']) && trim($f['airline_name']) !== '' 
											? trim($f['airline_name']) 
											: '-';
									}
								}
								
								// Get airline IATA/ICAO codes from airlines table if not stored
								if (empty($airlineIata) && empty($airlineIcao)) {
									$airlineCodes = get_airline_codes($airlineName, $f['flight_number']);
									$airlineIata = $airlineCodes['iata'];
									$airlineIcao = $airlineCodes['icao'];
								}
							?>
							<tr>
								<td data-label="Flight Number"><?php echo e($f['flight_number']); ?></td>
								<td data-label="Airline Name"><?php echo e($airlineName ?: '-'); ?></td>
								<td data-label="Aircraft"><?php echo e($f['aircraft']); ?></td>
								<td data-label="Departure Airport"><?php 
									$originIcaoDisplay = e($originIcao);
									echo $originName ? $originIcaoDisplay . ' - ' . e($originName) : $originIcaoDisplay;
								?></td>
								<td data-label="Destination"><?php 
									$destIcaoDisplay = e($destIcao);
									echo $destName ? $destIcaoDisplay . ' - ' . e($destName) : $destIcaoDisplay;
								?></td>
								<td data-label="Departure Time"><?php echo e($f['departure_time_zulu']); ?></td>
								<td data-label="Gate"><?php echo e($f['gate'] ?? '-'); ?></td>
								<td data-label="Booking">
									<?php 
										$currentUserVid = current_user()['vid'] ?? '';
										$isBooked = !empty($f['booked_by_vid']);
										$isBookedByUser = $isBooked && $f['booked_by_vid'] === $currentUserVid;
										$buttonText = $isBooked ? 'Booked' : 'Details';
										$buttonClass = $isBooked ? 'btn danger btn-small' : 'btn secondary btn-small';
									?>
									<button class="<?php echo e($buttonClass); ?>" onclick="openFlightModal(<?php echo htmlspecialchars(json_encode([
										'id' => $f['id'],
										'flight_number' => $f['flight_number'],
										'airline_name' => $airlineName ?: '',
										'airline_iata' => $airlineIata,
										'airline_icao' => $airlineIcao,
										'aircraft' => $f['aircraft'],
										'origin_icao' => $originIcao,
										'origin_name' => $originName ?: '',
										'origin_country' => $originCountry,
										'destination_icao' => $destIcao,
										'destination_name' => $destName ?: '',
										'destination_country' => $destCountry,
										'departure_time' => $f['departure_time_zulu'],
										'gate' => $f['gate'] ?? '',
										'booked_by_vid' => $f['booked_by_vid'] ?? null,
										'is_open' => $isOpen,
										'is_admin' => is_admin(),
										'current_user_vid' => $currentUserVid,
										'points_criteria' => $pointsCriteria
									]), ENT_QUOTES, 'UTF-8'); ?>)"><?php echo e($buttonText); ?></button>
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
	
	<!-- Flight Details Modal -->
	<div class="flight-modal" id="flightModal">
		<div class="flight-modal-content">
			<div class="flight-modal-header">
				<div class="flight-header">
					<div class="airline-logo" id="modalAirlineLogo"></div>
					<div class="flight-info">
						<div class="flight-number" id="modalFlightNumber"></div>
						<div class="airline-name" id="modalAirlineName"></div>
					</div>
				</div>
				<button class="flight-modal-close" onclick="closeFlightModal()">&times;</button>
			</div>
			<div class="flight-modal-body">
				<div class="flight-route">
					<div class="airport-section">
						<div class="airport-flag" id="modalOriginFlag"></div>
						<div class="airport-icao" id="modalOriginIcao"></div>
						<div class="airport-name" id="modalOriginName"></div>
					</div>
					<div class="aircraft-section">
						<div class="aircraft-icon">✈️</div>
						<div class="aircraft-type" id="modalAircraft"></div>
					</div>
					<div class="airport-section">
						<div class="airport-flag" id="modalDestFlag"></div>
						<div class="airport-icao" id="modalDestIcao"></div>
						<div class="airport-name" id="modalDestName"></div>
					</div>
				</div>
				<div class="departure-time">
					<div class="time-value" id="modalDepartureTime"></div>
					<div class="time-label">UTC</div>
				</div>
				<div class="award-points" id="modalAwardPoints">
					This flight will grant you <strong>0</strong> point/s of XM Pilot Event Award
				</div>
				<div class="award-points" id="modalBookingInfo" style="display: none;">
					<strong>Booked by VID:</strong> <span id="modalBookedByVid"></span>
				</div>
				<div class="flight-modal-actions">
					<button class="btn secondary" onclick="closeFlightModal()">Close</button>
					<form method="post" id="modalBookForm" style="flex: 1; display: none;">
						<input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>" />
						<input type="hidden" name="flight_id" id="modalFlightId" />
						<input type="hidden" name="action" value="book" />
						<button class="btn primary btn-block" type="submit">Book</button>
					</form>
					<form method="post" id="modalCancelForm" style="flex: 1; display: none;">
						<input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>" />
						<input type="hidden" name="flight_id" id="modalCancelFlightId" />
						<input type="hidden" name="action" value="unbook" />
						<button class="btn danger btn-block" type="submit" onclick="return confirm('Cancel this booking?')">Cancel Booking</button>
					</form>
				</div>
			</div>
		</div>
	</div>
	
	<script>
		let currentFlightData = null;
		
		function getCountryFlag(countryCode) {
			if (!countryCode || countryCode.length !== 2) return '🌍';
			// Convert country code to flag emoji
			const codePoints = countryCode.toUpperCase().split('').map(char => 127397 + char.charCodeAt());
			return String.fromCodePoint(...codePoints);
		}
		
		function setAirlineLogo(logoElement, iata, icao, airlineName) {
			// Show full airline name or fallback
			const displayText = airlineName || iata || icao || 'Airline';
			
			// Clear previous content
			logoElement.innerHTML = '';
			
			// Try to load image from Kiwi.com if IATA code is available
			if (iata && iata.length === 2) {
				const img = document.createElement('img');
				img.src = `https://images.kiwi.com/airlines/64x64/${iata}.png?default=airline.png`;
				img.alt = displayText;
				img.style.width = '100%';
				img.style.height = '100%';
				img.style.objectFit = 'contain';
				img.style.borderRadius = '4px';
				
				// Fallback to text if image fails to load
				img.onerror = function() {
					logoElement.innerHTML = displayText;
					logoElement.style.display = 'flex';
					logoElement.style.alignItems = 'center';
					logoElement.style.justifyContent = 'center';
					logoElement.style.fontSize = '12px';
					logoElement.style.padding = '0 8px';
					logoElement.style.whiteSpace = 'nowrap';
					logoElement.style.textAlign = 'center';
				};
				
				logoElement.appendChild(img);
			} else {
				// No IATA code, show text fallback
				logoElement.innerHTML = displayText;
				logoElement.style.display = 'flex';
				logoElement.style.alignItems = 'center';
				logoElement.style.justifyContent = 'center';
				logoElement.style.fontSize = '12px';
				logoElement.style.padding = '0 8px';
				logoElement.style.whiteSpace = 'nowrap';
				logoElement.style.textAlign = 'center';
			}
		}
		
		function openFlightModal(flightData) {
			currentFlightData = flightData;
			const modal = document.getElementById('flightModal');
			
			// Set flight number
			document.getElementById('modalFlightNumber').textContent = flightData.flight_number;
			
			// Set airline logo (try to load from web, fallback to text)
			const airlineLogo = document.getElementById('modalAirlineLogo');
			const airlineName = flightData.airline_name || '';
			
			// Set airline name
			document.getElementById('modalAirlineName').textContent = airlineName || 'Airline';
			
			// Use IATA/ICAO from flight data - prioritize database values
			const iataCode = flightData.airline_iata || null;
			const icaoCode = flightData.airline_icao || null;
			
			setAirlineLogo(airlineLogo, iataCode, icaoCode, airlineName);
			
			// Set departure
			document.getElementById('modalOriginFlag').textContent = getCountryFlag(flightData.origin_country);
			document.getElementById('modalOriginIcao').textContent = flightData.origin_icao;
			document.getElementById('modalOriginName').textContent = flightData.origin_name || flightData.origin_icao;
			
			// Set destination
			document.getElementById('modalDestFlag').textContent = getCountryFlag(flightData.destination_country);
			document.getElementById('modalDestIcao').textContent = flightData.destination_icao;
			document.getElementById('modalDestName').textContent = flightData.destination_name || flightData.destination_icao;
			
			// Set aircraft
			document.getElementById('modalAircraft').textContent = flightData.aircraft;
			
			// Set departure time
			document.getElementById('modalDepartureTime').textContent = flightData.departure_time;
			
			// Set award points
			const awardPointsEl = document.getElementById('modalAwardPoints');
			if (flightData.points_criteria && flightData.points_criteria.trim() !== '') {
				awardPointsEl.innerHTML = 'This flight will grant you <strong>' + flightData.points_criteria + '</strong> point/s of XM Pilot Event Award';
			} else {
				awardPointsEl.innerHTML = 'This flight will grant you <strong>0</strong> point/s of XM Pilot Event Award';
			}
			
			// Set booking info
			const bookingInfoEl = document.getElementById('modalBookingInfo');
			const bookedByVidEl = document.getElementById('modalBookedByVid');
			if (flightData.booked_by_vid) {
				bookedByVidEl.textContent = flightData.booked_by_vid;
				bookingInfoEl.style.display = 'block';
			} else {
				bookingInfoEl.style.display = 'none';
			}
			
		// Set book/cancel forms
		document.getElementById('modalFlightId').value = flightData.id;
		document.getElementById('modalCancelFlightId').value = flightData.id;
		
		const bookForm = document.getElementById('modalBookForm');
		const cancelForm = document.getElementById('modalCancelForm');
		
		// Check if user is logged in
		const isLoggedIn = !!(flightData.current_user_vid && flightData.current_user_vid !== '');
		
		// Show book button if user is logged in, slot is not booked, and system is open
		if (isLoggedIn && !flightData.booked_by_vid && flightData.is_open) {
			bookForm.style.display = 'block';
			cancelForm.style.display = 'none';
		} 
		// Show cancel button if booked by current user or admin
		else if (flightData.booked_by_vid && 
				 (flightData.is_admin || flightData.current_user_vid === flightData.booked_by_vid)) {
			bookForm.style.display = 'none';
			cancelForm.style.display = 'block';
		} 
		// Hide both if not logged in, booked by someone else, or system is closed
		else {
			bookForm.style.display = 'none';
			cancelForm.style.display = 'none';
		}
		
		modal.classList.add('active');
	}
		
	function closeFlightModal() {
		document.getElementById('flightModal').classList.remove('active');
		currentFlightData = null;
	}
		
	// Close modal on outside click
	document.getElementById('flightModal').addEventListener('click', function(e) {
		if (e.target === this) {
			closeFlightModal();
		}
	});
		
	// Close modal on Escape key
	document.addEventListener('keydown', function(e) {
		if (e.key === 'Escape') {
			closeFlightModal();
		}
	});
	</script>
	
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
	
	<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
