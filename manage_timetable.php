<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/helpers.php';

require_login();
if (!is_admin()) {
	redirect_with_message(base_url(''), 'error', 'Admins only.');
}
$pdo = db();

// Ensure flights has optional route column (best-effort)
try {
    $pdo->exec('ALTER TABLE flights ADD COLUMN IF NOT EXISTS route TEXT NULL AFTER departure_time_zulu');
} catch (Throwable $e) { /* older MySQL versions may not support IF NOT EXISTS; try plain add */
    try { $pdo->exec('ALTER TABLE flights ADD COLUMN route TEXT NULL'); } catch (Throwable $e2) { /* ignore */ }
}

// Ensure flights has optional airline_name column (best-effort)
try {
    $pdo->exec('ALTER TABLE flights ADD COLUMN IF NOT EXISTS airline_name VARCHAR(100) NULL AFTER flight_number');
} catch (Throwable $e) {
    try { 
        $pdo->exec('ALTER TABLE flights ADD COLUMN airline_name VARCHAR(100) NULL');
    } catch (Throwable $e2) { 
        // Check if column already exists
        $stmt = $pdo->query("SHOW COLUMNS FROM flights LIKE 'airline_name'");
        if ($stmt->rowCount() == 0) {
            // Column doesn't exist, try to add it after flight_number
            try {
                $pdo->exec('ALTER TABLE flights ADD COLUMN airline_name VARCHAR(100) NULL AFTER flight_number');
            } catch (Throwable $e3) { /* ignore */ }
        }
    }
}

// Ensure flights has optional airline_iata and airline_icao columns (best-effort)
try {
    $pdo->exec('ALTER TABLE flights ADD COLUMN IF NOT EXISTS airline_iata VARCHAR(2) NULL AFTER airline_name');
} catch (Throwable $e) {
    try { 
        $pdo->exec('ALTER TABLE flights ADD COLUMN airline_iata VARCHAR(2) NULL');
    } catch (Throwable $e2) { 
        $stmt = $pdo->query("SHOW COLUMNS FROM flights LIKE 'airline_iata'");
        if ($stmt->rowCount() == 0) {
            try {
                $pdo->exec('ALTER TABLE flights ADD COLUMN airline_iata VARCHAR(2) NULL AFTER airline_name');
            } catch (Throwable $e3) { /* ignore */ }
        }
    }
}

try {
    $pdo->exec('ALTER TABLE flights ADD COLUMN IF NOT EXISTS airline_icao VARCHAR(3) NULL AFTER airline_iata');
} catch (Throwable $e) {
    try { 
        $pdo->exec('ALTER TABLE flights ADD COLUMN airline_icao VARCHAR(3) NULL');
    } catch (Throwable $e2) { 
        $stmt = $pdo->query("SHOW COLUMNS FROM flights LIKE 'airline_icao'");
        if ($stmt->rowCount() == 0) {
            try {
                $pdo->exec('ALTER TABLE flights ADD COLUMN airline_icao VARCHAR(3) NULL AFTER airline_iata');
            } catch (Throwable $e3) { /* ignore */ }
        }
    }
}

// Ensure uploads directory (best effort)
@mkdir(__DIR__ . '/public/uploads', 0777, true);

// Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (!csrf_validate($_POST['csrf'] ?? '')) {
		redirect_with_message(base_url('manage_timetable.php'), 'error', 'Invalid CSRF token.');
	}
	$action = $_POST['action'] ?? '';

	if ($action === 'create' || $action === 'update') {
        $category = $_POST['category'] ?? 'departure';
		$flight_number = strtoupper(trim($_POST['flight_number'] ?? ''));
		$airline_name = trim($_POST['airline_name'] ?? '');
		$aircraft = strtoupper(trim($_POST['aircraft'] ?? ''));
		$origin_icao = strtoupper(trim($_POST['origin_icao'] ?? ''));
		$origin_name = trim($_POST['origin_name'] ?? '');
		$destination_icao = strtoupper(trim($_POST['destination_icao'] ?? ''));
		$destination_name = trim($_POST['destination_name'] ?? '');
		$departure_time_zulu = trim($_POST['departure_time_zulu'] ?? '');
        $route = trim($_POST['route'] ?? '');
		$gate = trim($_POST['gate'] ?? '');
		
		// Auto-populate airline_name if not provided using helper function
		if (empty($airline_name)) {
			$airline_name = get_airline_name_from_flight($flight_number);
		}

		// Get airline IATA/ICAO codes
		$airlineCodes = get_airline_codes($airline_name, $flight_number);
		$airline_iata = $airlineCodes['iata'];
		$airline_icao = $airlineCodes['icao'];

		// Ensure airline exists in airlines table (for manage_airlines.php)
		ensure_airline_exists($airline_name, $airline_iata, $airline_icao);

		// Always get the canonical airline name from airlines table to ensure consistency
		// This ensures flights.airline_name always matches airlines.airline_name
		$canonicalName = get_canonical_airline_name($airline_name, $airline_iata, $airline_icao, $flight_number);
		if (!empty($canonicalName)) {
			$airline_name = $canonicalName;
		}

		if (!in_array($category, ['departure','arrival','private'], true)) {
			redirect_with_message(base_url('manage_timetable.php'), 'error', 'Invalid category.');
		}
		if ($category !== 'private' && !is_valid_flight_number_public($flight_number)) {
			redirect_with_message(base_url('manage_timetable.php'), 'error', 'Invalid flight number (format AAA222).');
		}
		if ($category === 'private' && !is_valid_flight_number_private($flight_number)) {
			redirect_with_message(base_url('manage_timetable.php'), 'error', 'Invalid private flight number (max 6 alnum).');
		}
		if (!is_valid_icao($origin_icao) || !is_valid_icao($destination_icao)) {
			redirect_with_message(base_url('manage_timetable.php'), 'error', 'ICAO must be 4 letters.');
		}
		if (!is_valid_time_zulu($departure_time_zulu)) {
			redirect_with_message(base_url('manage_timetable.php'), 'error', 'Time must be HH:MM Z.');
		}

        if ($action === 'create') {
            $stmt = $pdo->prepare('INSERT INTO flights (source, flight_number, airline_name, airline_iata, airline_icao, aircraft, origin_icao, origin_name, destination_icao, destination_name, departure_time_zulu, route, gate, category) VALUES ("manual",?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$flight_number,$airline_name,$airline_iata,$airline_icao,$aircraft,$origin_icao,$origin_name,$destination_icao,$destination_name,$departure_time_zulu,$route,$gate,$category]);
			
			// Auto-sync airline name after insert
			$flightId = (int)$pdo->lastInsertId();
			if ($flightId > 0) {
				sync_flight_airline_name($flightId, $flight_number, $airline_name, $airline_iata, $airline_icao);
			}
			
			redirect_with_message(base_url('manage_timetable.php'), 'success', 'Flight added.');
		} else {
			$id = (int)($_POST['id'] ?? 0);
			
			// Get old flight data before update
			$stmt = $pdo->prepare('SELECT * FROM flights WHERE id = ?');
			$stmt->execute([$id]);
			$oldFlight = $stmt->fetch();
			
			// Update flight
            $stmt = $pdo->prepare('UPDATE flights SET flight_number=?, airline_name=?, airline_iata=?, airline_icao=?, aircraft=?, origin_icao=?, origin_name=?, destination_icao=?, destination_name=?, departure_time_zulu=?, route=?, gate=?, category=? WHERE id=?');
            $stmt->execute([$flight_number,$airline_name,$airline_iata,$airline_icao,$aircraft,$origin_icao,$origin_name,$destination_icao,$destination_name,$departure_time_zulu,$route,$gate,$category,$id]);
			
			// Auto-sync airline name after update
			if ($id > 0) {
				sync_flight_airline_name($id, $flight_number, $airline_name, $airline_iata, $airline_icao);
			}
			
			// Get updated flight data
			$stmt = $pdo->prepare('SELECT * FROM flights WHERE id = ?');
			$stmt->execute([$id]);
			$newFlight = $stmt->fetch();
			
			// Detect changes and send email notifications
			if ($oldFlight && $newFlight) {
				require_once __DIR__ . '/includes/email.php';
				
				$changes = [];
				$importantFields = ['flight_number', 'airline_name', 'aircraft', 'origin_icao', 'destination_icao', 'departure_time_zulu', 'gate'];
				
				foreach ($importantFields as $field) {
					$oldValue = $oldFlight[$field] ?? '';
					$newValue = $newFlight[$field] ?? '';
					if (trim($oldValue) !== trim($newValue)) {
						$changes[$field] = trim($oldValue) . ' → ' . trim($newValue);
					}
				}
				
				// Send email if there are changes
				if (!empty($changes)) {
					send_flight_update_email($newFlight, $changes);
				}
			}
			
			redirect_with_message(base_url('manage_timetable.php'), 'success', 'Flight updated.');
		}
	}

	if ($action === 'delete') {
		$id = (int)($_POST['id'] ?? 0);
		$pdo->prepare('DELETE FROM flights WHERE id=?')->execute([$id]);
		redirect_with_message(base_url('manage_timetable.php'), 'success', 'Flight deleted.');
	}

	if ($action === 'clear_all') {
		$pdo->exec('DELETE FROM bookings');
		$pdo->exec('DELETE FROM flights');
		redirect_with_message(base_url('manage_timetable.php'), 'success', 'All flights cleared.');
	}

	if ($action === 'sync_airline_names') {
		// Ensure airlines table exists
		try {
			$pdo->exec("CREATE TABLE IF NOT EXISTS airlines (
				id INT AUTO_INCREMENT PRIMARY KEY,
				iata VARCHAR(2) NULL,
				icao VARCHAR(3) NULL,
				airline_name VARCHAR(200) NOT NULL,
				callsign VARCHAR(50) NULL,
				created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
				INDEX idx_iata (iata),
				INDEX idx_icao (icao)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
		} catch (Throwable $e) {
			// Table might already exist
		}
		
		// Get all flights
		$stmt = $pdo->query("SELECT id, flight_number, airline_name, airline_iata, airline_icao FROM flights");
		$flights = $stmt->fetchAll();
		
		$updated = 0;
		$updatedCodes = 0;
		$notFound = 0;
		
		if (!empty($flights)) {
			$updateStmt = $pdo->prepare('UPDATE flights SET airline_name = ? WHERE id = ?');
			$updateCodesStmt = $pdo->prepare('UPDATE flights SET airline_iata = ?, airline_icao = ? WHERE id = ?');
			
			foreach ($flights as $flight) {
				$currentName = trim($flight['airline_name'] ?? '');
				$currentIata = trim($flight['airline_iata'] ?? '');
				$currentIcao = trim($flight['airline_icao'] ?? '');
				
				// First try lookup with stored codes, but also try flight number extraction
				// Flight number extraction is more reliable (e.g., FYC707 should find FYC as ICAO)
				$canonicalName = null;
				
				// Try by flight number first (most reliable - extracts codes from flight number)
				if (!empty($flight['flight_number'])) {
					$canonicalName = get_canonical_airline_name(
						'', // empty name to force flight number lookup
						null, // no IATA to force extraction
						null, // no ICAO to force extraction
						$flight['flight_number']
					);
				}
				
				// If flight number lookup didn't work, try with stored codes
				if (empty($canonicalName)) {
					$canonicalName = get_canonical_airline_name(
						$currentName, 
						$currentIata ?: null, 
						$currentIcao ?: null,
						$flight['flight_number'] ?? null
					);
				}
				
				// Always update if we found a canonical name
				if (!empty($canonicalName)) {
					$canonicalName = trim($canonicalName);
					// Update if different (case-insensitive comparison) OR if flight has no name but canonical name exists
					if (strcasecmp($canonicalName, $currentName) !== 0 || empty($currentName)) {
						$updateStmt->execute([$canonicalName, $flight['id']]);
						$updated++;
					}
					
					// Always try to update codes from airlines table (they might be wrong)
					$codes = get_airline_codes($canonicalName, $flight['flight_number']);
					$newIata = $codes['iata'] ?? null;
					$newIcao = $codes['icao'] ?? null;
					
					// Update codes if they're missing or different
					if (!empty($newIata) || !empty($newIcao)) {
						// Check if codes need updating
						if ($newIata !== $currentIata || $newIcao !== $currentIcao) {
							$updateCodesStmt->execute([$newIata, $newIcao, $flight['id']]);
							$updatedCodes++;
						}
					}
				} else {
					// No canonical name found - try to get codes anyway
					if (empty($currentIata) && empty($currentIcao) && !empty($currentName)) {
						$codes = get_airline_codes($currentName, $flight['flight_number']);
						if (!empty($codes['iata']) || !empty($codes['icao'])) {
							$updateCodesStmt->execute([$codes['iata'], $codes['icao'], $flight['id']]);
							$updatedCodes++;
						}
					}
					$notFound++;
				}
			}
		}
		
		$message = '';
		if ($updated > 0) {
			$message .= "Synced {$updated} flights' airline names with airlines table. ";
		}
		if ($updatedCodes > 0) {
			$message .= "Updated {$updatedCodes} flights with airline codes. ";
		}
		if ($notFound > 0) {
			$message .= "Note: {$notFound} flights couldn't be matched with airlines table (airlines may need to be added to manage_airlines.php).";
		}
		if ($updated == 0 && $updatedCodes == 0 && $notFound == 0) {
			$message = "All flights are already synced with airlines table.";
		}
		if (empty($message)) {
			$message = "No flights found to sync.";
		}
		
		redirect_with_message(base_url('manage_timetable.php'), $notFound > 0 && $updated == 0 ? 'warning' : 'success', $message);
	}

    if ($action === 'import_csv' && !empty($_FILES['csv']['tmp_name'])) {
        // Simplify: always import as departures using ICAO codes
        $category = 'departure';
		$tmp = $_FILES['csv']['tmp_name'];
		$fp = fopen($tmp, 'r');
		if ($fp === false) {
			redirect_with_message(base_url('manage_timetable.php'), 'error', 'Failed to open CSV.');
		}
        $header = fgetcsv($fp) ?: [];
        // Normalize header: trim, lowercase, strip UTF-8 BOM
        $header = array_map(function($h){
            $s = is_string($h) ? $h : '';
            $s = preg_replace('/^\xEF\xBB\xBF/', '', $s); // remove BOM if present
            return strtolower(trim($s));
        }, $header);
        // Flexible columns.
        // A) Ops header: flightnumber,departure/origin,destination,deptime,arrtime,aircraft,route,gate (ICAO for departure/destination)
        // B) Full headers with names
        // C) Minimal headers: Flight Number, Aircraft, Departure ICAO, Destination ICAO, Departure Time [, Gate]
        $headerLower = $header; // already normalized
        $insert = $pdo->prepare('INSERT INTO flights (source, flight_number, airline_name, airline_iata, airline_icao, aircraft, origin_icao, origin_name, destination_icao, destination_name, departure_time_zulu, route, gate, category) VALUES ("import",?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $count = 0; $depCount = 0; $arrCount = 0;
        while (($row = fgetcsv($fp)) !== false) {
            // Normalize cells
            $row = array_map(function($v){
                $s = is_string($v) ? $v : '';
                $s = preg_replace('/^\xEF\xBB\xBF/', '', $s);
                return trim($s);
            }, $row);
            if (in_array('flightnumber', $headerLower, true) && (in_array('origin', $headerLower, true) || in_array('departure', $headerLower, true)) && in_array('destination', $headerLower, true) && in_array('deptime', $headerLower, true) && in_array('aircraft', $headerLower, true)) {
                // Format A: flightnumber,airline name,departure/origin,destination,deptime,arrtime,aircraft,gate (optional route)
                $idx = array_flip($headerLower);
                $fn = $row[$idx['flightnumber']] ?? '';
                // Check for airline name in various formats: airline name, airline_name, airlinename
                $airlineNameCsv = '';
                if (isset($idx['airline name'])) {
                    $airlineNameCsv = $row[$idx['airline name']] ?? '';
                } elseif (isset($idx['airline_name'])) {
                    $airlineNameCsv = $row[$idx['airline_name']] ?? '';
                } elseif (isset($idx['airlinename'])) {
                    $airlineNameCsv = $row[$idx['airlinename']] ?? '';
                }
                // Accept both "origin" and "departure" as header names
                $oicao = $row[$idx['departure']] ?? ($row[$idx['origin']] ?? '');
                $dicao = $row[$idx['destination']] ?? '';
                $depTime = $row[$idx['deptime']] ?? '';
                $arrTime = isset($idx['arrtime']) ? ($row[$idx['arrtime']] ?? '') : '';
                $ac = $row[$idx['aircraft']] ?? '';
                $routeCsv = isset($idx['route']) ? ($row[$idx['route']] ?? '') : '';
                $gate = isset($idx['gate']) ? ($row[$idx['gate']] ?? '') : '';
                $oname = $oicao; $dname = $dicao;
                // normalize time: allow 1230, 12:30, 12:30Z
                $depTime = strtoupper(trim((string)$depTime));
                $arrTime = strtoupper(trim((string)$arrTime));
                $depTime = rtrim($depTime, 'Z');
                $arrTime = rtrim($arrTime, 'Z');
                if (preg_match('/^\\d{4}$/', $depTime)) { $depTime = substr($depTime,0,2) . ':' . substr($depTime,2,2); }
                if (preg_match('/^\\d{4}$/', $arrTime)) { $arrTime = substr($arrTime,0,2) . ':' . substr($arrTime,2,2); }
            } elseif (count($header) >= 7) {
                // Full format with names - check if airline name column exists
                $idx = array_flip($headerLower);
                $airlineNameCsv = '';
                // Check for airline name in various formats
                if (isset($idx['airline name'])) {
                    $airlineNameCsv = $row[$idx['airline name']] ?? '';
                } elseif (isset($idx['airline_name'])) {
                    $airlineNameCsv = $row[$idx['airline_name']] ?? '';
                } elseif (isset($idx['airlinename'])) {
                    $airlineNameCsv = $row[$idx['airlinename']] ?? '';
                }
                [$fn,$ac,$oicao,$oname,$dicao,$dname,$time,$routeCsv,$gate] = array_pad($row, 9, '');
                // If airline name is in a different position, try to extract it
                if (empty($airlineNameCsv)) {
                    $airlineIdx = array_search('airline name', $headerLower);
                    if ($airlineIdx === false) {
                        $airlineIdx = array_search('airline_name', $headerLower);
                    }
                    if ($airlineIdx === false) {
                        $airlineIdx = array_search('airlinename', $headerLower);
                    }
                    if ($airlineIdx !== false && isset($row[$airlineIdx])) {
                        $airlineNameCsv = $row[$airlineIdx];
                    }
                }
                $depTime = $time; $arrTime = '';
            } else {
                // Minimal format
                [$fn,$ac,$oicao,$dicao,$time,$routeCsv,$gate] = array_pad($row, 7, '');
                $airlineNameCsv = '';
                $oname = $oicao; // use ICAO if name not provided
                $dname = $dicao;
                $depTime = $time; $arrTime = '';
            }
			$fn = strtoupper(trim($fn));
			$ac = strtoupper(trim($ac));
			$oicao = strtoupper(trim($oicao));
			$dicao = strtoupper(trim($dicao));
			$oname = trim($oname);
			$dname = trim($dname);
            $depTime = trim($depTime);
            $arrTime = trim($arrTime);
            $routeCsv = trim((string)$routeCsv);
			$gate = trim($gate);
			$airlineNameCsv = trim((string)($airlineNameCsv ?? ''));
			
			// Auto-populate airline_name if not provided
			if (empty($airlineNameCsv)) {
				$airlineNameCsv = get_airline_name_from_flight($fn);
			}
			
			// Get airline IATA/ICAO codes
			$airlineCodes = get_airline_codes($airlineNameCsv, $fn);
			$airline_iata = $airlineCodes['iata'];
			$airline_icao = $airlineCodes['icao'];

			// Ensure airline exists in airlines table (for manage_airlines.php)
			ensure_airline_exists($airlineNameCsv, $airline_iata, $airline_icao);

			// Always get the canonical airline name from airlines table to ensure consistency
			// This ensures flights.airline_name always matches airlines.airline_name
			$canonicalName = get_canonical_airline_name($airlineNameCsv, $airline_iata, $airline_icao, $fn);
			if (!empty($canonicalName)) {
				$airlineNameCsv = $canonicalName;
			}

            if (!is_valid_icao($oicao) || !is_valid_icao($dicao)) { continue; }
            // Departures only, so use public flight validation
            if (!is_valid_flight_number_public($fn)) { continue; }
            // Determine category/time per row: prefer deptime for departure else arrtime for arrival
            $rowCategory = null; $rowTime = '';
            if ($depTime !== '' && is_valid_time_zulu($depTime)) { $rowCategory = 'departure'; $rowTime = $depTime; }
            elseif ($arrTime !== '' && is_valid_time_zulu($arrTime)) { $rowCategory = 'arrival'; $rowTime = $arrTime; }
            else { continue; }
            try {
                $insert->execute([$fn,$airlineNameCsv,$airline_iata,$airline_icao,$ac,$oicao,$oname,$dicao,$dname,$rowTime,$routeCsv,$gate,$rowCategory]);
                
				// Auto-sync airline name after import
				$flightId = (int)$pdo->lastInsertId();
				if ($flightId > 0) {
					sync_flight_airline_name($flightId, $fn, $airlineNameCsv, $airline_iata, $airline_icao);
				}
				
                $count++; if ($rowCategory==='departure') { $depCount++; } else { $arrCount++; }
            } catch (Throwable $e) { /* skip duplicates */ }
		}
		fclose($fp);
        redirect_with_message(base_url('manage_timetable.php'), 'success', "Imported {$count} flights ({$depCount} dep, {$arrCount} arr).");
	}
}

// Download Example CSV
if (($_GET['download'] ?? '') === 'example_csv') {
	$exampleFile = __DIR__ . '/example_flights_import.csv';
	if (file_exists($exampleFile)) {
		header('Content-Type: text/csv');
		header('Content-Disposition: attachment; filename="example_flights_import.csv"');
		readfile($exampleFile);
		exit;
	} else {
		// Generate example CSV on the fly if file doesn't exist
		header('Content-Type: text/csv');
		header('Content-Disposition: attachment; filename="example_flights_import.csv"');
		$out = fopen('php://output', 'w');
		fputcsv($out, ['flightnumber','airline name','departure','destination','deptime','arrtime','aircraft','route','gate']);
		fputcsv($out, ['FYC701','Air Arabia','OMDB','OEJN','08:00','09:30','A320','UBBLB UL602','U1']);
		fputcsv($out, ['MSR707','EgyptAir','OMDB','HECA','09:15','10:45','B738','UBBLB UL602','U2']);
		fputcsv($out, ['FZ123','FlyDubai','OMDB','OBBI','10:30','11:15','B737','UBBLB UL602','U3']);
		fputcsv($out, ['EK205','Emirates','OMDB','OMAA','11:00','11:30','A380','UBBLB UL602','U4']);
		fputcsv($out, ['EY301','Etihad Airways','OMDB','OBBI','12:15','12:45','B787','UBBLB UL602','U5']);
		fclose($out);
		exit;
	}
}

// Export CSV
if (($_GET['export'] ?? '') === 'csv') {
	$category = in_array(($_GET['category'] ?? 'departure'), ['departure','arrival','private'], true) ? $_GET['category'] : 'departure';
	$stmt = $pdo->prepare('SELECT f.flight_number, f.airline_name, f.aircraft, f.origin_icao, f.destination_icao, f.departure_time_zulu, f.gate, b.booked_by_vid FROM flights f LEFT JOIN bookings b ON b.flight_id = f.id WHERE f.category=? ORDER BY f.departure_time_zulu');
	$stmt->execute([$category]);
	$rows = $stmt->fetchAll();
	header('Content-Type: text/csv');
	header('Content-Disposition: attachment; filename="flights_' . $category . '.csv"');
	$out = fopen('php://output', 'w');
	// Match requested structure: flightnumber,airline_name,departure,destination,deptime,arrtime,aircraft,gate,user_vid
	fputcsv($out, ['flightnumber','airline_name','departure','destination','deptime','arrtime','aircraft','gate','user_vid']);
	foreach ($rows as $r) {
		$dep = $category === 'departure' ? $r['departure_time_zulu'] : '';
		$arr = $category === 'arrival' ? $r['departure_time_zulu'] : '';
		fputcsv($out, [
			$r['flight_number'],
			$r['airline_name'] ?? '',
			$r['origin_icao'],
			$r['destination_icao'],
			$dep,
			$arr,
			$r['aircraft'],
            $r['gate'] ?? '',
            $r['booked_by_vid'] ?? ''
        ]);
    }
    fclose($out);
    exit;
}

$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$edit = null;
if ($editId > 0) {
	$stmt = $pdo->prepare('SELECT * FROM flights WHERE id=?');
	$stmt->execute([$editId]);
	$edit = $stmt->fetch();
}

$flights = $pdo->query('SELECT * FROM flights ORDER BY category, departure_time_zulu')->fetchAll();

// Meta page variables
$MetaPageTitle = "Manage Timetable - IVAO Middle East Division";
$MetaPageDescription = "Administrative panel for managing flight timetable. Add, edit, delete, and import/export flights for the IVAO Middle East Division.";
$MetaPageKeywords = "IVAO, manage timetable, flight management, admin, timetable administration";
$MetaPageURL = base_url('manage_timetable.php');
$MetaPageImage = base_url('public/uploads/logo.png');
?>
<!doctype html>
<html>
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title>Manage Timetable - IVAO Middle East Division</title>
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
					<h2 class="navbar-title">Manage Timetable</h2>
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
				<div class="container full-width">
					<?php flash(); ?>
		<div class="card" id="flightForm">
			<div class="card-header">
				<h2><?php echo $edit ? 'Edit Flight' : 'Add Flight'; ?></h2>
			</div>
			<form method="post" class="form-grid">
				<input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>" />
				<input type="hidden" name="action" value="<?php echo $edit ? 'update' : 'create'; ?>" />
				<?php if ($edit): ?><input type="hidden" name="id" value="<?php echo (int)$edit['id']; ?>" /><?php endif; ?>
				<div class="form-group">
					<label class="label">Category</label>
					<select class="input" name="category">
						<option value="departure" <?php echo $edit && $edit['category']==='departure'?'selected':''; ?>>Departure</option>
						<option value="arrival" <?php echo $edit && $edit['category']==='arrival'?'selected':''; ?>>Arrival</option>
						<option value="private" <?php echo $edit && $edit['category']==='private'?'selected':''; ?>>Private</option>
					</select>
				</div>
				<div class="form-group">
					<label class="label">Flight Number</label>
					<input class="input" name="flight_number" value="<?php echo e($edit['flight_number'] ?? ''); ?>" required />
				</div>
				<div class="form-group">
					<label class="label">Airline Name</label>
					<input class="input" name="airline_name" value="<?php echo e($edit['airline_name'] ?? ''); ?>" placeholder="Auto-detected from flight number if empty" />
				</div>
				<div class="form-group">
					<label class="label">Aircraft</label>
					<input class="input" name="aircraft" value="<?php echo e($edit['aircraft'] ?? ''); ?>" required />
				</div>
				<div class="form-group">
					<label class="label">Departure ICAO</label>
					<input class="input" name="origin_icao" value="<?php echo e($edit['origin_icao'] ?? ''); ?>" required />
				</div>
				<div class="form-group">
					<label class="label">Departure Name</label>
					<input class="input" name="origin_name" value="<?php echo e($edit['origin_name'] ?? ''); ?>" required />
				</div>
				<div class="form-group">
					<label class="label">Destination ICAO</label>
					<input class="input" name="destination_icao" value="<?php echo e($edit['destination_icao'] ?? ''); ?>" required />
				</div>
				<div class="form-group">
					<label class="label">Destination Name</label>
					<input class="input" name="destination_name" value="<?php echo e($edit['destination_name'] ?? ''); ?>" required />
				</div>
				<div class="form-group">
					<label class="label">Departure Time (Zulu HH:MM)</label>
					<input class="input" name="departure_time_zulu" value="<?php echo e($edit['departure_time_zulu'] ?? ''); ?>" required />
				</div>
				<div class="form-group full">
					<label class="label">Route (optional)</label>
					<input class="input" name="route" value="<?php echo e($edit['route'] ?? ''); ?>" />
				</div>
				<div class="form-group">
					<label class="label">Gate</label>
					<input class="input" name="gate" value="<?php echo e($edit['gate'] ?? ''); ?>" />
				</div>
				<div class="form-group full">
					<button class="btn primary btn-block" type="submit"><?php echo $edit ? 'Update Flight' : 'Add Flight'; ?></button>
				</div>
			</form>
		</div>

		<div class="card mt-3">
			<div class="card-header">
				<h2>Import / Export</h2>
			</div>
			<div class="flex gap-2" style="flex-wrap: wrap; margin-bottom: 16px;">
				<a class="btn secondary btn-small" href="?download=example_csv">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 4px; vertical-align: middle;">
						<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
						<polyline points="7 10 12 15 17 10"></polyline>
						<line x1="12" y1="15" x2="12" y2="3"></line>
					</svg>
					Download Example CSV
				</a>
				<a class="btn primary btn-small" href="?export=csv&category=departure">Export Departures CSV</a>
				<a class="btn primary btn-small" href="?export=csv&category=arrival">Export Arrivals CSV</a>
				<a class="btn primary btn-small" href="?export=csv&category=private">Export Private CSV</a>
			</div>
			<form method="post" enctype="multipart/form-data" class="flex gap-2" style="flex-wrap: wrap; align-items: flex-end;">
				<input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>" />
				<input type="hidden" name="action" value="import_csv" />
				<div class="form-group" style="flex: 1; min-width: 250px; margin-bottom: 0;">
					<label class="label">CSV File</label>
					<input class="input" type="file" name="csv" accept=".csv" required />
				</div>
				<button class="btn primary" type="submit">Import Flight</button>
			</form>
		</div>

		<div class="card mt-3">
			<div class="card-header">
				<h2>Flights</h2>
				<div class="card-header-actions">
					<form method="post" style="display: inline; margin-right: 8px;">
						<input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>" />
						<input type="hidden" name="action" value="sync_airline_names" />
						<button class="btn secondary" type="submit" onclick="return confirm('Sync all flights\' airline names with airlines table? This will update airline names to match manage_airlines.php.')">Sync Airline Names</button>
					</form>
					<form method="post" style="display: inline;">
						<input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>" />
						<input type="hidden" name="action" value="clear_all" />
						<button class="btn danger" type="submit" onclick="return confirm('Clear ALL flights and bookings?')">Clear All</button>
					</form>
				</div>
			</div>
			<div class="table-wrapper">
				<table class="table">
					<thead>
						<tr>
							<th>Category</th>
							<th>Flight Number</th>
							<th>Airline Name</th>
							<th>Aircraft</th>
							<th>Departure</th>
							<th>Destination</th>
							<th>Time (Z)</th>
							<th>Route</th>
							<th>Gate</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($flights as $f): 
							// Get airline name from airlines table to ensure consistency with manage_airlines.php
							// Pass flight_number to extract IATA/ICAO if codes aren't stored
							$displayAirlineName = get_canonical_airline_name(
								$f['airline_name'] ?? '', 
								$f['airline_iata'] ?? null, 
								$f['airline_icao'] ?? null,
								$f['flight_number'] ?? null
							);
							if (empty($displayAirlineName)) {
								$displayAirlineName = $f['airline_name'] ?? '-';
							}
						?>
						<tr>
							<td data-label="Category"><span class="badge info"><?php echo e($f['category']); ?></span></td>
							<td data-label="Flight Number"><?php echo e($f['flight_number']); ?></td>
							<td data-label="Airline Name"><?php echo e($displayAirlineName); ?></td>
							<td data-label="Aircraft"><?php echo e($f['aircraft']); ?></td>
							<td data-label="Departure"><?php echo e($f['origin_icao']); ?></td>
							<td data-label="Destination"><?php echo e($f['destination_icao']); ?></td>
							<td data-label="Time (Z)"><?php echo e($f['departure_time_zulu']); ?></td>
							<td data-label="Route"><?php echo e($f['route'] ?? '-'); ?></td>
							<td data-label="Gate"><?php echo e($f['gate'] ?? '-'); ?></td>
							<td data-label="Actions" style="display: flex; gap: 6px; align-items: center;">
								<a class="btn primary btn-small" href="?edit=<?php echo (int)$f['id']; ?>#flightForm">Edit</a>
								<form method="post" style="display: inline;">
									<input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>" />
									<input type="hidden" name="action" value="delete" />
									<input type="hidden" name="id" value="<?php echo (int)$f['id']; ?>" />
									<button class="btn danger btn-small" type="submit" onclick="return confirm('Delete this flight?')">Delete</button>
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
	
	<script>
		// Scroll to form when editing
		<?php if ($edit): ?>
		window.addEventListener('load', function() {
			const formElement = document.getElementById('flightForm');
			if (formElement) {
				formElement.scrollIntoView({ behavior: 'smooth', block: 'start' });
			}
		});
		<?php endif; ?>
		
		// Handle hash navigation
		if (window.location.hash === '#flightForm') {
			window.addEventListener('load', function() {
				const formElement = document.getElementById('flightForm');
				if (formElement) {
					setTimeout(function() {
						formElement.scrollIntoView({ behavior: 'smooth', block: 'start' });
					}, 100);
				}
			});
		}
		
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
