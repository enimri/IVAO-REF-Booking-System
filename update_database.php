<?php
/**
 * Database Update Script
 * This script ensures all database tables, columns, indexes, and constraints
 * are properly set up and up to date.
 * 
 * Run via CLI: php update_database.php
 * Or access via browser (admin access required)
 */

require_once __DIR__ . '/config.php';

// Determine if running from CLI or browser
$is_cli = php_sapi_name() === 'cli';

if (!$is_cli) {
	// Browser mode - require login and admin access
	require_once __DIR__ . '/includes/helpers.php';
	require_login();
	if (!is_admin()) {
		die('Admins only.');
	}
	
	// Meta page variables
	$MetaPageTitle = "Database Update - IVAO Middle East Division";
	$MetaPageDescription = "Database update utility for ensuring all tables and columns are properly set up.";
	$MetaPageKeywords = "IVAO, database update, admin utility, database maintenance";
	$MetaPageURL = base_url('update_database.php');
	$MetaPageImage = base_url('public/uploads/logo.png');
	
	echo "<h2>Database Update</h2>\n";
	echo "<pre>\n";
} else {
	// CLI mode - just need config
	echo "=== Database Update ===\n\n";
}

$pdo = db();
$errors = [];
$success = [];
$warnings = [];

global $DB_NAME;

echo "Updating database: " . ($DB_NAME ?? 'N/A') . "\n";
echo str_repeat("=", 50) . "\n\n";

// 1. Ensure users table exists with all required columns
try {
	$pdo->exec("CREATE TABLE IF NOT EXISTS users (
		vid VARCHAR(10) PRIMARY KEY,
		name VARCHAR(100) NOT NULL,
		email VARCHAR(150) NULL,
		is_staff TINYINT(1) NOT NULL DEFAULT 0,
		created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
	$success[] = "✅ 'users' table exists";
	
	// Check and add missing columns
	$columns = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
	
	if (!in_array('email', $columns)) {
		$pdo->exec('ALTER TABLE users ADD COLUMN email VARCHAR(150) NULL AFTER name');
		$success[] = "✅ Added 'email' column to 'users' table";
	}
	if (!in_array('is_staff', $columns)) {
		$pdo->exec('ALTER TABLE users ADD COLUMN is_staff TINYINT(1) NOT NULL DEFAULT 0 AFTER email');
		$success[] = "✅ Added 'is_staff' column to 'users' table";
	}
	if (!in_array('created_at', $columns)) {
		$pdo->exec('ALTER TABLE users ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
		$success[] = "✅ Added 'created_at' column to 'users' table";
	}
} catch (Throwable $e) {
	$errors[] = "❌ Error with 'users' table: " . $e->getMessage();
}

// 2. Ensure user_roles table exists
try {
	$pdo->exec("CREATE TABLE IF NOT EXISTS user_roles (
		vid VARCHAR(10) NOT NULL,
		role ENUM('admin','private_admin') NOT NULL,
		PRIMARY KEY (vid, role),
		CONSTRAINT fk_user_roles_user FOREIGN KEY (vid) REFERENCES users(vid) ON DELETE CASCADE
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
	$success[] = "✅ 'user_roles' table exists";
} catch (Throwable $e) {
	$errors[] = "❌ Error with 'user_roles' table: " . $e->getMessage();
}

// 3. Ensure events table exists with all required columns
try {
	$pdo->exec("CREATE TABLE IF NOT EXISTS events (
		id INT AUTO_INCREMENT PRIMARY KEY,
		division VARCHAR(10) NOT NULL,
		other_divisions TEXT NULL,
		is_hq_approved TINYINT(1) NOT NULL DEFAULT 0,
		title VARCHAR(200) NOT NULL,
		description TEXT NULL,
		start_zulu DATETIME NOT NULL,
		end_zulu DATETIME NOT NULL,
		event_airport VARCHAR(10) NOT NULL,
		points_criteria TEXT NULL,
		banner_url TEXT NULL,
		announcement_links TEXT NULL,
		is_open TINYINT(1) NOT NULL DEFAULT 0,
		private_slots_enabled TINYINT(1) NOT NULL DEFAULT 0,
		created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
	$success[] = "✅ 'events' table exists";
	
	// Check and add missing columns
	$columns = $pdo->query("SHOW COLUMNS FROM events")->fetchAll(PDO::FETCH_COLUMN);
	
	if (!in_array('private_slots_enabled', $columns)) {
		try {
			$pdo->exec('ALTER TABLE events ADD COLUMN private_slots_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER is_open');
			$success[] = "✅ Added 'private_slots_enabled' column to 'events' table";
		} catch (Throwable $e) {
			if (strpos($e->getMessage(), 'Duplicate column') === false) {
				$warnings[] = "⚠️ Could not add 'private_slots_enabled' to 'events': " . $e->getMessage();
			}
		}
	}
} catch (Throwable $e) {
	$errors[] = "❌ Error with 'events' table: " . $e->getMessage();
}

// 4. Ensure flights table exists with all required columns
try {
	$pdo->exec("CREATE TABLE IF NOT EXISTS flights (
		id INT AUTO_INCREMENT PRIMARY KEY,
		source ENUM('manual','import') NOT NULL DEFAULT 'manual',
		flight_number VARCHAR(10) NOT NULL,
		airline_name VARCHAR(100) NULL,
		airline_iata VARCHAR(2) NULL,
		airline_icao VARCHAR(3) NULL,
		aircraft VARCHAR(20) NOT NULL,
		origin_icao VARCHAR(4) NOT NULL,
		origin_name VARCHAR(100) NOT NULL,
		destination_icao VARCHAR(4) NOT NULL,
		destination_name VARCHAR(100) NOT NULL,
		departure_time_zulu CHAR(5) NOT NULL,
		route TEXT NULL,
		gate VARCHAR(10) NULL,
		category ENUM('departure','arrival','private') NOT NULL DEFAULT 'departure',
		created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
		UNIQUE KEY uniq_flight (flight_number, departure_time_zulu, category)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
	$success[] = "✅ 'flights' table exists";
	
	// Check and add missing columns
	$columns = $pdo->query("SHOW COLUMNS FROM flights")->fetchAll(PDO::FETCH_COLUMN);
	
	$columnsToAdd = [
		['name' => 'route', 'definition' => 'TEXT NULL AFTER departure_time_zulu'],
		['name' => 'gate', 'definition' => 'VARCHAR(10) NULL AFTER route'],
		['name' => 'airline_name', 'definition' => 'VARCHAR(100) NULL AFTER flight_number'],
		['name' => 'airline_iata', 'definition' => 'VARCHAR(2) NULL AFTER airline_name'],
		['name' => 'airline_icao', 'definition' => 'VARCHAR(3) NULL AFTER airline_iata'],
	];
	
	foreach ($columnsToAdd as $col) {
		if (!in_array($col['name'], $columns)) {
			try {
				$pdo->exec("ALTER TABLE flights ADD COLUMN {$col['name']} {$col['definition']}");
				$success[] = "✅ Added '{$col['name']}' column to 'flights' table";
			} catch (Throwable $e) {
				if (strpos($e->getMessage(), 'Duplicate column') === false) {
					$warnings[] = "⚠️ Could not add '{$col['name']}' to 'flights': " . $e->getMessage();
				}
			}
		}
	}
} catch (Throwable $e) {
	$errors[] = "❌ Error with 'flights' table: " . $e->getMessage();
}

// 5. Ensure bookings table exists
try {
	$pdo->exec("CREATE TABLE IF NOT EXISTS bookings (
		id INT AUTO_INCREMENT PRIMARY KEY,
		flight_id INT NOT NULL,
		booked_by_vid VARCHAR(10) NOT NULL,
		created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
		CONSTRAINT fk_booking_flight FOREIGN KEY (flight_id) REFERENCES flights(id) ON DELETE CASCADE,
		CONSTRAINT fk_booking_user FOREIGN KEY (booked_by_vid) REFERENCES users(vid) ON DELETE CASCADE,
		UNIQUE KEY uniq_booking (flight_id)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
	$success[] = "✅ 'bookings' table exists";
} catch (Throwable $e) {
	$errors[] = "❌ Error with 'bookings' table: " . $e->getMessage();
}

// 6. Ensure private_slot_requests table exists
try {
	$pdo->exec("CREATE TABLE IF NOT EXISTS private_slot_requests (
		id INT AUTO_INCREMENT PRIMARY KEY,
		vid VARCHAR(10) NOT NULL,
		flight_number VARCHAR(6) NOT NULL,
		aircraft_type VARCHAR(20) NOT NULL,
		origin_icao VARCHAR(4) NOT NULL,
		destination_icao VARCHAR(4) NOT NULL,
		departure_time_zulu CHAR(5) NOT NULL,
		status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
		created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
		updated_at TIMESTAMP NULL DEFAULT NULL,
		CONSTRAINT fk_psr_user FOREIGN KEY (vid) REFERENCES users(vid) ON DELETE CASCADE
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
	$success[] = "✅ 'private_slot_requests' table exists";
	
	// Ensure updated_at column exists
	$columns = $pdo->query("SHOW COLUMNS FROM private_slot_requests")->fetchAll(PDO::FETCH_COLUMN);
	if (!in_array('updated_at', $columns)) {
		try {
			$pdo->exec('ALTER TABLE private_slot_requests ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL');
			$success[] = "✅ Added 'updated_at' column to 'private_slot_requests' table";
		} catch (Throwable $e) {
			if (strpos($e->getMessage(), 'Duplicate column') === false) {
				$warnings[] = "⚠️ Could not add 'updated_at' to 'private_slot_requests': " . $e->getMessage();
			}
		}
	}
	// Ensure rejection_reason column exists
	if (!in_array('rejection_reason', $columns)) {
		try {
			$pdo->exec('ALTER TABLE private_slot_requests ADD COLUMN rejection_reason TEXT NULL AFTER status');
			$success[] = "✅ Added 'rejection_reason' column to 'private_slot_requests' table";
		} catch (Throwable $e) {
			if (strpos($e->getMessage(), 'Duplicate column') === false) {
				$warnings[] = "⚠️ Could not add 'rejection_reason' to 'private_slot_requests': " . $e->getMessage();
			}
		}
	}
	// Ensure cancellation_reason column exists
	if (!in_array('cancellation_reason', $columns)) {
		try {
			$pdo->exec('ALTER TABLE private_slot_requests ADD COLUMN cancellation_reason TEXT NULL AFTER rejection_reason');
			$success[] = "✅ Added 'cancellation_reason' column to 'private_slot_requests' table";
		} catch (Throwable $e) {
			if (strpos($e->getMessage(), 'Duplicate column') === false) {
				$warnings[] = "⚠️ Could not add 'cancellation_reason' to 'private_slot_requests': " . $e->getMessage();
			}
		}
	}
} catch (Throwable $e) {
	$errors[] = "❌ Error with 'private_slot_requests' table: " . $e->getMessage();
}

// 7. Ensure airlines table exists
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
	$success[] = "✅ 'airlines' table exists";
} catch (Throwable $e) {
	$errors[] = "❌ Error with 'airlines' table: " . $e->getMessage();
}

// 8. Ensure airports table exists
try {
	$pdo->exec("CREATE TABLE IF NOT EXISTS airports (
		icao VARCHAR(4) PRIMARY KEY,
		airport_name VARCHAR(200) NOT NULL,
		country_code VARCHAR(2) NULL,
		created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
		updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
		INDEX idx_country (country_code)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
	$success[] = "✅ 'airports' table exists";
} catch (Throwable $e) {
	$errors[] = "❌ Error with 'airports' table: " . $e->getMessage();
}

// 9. Verify and optionally convert database character set
try {
	if (!empty($DB_NAME)) {
		$stmt = $pdo->prepare("SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME 
			FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?");
		$stmt->execute([$DB_NAME]);
		$dbInfo = $stmt->fetch();
		
		if ($dbInfo) {
			$currentCharset = $dbInfo['DEFAULT_CHARACTER_SET_NAME'];
			if (strpos($currentCharset, 'utf8mb4') === false) {
				// Try to convert database to utf8mb4
				try {
					$pdo->exec("ALTER DATABASE `{$DB_NAME}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
					$success[] = "✅ Converted database character set from '{$currentCharset}' to utf8mb4";
					
					// Convert all tables to utf8mb4
					$tables = ['users', 'user_roles', 'events', 'flights', 'bookings', 'private_slot_requests', 'airlines', 'airports'];
					foreach ($tables as $table) {
						try {
							$pdo->exec("ALTER TABLE `{$table}` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
						} catch (Throwable $e) {
							// Table might not exist yet, ignore
						}
					}
					$success[] = "✅ Converted all tables to utf8mb4";
				} catch (Throwable $e) {
					// Conversion might require higher privileges or fail for other reasons
					$warnings[] = "⚠️ Database character set is '{$currentCharset}', recommended: utf8mb4";
					$warnings[] = "⚠️ Could not automatically convert: " . $e->getMessage();
					$warnings[] = "⚠️ To convert manually, run: ALTER DATABASE `{$DB_NAME}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
				}
			} else {
				$success[] = "✅ Database character set is utf8mb4";
			}
		}
	}
} catch (Throwable $e) {
	$warnings[] = "⚠️ Could not verify database character set: " . $e->getMessage();
}

// Output results
echo "\n" . str_repeat("=", 50) . "\n";
echo "UPDATE SUMMARY\n";
echo str_repeat("=", 50) . "\n\n";

if (!empty($success)) {
	echo "✅ SUCCESS (" . count($success) . "):\n";
	foreach ($success as $msg) {
		echo "   " . $msg . "\n";
	}
	echo "\n";
}

if (!empty($warnings)) {
	echo "⚠️ WARNINGS (" . count($warnings) . "):\n";
	foreach ($warnings as $msg) {
		echo "   " . $msg . "\n";
	}
	echo "\n";
}

if (!empty($errors)) {
	echo "❌ ERRORS (" . count($errors) . "):\n";
	foreach ($errors as $msg) {
		echo "   " . $msg . "\n";
	}
	echo "\n";
}

if (empty($errors)) {
	echo "✅ Database update completed successfully!\n";
	echo "   All tables and columns are properly set up.\n";
} else {
	echo "⚠️ Database update completed with errors.\n";
	echo "   Please review the errors above and fix them manually if needed.\n";
}

echo "\n";

if (!$is_cli) {
	echo "</pre>\n";
	echo "<p><a href='" . base_url('admin.php') . "'>Go to Admin Panel</a> | ";
	echo "<a href='" . base_url('system_status.php') . "'>Go to System Status</a></p>";
}
