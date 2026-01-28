# PackageTrackr

A self-hosted package tracking application that provides real-time updates for your shipments. This project serves as a replacement for packagetrackr.com, which is no longer maintained. I didn't want to invest a ton of time in this so Claude made about 95% of it, but it seems secure enough.

I have a public instance running at https://packagetrackr.app/ if you'd rather use it than run it yourself.

## Overview

PackageTrackr is a lightweight, self-hosted app to monitor your incoming/outgoing packages. It integrates with the 17track API to provide comprehensive tracking information for packages from multiple carriers including UPS, USPS, FedEx, YunExpress, China Post, SF Express, and Amazon.

## Features

- **Multi-Carrier Support**: Track packages from UPS, USPS, FedEx, YunExpress, China Post, SF Express, and Amazon
- **Real-Time Updates**: Webhook integration with 17track API for instant tracking updates (they don't seem to be super fast, at least not on the free tier)
- **Email Integration**: Forward shipping emails to automatically extract and add tracking numbers
- **User Authentication**: Secure user registration and login system
- **Package Organization**:
  - Multiple views (Current, Archive, Trash)
  - Custom package naming
  - Mark shipments as outgoing
- **Detailed Tracking Information**:
  - Current status with color-coded indicators
  - Estimated delivery dates
  - Complete tracking history with events
  - Delivery confirmation
- **Modern UI**:
  - Responsive Bootstrap 5 interface
  - Theme support (via Bootswatch)
  - Color-coded status badges and indicators
- **Automatic Management**: Auto-archive delivered packages and auto-trash old deliveries

## Requirements

- **Web Server**: Apache with mod_rewrite
- **PHP**: 7.4 or higher
  - PDO extension
  - cURL extension
  - MailParse extension (for email tracking number extraction)
- **Database**: MySQL 5.7+ or MariaDB 10.2+
- **17track Account**: Free tier allows up to 100 packages per month

## Installation

1. **Clone the repository**
   ```bash
   git clone https://github.com/yourusername/packagetrackr.git
   cd packagetrackr
   ```

2. **Configure the database**
   ```bash
   mysql -u root -p < schema.sql
   ```

3. **Configure the application**
   - Copy `includes/config.php.example` to `includes/config.php`
   - Edit `includes/config.php` with your database credentials and settings:
     ```php
     define('SITE_NAME', 'PackageTrackr');
     define('DB_HOST', 'localhost');
     define('DB_USER', 'your_db_user');
     define('DB_PASS', 'your_db_password');
     define('DB_NAME', 'your_db_name');
     define('EMAIL_FROM', 'noreply@yourdomain.com');
     define('TRACKING_EMAIL', 'tracking@yourdomain.com');
     ```

4. **Set up permissions**
   ```bash
   find . -type f -exec chmod 664 {} \;
   find . -type d -exec chmod 775 {} \;
   ```

5. **Configure Apache**
   - Point your virtual host document root to the `httpdocs` directory
   - Ensure `.htaccess` files are enabled (AllowOverride All)

6. **Set up cron jobs** (optional but recommended)
   ```cron
   # Auto-archive delivered packages (daily at 2 AM)
   0 2 * * * /usr/bin/php /path/to/packagetrackr/cron_auto_trash.php

   # Monitor tracking email inbox (every 3 minutes)
   */3 * * * * /usr/bin/php /path/to/packagetrackr/cron_imap_monitor.php

   # Handle Claude email parsing (every 5 minutes)
   */5 * * * * /usr/bin/php /path/to/packagetrackr/cron_claude_processor.php
   ```

## Configuration

### 17track API Setup

1. Create a free account at [17track.net](https://www.17track.net/)
2. Navigate to [API Settings](https://admin.17track.net/api/settings)
3. Copy your Security key
4. Configure webhook settings:
   - **Webhook URL**: `https://yourdomain.com/webhook.php`
   - **Version**: V 2.4

### User Setup

1. Register a new account at `https://yourdomain.com/register.php`
2. Verify your email address
3. Log in and navigate to Settings
4. Enter your 17track API key

### Email Forwarding (Optional)

To automatically add tracking numbers from shipping emails:

1. Set up email forwarding rules in your email client
2. Forward shipping notification emails to the `TRACKING_EMAIL` address configured in `config.php`
3. The cron job will parse emails and extract tracking numbers automatically

## Usage

### Adding Tracking Numbers

- **Manual**: Enter tracking number in the "Quick Track" box or use the "+ Add Tracking Number" button
- **Automatic**: Forward shipping emails to your configured tracking email address

### Managing Packages

- **Rename**: Click the package name to edit it inline
- **View Details**: Click "View Details" to see complete tracking history
- **Mark as Outgoing**: Use the send icon to mark shipments you're sending
- **Organize**: Move packages between Current, Archive, and Trash views
- **Refresh**: Force update tracking information with the refresh button

### Views

- **Current**: Active packages that haven't been delivered or archived
- **Archive**: Delivered packages or packages you've manually archived
- **Trash**: Deleted packages (can be permanently deleted or restored)

## Development

### Project Structure

```
packagetrackr/
├── includes/               # Backend PHP files
│   ├── config.php          # Configuration
│   ├── auth.php            # Authentication
│   ├── database.php        # Database functions
│   ├── tracking_api.php    # 17track API integration
│   ├── functions.inc.php   # Utility functions
│   └── carriers/           # Carrier implementations
│       ├── Carrier.php         # Abstract base class
│       ├── CarrierRegistry.php # Singleton registry for all carriers
│       ├── UpsCarrier.php
│       ├── UspsCarrier.php
│       ├── FedexCarrier.php
│       ├── YunExpressCarrier.php
│       ├── ChinaPostCarrier.php
│       ├── SfExpressCarrier.php
│       └── AmazonCarrier.php
├── *.php                   # Frontend pages
├── api.php                 # API endpoint
├── webhook.php             # 17track webhook handler
├── app.js                  # Frontend JavaScript
├── cron_*.php              # Cron job scripts
└── schema.sql              # Database schema
```

### Adding a New Carrier

The carrier system uses a registry pattern. To add a new carrier:

1. **Create a new carrier class** in `includes/carriers/`:

   ```php
   <?php
   // includes/carriers/NewCarrier.php

   require_once __DIR__ . '/Carrier.php';

   class NewCarrier extends Carrier {
       public function getName(): string {
           return 'New Carrier';
       }

       public function getId(): string {
           return 'NewCarrier';
       }

       public function get17TrackCode(): string {
           // Find the carrier code in the JSON or CSV files at https://api.17track.net/en/doc?version=v2.4&anchor=carrier-code
           return '12345';
       }

       public function getTrackingPatterns(): array {
           // Regex patterns to match this carrier's tracking numbers
           return [
               '/^NC[0-9]{12}$/i',  // Example: NC123456789012
           ];
       }

       public function getLogoPath(): string {
           return '/images/carriers/newcarrier.png';
       }

       public function getTrackingUrl(string $trackingNumber): string {
           return 'https://newcarrier.com/track?num=' . urlencode($trackingNumber);
       }

       public function getDetectionPriority(): int {
           // Higher priority = checked first during auto-detection
           // Use higher values for more specific patterns
           return 50;
       }
   }
   ```

2. **Register the carrier** in `includes/carriers/CarrierRegistry.php`:

   ```php
   // Add at top of file with other requires
   require_once __DIR__ . '/NewCarrier.php';

   // Add in registerDefaultCarriers() method
   $this->register(new NewCarrier());
   ```

3. **Add a carrier logo** (optional) to `/images/carriers/`

### Carrier Detection Priority

When a tracking number is entered, carriers are checked in priority order (highest first). Set the detection priority based on pattern specificity:

| Priority Range | Use Case |
|----------------|----------|
| 90-100 | Very unique patterns (specific prefixes + fixed length) |
| 70-89 | Specific patterns (known prefixes or suffixes) |
| 50-69 | Moderately specific patterns |
| 20-49 | Generic patterns (just digit counts) |

This prevents generic patterns (like "any 12 digits") from matching before more specific ones.

## Security

- All user passwords are hashed using PHP's `password_hash()` with bcrypt
- SQL injection protection via prepared statements
- Session management with configurable timeout
- hCaptcha integration for registration
- Webhook signature verification (HMAC-SHA256)
- `.htaccess` protection for sensitive directories

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

This project is open source and available under the [MIT License](LICENSE).

## Credits

- Built with [Bootstrap 5](https://getbootstrap.com/)
- Themes by [Bootswatch](https://bootswatch.com/)
- Icons by [Bootstrap Icons](https://icons.getbootstrap.com/)
- Package tracking powered by [17track API](https://www.17track.net/)

## Acknowledgments

This project was created as a self-hosted replacement for packagetrackr.com, which is no longer maintained. Special thanks to the original packagetrackr.com for inspiring this project.

## Support

For issues, questions, or contributions, please open an issue on GitHub.
