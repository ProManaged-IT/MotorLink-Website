<?php
/**
 * MotorLink - Business Onboarding API
 * Complete fixed version for database connectivity
 */

// ============================================================================
// CONFIGURATION & INITIALIZATION
// ============================================================================

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/onboarding_errors.log');

// Create logs directory if it doesn't exist
if (!is_dir(__DIR__ . '/logs')) {
    mkdir(__DIR__ . '/logs', 0755, true);
}

// Headers for CORS and JSON responses
if (PHP_SAPI !== 'cli' && !(defined('ONBOARDING_API_AS_LIB') && ONBOARDING_API_AS_LIB === true)) {
    header('Content-Type: application/json; charset=utf-8');

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowedOrigins = [
        'http://localhost:8000',
        'http://127.0.0.1:8000',
        'https://promanaged-it.com',
        'https://www.promanaged-it.com'
    ];

    if ($origin && in_array($origin, $allowedOrigins, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Vary: Origin');
    }

    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }

    // Start session early with cookie params matching admin-api.php so the
    // shared PHP session created at login is readable here.
    if (session_status() !== PHP_SESSION_ACTIVE) {
        $isHTTPS = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? null) == 443)
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

        session_set_cookie_params([
            'lifetime' => 86400,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isHTTPS,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        session_start();
    }
}

// Database Configuration
$serverHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
$isLocalhost = in_array($serverHost, ['localhost', '127.0.0.1'])
    || strpos($serverHost, 'localhost:') === 0
    || strpos($serverHost, '127.0.0.1:') === 0
    || preg_match('/^(192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $serverHost);
$defaultDbHost = (!$isLocalhost && !empty($serverHost)) ? 'localhost' : 'promanaged-it.com';

function loadOnboardingLocalSecrets() {
    $paths = [
        __DIR__ . '/../admin/admin-secrets.local.php',
        __DIR__ . '/../admin/admin-secrets.example.php'
    ];

    foreach ($paths as $path) {
        if (!file_exists($path)) {
            continue;
        }
        $loaded = require $path;
        if (is_array($loaded)) {
            return $loaded;
        }
    }

    return [];
}

function getOnboardingBootstrapDbConfig($defaultHost) {
    $local = loadOnboardingLocalSecrets();

    $config = [
        'host' => getenv('MOTORLINK_DB_HOST') ?: ($local['MOTORLINK_DB_HOST'] ?? $defaultHost),
        'user' => getenv('MOTORLINK_DB_USER') ?: ($local['MOTORLINK_DB_USER'] ?? ''),
        'pass' => getenv('MOTORLINK_DB_PASS') ?: ($local['MOTORLINK_DB_PASS'] ?? ''),
        'name' => getenv('MOTORLINK_DB_NAME') ?: ($local['MOTORLINK_DB_NAME'] ?? '')
    ];

    if ($config['user'] === '' || $config['pass'] === '' || $config['name'] === '') {
        throw new Exception('Missing DB bootstrap credentials. Configure MOTORLINK_DB_* or admin/admin-secrets.local.php.');
    }

    return $config;
}

function loadOnboardingFinalDbConfig(array $bootstrapConfig) {
    $resolved = $bootstrapConfig;

    try {
        $pdo = new PDO(
            "mysql:host={$bootstrapConfig['host']};dbname={$bootstrapConfig['name']};charset=utf8mb4",
            $bootstrapConfig['user'],
            $bootstrapConfig['pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );

        $keyMap = [
            'admin_db_host' => 'host',
            'admin_db_user' => 'user',
            'admin_db_pass' => 'pass',
            'admin_db_name' => 'name'
        ];

        $keys = array_keys($keyMap);
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ($placeholders)");
        $stmt->execute($keys);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $target = $keyMap[$row['setting_key']] ?? null;
            $value = trim((string)($row['setting_value'] ?? ''));
            if ($target && $value !== '') {
                $resolved[$target] = $value;
            }
        }
    } catch (Exception $e) {
        error_log('Onboarding DB settings load warning: ' . $e->getMessage());
    }

    return $resolved;
}

$bootstrapDb = getOnboardingBootstrapDbConfig($defaultDbHost);
$runtimeDb = loadOnboardingFinalDbConfig($bootstrapDb);

define('DB_HOST', $runtimeDb['host']);
define('DB_USER', $runtimeDb['user']);
define('DB_PASS', $runtimeDb['pass']);
define('DB_NAME', $runtimeDb['name']);
define('SITE_NAME', 'MotorLink');
define('SITE_URL', 'https://promanaged-it.com/motorlink');

// Runtime site config (provides motorlink_get_site_runtime_config used for WhatsApp dial code, etc.)
require_once __DIR__ . '/../includes/runtime-site-config.php';

/**
 * Database Connection
 */
class Database {
    private static $instance = null;
    private $connection;
    
    private function __construct() {
        try {
            $this->connection = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch(PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            throw new Exception("Database connection failed: " . $e->getMessage());
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
}

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

/**
 * Send error response and exit
 */
function sendError($message, $code = 500) {
    http_response_code($code);
    echo json_encode([
        'success' => false, 
        'message' => $message, 
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}

/**
 * Send success response and exit
 */
function sendSuccess($data = [], $code = 200) {
    http_response_code($code);
    echo json_encode(array_merge([
        'success' => true,
        'timestamp' => date('Y-m-d H:i:s')
    ], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Get database connection
 */
function getDB() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    
    try {
        $pdo = Database::getInstance()->getConnection();
        return $pdo;
    } catch (Exception $e) {
        sendError('Database connection failed: ' . $e->getMessage(), 500);
    }
}

function getCurrentOnboardingAdmin($db) {
    $adminId = (int)($_SESSION['admin_id'] ?? 0);
    $loggedIn = !empty($_SESSION['admin_logged_in']);

    if (!$loggedIn || $adminId <= 0) {
        return null;
    }

    $stmt = $db->prepare("SELECT id, full_name, email, role, status FROM admin_users WHERE id = ? LIMIT 1");
    $stmt->execute([$adminId]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin || ($admin['status'] ?? '') !== 'active') {
        return null;
    }

    $_SESSION['admin_role'] = $admin['role'] ?? 'admin';
    $_SESSION['admin_name'] = $admin['full_name'] ?? ($_SESSION['admin_name'] ?? '');
    $_SESSION['admin_email'] = $admin['email'] ?? ($_SESSION['admin_email'] ?? '');

    return $admin;
}

function requireOnboardingManagerAccess($db) {
    $admin = getCurrentOnboardingAdmin($db);
    $allowedRoles = ['super_admin', 'admin', 'onboarding_manager'];

    if (!$admin || !in_array($admin['role'] ?? '', $allowedRoles, true)) {
        sendError('Onboarding manager access required', 403);
    }

    return $admin;
}

function handleCheckOnboardingAuth($db) {
    $admin = getCurrentOnboardingAdmin($db);
    $allowedRoles = ['super_admin', 'admin', 'onboarding_manager'];

    if (!$admin || !in_array($admin['role'] ?? '', $allowedRoles, true)) {
        sendSuccess(['authenticated' => false]);
    }

    sendSuccess([
        'authenticated' => true,
        'admin' => [
            'id' => (int)$admin['id'],
            'name' => $admin['full_name'],
            'email' => $admin['email'],
            'role' => $admin['role']
        ]
    ]);
}

/**
 * Validation helper functions
 */
function validateEmail($email) {
    if (empty($email)) {
        return ['valid' => false, 'message' => 'Email is required'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['valid' => false, 'message' => 'Invalid email format'];
    }
    if (strlen($email) > 255) {
        return ['valid' => false, 'message' => 'Email is too long (max 255 characters)'];
    }
    return ['valid' => true];
}

function validatePhone($phone) {
    if (empty($phone)) {
        return ['valid' => false, 'message' => 'Phone number is required'];
    }
    // Remove common formatting characters
    $cleaned = preg_replace('/[\s\-\(\)\+]/', '', $phone);
    // Check if it contains only digits and is reasonable length (7-15 digits)
    if (!preg_match('/^\d{7,15}$/', $cleaned)) {
        return ['valid' => false, 'message' => 'Invalid phone number format. Use 7-15 digits'];
    }
    return ['valid' => true];
}

function validatePassword($password) {
    if (empty($password)) {
        return ['valid' => false, 'message' => 'Password is required'];
    }
    if (strlen($password) < 8) {
        return ['valid' => false, 'message' => 'Password must be at least 8 characters long'];
    }
    if (strlen($password) > 128) {
        return ['valid' => false, 'message' => 'Password is too long (max 128 characters)'];
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return ['valid' => false, 'message' => 'Password must include at least one uppercase letter'];
    }
    if (!preg_match('/[0-9]/', $password)) {
        return ['valid' => false, 'message' => 'Password must include at least one number'];
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        return ['valid' => false, 'message' => 'Password must include at least one special character'];
    }
    return ['valid' => true];
}

function validateUsername($username) {
    if (empty($username)) {
        return ['valid' => false, 'message' => 'Username is required'];
    }
    if (strlen($username) < 3) {
        return ['valid' => false, 'message' => 'Username must be at least 3 characters long'];
    }
    if (strlen($username) > 50) {
        return ['valid' => false, 'message' => 'Username is too long (max 50 characters)'];
    }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        return ['valid' => false, 'message' => 'Username can only contain letters, numbers, and underscores'];
    }
    return ['valid' => true];
}

function validateURL($url, $fieldName = 'URL') {
    if (empty($url)) {
        return ['valid' => true]; // URLs are optional
    }
    if (strlen($url) > 500) {
        return ['valid' => false, 'message' => "$fieldName is too long (max 500 characters)"];
    }
    // Check if it's a valid URL format
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return ['valid' => false, 'message' => "Invalid $fieldName format. Please include http:// or https://"];
    }
    return ['valid' => true];
}

function validateBusinessName($name, $fieldName = 'Business name') {
    $name = trim((string)$name);
    if ($name === '') {
        return ['valid' => false, 'message' => "$fieldName is required"];
    }
    if (strlen($name) < 2) {
        return ['valid' => false, 'message' => "$fieldName must be at least 2 characters long"];
    }
    if (strlen($name) > 255) {
        return ['valid' => false, 'message' => "$fieldName is too long (max 255 characters)"];
    }
    return ['valid' => true];
}

function validateOwnerName($name) {
    $name = trim((string)$name);
    if ($name === '') {
        return ['valid' => false, 'message' => 'Owner name is required'];
    }
    if (strlen($name) < 2) {
        return ['valid' => false, 'message' => 'Owner name must be at least 2 characters'];
    }
    if (strlen($name) > 150) {
        return ['valid' => false, 'message' => 'Owner name is too long (max 150 characters)'];
    }
    if (!preg_match('/^[\p{L}\s\'\-\.]+$/u', $name)) {
        return ['valid' => false, 'message' => 'Owner name contains invalid characters'];
    }
    return ['valid' => true];
}

/**
 * Check if business already exists (by name, email, or phone)
 */
function businessExists($db, $type, $businessName, $email, $phone) {
    try {
        $table = '';
        $nameField = '';
        
        switch ($type) {
            case 'car_hire':
                $table = 'car_hire_companies';
                $nameField = 'business_name';
                break;
            case 'garage':
                $table = 'garages';
                $nameField = 'name';
                break;
            case 'dealer':
                $table = 'car_dealers';
                $nameField = 'business_name';
                break;
            default:
                return false;
        }
        
        // Check in business table (including pending_approval)
        $stmt = $db->prepare("
            SELECT id, $nameField as name, email, phone 
            FROM $table 
            WHERE (LOWER($nameField) = LOWER(?) OR LOWER(email) = LOWER(?) OR phone = ?)
            AND status IN ('active', 'pending_approval')
            LIMIT 1
        ");
        
        $stmt->execute([$businessName, $email, $phone]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            return $existing;
        }
        
        // Also check in users table for duplicates
        $stmt = $db->prepare("
            SELECT id, business_name, email, phone 
            FROM users 
            WHERE (LOWER(business_name) = LOWER(?) OR LOWER(email) = LOWER(?) OR phone = ?)
            AND user_type = ?
            AND status IN ('active', 'pending', 'pending_approval')
            LIMIT 1
        ");
        
        $stmt->execute([$businessName, $email, $phone, $type]);
        $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $existingUser ?: false;
        
    } catch (Exception $e) {
        error_log("Business exists check error: " . $e->getMessage());
        return false;
    }
}

/**
 * Log API activity to file
 */
function logActivity($message) {
    $logFile = __DIR__ . '/logs/onboarding_activity.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}

/**
 * Write a structured entry to the shared admin activity_logs table.
 * Uses the admin_id stored in the session by admin-api.php on login.
 */
function logAdminActivityLog($db, $actionType, $description, $details = null) {
    $adminId = isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null;
    if (!$adminId) return; // no-op when no session (shouldn't happen after auth gate)
    try {
        $stmt = $db->prepare(
            "INSERT INTO activity_logs
                (admin_id, action_type, action_description, details, ip_address, user_agent, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())"
        );
        $stmt->execute([
            $adminId,
            $actionType,
            $description,
            $details,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    } catch (Exception $e) {
        error_log("logAdminActivityLog error: " . $e->getMessage());
    }
}

/**
 * Check whether a table column exists.
 */
function hasTableColumn($db, $table, $column) {
    try {
        $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$table, $column]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return ((int)($row['cnt'] ?? 0)) > 0;
    } catch (Exception $e) {
        logActivity('Column check warning for ' . $table . '.' . $column . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * Ensure users.whatsapp_notifications column exists for opt-in preference persistence.
 */
function ensureUserWhatsappPreferenceColumn($db) {
    try {
        if (!hasTableColumn($db, 'users', 'whatsapp_notifications')) {
            $db->exec("ALTER TABLE users ADD COLUMN whatsapp_notifications TINYINT(1) NOT NULL DEFAULT 0");
        }
    } catch (Exception $e) {
        // Ignore duplicate column and non-fatal schema update errors.
        logActivity('Ensure whatsapp_notifications column warning: ' . $e->getMessage());
    }
}

/**
 * Load SMTP + onboarding notification settings.
 */
function getOnboardingNotificationSettings($db) {
    require_once(__DIR__ . '/../config-smtp.php');

    $defaults = [
        'smtp_host' => defined('SMTP_HOST') ? SMTP_HOST : 'localhost',
        'smtp_port' => (string)(defined('SMTP_PORT') ? SMTP_PORT : 587),
        'smtp_username' => defined('SMTP_USERNAME') ? SMTP_USERNAME : '',
        'smtp_password' => defined('SMTP_PASSWORD') ? SMTP_PASSWORD : '',
        'smtp_from_email' => defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : 'noreply@promanaged-it.com',
        'smtp_from_name' => defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'MotorLink',
        'onboarding_whatsapp_enabled' => '0',
        'onboarding_whatsapp_provider' => 'generic',
        'onboarding_whatsapp_api_url' => '',
        'onboarding_whatsapp_api_token' => '',
        'onboarding_portal_url' => rtrim(SITE_URL, '/') . '/login.html'
    ];

    try {
        $keys = array_keys($defaults);
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $db->prepare("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ($placeholders)");
        $stmt->execute($keys);

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (array_key_exists($row['setting_key'], $defaults)) {
                $defaults[$row['setting_key']] = (string)$row['setting_value'];
            }
        }
    } catch (Exception $e) {
        logActivity('Onboarding settings load warning: ' . $e->getMessage());
    }

    return $defaults;
}

/**
 * Build a tailored quick-start guide for the new business owner.
 * @return array{html:string,text:string,dashboard_url:string}
 */
function getOnboardingQuickStartGuide($businessTypeKey, $portalUrl) {
    $base = rtrim(SITE_URL, '/');
    $dashboards = [
        'car_hire' => $base . '/car-hire-dashboard.html',
        'garage'   => $base . '/garage-dashboard.html',
        'dealer'   => $base . '/dealer-dashboard.html'
    ];
    $dashboardUrl = $dashboards[$businessTypeKey] ?? ($base . '/profile.html');

    $commonSteps = [
        'Sign in using the credentials below and change your password from <em>Profile &raquo; Account Settings</em>.',
        'Upload your business logo and at least 3 photos so customers can recognise you.',
        'Complete your business profile: opening hours, address, phone, website and social links.',
        'Enable WhatsApp notifications in your profile to receive enquiries instantly.'
    ];

    $typeSteps = [
        'car_hire' => [
            'Add your fleet under <em>My Vehicles</em> with daily, weekly and monthly rates.',
            'Set <em>Hire Categories</em> (Standard, Events, Vans &amp; Trucks) to appear in the right searches.',
            'Confirm your service area and pickup/return options so customers see availability.'
        ],
        'garage' => [
            'Add the <em>Services</em> you offer (mechanical, body, electrical, recovery) and approximate price ranges.',
            'List the <em>Vehicle Makes</em> you specialise in to match incoming customer requests.',
            'Turn on <em>24/7 Recovery</em> if applicable so urgent jobs reach you first.'
        ],
        'dealer' => [
            'Post your first vehicle from the dashboard &raquo; <em>Add New Listing</em>.',
            'Mark featured stock to appear at the top of search results.',
            'Add finance, trade-in and import options so buyers know what you offer.'
        ]
    ];

    $steps = array_merge($typeSteps[$businessTypeKey] ?? [], $commonSteps);

    $listHtml = '';
    foreach ($steps as $i => $step) {
        $listHtml .= '<li style="margin:0 0 8px 0;"><strong>Step ' . ($i + 1) . '.</strong> ' . $step . '</li>';
    }

    $safeDashboard = htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8');

    $html = '
        <div style="border-top:1px solid #e1ece6;margin:16px 0 0 0;padding:16px 0 0 0;">
            <h3 style="margin:0 0 10px 0;color:#0f6d37;font-size:16px;">Quick Start Guide</h3>
            <p style="margin:0 0 10px 0;font-size:14px;">Get the most out of MotorLink in less than 10 minutes:</p>
            <ol style="margin:0 0 12px 18px;padding:0;font-size:14px;color:#1c2d24;">' . $listHtml . '</ol>
            <p style="margin:0 0 6px 0;font-size:14px;">
                <a href="' . $safeDashboard . '" style="display:inline-block;background:#1a7431;color:#ffffff;text-decoration:none;padding:10px 16px;border-radius:8px;font-weight:600;">Open Your Dashboard</a>
            </p>
            <p style="margin:8px 0 0 0;font-size:13px;color:#5f6b66;">Tip: keep your contact phone and WhatsApp current so customers can reach you instantly.</p>
        </div>
    ';

    $textLines = ["Quick Start Guide:"];
    foreach ($steps as $i => $step) {
        $textLines[] = ($i + 1) . '. ' . trim(strip_tags($step));
    }
    $textLines[] = '';
    $textLines[] = 'Your dashboard: ' . $dashboardUrl;
    $text = implode("\n", $textLines);

    return [
        'html' => $html,
        'text' => $text,
        'dashboard_url' => $dashboardUrl
    ];
}

/**
 * Send onboarding credentials email.
 */
function sendOnboardingWelcomeEmail($db, $payload) {
    try {
        require_once(__DIR__ . '/../includes/smtp-mailer.php');
        $settings = getOnboardingNotificationSettings($db);

        $recipientEmail = trim((string)($payload['email'] ?? ''));
        if ($recipientEmail === '' || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            return [
                'sent' => false,
                'status' => 'skipped',
                'message' => 'Invalid recipient email address'
            ];
        }

        $recipientName = trim((string)($payload['owner_name'] ?? 'Client'));
        $businessName = trim((string)($payload['business_name'] ?? 'Your Business'));
        $businessType = trim((string)($payload['business_type'] ?? 'Business Account'));
        $username = trim((string)($payload['username'] ?? ''));
        $plainPassword = (string)($payload['password'] ?? '');
        $reference = trim((string)($payload['reference'] ?? ''));
        $portalUrl = trim((string)$settings['onboarding_portal_url']);
        if ($portalUrl === '') {
            $portalUrl = rtrim(SITE_URL, '/') . '/login.html';
        }

        $subject = SITE_NAME . ' - Your New Account Credentials';

        $safeRecipientName = htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8');
        $safeBusinessName = htmlspecialchars($businessName, ENT_QUOTES, 'UTF-8');
        $safeBusinessType = htmlspecialchars($businessType, ENT_QUOTES, 'UTF-8');
        $safeUsername = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
        $safePassword = htmlspecialchars($plainPassword, ENT_QUOTES, 'UTF-8');
        $safePortalUrl = htmlspecialchars($portalUrl, ENT_QUOTES, 'UTF-8');
        $safeReference = htmlspecialchars($reference, ENT_QUOTES, 'UTF-8');

        $referenceLine = $safeReference !== ''
            ? '<p style="margin:0 0 8px 0;"><strong>Reference:</strong> ' . $safeReference . '</p>'
            : '';

        $businessTypeKey = strtolower(trim((string)($payload['business_type_key'] ?? '')));
        if (!in_array($businessTypeKey, ['car_hire', 'garage', 'dealer'], true)) {
            // Fallback: derive from business_type label.
            $btLower = strtolower($businessType);
            if (strpos($btLower, 'hire') !== false)      $businessTypeKey = 'car_hire';
            elseif (strpos($btLower, 'garage') !== false) $businessTypeKey = 'garage';
            elseif (strpos($btLower, 'dealer') !== false) $businessTypeKey = 'dealer';
            else $businessTypeKey = 'dealer';
        }
        $guide = getOnboardingQuickStartGuide($businessTypeKey, $portalUrl);

        $htmlMessage = '
            <div style="font-family:Segoe UI,Arial,sans-serif;line-height:1.55;color:#113322;background:#f3fbf6;padding:20px;">
                <div style="max-width:620px;margin:0 auto;background:#ffffff;border:1px solid #dcefe2;border-radius:14px;overflow:hidden;">
                    <div style="background:linear-gradient(135deg,#1f8f4b,#0f6d37);color:#ffffff;padding:20px 22px;">
                        <h2 style="margin:0;font-size:20px;">Welcome to ' . htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8') . '</h2>
                        <p style="margin:8px 0 0 0;font-size:14px;opacity:0.95;">Your onboarding is complete and your account is ready.</p>
                    </div>
                    <div style="padding:22px;">
                        <p style="margin:0 0 12px 0;">Hello ' . $safeRecipientName . ',</p>
                        <p style="margin:0 0 14px 0;">We created your <strong>' . $safeBusinessType . '</strong> account for <strong>' . $safeBusinessName . '</strong>.</p>
                        ' . $referenceLine . '
                        <div style="border:1px solid #cde7d6;border-radius:10px;padding:14px 16px;background:#f7fffa;margin:0 0 16px 0;">
                            <p style="margin:0 0 8px 0;"><strong>Login URL:</strong> <a href="' . $safePortalUrl . '" style="color:#0f6d37;text-decoration:none;">' . $safePortalUrl . '</a></p>
                            <p style="margin:0 0 8px 0;"><strong>Username:</strong> ' . $safeUsername . '</p>
                            <p style="margin:0;"><strong>Temporary Password:</strong> ' . $safePassword . '</p>
                        </div>
                        <p style="margin:0 0 12px 0;">Please log in and change your password immediately for security.</p>
                        ' . $guide['html'] . '
                        <p style="margin:14px 0 0 0;">Need help? Reply to this email and our team will assist you.</p>
                    </div>
                </div>
            </div>
        ';

        $textMessage = "Welcome to " . SITE_NAME . "\n\n" .
            "Hello {$recipientName},\n" .
            "Your {$businessType} account for {$businessName} has been created.\n" .
            ($reference !== '' ? "Reference: {$reference}\n" : '') .
            "Login URL: {$portalUrl}\n" .
            "Username: {$username}\n" .
            "Temporary Password: {$plainPassword}\n\n" .
            "Please log in and change your password immediately.\n\n" .
            $guide['text'];

        $mailer = new SMTPMailer(
            (string)$settings['smtp_host'],
            (int)$settings['smtp_port'],
            (string)$settings['smtp_username'],
            (string)$settings['smtp_password'],
            (string)$settings['smtp_from_email'],
            (string)$settings['smtp_from_name']
        );

        $sent = $mailer->send($recipientEmail, $subject, $htmlMessage, $textMessage);
        if ($sent) {
            logActivity('Onboarding credentials email sent to ' . $recipientEmail);
            return [
                'sent' => true,
                'status' => 'sent',
                'message' => 'Credentials email delivered'
            ];
        }

        logActivity('Onboarding credentials email failed for ' . $recipientEmail);
        return [
            'sent' => false,
            'status' => 'failed',
            'message' => 'SMTP send failed'
        ];
    } catch (Exception $e) {
        logActivity('Onboarding email exception: ' . $e->getMessage());
        return [
            'sent' => false,
            'status' => 'failed',
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Normalize phone to WhatsApp-friendly international format.
 * @param string $phone   Raw phone input from user
 * @param string $dialCode Country dial code digits only (e.g. '265' for Malawi, '27' for South Africa)
 */
function normalizeWhatsappPhone($phone, string $dialCode = '') {
    $digits = preg_replace('/\D+/', '', (string)$phone);
    if ($digits === '') {
        return '';
    }

    // Local format 0XXXXXXXXX -> {dialCode}XXXXXXXXX
    if ($dialCode !== '' && strpos($digits, '0') === 0 && strlen($digits) >= 9) {
        return $dialCode . ltrim($digits, '0');
    }

    return $digits;
}

/**
 * Send onboarding credentials on WhatsApp using optional webhook/API endpoint.
 */
function sendOnboardingWelcomeWhatsApp($db, $payload) {
    try {
        $optIn = (int)($payload['whatsapp_updates_opt_in'] ?? 0) === 1;
        if (!$optIn) {
            return [
                'sent' => false,
                'status' => 'skipped',
                'message' => 'Client opted out of WhatsApp updates'
            ];
        }

        $settings = getOnboardingNotificationSettings($db);
        $enabledRaw = strtolower(trim((string)($settings['onboarding_whatsapp_enabled'] ?? '0')));
        $enabled = in_array($enabledRaw, ['1', 'true', 'yes', 'on'], true);

        if (!$enabled) {
            return [
                'sent' => false,
                'status' => 'skipped',
                'message' => 'WhatsApp notifications are disabled'
            ];
        }

        $apiUrl = trim((string)($settings['onboarding_whatsapp_api_url'] ?? ''));
        $provider = strtolower(trim((string)($settings['onboarding_whatsapp_provider'] ?? 'generic')));
        $apiToken = trim((string)($settings['onboarding_whatsapp_api_token'] ?? ''));

        // Provider must be either a generic POST endpoint or a built-in (callmebot).
        if ($provider !== 'callmebot' && $apiUrl === '') {
            return [
                'sent' => false,
                'status' => 'skipped',
                'message' => 'WhatsApp provider not configured. Set onboarding_whatsapp_provider (generic|callmebot), onboarding_whatsapp_api_url and onboarding_whatsapp_api_token in site_settings.'
            ];
        }
        if ($provider === 'callmebot' && $apiToken === '') {
            return [
                'sent' => false,
                'status' => 'skipped',
                'message' => 'CallMeBot provider selected but onboarding_whatsapp_api_token (CallMeBot APIKEY) is empty.'
            ];
        }

        $siteConfig = motorlink_get_site_runtime_config($db);
        $targetPhone = normalizeWhatsappPhone($payload['whatsapp'] ?? ($payload['phone'] ?? ''), $siteConfig['phone_dial_code'] ?? '');
        if ($targetPhone === '') {
            return [
                'sent' => false,
                'status' => 'skipped',
                'message' => 'No WhatsApp number provided by client'
            ];
        }

        if (!function_exists('curl_init')) {
            return [
                'sent' => false,
                'status' => 'failed',
                'message' => 'cURL extension not available'
            ];
        }

        $recipientName = trim((string)($payload['owner_name'] ?? 'Client'));
        $businessName = trim((string)($payload['business_name'] ?? 'Your Business'));
        $username = trim((string)($payload['username'] ?? ''));
        $plainPassword = (string)($payload['password'] ?? '');
        $portalUrl = trim((string)($settings['onboarding_portal_url'] ?? rtrim(SITE_URL, '/') . '/login.html'));

        $messageText = SITE_NAME . " Onboarding\n" .
            "Hello {$recipientName}, your account for {$businessName} is ready.\n" .
            "Login: {$portalUrl}\n" .
            "Username: {$username}\n" .
            "Password: {$plainPassword}\n" .
            "Please change password after first login.\n\n" .
            "Quick start:\n" .
            "1. Sign in & change password\n" .
            "2. Upload logo and 3+ photos\n" .
            "3. Complete profile (hours, address, socials)\n" .
            "4. Enable WhatsApp notifications\n" .
            "Need help? Reply to this message.";

        $requestBody = [
            'to' => $targetPhone,
            'name' => $recipientName,
            'message' => $messageText,
            'username' => $username,
            'password' => $plainPassword,
            'business_name' => $businessName
        ];

        $headers = ['Content-Type: application/json'];
        if ($apiToken !== '' && $provider !== 'callmebot') {
            $headers[] = 'Authorization: Bearer ' . $apiToken;
        }

        if ($provider === 'callmebot') {
            // CallMeBot WhatsApp: simple GET endpoint, recipient must enroll first.
            $cmbUrl = 'https://api.callmebot.com/whatsapp.php?' . http_build_query([
                'phone'  => $targetPhone,
                'text'   => $messageText,
                'apikey' => $apiToken
            ]);
            $ch = curl_init($cmbUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPGET, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        } else {
            $ch = curl_init($apiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody, JSON_UNESCAPED_UNICODE));
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        }

        $responseBody = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            logActivity('Onboarding WhatsApp cURL error: ' . $curlError);
            return [
                'sent' => false,
                'status' => 'failed',
                'message' => $curlError
            ];
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            logActivity('Onboarding WhatsApp sent to ' . $targetPhone);
            return [
                'sent' => true,
                'status' => 'sent',
                'message' => 'WhatsApp notification sent',
                'http_code' => $httpCode
            ];
        }

        logActivity('Onboarding WhatsApp API failed: HTTP ' . $httpCode . ' | response=' . substr((string)$responseBody, 0, 300));
        return [
            'sent' => false,
            'status' => 'failed',
            'message' => 'WhatsApp API returned HTTP ' . $httpCode,
            'http_code' => $httpCode
        ];
    } catch (Exception $e) {
        logActivity('Onboarding WhatsApp exception: ' . $e->getMessage());
        return [
            'sent' => false,
            'status' => 'failed',
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Send onboarding notifications (email + optional WhatsApp).
 */
function sendOnboardingWelcomeNotifications($db, $payload) {
    $email = sendOnboardingWelcomeEmail($db, $payload);
    $whatsapp = sendOnboardingWelcomeWhatsApp($db, $payload);

    return [
        'email' => $email,
        'whatsapp' => $whatsapp
    ];
}

// ============================================================================
// API ROUTING & MAIN EXECUTION
// ============================================================================

// Allow this file to be included for CLI/test harnesses without invoking
// the HTTP routing block. Define ONBOARDING_API_AS_LIB=true before include.
if (defined('ONBOARDING_API_AS_LIB') && ONBOARDING_API_AS_LIB === true) {
    return;
}

try {
    $db = getDB();
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    
    logActivity("API call: $action from " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    
    if (empty($action)) {
        sendError('No action specified', 400);
    }

    if ($action !== 'check_auth') {
        requireOnboardingManagerAccess($db);
    }
    
    // Route to appropriate handler
    switch ($action) {
        case 'check_auth':
            handleCheckOnboardingAuth($db);
            break;
        case 'locations': 
            getLocations($db); 
            break;
        case 'check_business': 
            checkBusinessExists($db); 
            break;
        case 'check_email_phone': 
            checkEmailOrPhoneExists($db); 
            break;
        case 'check_business_name': 
            checkBusinessNameExists($db); 
            break;
        case 'check_username':
            checkUsernameExists($db);
            break;
        case 'add_car_hire': 
            addCarHireCompany($db); 
            break;
        case 'add_garage': 
            addGarage($db); 
            break;
        case 'add_dealer': 
            addCarDealer($db); 
            break;
        case 'get_makes': 
            getMakes($db); 
            break;
        case 'get_services': 
            getServices($db); 
            break;
        case 'get_vehicle_types': 
            getVehicleTypes($db); 
            break;
        case 'search_existing_businesses':
            searchExistingBusinesses($db);
            break;
        case 'claim_existing_business':
            claimExistingBusiness($db);
            break;
        default:
            sendError('Invalid action: ' . $action, 400);
    }
} catch (Exception $e) {
    error_log("Onboarding API Fatal Error: " . $e->getMessage());
    logActivity("FATAL ERROR: " . $e->getMessage());
    sendError('Internal server error: ' . $e->getMessage(), 500);
}

// ============================================================================
// API HANDLERS
// ============================================================================

/**
 * Get all active locations
 */
function getLocations($db) {
    try {
        $stmt = $db->query("
            SELECT id, name, region, district 
            FROM locations 
            WHERE is_active = 1 
            ORDER BY region ASC, name ASC
        ");
        $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        logActivity("Loaded " . count($locations) . " locations");
        sendSuccess(['locations' => $locations]);
        
    } catch (Exception $e) {
        error_log("getLocations error: " . $e->getMessage());
        sendError('Failed to load locations', 500);
    }
}

/**
 * Check if business already exists
 */
function checkBusinessExists($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        $input = $_POST;
    }
    
    $type = $input['type'] ?? '';
    $businessName = $input['business_name'] ?? '';
    $email = $input['email'] ?? '';
    $phone = $input['phone'] ?? '';
    
    if (empty($type) || empty($businessName) || empty($email) || empty($phone)) {
        sendError('Type, business name, email, and phone are required', 400);
    }
    
    try {
        $existing = businessExists($db, $type, $businessName, $email, $phone);
        
        // Check if email belongs to an existing user (for second business check)
        $isSecondBusiness = false;
        if ($existing) {
            $userStmt = $db->prepare("
                SELECT id, email, user_type, business_id 
                FROM users 
                WHERE LOWER(email) = LOWER(?)
                AND status IN ('active', 'pending', 'pending_approval')
                LIMIT 1
            ");
            $userStmt->execute([$email]);
            $existingUser = $userStmt->fetch(PDO::FETCH_ASSOC);
            
            // If email matches an existing user, it might be a second business
            if ($existingUser) {
                $isSecondBusiness = true;
            }
        }
        
        if ($existing) {
            logActivity("Duplicate found for $type: " . $businessName);
            sendSuccess([
                'exists' => true,
                'business' => $existing,
                'is_second_business' => $isSecondBusiness,
                'message' => 'A business with similar details already exists in our system.'
            ]);
        } else {
            logActivity("No duplicate found for $type: " . $businessName);
            sendSuccess([
                'exists' => false, 
                'message' => 'No duplicate business found.'
            ]);
        }
        
    } catch (Exception $e) {
        error_log("Check business exists error: " . $e->getMessage());
        sendError('Failed to check business existence', 500);
    }
}

/**
 * Check if email or phone already exists (for real-time validation)
 */
function checkEmailOrPhoneExists($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        $input = $_POST;
    }
    
    $email = $input['email'] ?? '';
    $phone = $input['phone'] ?? '';
    $type = $input['type'] ?? '';
    
    if (empty($email) && empty($phone)) {
        sendError('Email or phone is required', 400);
    }
    
    try {
        $results = [
            'email_exists' => false,
            'phone_exists' => false,
            'email_belongs_to_user' => false,
            'phone_belongs_to_user' => false
        ];
        
        // Check email in users table
        if (!empty($email)) {
            $stmt = $db->prepare("
                SELECT id, email, user_type, business_id 
                FROM users 
                WHERE LOWER(email) = LOWER(?)
                AND status IN ('active', 'pending', 'pending_approval')
                LIMIT 1
            ");
            $stmt->execute([$email]);
            $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existingUser) {
                $results['email_exists'] = true;
                $results['email_belongs_to_user'] = true;
            }
            
            // Also check in business tables
            if (!$results['email_exists']) {
                $tables = ['car_hire_companies', 'garages', 'car_dealers'];
                foreach ($tables as $table) {
                    $emailField = ($table === 'garages') ? 'email' : 'email';
                    $stmt = $db->prepare("
                        SELECT id, email 
                        FROM $table 
                        WHERE LOWER(email) = LOWER(?)
                        AND status IN ('active', 'pending_approval')
                        LIMIT 1
                    ");
                    $stmt->execute([$email]);
                    if ($stmt->fetch()) {
                        $results['email_exists'] = true;
                        break;
                    }
                }
            }
        }
        
        // Check phone in users table
        if (!empty($phone)) {
            $stmt = $db->prepare("
                SELECT id, phone, user_type, business_id 
                FROM users 
                WHERE phone = ?
                AND status IN ('active', 'pending', 'pending_approval')
                LIMIT 1
            ");
            $stmt->execute([$phone]);
            $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existingUser) {
                $results['phone_exists'] = true;
                $results['phone_belongs_to_user'] = true;
            }
            
            // Also check in business tables
            if (!$results['phone_exists']) {
                $tables = ['car_hire_companies', 'garages', 'car_dealers'];
                foreach ($tables as $table) {
                    $stmt = $db->prepare("
                        SELECT id, phone 
                        FROM $table 
                        WHERE phone = ?
                        AND status IN ('active', 'pending_approval')
                        LIMIT 1
                    ");
                    $stmt->execute([$phone]);
                    if ($stmt->fetch()) {
                        $results['phone_exists'] = true;
                        break;
                    }
                }
            }
        }
        
        sendSuccess($results);
        
    } catch (Exception $e) {
        error_log("Check email/phone exists error: " . $e->getMessage());
        sendError('Failed to check email/phone existence', 500);
    }
}

/**
 * Check if business name already exists (for real-time validation)
 */
function checkBusinessNameExists($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        $input = $_POST;
    }
    
    $businessName = $input['business_name'] ?? '';
    $type = $input['type'] ?? '';
    
    if (empty($businessName) || empty($type)) {
        sendError('Business name and type are required', 400);
    }
    
    try {
        $results = [
            'exists' => false,
            'is_second_business' => false
        ];
        
        // Determine table and name field based on type
        $table = '';
        $nameField = '';
        
        switch ($type) {
            case 'car_hire':
                $table = 'car_hire_companies';
                $nameField = 'business_name';
                break;
            case 'garage':
                $table = 'garages';
                $nameField = 'name';
                break;
            case 'dealer':
                $table = 'car_dealers';
                $nameField = 'business_name';
                break;
            default:
                sendError('Invalid business type', 400);
        }
        
        // Check in business table (including pending_approval)
        $stmt = $db->prepare("
            SELECT id, $nameField as name, email, phone, user_id
            FROM $table 
            WHERE $nameField = ?
            AND status IN ('active', 'pending_approval')
            LIMIT 1
        ");
        $stmt->execute([$businessName]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            $results['exists'] = true;
            
            // Check if this business belongs to a user (might be second business)
            if (!empty($existing['user_id'])) {
                $userStmt = $db->prepare("
                    SELECT id, email, user_type, business_id 
                    FROM users 
                    WHERE id = ?
                    AND status IN ('active', 'pending', 'pending_approval')
                    LIMIT 1
                ");
                $userStmt->execute([$existing['user_id']]);
                $existingUser = $userStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existingUser) {
                    $results['is_second_business'] = true;
                }
            }
        }
        
        // Also check in users table for business_name
        if (!$results['exists']) {
            $stmt = $db->prepare("
                SELECT id, business_name, email, phone 
                FROM users 
                WHERE business_name = ?
                AND user_type = ?
                AND status IN ('active', 'pending', 'pending_approval')
                LIMIT 1
            ");
            $stmt->execute([$businessName, $type]);
            $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existingUser) {
                $results['exists'] = true;
                $results['is_second_business'] = true; // If in users table, likely same user
            }
        }
        
        sendSuccess($results);
        
    } catch (Exception $e) {
        error_log("Check business name exists error: " . $e->getMessage());
        sendError('Failed to check business name existence', 500);
    }
}

/**
 * Check if a username is already taken (real-time validation helper).
 * Returns { taken: bool, message: string }
 */
function checkUsernameExists($db) {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $username = trim((string)($input['username'] ?? ''));
    // Exclude this user's own placeholder when editing (optional)
    $excludeUserId = (int)($input['exclude_user_id'] ?? 0);

    if ($username === '') {
        sendError('username is required', 400);
    }

    // Format check first — fast, no DB hit
    $formatCheck = validateUsername($username);
    if (!$formatCheck['valid']) {
        sendSuccess(['taken' => false, 'format_error' => $formatCheck['message']]);
    }

    try {
        $stmt = $db->prepare(
            "SELECT id FROM users
             WHERE LOWER(username) = LOWER(?)
             AND (" . ($excludeUserId > 0 ? "id != ?" : "1=1") . ")
             LIMIT 1"
        );
        $params = [$username];
        if ($excludeUserId > 0) $params[] = $excludeUserId;
        $stmt->execute($params);

        $taken = (bool)$stmt->fetch();
        sendSuccess([
            'taken'   => $taken,
            'message' => $taken ? "Username \"{$username}\" is already taken. Please choose another." : 'Username is available.'
        ]);
    } catch (Exception $e) {
        error_log('checkUsernameExists error: ' . $e->getMessage());
        sendError('Failed to check username', 500);
    }
}

/**
 * Get car makes for specialization
 */
function getMakes($db) {
    try {
        $stmt = $db->query("
            SELECT id, name 
            FROM car_makes 
            WHERE is_active = 1 
            ORDER BY name ASC
        ");
        $makes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        sendSuccess(['makes' => $makes]);
    } catch (Exception $e) {
        error_log("getMakes error: " . $e->getMessage());
        // Return empty array instead of error for better UX
        sendSuccess(['makes' => []]);
    }
}

/**
 * Get common services for garages
 */
function getServices($db) {
    $services = [
        "Engine Repair", "Brake Service", "Oil Change", "AC Repair", "Transmission Service",
        "Electrical Repair", "Body Work", "Painting", "Dent Removal", "Glass Replacement",
        "Tire Service", "Battery Replacement", "Computer Diagnostics", "Hybrid Service", "Performance Tuning"
    ];
    
    sendSuccess(['services' => $services]);
}

/**
 * Get vehicle types for car hire
 */
function getVehicleTypes($db) {
    $types = [
        "Economy", "Compact", "Sedan", "SUV", "Pickup", "Luxury",
        "Sports Car", "Van", "Truck", "Minibus", "4WD", "Executive", "Limousine"
    ];
    
    sendSuccess(['vehicle_types' => $types]);
}

/**
 * Add a new car hire company
 */
function addCarHireCompany($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('POST method required', 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        $input = $_POST;
    }

    // Debug: Log received data
    logActivity("=== ADD CAR HIRE DEBUG ===");
    logActivity("Received fields: " . implode(', ', array_keys($input)));
    logActivity("Username received: " . ($input['username'] ?? 'NOT SET'));
    logActivity("Password received: " . (isset($input['password']) ? 'YES (length: ' . strlen($input['password']) . ')' : 'NOT SET'));
    logActivity("Email received: " . ($input['email'] ?? 'NOT SET'));
    logActivity("Business name received: " . ($input['business_name'] ?? 'NOT SET'));

    // Validate required fields (including login credentials)
    $required = ['business_name', 'owner_name', 'email', 'phone', 'address', 'location_id', 'username', 'password'];
    $missing = [];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            $missing[] = $field;
        }
    }

    if (!empty($missing)) {
        logActivity("Missing required fields: " . implode(', ', $missing));
        sendError("Missing required fields: " . implode(', ', $missing), 400);
    }

    // Comprehensive validation
    $validation = validateBusinessName($input['business_name'], 'Business name');
    if (!$validation['valid']) {
        sendError($validation['message'], 400);
    }

    $validation = validateOwnerName($input['owner_name']);
    if (!$validation['valid']) {
        sendError($validation['message'], 400);
    }

    $validation = validateEmail($input['email']);
    if (!$validation['valid']) {
        sendError($validation['message'], 400);
    }

    $validation = validatePhone($input['phone']);
    if (!$validation['valid']) {
        sendError($validation['message'], 400);
    }

    $validation = validateUsername($input['username']);
    if (!$validation['valid']) {
        sendError($validation['message'], 400);
    }

    $validation = validatePassword($input['password']);
    if (!$validation['valid']) {
        sendError($validation['message'], 400);
    }

    // Validate optional URLs
    if (!empty($input['website'])) {
        $validation = validateURL($input['website'], 'Website URL');
        if (!$validation['valid']) {
            sendError($validation['message'], 400);
        }
    }

    if (!empty($input['facebook_url'])) {
        $validation = validateURL($input['facebook_url'], 'Facebook URL');
        if (!$validation['valid']) {
            sendError($validation['message'], 400);
        }
    }

    if (!empty($input['instagram_url'])) {
        $validation = validateURL($input['instagram_url'], 'Instagram URL');
        if (!$validation['valid']) {
            sendError($validation['message'], 400);
        }
    }

    if (!empty($input['twitter_url'])) {
        $validation = validateURL($input['twitter_url'], 'Twitter URL');
        if (!$validation['valid']) {
            sendError($validation['message'], 400);
        }
    }

    if (!empty($input['linkedin_url'])) {
        $validation = validateURL($input['linkedin_url'], 'LinkedIn URL');
        if (!$validation['valid']) {
            sendError($validation['message'], 400);
        }
    }

    // Validate WhatsApp if provided
    if (!empty($input['whatsapp'])) {
        $validation = validatePhone($input['whatsapp']);
        if (!$validation['valid']) {
            sendError('Invalid WhatsApp number format. ' . $validation['message'], 400);
        }
    }

    try {
        // Check if business already exists (including pending_approval)
        $existing = businessExists($db, 'car_hire', $input['business_name'], $input['email'], $input['phone']);
        if ($existing) {
            $existingName = $existing['name'] ?? $existing['business_name'] ?? 'Unknown';
            sendError('A car hire company with similar details already exists. Business: ' . $existingName, 409);
        }

        // Check if username already exists (case-insensitive) - check all statuses
        $stmt = $db->prepare("SELECT id, username FROM users WHERE LOWER(username) = LOWER(?)");
        $stmt->execute([$input['username']]);
        $existingUser = $stmt->fetch();
        if ($existingUser) {
            sendError('Username "' . $existingUser['username'] . '" already exists. Please choose a different username.', 409);
        }

        // Check if email already exists in users table (case-insensitive) - check all statuses
        $stmt = $db->prepare("SELECT id, email FROM users WHERE LOWER(email) = LOWER(?)");
        $stmt->execute([$input['email']]);
        $existingEmail = $stmt->fetch();
        if ($existingEmail) {
            sendError('Email "' . $existingEmail['email'] . '" is already registered. Please use a different email or login with existing account.', 409);
        }

        // Check if phone number already exists in users table - check all statuses
        $stmt = $db->prepare("SELECT id, phone, business_name FROM users WHERE phone = ? AND user_type IN ('car_hire', 'garage', 'dealer')");
        $stmt->execute([$input['phone']]);
        $existingPhone = $stmt->fetch();
        if ($existingPhone) {
            $businessInfo = $existingPhone['business_name'] ? ' (Business: ' . $existingPhone['business_name'] . ')' : '';
            sendError('Phone number "' . $input['phone'] . '" is already registered' . $businessInfo . '. Please use a different phone number.', 409);
        }

        // Check if business name already exists in business table (including pending_approval)
        $stmt = $db->prepare("SELECT id, business_name FROM car_hire_companies WHERE business_name = ? AND status IN ('active', 'pending_approval') LIMIT 1");
        $stmt->execute([$input['business_name']]);
        $existingBusiness = $stmt->fetch();
        if ($existingBusiness) {
            sendError('A car hire company with the name "' . $input['business_name'] . '" already exists. Please choose a different business name.', 409);
        }

        // Sanitize and validate structured car hire fields before DB writes.
        $allowed_hire_categories = ['standard', 'events', 'vans_trucks', 'all'];
        $hire_category = in_array($input['hire_category'] ?? '', $allowed_hire_categories, true)
            ? $input['hire_category']
            : 'standard';

        $sanitizeMultiSelect = function($values, $allowedValues) {
            $sanitized = [];
            if (is_array($values)) {
                foreach ($values as $value) {
                    $value = trim((string)$value);
                    if ($value !== '' && in_array($value, $allowedValues, true)) {
                        $sanitized[] = $value;
                    }
                }
            }
            return array_values(array_unique($sanitized));
        };

        $allowed_vehicle_types = [
            'Economy', 'Compact', 'Sedan', 'SUV', 'Pickup', 'Luxury',
            'Sports Car', 'Van', 'Truck', 'Minibus', '4WD', 'Executive', 'Limousine'
        ];
        $allowed_car_hire_services = [
            'Self Drive', 'With Driver', 'Airport Pickup', 'Long Distance',
            'Wedding Cars', 'Corporate Rental', 'Van Hire', 'Truck Hire'
        ];
        $allowed_special_services = [
            'VIP Service', 'Tourist Packages', '24/7 Service', 'Chauffeur Service'
        ];
        $allowed_event_types = [
            'Wedding',
            'Corporate Event',
            'Funeral',
            'Birthday Party',
            'Prom Night',
            'Airport VIP Transfer',
            'Graduation',
            'Church Event'
        ];

        $sanitized_vehicle_types = $sanitizeMultiSelect($input['vehicle_types'] ?? [], $allowed_vehicle_types);
        $sanitized_services = $sanitizeMultiSelect($input['services'] ?? [], $allowed_car_hire_services);
        $sanitized_special_services = $sanitizeMultiSelect($input['special_services'] ?? [], $allowed_special_services);
        $sanitized_event_types = ($hire_category === 'events' || $hire_category === 'all')
            ? $sanitizeMultiSelect($input['event_types'] ?? [], $allowed_event_types)
            : [];

        if (($hire_category === 'events' || $hire_category === 'all') && empty($sanitized_event_types)) {
            sendError('Please select at least one event type when Hire Category is Events or All.', 400);
        }

        ensureUserWhatsappPreferenceColumn($db);

        // Start transaction to ensure atomicity and prevent ID skipping
        $db->beginTransaction();
        
        try {
            // Create user account first (simple, like admin)
            $passwordHash = password_hash($input['password'], PASSWORD_DEFAULT);

            $city = null;
            if (!empty($input['location_id'])) {
                $locStmt = $db->prepare("SELECT name FROM locations WHERE id = ?");
                $locStmt->execute([$input['location_id']]);
                $location = $locStmt->fetch(PDO::FETCH_ASSOC);
                $city = $location['name'] ?? null;
            }

            $stmt = $db->prepare("
                INSERT INTO users (username, email, password_hash, full_name, phone, whatsapp, address, city,
                                 user_type, status, business_name, business_registration, national_id, date_of_birth,
                                 created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'car_hire', 'pending', ?, ?, ?, ?, NOW(), NOW())
            ");

            $stmt->execute([
                $input['username'],
                $input['email'],
                $passwordHash,
                $input['owner_name'],
                $input['phone'],
                $input['whatsapp'] ?? null,
                $input['address'],
                $city,
                $input['business_name'],
                $input['business_registration'] ?? null,
                $input['owner_id_number'] ?? null,
                $input['owner_dob'] ?? null
            ]);

            $userId = $db->lastInsertId();

            if (!$userId || $userId <= 0) {
                throw new Exception('Failed to get user ID after insert');
            }
            
            // Verify user was actually created
            $verifyStmt = $db->prepare("SELECT id, username, email FROM users WHERE id = ?");
            $verifyStmt->execute([$userId]);
            $verifiedUser = $verifyStmt->fetch();
            
            if (!$verifiedUser) {
                throw new Exception('User was not found in database after insert');
            }

            if (hasTableColumn($db, 'users', 'whatsapp_notifications')) {
                $optIn = !empty($input['whatsapp_updates_opt_in']) ? 1 : 0;
                $prefStmt = $db->prepare("UPDATE users SET whatsapp_notifications = ? WHERE id = ?");
                $prefStmt->execute([$optIn, $userId]);
            }
            
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw new Exception('User creation failed: ' . $e->getMessage());
        }

        // Create car hire company and link to user
        $stmt = $db->prepare("
            INSERT INTO car_hire_companies (user_id, business_name, owner_name, email, phone, whatsapp, address, location_id,
                                           vehicle_types, services, special_services, hire_category, event_types,
                                           daily_rate_from, weekly_rate_from, monthly_rate_from,
                                           years_established, business_hours, website, facebook_url, instagram_url, twitter_url, linkedin_url,
                                           description, verified, featured, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending_approval', NOW(), NOW())
        ");

        $stmt->execute([
            $userId,
            $input['business_name'],
            $input['owner_name'],
            $input['email'],
            $input['phone'],
            $input['whatsapp'] ?? null,
            $input['address'],
            $input['location_id'],
            !empty($sanitized_vehicle_types) ? json_encode($sanitized_vehicle_types) : null,
            !empty($sanitized_services) ? json_encode($sanitized_services) : null,
            !empty($sanitized_special_services) ? json_encode($sanitized_special_services) : null,
            $hire_category,
            !empty($sanitized_event_types) ? json_encode($sanitized_event_types) : null,
            $input['daily_rate_from'] ?? null,
            $input['weekly_rate_from'] ?? null,
            $input['monthly_rate_from'] ?? null,
            $input['years_established'] ?? null,
            $input['business_hours'] ?? null,
            $input['website'] ?? null,
            $input['facebook_url'] ?? null,
            $input['instagram_url'] ?? null,
            $input['twitter_url'] ?? null,
            $input['linkedin_url'] ?? null,
            $input['description'] ?? null,
            $input['verified'] ?? 0,
            $input['featured'] ?? 0
        ]);

        $companyId = $db->lastInsertId();
        
        if (!$companyId || $companyId <= 0) {
            throw new Exception('Failed to get company ID after insert');
        }

        // Link user to business
        $stmt = $db->prepare("UPDATE users SET business_id = ? WHERE id = ?");
        $stmt->execute([$companyId, $userId]);
        
        // Commit transaction - both user and business are now in database
        $db->commit();
        
        // Log successful creation
        logActivity("Car hire company created successfully: Company ID=$companyId, User ID=$userId, Business={$input['business_name']}");
        logAdminActivityLog($db, 'onboarding_car_hire',
            "Onboarded car hire company: {$input['business_name']}",
            "Company ID: $companyId | User ID: $userId | Ref: CH" . str_pad($companyId, 5, '0', STR_PAD_LEFT)
        );

        $notifications = sendOnboardingWelcomeNotifications($db, [
            'email' => $input['email'],
            'owner_name' => $input['owner_name'],
            'business_name' => $input['business_name'],
            'business_type' => 'Car Hire Company',
            'business_type_key' => 'car_hire',
            'username' => $input['username'],
            'password' => $input['password'],
            'phone' => $input['phone'],
            'whatsapp' => $input['whatsapp'] ?? null,
            'whatsapp_updates_opt_in' => !empty($input['whatsapp_updates_opt_in']) ? 1 : 0,
            'reference' => 'CH' . str_pad($companyId, 5, '0', STR_PAD_LEFT)
        ]);

        sendSuccess([
            'api_version' => 'v2_with_user_creation',
            'message' => 'Car hire company successfully onboarded! Status: Pending Approval. Login account created.',
            'company_id' => $companyId,
            'user_id' => $userId,
            'username' => $input['username'],
            'business_name' => $input['business_name'],
            'owner_name' => $input['owner_name'],
            'email' => $input['email'],
            'phone' => $input['phone'],
            'business_status' => 'pending_approval',
            'user_status' => 'pending',
            'status' => 'pending_approval',
            'reference' => 'CH' . str_pad($companyId, 5, '0', STR_PAD_LEFT),
            'notifications' => $notifications
        ]);

    } catch (Exception $e) {
        // Rollback transaction if still active
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("addCarHireCompany error: " . $e->getMessage());
        logActivity("ERROR adding car hire company: " . $e->getMessage());
        sendError('Failed to add car hire company: ' . $e->getMessage(), 500);
    }
}

/**
 * Add a new garage
 */
function addGarage($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('POST method required', 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        $input = $_POST;
    }

    // Debug: Log received data
    logActivity("=== ADD GARAGE DEBUG ===");
    logActivity("Received fields: " . implode(', ', array_keys($input)));
    logActivity("Username received: " . ($input['username'] ?? 'NOT SET'));
    logActivity("Password received: " . (isset($input['password']) ? 'YES (length: ' . strlen($input['password']) . ')' : 'NOT SET'));
    logActivity("Email received: " . ($input['email'] ?? 'NOT SET'));
    logActivity("Business name received: " . ($input['name'] ?? 'NOT SET'));

    // Validate required fields (including login credentials)
    $required = ['name', 'owner_name', 'email', 'phone', 'address', 'location_id', 'username', 'password'];
    $missing = [];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            $missing[] = $field;
        }
    }

    if (!empty($missing)) {
        logActivity("Missing required fields: " . implode(', ', $missing));
        sendError("Missing required fields: " . implode(', ', $missing), 400);
    }

    // Comprehensive validation
    $validation = validateBusinessName($input['name'], 'Garage name');
    if (!$validation['valid']) {
        sendError($validation['message'], 400);
    }

    $validation = validateOwnerName($input['owner_name']);
    if (!$validation['valid']) {
        sendError($validation['message'], 400);
    }

    $validation = validateEmail($input['email']);
    if (!$validation['valid']) {
        sendError($validation['message'], 400);
    }

    $validation = validatePhone($input['phone']);
    if (!$validation['valid']) {
        sendError($validation['message'], 400);
    }

    $validation = validateUsername($input['username']);
    if (!$validation['valid']) {
        sendError($validation['message'], 400);
    }

    $validation = validatePassword($input['password']);
    if (!$validation['valid']) {
        sendError($validation['message'], 400);
    }

    // Validate optional URLs
    if (!empty($input['website'])) {
        $validation = validateURL($input['website'], 'Website URL');
        if (!$validation['valid']) {
            sendError($validation['message'], 400);
        }
    }

    if (!empty($input['facebook_url'])) {
        $validation = validateURL($input['facebook_url'], 'Facebook URL');
        if (!$validation['valid']) {
            sendError($validation['message'], 400);
        }
    }

    if (!empty($input['instagram_url'])) {
        $validation = validateURL($input['instagram_url'], 'Instagram URL');
        if (!$validation['valid']) {
            sendError($validation['message'], 400);
        }
    }

    if (!empty($input['twitter_url'])) {
        $validation = validateURL($input['twitter_url'], 'Twitter URL');
        if (!$validation['valid']) {
            sendError($validation['message'], 400);
        }
    }

    if (!empty($input['linkedin_url'])) {
        $validation = validateURL($input['linkedin_url'], 'LinkedIn URL');
        if (!$validation['valid']) {
            sendError($validation['message'], 400);
        }
    }

    // Validate WhatsApp if provided
    if (!empty($input['whatsapp'])) {
        $validation = validatePhone($input['whatsapp']);
        if (!$validation['valid']) {
            sendError('Invalid WhatsApp number format. ' . $validation['message'], 400);
        }
    }

    // Validate recovery number if provided
    if (!empty($input['recovery_number'])) {
        $validation = validatePhone($input['recovery_number']);
        if (!$validation['valid']) {
            sendError('Invalid recovery number format. ' . $validation['message'], 400);
        }
    }

    try {
        // Check if business already exists (including pending_approval)
        $existing = businessExists($db, 'garage', $input['name'], $input['email'], $input['phone']);
        if ($existing) {
            $existingName = $existing['name'] ?? $existing['business_name'] ?? 'Unknown';
            sendError('A garage with similar details already exists. Business: ' . $existingName, 409);
        }

        // Check if username already exists (case-insensitive) - check all statuses
        $stmt = $db->prepare("SELECT id, username FROM users WHERE LOWER(username) = LOWER(?)");
        $stmt->execute([$input['username']]);
        $existingUser = $stmt->fetch();
        if ($existingUser) {
            sendError('Username "' . $existingUser['username'] . '" already exists. Please choose a different username.', 409);
        }

        // Check if email already exists in users table (case-insensitive) - check all statuses
        $stmt = $db->prepare("SELECT id, email FROM users WHERE LOWER(email) = LOWER(?)");
        $stmt->execute([$input['email']]);
        $existingEmail = $stmt->fetch();
        if ($existingEmail) {
            sendError('Email "' . $existingEmail['email'] . '" is already registered. Please use a different email or login with existing account.', 409);
        }

        // Check if phone number already exists in users table - check all statuses
        $stmt = $db->prepare("SELECT id, phone, business_name FROM users WHERE phone = ? AND user_type IN ('car_hire', 'garage', 'dealer')");
        $stmt->execute([$input['phone']]);
        $existingPhone = $stmt->fetch();
        if ($existingPhone) {
            $businessInfo = $existingPhone['business_name'] ? ' (Business: ' . $existingPhone['business_name'] . ')' : '';
            sendError('Phone number "' . $input['phone'] . '" is already registered' . $businessInfo . '. Please use a different phone number.', 409);
        }

        // Check if garage name already exists in business table (including pending_approval)
        $stmt = $db->prepare("SELECT id, name FROM garages WHERE name = ? AND status IN ('active', 'pending_approval') LIMIT 1");
        $stmt->execute([$input['name']]);
        $existingBusiness = $stmt->fetch();
        if ($existingBusiness) {
            sendError('A garage with the name "' . $input['name'] . '" already exists. Please choose a different garage name.', 409);
        }

        ensureUserWhatsappPreferenceColumn($db);

        // Start transaction to ensure atomicity and prevent ID skipping
        $db->beginTransaction();
        
        try {
            // Create user account first
            $passwordHash = password_hash($input['password'], PASSWORD_DEFAULT);

            $city = null;
            if (!empty($input['location_id'])) {
                $locStmt = $db->prepare("SELECT name FROM locations WHERE id = ?");
                $locStmt->execute([$input['location_id']]);
                $location = $locStmt->fetch(PDO::FETCH_ASSOC);
                $city = $location['name'] ?? null;
            }

            $stmt = $db->prepare("
                INSERT INTO users (username, email, password_hash, full_name, phone, whatsapp, address, city,
                                 user_type, status, business_name, business_registration, national_id, date_of_birth,
                                 created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'garage', 'pending', ?, ?, ?, ?, NOW(), NOW())
            ");

            $stmt->execute([
                $input['username'],
                $input['email'],
                $passwordHash,
                $input['owner_name'],
                $input['phone'],
                $input['whatsapp'] ?? null,
                $input['address'],
                $city,
                $input['name'],  // business_name for garages
                $input['business_registration'] ?? null,
                $input['owner_id_number'] ?? null,
                $input['owner_dob'] ?? null
            ]);

            $userId = $db->lastInsertId();

            if (!$userId || $userId <= 0) {
                throw new Exception('Failed to get user ID after insert');
            }
            
            // Verify user was actually created
            $verifyStmt = $db->prepare("SELECT id, username, email FROM users WHERE id = ?");
            $verifyStmt->execute([$userId]);
            $verifiedUser = $verifyStmt->fetch();
            
            if (!$verifiedUser) {
                throw new Exception('User was not found in database after insert');
            }

            if (hasTableColumn($db, 'users', 'whatsapp_notifications')) {
                $optIn = !empty($input['whatsapp_updates_opt_in']) ? 1 : 0;
                $prefStmt = $db->prepare("UPDATE users SET whatsapp_notifications = ? WHERE id = ?");
                $prefStmt->execute([$optIn, $userId]);
            }
            
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw new Exception('User creation failed: ' . $e->getMessage());
        }

        // Create garage and link to user
        $stmt = $db->prepare("
            INSERT INTO garages (user_id, name, owner_name, email, phone, whatsapp, recovery_number, address, location_id,
                               services, emergency_services, specialization, specializes_in_cars, years_experience,
                               operating_hours, website, facebook_url, instagram_url, twitter_url, linkedin_url,
                               description, verified, certified, featured, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending_approval', NOW(), NOW())
        ");

        $stmt->execute([
            $userId,
            $input['name'],
            $input['owner_name'],
            $input['email'],
            $input['phone'],
            $input['whatsapp'] ?? null,
            $input['recovery_number'] ?? null,
            $input['address'],
            $input['location_id'],
            !empty($input['services']) ? json_encode($input['services']) : null,
            !empty($input['emergency_services']) ? json_encode($input['emergency_services']) : null,
            !empty($input['specialization']) ? json_encode($input['specialization']) : null,
            !empty($input['specializes_in_cars']) ? json_encode($input['specializes_in_cars']) : null,
            $input['years_established'] ?? $input['years_experience'] ?? null,
            $input['operating_hours'] ?? null,
            $input['website'] ?? null,
            $input['facebook_url'] ?? null,
            $input['instagram_url'] ?? null,
            $input['twitter_url'] ?? null,
            $input['linkedin_url'] ?? null,
            $input['description'] ?? null,
            $input['verified'] ?? 0,
            $input['certified'] ?? 0,
            $input['featured'] ?? 0
        ]);

        $garageId = $db->lastInsertId();

        // Link user to business
        $stmt = $db->prepare("UPDATE users SET business_id = ? WHERE id = ?");
        $stmt->execute([$garageId, $userId]);

        // Commit transaction so user and garage records are persisted atomically
        $db->commit();

        // Log successful creation
        logActivity("Garage created successfully: Garage ID=$garageId, User ID=$userId, Business={$input['name']}");
        logAdminActivityLog($db, 'onboarding_garage',
            "Onboarded garage: {$input['name']}",
            "Garage ID: $garageId | User ID: $userId | Ref: GR" . str_pad($garageId, 5, '0', STR_PAD_LEFT)
        );

        $notifications = sendOnboardingWelcomeNotifications($db, [
            'email' => $input['email'],
            'owner_name' => $input['owner_name'],
            'business_name' => $input['name'],
            'business_type' => 'Garage',
            'business_type_key' => 'garage',
            'username' => $input['username'],
            'password' => $input['password'],
            'phone' => $input['phone'],
            'whatsapp' => $input['whatsapp'] ?? null,
            'whatsapp_updates_opt_in' => !empty($input['whatsapp_updates_opt_in']) ? 1 : 0,
            'reference' => 'GR' . str_pad($garageId, 5, '0', STR_PAD_LEFT)
        ]);

        sendSuccess([
            'api_version' => 'v2_with_user_creation',
            'message' => 'Garage successfully onboarded! Status: Pending Approval. Login account created.',
            'garage_id' => $garageId,
            'user_id' => $userId,
            'username' => $input['username'],
            'business_name' => $input['name'],
            'owner_name' => $input['owner_name'],
            'email' => $input['email'],
            'phone' => $input['phone'],
            'business_status' => 'pending_approval',
            'user_status' => 'pending',
            'status' => 'pending_approval',
            'reference' => 'GR' . str_pad($garageId, 5, '0', STR_PAD_LEFT),
            'notifications' => $notifications
        ]);

    } catch (Exception $e) {
        // Rollback transaction if still active
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("addGarage error: " . $e->getMessage());
        logActivity("ERROR adding garage: " . $e->getMessage());
        sendError('Failed to add garage: ' . $e->getMessage(), 500);
    }
}

/**
 * Add a new car dealer
 */
function addCarDealer($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('POST method required', 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        $input = $_POST;
    }

    // Debug: Log received data
    logActivity("=== ADD DEALER DEBUG ===");
    logActivity("Received fields: " . implode(', ', array_keys($input)));
    logActivity("Username received: " . ($input['username'] ?? 'NOT SET'));
    logActivity("Password received: " . (isset($input['password']) ? 'YES (length: ' . strlen($input['password']) . ')' : 'NOT SET'));
    logActivity("Email received: " . ($input['email'] ?? 'NOT SET'));
    logActivity("Business name received: " . ($input['business_name'] ?? 'NOT SET'));

    // Validate required fields (including login credentials)
    $required = ['business_name', 'owner_name', 'email', 'phone', 'address', 'location_id', 'username', 'password'];
    $missing = [];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            $missing[] = $field;
        }
    }

    if (!empty($missing)) {
        logActivity("Missing required fields: " . implode(', ', $missing));
        sendError("Missing required fields: " . implode(', ', $missing), 400);
    }

    // Comprehensive validation
    $validation = validateBusinessName($input['business_name'], 'Business name');
    if (!$validation['valid']) {
        sendError($validation['message'], 400);
    }

    $validation = validateOwnerName($input['owner_name']);
    if (!$validation['valid']) {
        sendError($validation['message'], 400);
    }

    $validation = validateEmail($input['email']);
    if (!$validation['valid']) {
        sendError($validation['message'], 400);
    }

    $validation = validatePhone($input['phone']);
    if (!$validation['valid']) {
        sendError($validation['message'], 400);
    }

    $validation = validateUsername($input['username']);
    if (!$validation['valid']) {
        sendError($validation['message'], 400);
    }

    $validation = validatePassword($input['password']);
    if (!$validation['valid']) {
        sendError($validation['message'], 400);
    }

    // Validate optional URLs
    if (!empty($input['website'])) {
        $validation = validateURL($input['website'], 'Website URL');
        if (!$validation['valid']) {
            sendError($validation['message'], 400);
        }
    }

    if (!empty($input['facebook_url'])) {
        $validation = validateURL($input['facebook_url'], 'Facebook URL');
        if (!$validation['valid']) {
            sendError($validation['message'], 400);
        }
    }

    if (!empty($input['instagram_url'])) {
        $validation = validateURL($input['instagram_url'], 'Instagram URL');
        if (!$validation['valid']) {
            sendError($validation['message'], 400);
        }
    }

    if (!empty($input['twitter_url'])) {
        $validation = validateURL($input['twitter_url'], 'Twitter URL');
        if (!$validation['valid']) {
            sendError($validation['message'], 400);
        }
    }

    if (!empty($input['linkedin_url'])) {
        $validation = validateURL($input['linkedin_url'], 'LinkedIn URL');
        if (!$validation['valid']) {
            sendError($validation['message'], 400);
        }
    }

    // Validate WhatsApp if provided
    if (!empty($input['whatsapp'])) {
        $validation = validatePhone($input['whatsapp']);
        if (!$validation['valid']) {
            sendError('Invalid WhatsApp number format. ' . $validation['message'], 400);
        }
    }

    try {
        // Check if business already exists (including pending_approval)
        $existing = businessExists($db, 'dealer', $input['business_name'], $input['email'], $input['phone']);
        if ($existing) {
            $existingName = $existing['business_name'] ?? $existing['name'] ?? 'Unknown';
            sendError('A car dealer with similar details already exists. Business: ' . $existingName, 409);
        }

        // Check if username already exists (case-insensitive) - check all statuses
        $stmt = $db->prepare("SELECT id, username FROM users WHERE LOWER(username) = LOWER(?)");
        $stmt->execute([$input['username']]);
        $existingUser = $stmt->fetch();
        if ($existingUser) {
            sendError('Username "' . $existingUser['username'] . '" already exists. Please choose a different username.', 409);
        }

        // Check if email already exists in users table (case-insensitive) - check all statuses
        $stmt = $db->prepare("SELECT id, email FROM users WHERE LOWER(email) = LOWER(?)");
        $stmt->execute([$input['email']]);
        $existingEmail = $stmt->fetch();
        if ($existingEmail) {
            sendError('Email "' . $existingEmail['email'] . '" is already registered. Please use a different email or login with existing account.', 409);
        }

        // Check if phone number already exists in users table - check all statuses
        $stmt = $db->prepare("SELECT id, phone, business_name FROM users WHERE phone = ? AND user_type IN ('car_hire', 'garage', 'dealer')");
        $stmt->execute([$input['phone']]);
        $existingPhone = $stmt->fetch();
        if ($existingPhone) {
            $businessInfo = $existingPhone['business_name'] ? ' (Business: ' . $existingPhone['business_name'] . ')' : '';
            sendError('Phone number "' . $input['phone'] . '" is already registered' . $businessInfo . '. Please use a different phone number.', 409);
        }

        // Check if business name already exists in business table (including pending_approval)
        $stmt = $db->prepare("SELECT id, business_name FROM car_dealers WHERE business_name = ? AND status IN ('active', 'pending_approval') LIMIT 1");
        $stmt->execute([$input['business_name']]);
        $existingBusiness = $stmt->fetch();
        if ($existingBusiness) {
            sendError('A car dealer with the name "' . $input['business_name'] . '" already exists. Please choose a different business name.', 409);
        }

        ensureUserWhatsappPreferenceColumn($db);

        // Start transaction to ensure atomicity and prevent partial writes
        $db->beginTransaction();

        // Create user account first
        $passwordHash = password_hash($input['password'], PASSWORD_DEFAULT);

        $city = null;
        if (!empty($input['location_id'])) {
            $locStmt = $db->prepare("SELECT name FROM locations WHERE id = ?");
            $locStmt->execute([$input['location_id']]);
            $location = $locStmt->fetch(PDO::FETCH_ASSOC);
            $city = $location['name'] ?? null;
        }

        try {
            $stmt = $db->prepare("
                INSERT INTO users (username, email, password_hash, full_name, phone, whatsapp, address, city,
                                 user_type, status, business_name, business_registration, national_id, date_of_birth,
                                 created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'dealer', 'pending', ?, ?, ?, ?, NOW(), NOW())
            ");

            $stmt->execute([
                $input['username'],
                $input['email'],
                $passwordHash,
                $input['owner_name'],
                $input['phone'],
                $input['whatsapp'] ?? null,
                $input['address'],
                $city,
                $input['business_name'],
                $input['business_registration'] ?? null,
                $input['owner_id_number'] ?? null,
                $input['owner_dob'] ?? null
            ]);

            $userId = $db->lastInsertId();

            if (!$userId) {
                throw new Exception('Failed to get user ID after insert');
            }

            if (hasTableColumn($db, 'users', 'whatsapp_notifications')) {
                $optIn = !empty($input['whatsapp_updates_opt_in']) ? 1 : 0;
                $prefStmt = $db->prepare("UPDATE users SET whatsapp_notifications = ? WHERE id = ?");
                $prefStmt->execute([$optIn, $userId]);
            }
        } catch (Exception $e) {
            throw new Exception('User creation failed: ' . $e->getMessage());
        }

        // Create dealer and link to user
        $stmt = $db->prepare("
            INSERT INTO car_dealers (user_id, business_name, owner_name, email, phone, whatsapp, address, location_id,
                                   specialization, years_established, business_hours, website, facebook_url, instagram_url, twitter_url, linkedin_url,
                                   description, verified, featured, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending_approval', NOW(), NOW())
        ");

        $stmt->execute([
            $userId,
            $input['business_name'],
            $input['owner_name'],
            $input['email'],
            $input['phone'],
            $input['whatsapp'] ?? null,
            $input['address'],
            $input['location_id'],
            !empty($input['specialization']) ? json_encode($input['specialization']) : null,
            $input['years_established'] ?? null,
            $input['business_hours'] ?? null,
            $input['website'] ?? null,
            $input['facebook_url'] ?? null,
            $input['instagram_url'] ?? null,
            $input['twitter_url'] ?? null,
            $input['linkedin_url'] ?? null,
            $input['description'] ?? null,
            $input['verified'] ?? 0,
            $input['featured'] ?? 0
        ]);

        $dealerId = $db->lastInsertId();
        
        if (!$dealerId || $dealerId <= 0) {
            throw new Exception('Failed to get dealer ID after insert');
        }

        // Link user to business
        $stmt = $db->prepare("UPDATE users SET business_id = ? WHERE id = ?");
        $stmt->execute([$dealerId, $userId]);
        
        // Commit transaction - both user and business are now in database
        $db->commit();
        
        // Log successful creation
        logActivity("Car dealer created successfully: Dealer ID=$dealerId, User ID=$userId, Business={$input['business_name']}");
        logAdminActivityLog($db, 'onboarding_dealer',
            "Onboarded car dealer: {$input['business_name']}",
            "Dealer ID: $dealerId | User ID: $userId | Ref: DL" . str_pad($dealerId, 5, '0', STR_PAD_LEFT)
        );

        $notifications = sendOnboardingWelcomeNotifications($db, [
            'email' => $input['email'],
            'owner_name' => $input['owner_name'],
            'business_name' => $input['business_name'],
            'business_type' => 'Car Dealer',
            'business_type_key' => 'dealer',
            'username' => $input['username'],
            'password' => $input['password'],
            'phone' => $input['phone'],
            'whatsapp' => $input['whatsapp'] ?? null,
            'whatsapp_updates_opt_in' => !empty($input['whatsapp_updates_opt_in']) ? 1 : 0,
            'reference' => 'DL' . str_pad($dealerId, 5, '0', STR_PAD_LEFT)
        ]);

        sendSuccess([
            'api_version' => 'v2_with_user_creation',
            'message' => 'Car dealer successfully onboarded! Status: Pending Approval. Login account created.',
            'dealer_id' => $dealerId,
            'user_id' => $userId,
            'username' => $input['username'],
            'business_name' => $input['business_name'],
            'owner_name' => $input['owner_name'],
            'email' => $input['email'],
            'phone' => $input['phone'],
            'business_status' => 'pending_approval',
            'user_status' => 'pending',
            'status' => 'pending_approval',
            'reference' => 'DL' . str_pad($dealerId, 5, '0', STR_PAD_LEFT),
            'notifications' => $notifications
        ]);

    } catch (Exception $e) {
        // Rollback transaction if still active
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("addCarDealer error: " . $e->getMessage());
        logActivity("ERROR adding car dealer: " . $e->getMessage());
        sendError('Failed to add car dealer: ' . $e->getMessage(), 500);
    }
}

// ============================================================================
// CLAIM EXISTING (SCRAPED) BUSINESS — assign an unclaimed listing to its real owner
// ============================================================================

/**
 * Maps a business type key to its DB table + display fields.
 */
function claimGetTypeMap($type) {
    $map = [
        'car_hire' => [
            'table' => 'car_hire_companies',
            'name_field' => 'business_name',
            'ref_prefix' => 'CH',
            'business_type_label' => 'Car Hire Company'
        ],
        'garage' => [
            'table' => 'garages',
            'name_field' => 'name',
            'ref_prefix' => 'GR',
            'business_type_label' => 'Garage'
        ],
        'dealer' => [
            'table' => 'car_dealers',
            'name_field' => 'business_name',
            'ref_prefix' => 'DL',
            'business_type_label' => 'Car Dealer'
        ]
    ];
    return $map[$type] ?? null;
}

/**
 * Search for unclaimed/scraped businesses an admin can assign to a real owner.
 * Filters: type (required), q (name contains), location_id, phone, email
 * Unclaimed = linked user has placeholder email '@motorlink.test' OR business has user_id NULL.
 */
function searchExistingBusinesses($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = array_merge($_GET, $_POST);
    }

    $type = strtolower(trim((string)($input['type'] ?? '')));
    $info = claimGetTypeMap($type);
    if (!$info) {
        sendError('Invalid or missing business type. Use car_hire, garage or dealer.', 400);
    }

    $q = trim((string)($input['q'] ?? ''));
    $locationId = isset($input['location_id']) && $input['location_id'] !== '' ? (int)$input['location_id'] : null;
    $phone = trim((string)($input['phone'] ?? ''));
    $email = trim((string)($input['email'] ?? ''));

    $table = $info['table'];
    $nameField = $info['name_field'];

    try {
        $where = ["b.status IN ('active','pending_approval','pending')"];
        $params = [];

        // Restrict to "unclaimed" rows only.
        $where[] = "(b.user_id IS NULL OR EXISTS (SELECT 1 FROM users u2 WHERE u2.id = b.user_id AND (u2.email LIKE '%@motorlink.test' OR u2.email IS NULL OR u2.email = '')))";

        if ($q !== '') {
            $where[] = "(b.$nameField LIKE :q OR b.address LIKE :q OR b.phone LIKE :q OR b.email LIKE :q)";
            $params[':q'] = '%' . $q . '%';
        }
        if ($locationId !== null) {
            $where[] = "b.location_id = :location_id";
            $params[':location_id'] = $locationId;
        }
        if ($phone !== '') {
            $where[] = "b.phone LIKE :phone";
            $params[':phone'] = '%' . preg_replace('/\D+/', '', $phone) . '%';
        }
        if ($email !== '') {
            $where[] = "LOWER(b.email) LIKE LOWER(:email)";
            $params[':email'] = '%' . $email . '%';
        }

        $sql = "
            SELECT b.id, b.$nameField AS business_name, b.owner_name, b.email, b.phone, b.address, b.location_id,
                   b.status, b.user_id, b.logo_url, b.website,
                   l.name AS location_name, l.region AS location_region,
                   u.email AS user_email, u.username AS user_username
            FROM {$table} b
            LEFT JOIN locations l ON l.id = b.location_id
            LEFT JOIN users u ON u.id = b.user_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY b.$nameField ASC
            LIMIT 50
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($results as &$row) {
            $row['is_placeholder_user'] = !empty($row['user_email']) && stripos($row['user_email'], '@motorlink.test') !== false;
            $row['type'] = $type;
            $row['reference'] = $info['ref_prefix'] . str_pad((string)$row['id'], 5, '0', STR_PAD_LEFT);
        }
        unset($row);

        sendSuccess([
            'type' => $type,
            'count' => count($results),
            'results' => $results
        ]);
    } catch (Exception $e) {
        error_log('searchExistingBusinesses error: ' . $e->getMessage());
        sendError('Failed to search existing businesses: ' . $e->getMessage(), 500);
    }
}

/**
 * Claim an existing (scraped) business: replace placeholder user with real owner credentials,
 * update business contact details, send onboarding email + optional WhatsApp.
 *
 * Required input: type, business_id, owner_name, email, phone, username, password
 * Optional: whatsapp, whatsapp_updates_opt_in, address, location_id, website,
 *           facebook_url, instagram_url, twitter_url, linkedin_url, business_registration,
 *           owner_id_number, owner_dob
 */
function claimExistingBusiness($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('POST method required', 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    $type = strtolower(trim((string)($input['type'] ?? '')));
    $info = claimGetTypeMap($type);
    if (!$info) {
        sendError('Invalid business type. Use car_hire, garage or dealer.', 400);
    }

    $businessId = (int)($input['business_id'] ?? 0);
    if ($businessId <= 0) {
        sendError('A valid business_id is required.', 400);
    }

    $required = ['owner_name', 'email', 'phone', 'username', 'password'];
    $missing = [];
    foreach ($required as $f) {
        if (trim((string)($input[$f] ?? '')) === '') $missing[] = $f;
    }
    if ($missing) {
        sendError('Missing required fields: ' . implode(', ', $missing), 400);
    }

    $ownerValid = validateOwnerName($input['owner_name']);
    if (!$ownerValid['valid']) sendError($ownerValid['message'], 400);

    $emailValid = validateEmail($input['email']);
    if (!$emailValid['valid']) sendError($emailValid['message'], 400);

    $phoneValid = validatePhone($input['phone']);
    if (!$phoneValid['valid']) sendError($phoneValid['message'], 400);

    $userValid = validateUsername($input['username']);
    if (!$userValid['valid']) sendError($userValid['message'], 400);

    $passValid = validatePassword($input['password']);
    if (!$passValid['valid']) sendError($passValid['message'], 400);

    if (!empty($input['whatsapp'])) {
        $waValid = validatePhone($input['whatsapp']);
        if (!$waValid['valid']) sendError('Invalid WhatsApp number. ' . $waValid['message'], 400);
    }

    // Validate optional URL fields if provided
    foreach (['website', 'facebook_url', 'instagram_url', 'twitter_url', 'linkedin_url'] as $_urlField) {
        if (!empty($input[$_urlField])) {
            $urlLabel = ucwords(str_replace('_', ' ', $_urlField));
            $urlCheck = validateURL($input[$_urlField], $urlLabel);
            if (!$urlCheck['valid']) sendError($urlCheck['message'], 400);
        }
    }

    $table = $info['table'];
    $nameField = $info['name_field'];

    try {
        // Load the target business row
        $stmt = $db->prepare("SELECT * FROM {$table} WHERE id = ? LIMIT 1");
        $stmt->execute([$businessId]);
        $business = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$business) {
            sendError('Business not found.', 404);
        }

        // Verify business is unclaimed (no real user attached)
        $existingUserId = (int)($business['user_id'] ?? 0);
        $placeholderUserId = null;
        if ($existingUserId > 0) {
            $uStmt = $db->prepare("SELECT id, email FROM users WHERE id = ? LIMIT 1");
            $uStmt->execute([$existingUserId]);
            $existingUser = $uStmt->fetch(PDO::FETCH_ASSOC);
            if ($existingUser) {
                $isPlaceholder = stripos((string)$existingUser['email'], '@motorlink.test') !== false
                    || empty($existingUser['email']);
                if (!$isPlaceholder) {
                    sendError('This business is already linked to an active owner. Use the admin tools to transfer ownership instead.', 409);
                }
                $placeholderUserId = (int)$existingUser['id'];
            }
        }

        // Username uniqueness against non-placeholder users
        $stmt = $db->prepare("SELECT id, username, email FROM users WHERE LOWER(username) = LOWER(?) AND id != ? LIMIT 1");
        $stmt->execute([$input['username'], $placeholderUserId ?? 0]);
        if ($stmt->fetch()) {
            sendError('Username "' . $input['username'] . '" is already taken.', 409);
        }

        // Email uniqueness against non-placeholder users
        $stmt = $db->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?) AND id != ? AND email NOT LIKE '%@motorlink.test' LIMIT 1");
        $stmt->execute([$input['email'], $placeholderUserId ?? 0]);
        if ($stmt->fetch()) {
            sendError('Email "' . $input['email'] . '" is already registered to another account.', 409);
        }

        ensureUserWhatsappPreferenceColumn($db);

        $passwordHash = password_hash($input['password'], PASSWORD_DEFAULT);

        // Resolve city from location if provided
        $city = null;
        $locationId = isset($input['location_id']) && $input['location_id'] !== '' ? (int)$input['location_id'] : (int)($business['location_id'] ?? 0);
        if ($locationId > 0) {
            $locStmt = $db->prepare("SELECT name FROM locations WHERE id = ?");
            $locStmt->execute([$locationId]);
            $row = $locStmt->fetch(PDO::FETCH_ASSOC);
            $city = $row['name'] ?? null;
        }

        $businessName = (string)($business[$nameField] ?? 'Your Business');
        $address = trim((string)($input['address'] ?? $business['address'] ?? ''));
        $whatsappPhone = $input['whatsapp'] ?? ($business['whatsapp'] ?? null);
        $optIn = !empty($input['whatsapp_updates_opt_in']) ? 1 : 0;

        $db->beginTransaction();
        try {
            if ($placeholderUserId) {
                // Reuse placeholder user row in-place
                $stmt = $db->prepare("
                    UPDATE users
                    SET username = ?, email = ?, password_hash = ?, full_name = ?, phone = ?, whatsapp = ?,
                        address = ?, city = ?, user_type = ?, status = 'pending', business_name = ?,
                        business_registration = COALESCE(?, business_registration),
                        national_id = COALESCE(?, national_id),
                        date_of_birth = COALESCE(?, date_of_birth),
                        email_verified = 0, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $input['username'],
                    $input['email'],
                    $passwordHash,
                    $input['owner_name'],
                    $input['phone'],
                    $whatsappPhone,
                    $address,
                    $city,
                    $type,
                    $businessName,
                    $input['business_registration'] ?? null,
                    $input['owner_id_number'] ?? null,
                    $input['owner_dob'] ?? null,
                    $placeholderUserId
                ]);
                $userId = $placeholderUserId;
            } else {
                // Business had no user; create a fresh one
                $stmt = $db->prepare("
                    INSERT INTO users (username, email, password_hash, full_name, phone, whatsapp, address, city,
                                     user_type, status, business_name, business_registration, national_id, date_of_birth,
                                     created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt->execute([
                    $input['username'],
                    $input['email'],
                    $passwordHash,
                    $input['owner_name'],
                    $input['phone'],
                    $whatsappPhone,
                    $address,
                    $city,
                    $type,
                    $businessName,
                    $input['business_registration'] ?? null,
                    $input['owner_id_number'] ?? null,
                    $input['owner_dob'] ?? null
                ]);
                $userId = (int)$db->lastInsertId();
            }

            if (!$userId) {
                throw new Exception('Failed to resolve user ID for claim.');
            }

            if (hasTableColumn($db, 'users', 'whatsapp_notifications')) {
                $prefStmt = $db->prepare("UPDATE users SET whatsapp_notifications = ? WHERE id = ?");
                $prefStmt->execute([$optIn, $userId]);
            }
            $prefStmt = $db->prepare("UPDATE users SET business_id = ? WHERE id = ?");
            $prefStmt->execute([$businessId, $userId]);

            // Update business contact + ownership info
            $bizSet = "user_id = ?, owner_name = ?, email = ?, phone = ?, whatsapp = ?, address = ?, status = 'pending_approval', updated_at = NOW()";
            $bizParams = [$userId, $input['owner_name'], $input['email'], $input['phone'], $whatsappPhone, $address];

            if ($locationId > 0) {
                $bizSet .= ", location_id = ?";
                $bizParams[] = $locationId;
            }

            // Optional URL updates
            foreach (['website', 'facebook_url', 'instagram_url', 'twitter_url', 'linkedin_url'] as $col) {
                if (array_key_exists($col, $input) && trim((string)$input[$col]) !== '') {
                    $val = trim((string)$input[$col]);
                    $val = $col === 'website'
                        ? (preg_match('#^https?://#i', $val) ? $val : 'https://' . $val)
                        : $val;
                    if (hasTableColumn($db, $table, $col)) {
                        $bizSet .= ", $col = ?";
                        $bizParams[] = $val;
                    }
                }
            }

            $bizParams[] = $businessId;
            $stmt = $db->prepare("UPDATE {$table} SET $bizSet WHERE id = ?");
            $stmt->execute($bizParams);

            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        $reference = $info['ref_prefix'] . str_pad((string)$businessId, 5, '0', STR_PAD_LEFT);

        logActivity("Claim successful: type=$type business_id=$businessId user_id=$userId business={$businessName}");
        logAdminActivityLog($db, 'onboarding_claim',
            "Claimed existing {$info['business_type_label']}: {$businessName}",
            "Business ID: $businessId | User ID: $userId | Ref: $reference"
        );

        $notifications = sendOnboardingWelcomeNotifications($db, [
            'email' => $input['email'],
            'owner_name' => $input['owner_name'],
            'business_name' => $businessName,
            'business_type' => $info['business_type_label'],
            'business_type_key' => $type,
            'username' => $input['username'],
            'password' => $input['password'],
            'phone' => $input['phone'],
            'whatsapp' => $whatsappPhone,
            'whatsapp_updates_opt_in' => $optIn,
            'reference' => $reference
        ]);

        sendSuccess([
            'api_version' => 'v2_claim_existing',
            'message' => 'Business successfully claimed and assigned to its owner. Status: Pending Approval. Login credentials emailed.',
            'mode' => 'claim',
            'business_id' => $businessId,
            'user_id' => $userId,
            'username' => $input['username'],
            'business_name' => $businessName,
            'owner_name' => $input['owner_name'],
            'email' => $input['email'],
            'phone' => $input['phone'],
            'business_status' => 'pending_approval',
            'user_status' => 'pending',
            'status' => 'pending_approval',
            'reference' => $reference,
            'notifications' => $notifications
        ]);
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('claimExistingBusiness error: ' . $e->getMessage());
        logActivity('ERROR claiming business: ' . $e->getMessage());
        sendError('Failed to claim existing business: ' . $e->getMessage(), 500);
    }
}

?>