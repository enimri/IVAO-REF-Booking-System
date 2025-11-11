-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Nov 11, 2025 at 01:04 PM
-- Server version: 11.8.3-MariaDB-log
-- PHP Version: 7.2.34

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `u227507338_testbooking`
--

-- --------------------------------------------------------

--
-- Table structure for table `airlines`
--

CREATE TABLE `airlines` (
  `id` int(11) NOT NULL,
  `iata` varchar(2) DEFAULT NULL,
  `icao` varchar(3) DEFAULT NULL,
  `airline_name` varchar(200) NOT NULL,
  `callsign` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `airports`
--

CREATE TABLE `airports` (
  `icao` varchar(4) NOT NULL,
  `airport_name` varchar(200) NOT NULL,
  `country_code` varchar(2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bookings`
--

CREATE TABLE `bookings` (
  `id` int(11) NOT NULL,
  `flight_id` int(11) NOT NULL,
  `booked_by_vid` varchar(10) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `events`
--

CREATE TABLE `events` (
  `id` int(11) NOT NULL,
  `division` varchar(10) NOT NULL,
  `other_divisions` text DEFAULT NULL,
  `is_hq_approved` tinyint(1) NOT NULL DEFAULT 0,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `start_zulu` datetime NOT NULL,
  `end_zulu` datetime NOT NULL,
  `event_airport` varchar(10) NOT NULL,
  `points_criteria` text DEFAULT NULL,
  `banner_url` text DEFAULT NULL,
  `announcement_links` text DEFAULT NULL,
  `is_open` tinyint(1) NOT NULL DEFAULT 0,
  `private_slots_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `flights`
--

CREATE TABLE `flights` (
  `id` int(11) NOT NULL,
  `source` enum('manual','import') NOT NULL DEFAULT 'manual',
  `flight_number` varchar(10) NOT NULL,
  `airline_name` varchar(100) DEFAULT NULL,
  `airline_iata` varchar(2) DEFAULT NULL,
  `airline_icao` varchar(3) DEFAULT NULL,
  `aircraft` varchar(20) NOT NULL,
  `origin_icao` varchar(4) NOT NULL,
  `origin_name` varchar(100) NOT NULL,
  `destination_icao` varchar(4) NOT NULL,
  `destination_name` varchar(100) NOT NULL,
  `departure_time_zulu` char(5) NOT NULL,
  `route` text DEFAULT NULL,
  `gate` varchar(10) DEFAULT NULL,
  `category` enum('departure','arrival','private') NOT NULL DEFAULT 'departure',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `private_slot_requests`
--

CREATE TABLE `private_slot_requests` (
  `id` int(11) NOT NULL,
  `vid` varchar(10) NOT NULL,
  `flight_number` varchar(6) NOT NULL,
  `aircraft_type` varchar(20) NOT NULL,
  `origin_icao` varchar(4) NOT NULL,
  `destination_icao` varchar(4) NOT NULL,
  `departure_time_zulu` char(5) NOT NULL,
  `status` enum('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  `rejection_reason` text DEFAULT NULL,
  `cancellation_reason` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `vid` varchar(10) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `is_staff` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_roles`
--

CREATE TABLE `user_roles` (
  `vid` varchar(10) NOT NULL,
  `role` enum('admin','private_admin') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `airlines`
--
ALTER TABLE `airlines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_iata` (`iata`),
  ADD KEY `idx_icao` (`icao`);

--
-- Indexes for table `airports`
--
ALTER TABLE `airports`
  ADD PRIMARY KEY (`icao`),
  ADD KEY `idx_country` (`country_code`);

--
-- Indexes for table `bookings`
--
ALTER TABLE `bookings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_booking` (`flight_id`),
  ADD KEY `fk_booking_user` (`booked_by_vid`);

--
-- Indexes for table `events`
--
ALTER TABLE `events`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `flights`
--
ALTER TABLE `flights`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_flight` (`flight_number`,`departure_time_zulu`,`category`);

--
-- Indexes for table `private_slot_requests`
--
ALTER TABLE `private_slot_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_psr_user` (`vid`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`vid`);

--
-- Indexes for table `user_roles`
--
ALTER TABLE `user_roles`
  ADD PRIMARY KEY (`vid`,`role`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `airlines`
--
ALTER TABLE `airlines`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `bookings`
--
ALTER TABLE `bookings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `events`
--
ALTER TABLE `events`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `flights`
--
ALTER TABLE `flights`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `private_slot_requests`
--
ALTER TABLE `private_slot_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `bookings`
--
ALTER TABLE `bookings`
  ADD CONSTRAINT `fk_booking_flight` FOREIGN KEY (`flight_id`) REFERENCES `flights` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_booking_user` FOREIGN KEY (`booked_by_vid`) REFERENCES `users` (`vid`) ON DELETE CASCADE;

--
-- Constraints for table `private_slot_requests`
--
ALTER TABLE `private_slot_requests`
  ADD CONSTRAINT `fk_psr_user` FOREIGN KEY (`vid`) REFERENCES `users` (`vid`) ON DELETE CASCADE;

--
-- Constraints for table `user_roles`
--
ALTER TABLE `user_roles`
  ADD CONSTRAINT `fk_user_roles_user` FOREIGN KEY (`vid`) REFERENCES `users` (`vid`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
