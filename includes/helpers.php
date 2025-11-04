<?php
declare(strict_types=1);

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function is_valid_icao(string $s): bool {
	return (bool)preg_match('/^[A-Z]{4}$/', strtoupper($s));
}

function is_valid_time_zulu(string $s): bool {
	return (bool)preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $s);
}

function is_valid_flight_number_public(string $s): bool {
	// e.g. AAA222 (3 letters + 1-4 digits) limited to 3 letters prefix
	return (bool)preg_match('/^[A-Z]{3}\d{1,4}$/', strtoupper($s));
}

function is_valid_flight_number_private(string $s): bool {
	return (bool)preg_match('/^[A-Z0-9]{1,6}$/', strtoupper($s));
}

function redirect_with_message(string $url, string $type, string $message): void {
	$_SESSION['flash'] = ['type' => $type, 'message' => $message];
	header('Location: ' . $url);
	exit;
}

function flash(): void {
	if (!empty($_SESSION['flash'])) {
		$type = e($_SESSION['flash']['type']);
		$message = e($_SESSION['flash']['message']);
		echo "<div class=\"flash {$type}\">{$message}</div>";
		unset($_SESSION['flash']);
	}
}

function get_airline_name_from_flight(string $flight_number): ?string {
	$flight = strtoupper(trim($flight_number));
	
	try {
		$pdo = db();
		
		// Try IATA code first (first 2 letters)
		if (strlen($flight) >= 2) {
			$iata = substr($flight, 0, 2);
			$stmt = $pdo->prepare('SELECT airline_name FROM airlines WHERE iata = ? LIMIT 1');
			$stmt->execute([$iata]);
			$row = $stmt->fetch();
			if ($row) {
				return $row['airline_name'];
			}
		}
		
		// Try ICAO code (first 3 letters)
		if (strlen($flight) >= 3) {
			$icao = substr($flight, 0, 3);
			$stmt = $pdo->prepare('SELECT airline_name FROM airlines WHERE icao = ? LIMIT 1');
			$stmt->execute([$icao]);
			$row = $stmt->fetch();
			if ($row) {
				return $row['airline_name'];
			}
		}
	} catch (Throwable $e) {
		// Database error - return null
		return null;
	}
	
	return null;
}

function get_airport_name_from_icao(string $icao): ?string {
	static $cache = [];
	$icaoUpper = strtoupper(trim($icao));
	
	if (isset($cache[$icaoUpper])) {
		return $cache[$icaoUpper];
	}
	
	try {
		$pdo = db();
		$stmt = $pdo->prepare('SELECT airport_name FROM airports WHERE icao = ? LIMIT 1');
		$stmt->execute([$icaoUpper]);
		$row = $stmt->fetch();
		
		if ($row) {
			$cache[$icaoUpper] = $row['airport_name'];
			return $cache[$icaoUpper];
		}
	} catch (Throwable $e) {
		// Database error - return null
		return null;
	}
	
	return null;
}

function get_airport_country_code(string $icao): ?string {
	static $cache = [];
	$icaoUpper = strtoupper(trim($icao));
	
	if (isset($cache[$icaoUpper])) {
		return $cache[$icaoUpper];
	}
	
	try {
		$pdo = db();
		$stmt = $pdo->prepare('SELECT country_code FROM airports WHERE icao = ? LIMIT 1');
		$stmt->execute([$icaoUpper]);
		$row = $stmt->fetch();
		
		if ($row && !empty($row['country_code'])) {
			$cache[$icaoUpper] = $row['country_code'];
			return $cache[$icaoUpper];
		}
	} catch (Throwable $e) {
		// Database error - return null
		return null;
	}
	
	return null;
}

function get_airline_codes(?string $airline_name, ?string $flight_number): array {
	$result = ['iata' => null, 'icao' => null];
	
	try {
		$pdo = db();
		
		// Try to get codes by airline name first
		if (!empty($airline_name)) {
			$stmt = $pdo->prepare('SELECT iata, icao FROM airlines WHERE airline_name = ? LIMIT 1');
			$stmt->execute([trim($airline_name)]);
			$row = $stmt->fetch();
			
			if ($row) {
				$result['iata'] = !empty($row['iata']) ? $row['iata'] : null;
				$result['icao'] = !empty($row['icao']) ? $row['icao'] : null;
				
				// If we found codes, return them
				if ($result['iata'] || $result['icao']) {
					return $result;
				}
			}
		}
		
		// Try to get codes by flight number prefix
		if (!empty($flight_number)) {
			$flight = strtoupper(trim($flight_number));
			
			// Try IATA code (first 2 letters)
			if (strlen($flight) >= 2) {
				$flightIata = substr($flight, 0, 2);
				$stmt = $pdo->prepare('SELECT iata, icao FROM airlines WHERE iata = ? LIMIT 1');
				$stmt->execute([$flightIata]);
				$row = $stmt->fetch();
				
				if ($row) {
					$result['iata'] = !empty($row['iata']) ? $row['iata'] : null;
					$result['icao'] = !empty($row['icao']) ? $row['icao'] : null;
					
					if ($result['iata'] || $result['icao']) {
						return $result;
					}
				}
			}
			
			// Try ICAO code (first 3 letters)
			if (strlen($flight) >= 3) {
				$flightIcao = substr($flight, 0, 3);
				$stmt = $pdo->prepare('SELECT iata, icao FROM airlines WHERE icao = ? LIMIT 1');
				$stmt->execute([$flightIcao]);
				$row = $stmt->fetch();
				
				if ($row) {
					$result['iata'] = !empty($row['iata']) ? $row['iata'] : null;
					$result['icao'] = !empty($row['icao']) ? $row['icao'] : null;
					
					if ($result['iata'] || $result['icao']) {
						return $result;
					}
				}
			}
		}
	} catch (Throwable $e) {
		// Database error - return empty result
		return $result;
	}
	
	return $result;
}

function ensure_airline_exists(?string $airline_name, ?string $iata, ?string $icao): void {
	if (empty($airline_name) && empty($iata) && empty($icao)) {
		return; // Nothing to add
	}
	
	try {
		$pdo = db();
		
		// Ensure airlines table exists (best effort)
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
			// Table might already exist, continue
		}
		
		// Normalize inputs
		$iataUpper = !empty($iata) ? strtoupper(trim($iata)) : null;
		$icaoUpper = !empty($icao) ? strtoupper(trim($icao)) : null;
		$airlineNameTrimmed = !empty($airline_name) ? trim($airline_name) : null;
		
		// If no airline name but we have codes, try to find existing airline
		if (empty($airlineNameTrimmed) && ($iataUpper || $icaoUpper)) {
			$stmt = null;
			if ($iataUpper) {
				$stmt = $pdo->prepare('SELECT airline_name FROM airlines WHERE iata = ? LIMIT 1');
				$stmt->execute([$iataUpper]);
			} elseif ($icaoUpper) {
				$stmt = $pdo->prepare('SELECT airline_name FROM airlines WHERE icao = ? LIMIT 1');
				$stmt->execute([$icaoUpper]);
			}
			if ($stmt) {
				$row = $stmt->fetch();
				if ($row && !empty($row['airline_name'])) {
					$airlineNameTrimmed = $row['airline_name'];
				}
			}
		}
		
		// Need at least airline name or codes to proceed
		if (empty($airlineNameTrimmed) && empty($iataUpper) && empty($icaoUpper)) {
			return;
		}
		
		// Use airline name from codes lookup if still empty
		if (empty($airlineNameTrimmed)) {
			$airlineNameTrimmed = 'Unknown Airline';
		}
		
		// Check if airline already exists by IATA or ICAO
		$existing = null;
		if ($iataUpper) {
			$stmt = $pdo->prepare('SELECT * FROM airlines WHERE iata = ? LIMIT 1');
			$stmt->execute([$iataUpper]);
			$existing = $stmt->fetch();
		}
		if (!$existing && $icaoUpper) {
			$stmt = $pdo->prepare('SELECT * FROM airlines WHERE icao = ? LIMIT 1');
			$stmt->execute([$icaoUpper]);
			$existing = $stmt->fetch();
		}
		
		if ($existing) {
			// Always use the existing airline name from airlines table to maintain consistency
			// Only update if the existing airline has no name but we have one
			$updateFields = [];
			$updateParams = [];
			
			if (!empty($airlineNameTrimmed) && (empty($existing['airline_name']) || trim($existing['airline_name']) === '')) {
				$updateFields[] = 'airline_name = ?';
				$updateParams[] = $airlineNameTrimmed;
			}
			if ($iataUpper && empty($existing['iata'])) {
				$updateFields[] = 'iata = ?';
				$updateParams[] = $iataUpper;
			}
			if ($icaoUpper && empty($existing['icao'])) {
				$updateFields[] = 'icao = ?';
				$updateParams[] = $icaoUpper;
			}
			
			if (!empty($updateFields)) {
				$updateParams[] = $existing['id'];
				$stmt = $pdo->prepare('UPDATE airlines SET ' . implode(', ', $updateFields) . ' WHERE id = ?');
				$stmt->execute($updateParams);
			}
		} else {
			// Insert new airline
			$stmt = $pdo->prepare('INSERT INTO airlines (iata, icao, airline_name) VALUES (?, ?, ?)');
			$stmt->execute([$iataUpper, $icaoUpper, $airlineNameTrimmed]);
		}
	} catch (Throwable $e) {
		// Silently fail - don't break import if airline add fails
	}
}

function get_canonical_airline_name(?string $airline_name, ?string $iata, ?string $icao, ?string $flight_number = null): ?string {
	if (empty($airline_name) && empty($iata) && empty($icao) && empty($flight_number)) {
		return null;
	}
	
	try {
		$pdo = db();
		
		$foundByCode = false;
		
		// First try to find by IATA or ICAO codes (more reliable)
		if (!empty($iata)) {
			$iataUpper = strtoupper(trim($iata));
			$stmt = $pdo->prepare('SELECT airline_name FROM airlines WHERE iata = ? AND airline_name IS NOT NULL AND airline_name != "" LIMIT 1');
			$stmt->execute([$iataUpper]);
			$row = $stmt->fetch(PDO::FETCH_ASSOC);
			if ($row !== false && isset($row['airline_name']) && !empty(trim($row['airline_name']))) {
				return trim($row['airline_name']);
			}
		}
		
		if (!empty($icao)) {
			$icaoUpper = strtoupper(trim($icao));
			$stmt = $pdo->prepare('SELECT airline_name FROM airlines WHERE icao = ? AND airline_name IS NOT NULL AND airline_name != "" LIMIT 1');
			$stmt->execute([$icaoUpper]);
			$row = $stmt->fetch(PDO::FETCH_ASSOC);
			if ($row !== false && isset($row['airline_name']) && !empty(trim($row['airline_name']))) {
				return trim($row['airline_name']);
			}
		}
		
		// Always try extracting codes from flight number (even if codes exist, they might be wrong)
		// Flight number is more reliable for lookup (e.g., FYC707 should find FYC as ICAO)
		if (!empty($flight_number)) {
			$flight = strtoupper(trim($flight_number));
			
			// Try 3-letter code as ICAO first (standard: ICAO codes are 3 letters, like FYC)
			if (strlen($flight) >= 3) {
				$flightCode3 = substr($flight, 0, 3);
				// Try as ICAO first (standard for 3-letter codes)
				$stmt = $pdo->prepare('SELECT airline_name FROM airlines WHERE icao = ? AND airline_name IS NOT NULL AND airline_name != "" LIMIT 1');
				$stmt->execute([$flightCode3]);
				$row = $stmt->fetch(PDO::FETCH_ASSOC);
				if ($row !== false && isset($row['airline_name']) && !empty(trim($row['airline_name']))) {
					return trim($row['airline_name']);
				}
				// Also try as IATA (some airlines use 3-letter IATA codes like FYC)
				$stmt = $pdo->prepare('SELECT airline_name FROM airlines WHERE iata = ? AND airline_name IS NOT NULL AND airline_name != "" LIMIT 1');
				$stmt->execute([$flightCode3]);
				$row = $stmt->fetch(PDO::FETCH_ASSOC);
				if ($row !== false && isset($row['airline_name']) && !empty(trim($row['airline_name']))) {
					return trim($row['airline_name']);
				}
			}
			
			// Try IATA code (first 2 letters) - standard IATA codes are 2 letters
			if (strlen($flight) >= 2) {
				$flightIata = substr($flight, 0, 2);
				$stmt = $pdo->prepare('SELECT airline_name FROM airlines WHERE iata = ? AND airline_name IS NOT NULL AND airline_name != "" LIMIT 1');
				$stmt->execute([$flightIata]);
				$row = $stmt->fetch(PDO::FETCH_ASSOC);
				if ($row !== false && isset($row['airline_name']) && !empty(trim($row['airline_name']))) {
					return trim($row['airline_name']);
				}
			}
		}
		
		// Fallback: try by exact name match (case-sensitive first)
		if (!empty($airline_name)) {
			$airlineNameTrimmed = trim($airline_name);
			// Try exact match first
			$stmt = $pdo->prepare('SELECT airline_name FROM airlines WHERE airline_name = ? AND airline_name IS NOT NULL AND airline_name != "" LIMIT 1');
			$stmt->execute([$airlineNameTrimmed]);
			$row = $stmt->fetch(PDO::FETCH_ASSOC);
			if ($row !== false && isset($row['airline_name']) && !empty(trim($row['airline_name']))) {
				return trim($row['airline_name']);
			}
			// Try case-insensitive match
			$stmt = $pdo->prepare('SELECT airline_name FROM airlines WHERE LOWER(airline_name) = LOWER(?) AND airline_name IS NOT NULL AND airline_name != "" LIMIT 1');
			$stmt->execute([$airlineNameTrimmed]);
			$row = $stmt->fetch(PDO::FETCH_ASSOC);
			if ($row !== false && isset($row['airline_name']) && !empty(trim($row['airline_name']))) {
				return trim($row['airline_name']);
			}
			// Try LIKE match (for partial matches) - get best match
			$stmt = $pdo->prepare('SELECT airline_name FROM airlines WHERE (airline_name LIKE ? OR airline_name LIKE ?) AND airline_name IS NOT NULL AND airline_name != "" ORDER BY CASE WHEN airline_name = ? THEN 1 WHEN airline_name LIKE ? THEN 2 ELSE 3 END LIMIT 1');
			$stmt->execute(['%' . $airlineNameTrimmed . '%', $airlineNameTrimmed . '%', $airlineNameTrimmed, $airlineNameTrimmed . '%']);
			$row = $stmt->fetch(PDO::FETCH_ASSOC);
			if ($row !== false && isset($row['airline_name']) && !empty(trim($row['airline_name']))) {
				return trim($row['airline_name']);
			}
		}
	} catch (Throwable $e) {
		// Return original name if lookup fails
		return !empty($airline_name) ? trim($airline_name) : null;
	}
	
	// Return original name if not found in airlines table
	return !empty($airline_name) ? trim($airline_name) : null;
}

function sync_flight_airline_name(int $flight_id, ?string $flight_number, ?string $airline_name, ?string $iata, ?string $icao): void {
	try {
		$pdo = db();
		
		// Get canonical airline name from airlines table (prioritize flight number extraction)
		$canonicalName = null;
		
		// First try by flight number (most reliable - extracts codes from flight number)
		if (!empty($flight_number)) {
			$canonicalName = get_canonical_airline_name(
				'', // empty name to force flight number lookup
				null, // no IATA to force extraction
				null, // no ICAO to force extraction
				$flight_number
			);
		}
		
		// If flight number lookup didn't work, try with provided codes/name
		if (empty($canonicalName)) {
			$canonicalName = get_canonical_airline_name(
				$airline_name ?? '', 
				$iata, 
				$icao,
				$flight_number
			);
		}
		
		if (!empty($canonicalName)) {
			$canonicalName = trim($canonicalName);
			
			// Update airline name
			$stmt = $pdo->prepare('UPDATE flights SET airline_name = ? WHERE id = ?');
			$stmt->execute([$canonicalName, $flight_id]);
			
			// Update codes if available
			$codes = get_airline_codes($canonicalName, $flight_number);
			$newIata = $codes['iata'] ?? null;
			$newIcao = $codes['icao'] ?? null;
			
			if (!empty($newIata) || !empty($newIcao)) {
				$updateCodesStmt = $pdo->prepare('UPDATE flights SET airline_iata = ?, airline_icao = ? WHERE id = ?');
				$updateCodesStmt->execute([$newIata, $newIcao, $flight_id]);
			}
		}
	} catch (Throwable $e) {
		// Silently fail - don't break create/update if sync fails
	}
}