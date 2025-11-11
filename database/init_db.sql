-- MySQL schema for IVAO XM Booking System

CREATE TABLE IF NOT EXISTS users (
	vid VARCHAR(10) PRIMARY KEY,
	name VARCHAR(100) NOT NULL,
	email VARCHAR(150) NULL,
	is_staff TINYINT(1) NOT NULL DEFAULT 0,
	-- roles managed via user_roles table
	created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_roles (
	vid VARCHAR(10) NOT NULL,
	role ENUM('admin','private_admin') NOT NULL,
	PRIMARY KEY (vid, role),
	CONSTRAINT fk_user_roles_user FOREIGN KEY (vid) REFERENCES users(vid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS events (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS flights (
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
	departure_time_zulu CHAR(5) NOT NULL, -- HH:MM
	route TEXT NULL,
	gate VARCHAR(10) NULL,
	category ENUM('departure','arrival','private') NOT NULL DEFAULT 'departure',
	created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	UNIQUE KEY uniq_flight (flight_number, departure_time_zulu, category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bookings (
	id INT AUTO_INCREMENT PRIMARY KEY,
	flight_id INT NOT NULL,
	booked_by_vid VARCHAR(10) NOT NULL,
	created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	CONSTRAINT fk_booking_flight FOREIGN KEY (flight_id) REFERENCES flights(id) ON DELETE CASCADE,
	CONSTRAINT fk_booking_user FOREIGN KEY (booked_by_vid) REFERENCES users(vid) ON DELETE CASCADE,
	UNIQUE KEY uniq_booking (flight_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS private_slot_requests (
	id INT AUTO_INCREMENT PRIMARY KEY,
	vid VARCHAR(10) NOT NULL,
	flight_number VARCHAR(6) NOT NULL,
	aircraft_type VARCHAR(20) NOT NULL,
	origin_icao VARCHAR(4) NOT NULL,
	destination_icao VARCHAR(4) NOT NULL,
	departure_time_zulu CHAR(5) NOT NULL,
	status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
	rejection_reason TEXT NULL,
	cancellation_reason TEXT NULL,
	created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at TIMESTAMP NULL DEFAULT NULL,
	CONSTRAINT fk_psr_user FOREIGN KEY (vid) REFERENCES users(vid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS airlines (
	id INT AUTO_INCREMENT PRIMARY KEY,
	iata VARCHAR(2) NULL,
	icao VARCHAR(3) NULL,
	airline_name VARCHAR(200) NOT NULL,
	callsign VARCHAR(50) NULL,
	created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
	INDEX idx_iata (iata),
	INDEX idx_icao (icao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS airports (
	icao VARCHAR(4) PRIMARY KEY,
	airport_name VARCHAR(200) NOT NULL,
	country_code VARCHAR(2) NULL,
	created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
	INDEX idx_country (country_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default admin user and role
INSERT INTO users (vid, name, email, is_staff)
VALUES ('744759', 'Admin 744759', NULL, 1)
ON DUPLICATE KEY UPDATE name=VALUES(name);

INSERT IGNORE INTO user_roles (vid, role)
VALUES ('744759', 'admin');