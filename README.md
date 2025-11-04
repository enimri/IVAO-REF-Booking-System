# IVAO REF Booking System

A comprehensive flight slot booking system for IVAO divisions, allowing pilots to book departure, arrival, and private flight slots for organized events.

## Features

- 🔐 **IVAO OAuth Integration** - Secure login using IVAO OpenID Connect
- ✈️ **Flight Slot Management** - Manage departure, arrival, and private flight slots
- 📅 **Timetable System** - View and book available flight slots
- 👥 **User Management** - Automatic user creation via IVAO OAuth
- 🔑 **Role-Based Access Control** - Admin and private admin roles
- 📊 **Event Management** - Create and manage events with booking control
- 🛫 **Flight Import** - Import flights via CSV files
- 🏢 **Airline & Airport Management** - Manage airline and airport databases
- 📝 **Private Slot Requests** - Users can request custom private flight slots
- 📖 **My Bookings** - View and manage personal bookings

## Requirements

- PHP 7.4 or higher
- MySQL 5.7 or higher (or MariaDB 10.3+)
- Web server (Apache/Nginx)
- IVAO OAuth Application (Client ID and Secret)

## Installation

### 1. Clone or Download

Download or clone this repository to your web server directory:

```bash
git clone https://github.com/yourusername/ivao-ref-booking-system.git
cd ivao-ref-booking-system
```

### 2. Database Setup

Create a MySQL database and import the schema:

```bash
mysql -u your_username -p your_database < database/init_db.sql
```

Or manually execute the SQL file in phpMyAdmin or your preferred MySQL client.

### 3. Configuration

1. Copy the example configuration file:
   ```bash
   cp config.example.php config.php
   ```

2. Edit `config.php` and fill in your credentials:
   - **IVAO OAuth Credentials**: Get your `client_id` and `client_secret` from your IVAO OAuth application
   - **Database Credentials**: Update `$DB_HOST`, `$DB_NAME`, `$DB_USER`, and `$DB_PASS`
   - **Redirect URI**: Should match your OAuth application's redirect URI (usually `https://yourdomain.com/oauth_callback.php`)

### 4. File Permissions

Ensure the uploads directory is writable:

```bash
chmod 755 public/uploads
```

### 5. Web Server Configuration

#### Apache
Ensure mod_rewrite is enabled and `.htaccess` files are allowed (if using).

#### Nginx
Configure your server block to point to the project directory and handle PHP:

```nginx
server {
    listen 80;
    server_name yourdomain.com;
    root /path/to/public_html;
    index index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php7.4-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

## IVAO OAuth Setup

1. Register your application at [IVAO Developer Portal](https://wiki.ivao.aero/en/home/dev/api)
2. Set the redirect URI to: `https://yourdomain.com/oauth_callback.php`
3. Copy the Client ID and Client Secret to your `config.php`

## Admin Setup

### Option 1: Via Database (Recommended)

1. Log in to the system using your IVAO account (this creates your user)
2. Connect to your database and run:

```sql
-- Grant admin role
INSERT INTO user_roles (vid, role)
VALUES ('YOUR_IVAO_VID', 'admin')
ON DUPLICATE KEY UPDATE vid=vid;

-- Or set as staff
UPDATE users SET is_staff = 1 WHERE vid = 'YOUR_IVAO_VID';
```

### Option 2: Via SQL File

Edit `database/init_db.sql`, uncomment the admin user section, replace `YOUR_VID_HERE` with your IVAO VID, and run the SQL file.

## Usage

### For Users

1. **Login**: Click "Login" and authenticate with your IVAO account
2. **View Timetable**: Browse available flights on the Timetable page
3. **Book Slots**: Click "Book" on available flights (when system is open)
4. **My Bookings**: View and manage your booked slots
5. **Request Private Slots**: Submit requests for custom flight slots

### For Administrators

1. **Manage Events**: Create and configure events via the admin panel
2. **Manage Flights**: Add flights manually or import via CSV
3. **Manage Airlines**: Add and edit airline information
4. **Manage Airports**: Add and edit airport information
5. **Manage Private Requests**: Approve or reject private slot requests
6. **System Status**: Monitor system health and statistics

## CSV Import Format

The system supports importing flights via CSV. Two formats are supported:

### Minimal Format
```
Flight Number,Aircraft,Departure ICAO,Destination ICAO,Departure Time,Gate
FYC701,A320,OMDB,OEJN,08:00,U1
```

### Full Format
```
flightnumber,airline name,departure,destination,deptime,arrtime,aircraft,route,gate
FYC701,Air Arabia,OMDB,OEJN,08:00,09:30,A320,UBBLB UL602,U1
```

## File Structure

```
.
├── admin.php                  # Admin dashboard
├── config.example.php         # Configuration template
├── database/
│   └── init_db.sql           # Database schema
├── includes/
│   ├── footer.php            # Footer template
│   └── helpers.php            # Helper functions
├── index.php                  # Home page
├── login.php                  # Login page
├── logout.php                 # Logout handler
├── manage_airlines.php       # Airline management
├── manage_airports.php       # Airport management
├── manage_timetable.php      # Flight management
├── my_bookings.php           # User bookings page
├── oauth_callback.php        # IVAO OAuth callback
├── private_admin.php         # Private slot admin
├── private_request.php       # Private slot request form
├── public/
│   ├── assets/
│   │   └── styles.css        # Stylesheet
│   └── uploads/              # User uploads (logo, favicon, etc.)
├── system_status.php         # System status page
├── timetable.php             # Flight timetable
└── update_database.php       # Database update utility
```

## Security Notes

- Use HTTPS in production
- Regularly update PHP and MySQL
- Keep OAuth credentials secure

## Troubleshooting

### Database Connection Issues
- Verify database credentials in `config.php`
- Ensure MySQL service is running
- Check database user permissions

### OAuth Login Issues
- Verify redirect URI matches IVAO application settings
- Check client ID and secret are correct
- Ensure HTTPS is enabled (required for OAuth)

### Permission Errors
- Check file permissions on `public/uploads`
- Ensure PHP has write access to the uploads directory

## Support

For issues, questions, or contributions, please open an issue on GitHub.

## License

This project is open source and available for use by IVAO divisions.

## Credits

Developed for IVAO divisions to manage flight slot bookings efficiently.
