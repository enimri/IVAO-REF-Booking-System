<?php
declare(strict_types=1);

/**
 * Email helper functions for sending automated emails
 */

/**
 * Send an email using PHP's mail() function
 * 
 * @param string $to Recipient email address
 * @param string $subject Email subject
 * @param string $body Email body (HTML)
 * @param string $from From email address (optional)
 * @return bool True if email was sent successfully, false otherwise
 */
function send_email(string $to, string $subject, string $body, ?string $from = null): bool {
	if (empty($to)) {
		return false;
	}
	
	// Get from address from config or use default
	if ($from === null) {
		$from = get_email_from_address();
	}
	
	// Email headers
	$headers = [
		'MIME-Version: 1.0',
		'Content-Type: text/html; charset=UTF-8',
		'From: ' . $from,
		'Reply-To: ' . $from,
		'X-Mailer: PHP/' . phpversion()
	];
	
	// Send email
	$result = mail($to, $subject, $body, implode("\r\n", $headers));
	
	// Log email attempt (optional, for debugging)
	if (!$result) {
		error_log("Failed to send email to: {$to}, Subject: {$subject}");
	}
	
	return $result;
}

/**
 * Get the from email address from config or use default
 * 
 * @return string From email address
 */
function get_email_from_address(): string {
	// Try to get from config.php if defined
	if (defined('EMAIL_FROM_ADDRESS')) {
		return EMAIL_FROM_ADDRESS;
	}
	
	// Try to get from environment variable
	$envFrom = getenv('EMAIL_FROM_ADDRESS');
	if ($envFrom !== false) {
		return $envFrom;
	}
	
	// Default fallback
	$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
	return "noreply@{$host}";
}

/**
 * Send booking confirmation email
 * 
 * @param array $user User data (must contain 'email' and 'name' or 'vid')
 * @param array $flight Flight data
 * @return bool True if email was sent successfully
 */
function send_booking_confirmation_email(array $user, array $flight): bool {
	$email = $user['email'] ?? null;
	if (empty($email)) {
		return false; // No email address available
	}
	
	$userName = $user['name'] ?? $user['vid'] ?? 'User';
	$flightNumber = $flight['flight_number'] ?? 'N/A';
	$airlineName = $flight['airline_name'] ?? 'N/A';
	$aircraft = $flight['aircraft'] ?? 'N/A';
	$origin = $flight['origin_icao'] ?? 'N/A';
	$destination = $flight['destination_icao'] ?? 'N/A';
	$departureTime = $flight['departure_time_zulu'] ?? 'N/A';
	$gate = $flight['gate'] ?? 'N/A';
	
	$subject = "Flight Booking Confirmation - {$flightNumber}";
	
	$body = get_email_template('booking_confirmation', [
		'user_name' => htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'),
		'flight_number' => htmlspecialchars($flightNumber, ENT_QUOTES, 'UTF-8'),
		'airline_name' => htmlspecialchars($airlineName, ENT_QUOTES, 'UTF-8'),
		'aircraft' => htmlspecialchars($aircraft, ENT_QUOTES, 'UTF-8'),
		'origin' => htmlspecialchars($origin, ENT_QUOTES, 'UTF-8'),
		'destination' => htmlspecialchars($destination, ENT_QUOTES, 'UTF-8'),
		'departure_time' => htmlspecialchars($departureTime, ENT_QUOTES, 'UTF-8'),
		'gate' => htmlspecialchars($gate, ENT_QUOTES, 'UTF-8'),
		'base_url' => base_url('')
	]);
	
	return send_email($email, $subject, $body);
}

/**
 * Send booking cancellation email
 * 
 * @param array $user User data (must contain 'email' and 'name' or 'vid')
 * @param array $flight Flight data
 * @return bool True if email was sent successfully
 */
function send_booking_cancellation_email(array $user, array $flight): bool {
	$email = $user['email'] ?? null;
	if (empty($email)) {
		return false; // No email address available
	}
	
	$userName = $user['name'] ?? $user['vid'] ?? 'User';
	$flightNumber = $flight['flight_number'] ?? 'N/A';
	$airlineName = $flight['airline_name'] ?? 'N/A';
	$aircraft = $flight['aircraft'] ?? 'N/A';
	$origin = $flight['origin_icao'] ?? 'N/A';
	$destination = $flight['destination_icao'] ?? 'N/A';
	$departureTime = $flight['departure_time_zulu'] ?? 'N/A';
	
	$subject = "Flight Booking Cancelled - {$flightNumber}";
	
	$body = get_email_template('booking_cancellation', [
		'user_name' => htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'),
		'flight_number' => htmlspecialchars($flightNumber, ENT_QUOTES, 'UTF-8'),
		'airline_name' => htmlspecialchars($airlineName, ENT_QUOTES, 'UTF-8'),
		'aircraft' => htmlspecialchars($aircraft, ENT_QUOTES, 'UTF-8'),
		'origin' => htmlspecialchars($origin, ENT_QUOTES, 'UTF-8'),
		'destination' => htmlspecialchars($destination, ENT_QUOTES, 'UTF-8'),
		'departure_time' => htmlspecialchars($departureTime, ENT_QUOTES, 'UTF-8'),
		'base_url' => base_url('')
	]);
	
	return send_email($email, $subject, $body);
}

/**
 * Send private slot request approval email
 * 
 * @param array $user User data (must contain 'email' and 'name' or 'vid')
 * @param array $request Private slot request data
 * @return bool True if email was sent successfully
 */
function send_private_slot_approval_email(array $user, array $request): bool {
	$email = $user['email'] ?? null;
	if (empty($email)) {
		return false; // No email address available
	}
	
	$userName = $user['name'] ?? $user['vid'] ?? 'User';
	$flightNumber = $request['flight_number'] ?? 'N/A';
	$aircraft = $request['aircraft_type'] ?? 'N/A';
	$origin = $request['origin_icao'] ?? 'N/A';
	$destination = $request['destination_icao'] ?? 'N/A';
	$departureTime = $request['departure_time_zulu'] ?? 'N/A';
	
	$subject = "Private Slot Request Approved - {$flightNumber}";
	
	$body = get_email_template('private_slot_approval', [
		'user_name' => htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'),
		'flight_number' => htmlspecialchars($flightNumber, ENT_QUOTES, 'UTF-8'),
		'aircraft' => htmlspecialchars($aircraft, ENT_QUOTES, 'UTF-8'),
		'origin' => htmlspecialchars($origin, ENT_QUOTES, 'UTF-8'),
		'destination' => htmlspecialchars($destination, ENT_QUOTES, 'UTF-8'),
		'departure_time' => htmlspecialchars($departureTime, ENT_QUOTES, 'UTF-8'),
		'base_url' => base_url('')
	]);
	
	return send_email($email, $subject, $body);
}

/**
 * Send private slot request rejection email
 * 
 * @param array $user User data (must contain 'email' and 'name' or 'vid')
 * @param array $request Private slot request data
 * @param string $rejectionReason Reason for rejection
 * @return bool True if email was sent successfully
 */
function send_private_slot_rejection_email(array $user, array $request, string $rejectionReason = ''): bool {
	$email = $user['email'] ?? null;
	if (empty($email)) {
		return false; // No email address available
	}
	
	$userName = $user['name'] ?? $user['vid'] ?? 'User';
	$flightNumber = $request['flight_number'] ?? 'N/A';
	$aircraft = $request['aircraft_type'] ?? 'N/A';
	$origin = $request['origin_icao'] ?? 'N/A';
	$destination = $request['destination_icao'] ?? 'N/A';
	$departureTime = $request['departure_time_zulu'] ?? 'N/A';
	
	$subject = "Private Slot Request Rejected - {$flightNumber}";
	
	$body = get_email_template('private_slot_rejection', [
		'user_name' => htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'),
		'flight_number' => htmlspecialchars($flightNumber, ENT_QUOTES, 'UTF-8'),
		'aircraft' => htmlspecialchars($aircraft, ENT_QUOTES, 'UTF-8'),
		'origin' => htmlspecialchars($origin, ENT_QUOTES, 'UTF-8'),
		'destination' => htmlspecialchars($destination, ENT_QUOTES, 'UTF-8'),
		'departure_time' => htmlspecialchars($departureTime, ENT_QUOTES, 'UTF-8'),
		'rejection_reason' => htmlspecialchars($rejectionReason, ENT_QUOTES, 'UTF-8'),
		'base_url' => base_url('')
	]);
	
	return send_email($email, $subject, $body);
}

/**
 * Send private slot request cancellation email
 * 
 * @param array $user User data (must contain 'email' and 'name' or 'vid')
 * @param array $request Private slot request data
 * @param string $cancellationReason Reason for cancellation
 * @return bool True if email was sent successfully
 */
function send_private_slot_cancellation_email(array $user, array $request, string $cancellationReason = ''): bool {
	$email = $user['email'] ?? null;
	if (empty($email)) {
		return false; // No email address available
	}
	
	$userName = $user['name'] ?? $user['vid'] ?? 'User';
	$flightNumber = $request['flight_number'] ?? 'N/A';
	$aircraft = $request['aircraft_type'] ?? 'N/A';
	$origin = $request['origin_icao'] ?? 'N/A';
	$destination = $request['destination_icao'] ?? 'N/A';
	$departureTime = $request['departure_time_zulu'] ?? 'N/A';
	
	$subject = "Private Slot Request Cancelled - {$flightNumber}";
	
	$body = get_email_template('private_slot_cancellation', [
		'user_name' => htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'),
		'flight_number' => htmlspecialchars($flightNumber, ENT_QUOTES, 'UTF-8'),
		'aircraft' => htmlspecialchars($aircraft, ENT_QUOTES, 'UTF-8'),
		'origin' => htmlspecialchars($origin, ENT_QUOTES, 'UTF-8'),
		'destination' => htmlspecialchars($destination, ENT_QUOTES, 'UTF-8'),
		'departure_time' => htmlspecialchars($departureTime, ENT_QUOTES, 'UTF-8'),
		'cancellation_reason' => htmlspecialchars($cancellationReason, ENT_QUOTES, 'UTF-8'),
		'base_url' => base_url('')
	]);
	
	return send_email($email, $subject, $body);
}

/**
 * Send flight update notification email to all users who booked the flight
 * 
 * @param array $flight Flight data
 * @param array $changes Array of changed fields (optional)
 * @return int Number of emails sent
 */
function send_flight_update_email(array $flight, array $changes = []): int {
	$pdo = db();
	$emailsSent = 0;
	
	// Get all users who booked this flight
	$stmt = $pdo->prepare('
		SELECT DISTINCT u.vid, u.name, u.email 
		FROM bookings b
		INNER JOIN users u ON u.vid = b.booked_by_vid
		WHERE b.flight_id = ? AND u.email IS NOT NULL AND u.email != ""
	');
	$stmt->execute([$flight['id']]);
	$users = $stmt->fetchAll();
	
	foreach ($users as $user) {
		$email = $user['email'] ?? null;
		if (empty($email)) {
			continue;
		}
		
		$userName = $user['name'] ?? $user['vid'] ?? 'User';
		$flightNumber = $flight['flight_number'] ?? 'N/A';
		$airlineName = $flight['airline_name'] ?? 'N/A';
		$aircraft = $flight['aircraft'] ?? 'N/A';
		$origin = $flight['origin_icao'] ?? 'N/A';
		$destination = $flight['destination_icao'] ?? 'N/A';
		$departureTime = $flight['departure_time_zulu'] ?? 'N/A';
		$gate = $flight['gate'] ?? 'N/A';
		
		$subject = "Flight Update - {$flightNumber}";
		
		$body = get_email_template('flight_update', [
			'user_name' => htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'),
			'flight_number' => htmlspecialchars($flightNumber, ENT_QUOTES, 'UTF-8'),
			'airline_name' => htmlspecialchars($airlineName, ENT_QUOTES, 'UTF-8'),
			'aircraft' => htmlspecialchars($aircraft, ENT_QUOTES, 'UTF-8'),
			'origin' => htmlspecialchars($origin, ENT_QUOTES, 'UTF-8'),
			'destination' => htmlspecialchars($destination, ENT_QUOTES, 'UTF-8'),
			'departure_time' => htmlspecialchars($departureTime, ENT_QUOTES, 'UTF-8'),
			'gate' => htmlspecialchars($gate, ENT_QUOTES, 'UTF-8'),
			'changes' => $changes,
			'base_url' => base_url('')
		]);
		
		if (send_email($email, $subject, $body)) {
			$emailsSent++;
		}
	}
	
	return $emailsSent;
}

/**
 * Get email template with variables replaced
 * 
 * @param string $templateName Template name
 * @param array $variables Variables to replace in template
 * @return string Rendered email body
 */
function get_email_template(string $templateName, array $variables = []): string {
	$baseUrl = $variables['base_url'] ?? base_url('');
	$logoUrl = $baseUrl . '/public/uploads/logo.png';
	
	// Default template wrapper
	$wrapper = '
<!DOCTYPE html>
<html>
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>{subject}</title>
	<style>
		body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; line-height: 1.6; color: #333; background-color: #f4f4f4; margin: 0; padding: 0; }
		.email-container { max-width: 600px; margin: 0 auto; background-color: #ffffff; }
		.email-header { background: linear-gradient(135deg, #0f1117 0%, #1a1d29 100%); padding: 30px 20px; text-align: center; }
		.email-header img { max-width: 150px; height: auto; }
		.email-body { padding: 30px 20px; }
		.email-footer { background-color: #f8f9fa; padding: 20px; text-align: center; font-size: 12px; color: #666; }
		.button { display: inline-block; padding: 12px 24px; background-color: #3b82f6; color: #ffffff; text-decoration: none; border-radius: 6px; margin: 20px 0; }
		.info-box { background-color: #f8f9fa; border-left: 4px solid #3b82f6; padding: 15px; margin: 20px 0; }
		.warning-box { background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; }
		.danger-box { background-color: #f8d7da; border-left: 4px solid #dc3545; padding: 15px; margin: 20px 0; }
		.success-box { background-color: #d4edda; border-left: 4px solid #28a745; padding: 15px; margin: 20px 0; }
		table { width: 100%; border-collapse: collapse; margin: 20px 0; }
		table td { padding: 10px; border-bottom: 1px solid #e0e0e0; }
		table td:first-child { font-weight: bold; width: 40%; }
	</style>
</head>
<body>
	<div class="email-container">
		<div class="email-header">
			<img src="' . htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') . '" alt="IVAO Middle East Division" />
		</div>
		<div class="email-body">
			{content}
		</div>
		<div class="email-footer">
			<p>This is an automated email from IVAO Middle East Division.</p>
			<p>Please do not reply to this email.</p>
		</div>
	</div>
</body>
</html>';
	
	// Template content based on template name
	$content = '';
	
	switch ($templateName) {
		case 'booking_confirmation':
			$content = '
			<h2>Flight Booking Confirmed</h2>
			<p>Dear {user_name},</p>
			<p>Your flight booking has been confirmed successfully!</p>
			<div class="success-box">
				<h3>Booking Details</h3>
				<table>
					<tr><td>Flight Number:</td><td>{flight_number}</td></tr>
					<tr><td>Airline:</td><td>{airline_name}</td></tr>
					<tr><td>Aircraft:</td><td>{aircraft}</td></tr>
					<tr><td>Route:</td><td>{origin} → {destination}</td></tr>
					<tr><td>Departure Time (UTC):</td><td>{departure_time}</td></tr>
					<tr><td>Gate:</td><td>{gate}</td></tr>
				</table>
			</div>
			<p>Please make sure to arrive on time for your flight. We look forward to seeing you!</p>
			<p><a href="' . htmlspecialchars($baseUrl . '/my_bookings.php', ENT_QUOTES, 'UTF-8') . '" class="button">View My Bookings</a></p>
			<p>Best regards,<br>IVAO Middle East Division</p>';
			break;
			
		case 'booking_cancellation':
			$content = '
			<h2>Flight Booking Cancelled</h2>
			<p>Dear {user_name},</p>
			<p>Your flight booking has been cancelled.</p>
			<div class="warning-box">
				<h3>Cancelled Booking Details</h3>
				<table>
					<tr><td>Flight Number:</td><td>{flight_number}</td></tr>
					<tr><td>Airline:</td><td>{airline_name}</td></tr>
					<tr><td>Aircraft:</td><td>{aircraft}</td></tr>
					<tr><td>Route:</td><td>{origin} → {destination}</td></tr>
					<tr><td>Departure Time (UTC):</td><td>{departure_time}</td></tr>
				</table>
			</div>
			<p>If you have any questions or need assistance, please contact us.</p>
			<p><a href="' . htmlspecialchars($baseUrl . '/timetable.php', ENT_QUOTES, 'UTF-8') . '" class="button">Browse Available Flights</a></p>
			<p>Best regards,<br>IVAO Middle East Division</p>';
			break;
			
		case 'private_slot_approval':
			$content = '
			<h2>Private Slot Request Approved</h2>
			<p>Dear {user_name},</p>
			<p>Great news! Your private slot request has been approved.</p>
			<div class="success-box">
				<h3>Approved Private Slot Details</h3>
				<table>
					<tr><td>Flight Number:</td><td>{flight_number}</td></tr>
					<tr><td>Aircraft:</td><td>{aircraft}</td></tr>
					<tr><td>Route:</td><td>{origin} → {destination}</td></tr>
					<tr><td>Departure Time (UTC):</td><td>{departure_time}</td></tr>
				</table>
			</div>
			<p>Your private slot is now active. Please make sure to arrive on time for your flight.</p>
			<p><a href="' . htmlspecialchars($baseUrl . '/my_bookings.php', ENT_QUOTES, 'UTF-8') . '" class="button">View My Bookings</a></p>
			<p>Best regards,<br>IVAO Middle East Division</p>';
			break;
			
		case 'private_slot_rejection':
			$rejectionReasonText = !empty($variables['rejection_reason']) 
				? '<div class="danger-box"><strong>Reason for Rejection:</strong><br>' . nl2br($variables['rejection_reason']) . '</div>' 
				: '<p><em>No specific reason was provided.</em></p>';
			
			$content = '
			<h2>Private Slot Request Rejected</h2>
			<p>Dear {user_name},</p>
			<p>We regret to inform you that your private slot request has been rejected.</p>
			<div class="warning-box">
				<h3>Rejected Request Details</h3>
				<table>
					<tr><td>Flight Number:</td><td>{flight_number}</td></tr>
					<tr><td>Aircraft:</td><td>{aircraft}</td></tr>
					<tr><td>Route:</td><td>{origin} → {destination}</td></tr>
					<tr><td>Departure Time (UTC):</td><td>{departure_time}</td></tr>
				</table>
			</div>
			' . $rejectionReasonText . '
			<p>If you have any questions or would like to submit a new request, please visit our website.</p>
			<p><a href="' . htmlspecialchars($baseUrl . '/private_request.php', ENT_QUOTES, 'UTF-8') . '" class="button">Submit New Request</a></p>
			<p>Best regards,<br>IVAO Middle East Division</p>';
			break;
			
		case 'private_slot_cancellation':
			$cancellationReasonText = !empty($variables['cancellation_reason']) 
				? '<div class="danger-box"><strong>Reason for Cancellation:</strong><br>' . nl2br($variables['cancellation_reason']) . '</div>' 
				: '<p><em>No specific reason was provided.</em></p>';
			
			$content = '
			<h2>Private Slot Request Cancelled</h2>
			<p>Dear {user_name},</p>
			<p>We regret to inform you that your private slot request has been cancelled.</p>
			<div class="warning-box">
				<h3>Cancelled Request Details</h3>
				<table>
					<tr><td>Flight Number:</td><td>{flight_number}</td></tr>
					<tr><td>Aircraft:</td><td>{aircraft}</td></tr>
					<tr><td>Route:</td><td>{origin} → {destination}</td></tr>
					<tr><td>Departure Time (UTC):</td><td>{departure_time}</td></tr>
				</table>
			</div>
			' . $cancellationReasonText . '
			<p>If you have any questions or would like to submit a new request, please visit our website.</p>
			<p><a href="' . htmlspecialchars($baseUrl . '/private_request.php', ENT_QUOTES, 'UTF-8') . '" class="button">Submit New Request</a></p>
			<p>Best regards,<br>IVAO Middle East Division</p>';
			break;
			
		case 'flight_update':
			$changesText = '';
			if (!empty($variables['changes'])) {
				$changesText = '<div class="info-box"><h3>Changes Made:</h3><ul>';
				foreach ($variables['changes'] as $field => $value) {
					$changesText .= '<li><strong>' . htmlspecialchars(ucfirst(str_replace('_', ' ', $field)), ENT_QUOTES, 'UTF-8') . ':</strong> ' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</li>';
				}
				$changesText .= '</ul></div>';
			}
			
			$content = '
			<h2>Flight Update Notification</h2>
			<p>Dear {user_name},</p>
			<p>This is to inform you that there has been an update to your booked flight.</p>
			<div class="info-box">
				<h3>Updated Flight Details</h3>
				<table>
					<tr><td>Flight Number:</td><td>{flight_number}</td></tr>
					<tr><td>Airline:</td><td>{airline_name}</td></tr>
					<tr><td>Aircraft:</td><td>{aircraft}</td></tr>
					<tr><td>Route:</td><td>{origin} → {destination}</td></tr>
					<tr><td>Departure Time (UTC):</td><td>{departure_time}</td></tr>
					<tr><td>Gate:</td><td>{gate}</td></tr>
				</table>
			</div>
			' . $changesText . '
			<p>Please review the updated details and make any necessary adjustments to your plans.</p>
			<p><a href="' . htmlspecialchars($baseUrl . '/my_bookings.php', ENT_QUOTES, 'UTF-8') . '" class="button">View My Bookings</a></p>
			<p>Best regards,<br>IVAO Middle East Division</p>';
			break;
			
		default:
			$content = '<p>Email content not available.</p>';
	}
	
	// Replace variables in content
	foreach ($variables as $key => $value) {
		if ($key !== 'changes' && $key !== 'base_url') {
			$content = str_replace('{' . $key . '}', $value, $content);
		}
	}
	
	// Replace content in wrapper
	$wrapper = str_replace('{content}', $content, $wrapper);
	$wrapper = str_replace('{subject}', $variables['subject'] ?? 'Notification', $wrapper);
	
	return $wrapper;
}

