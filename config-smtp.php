<?php
/**
 * SMTP Email Configuration
 * 
 * IMPORTANT: Update the SMTP_PASSWORD with your actual cPanel password
 * You can also set it as an environment variable for better security
 */

// SMTP Server Settings
define('SMTP_HOST', 'mail.promanaged-it.com');
define('SMTP_PORT', 465);
define('SMTP_USERNAME', '_mainaccount@promanaged-it.com');

// SMTP password MUST come from environment variable for security.
// Example (PowerShell): $env:SMTP_PASSWORD = "your_password"
define('SMTP_PASSWORD', getenv('SMTP_PASSWORD') ?: '');

// Email Settings
define('SMTP_FROM_EMAIL', 'johnpaulchirwa@promanaged-it.com');
define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') ?: 'MotorLink');
define('SMTP_REPLY_TO', 'johnpaulchirwa@promanaged-it.com');

