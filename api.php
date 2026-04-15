<?php
/**
 * MotorLink Malawi - Complete API v4.0
 * Combined and optimized API file with all endpoints
 * Features: Car Marketplace, Car Hire, Garages, Dealers, Authentication, Admin
 */

// ============================================================================
// CONFIGURATION & INITIALIZATION
// ============================================================================

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/api_errors.log');

// Headers for CORS and JSON responses
header('Content-Type: application/json; charset=utf-8');

// Handle CORS - allow credentials with specific origins
// Explicitly allowed origins for production and local development
$allowedOrigins = [
    'http://localhost:8000',
    'http://localhost:5500',
    'http://localhost:5000',
    'http://localhost:3000',
    'http://127.0.0.1:8000',
    'http://127.0.0.1:5500',
    'http://127.0.0.1:5000',
    'http://127.0.0.1:3000',
    'https://promanaged-it.com',
    'http://promanaged-it.com'
];

// UAT/Cloud IDE patterns that should be allowed
$allowedPatterns = [
    '/\.github\.dev$/',           // GitHub Codespaces
    '/\.preview\.app$/',          // Preview apps
    '/\.gitpod\.io$/',            // Gitpod
    '/\.replit\.dev$/',           // Replit
    '/\.stackblitz\.io$/',        // StackBlitz
    '/\.vercel\.app$/',           // Vercel preview
    '/\.netlify\.app$/',          // Netlify preview
    '/\.ngrok\.io$/',             // ngrok tunnels
    '/\.ngrok-free\.app$/',       // ngrok free tier
    '/\.loca\.lt$/',              // localtunnel
    // Local network IP patterns (for mobile/tablet testing on same network)
    '/^https?:\/\/10\.\d{1,3}\.\d{1,3}\.\d{1,3}(:\d+)?$/',           // 10.0.0.0/8
    '/^https?:\/\/172\.(1[6-9]|2[0-9]|3[0-1])\.\d{1,3}\.\d{1,3}(:\d+)?$/', // 172.16.0.0/12
    '/^https?:\/\/192\.168\.\d{1,3}\.\d{1,3}(:\d+)?$/',              // 192.168.0.0/16
    '/^https?:\/\/169\.254\.\d{1,3}\.\d{1,3}(:\d+)?$/',              // 169.254.0.0/16 (link-local)
];

$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
$isAllowedOrigin = in_array($origin, $allowedOrigins);

// Check if origin matches any allowed pattern (for UAT environments)
if (!$isAllowedOrigin && $origin) {
    $originHost = parse_url($origin, PHP_URL_HOST);
    foreach ($allowedPatterns as $pattern) {
        // For local IP patterns, match against full origin (includes protocol and port)
        // For domain patterns, match against host only
        if (strpos($pattern, 'https?://') !== false) {
            // IP-based pattern - match full origin
            if (preg_match($pattern, $origin)) {
                $isAllowedOrigin = true;
                break;
            }
        } else {
            // Domain pattern - match host only
            if (preg_match($pattern, $originHost)) {
                $isAllowedOrigin = true;
                break;
            }
        }
    }
}

if ($isAllowedOrigin && $origin) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
} else if ($origin) {
    // For unknown origins with credentials, reject with specific origin but no credentials
    // This prevents CORS errors while maintaining security
    header("Access-Control-Allow-Origin: $origin");
} else {
    // No origin header - allow wildcard for direct API access (no credentials)
    header('Access-Control-Allow-Origin: *');
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Database Configuration
// Auto-detect environment: Production vs UAT (User Acceptance Testing)
//
// PRODUCTION: Running on promanaged-it.com server -> Use localhost (DB on same server)
// UAT: Running locally on developer laptop -> Use remote DB (promanaged-it.com)
//
// Detection: Check if the server hostname matches the production server
// Production: Any non-localhost hostname (flexible for any domain)
$serverHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
$isLocalhost = in_array($serverHost, ['localhost', '127.0.0.1']) || 
               strpos($serverHost, 'localhost:') === 0 || 
               strpos($serverHost, '127.0.0.1:') === 0 ||
               preg_match('/^(192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $serverHost);
$isProduction = !$isLocalhost && !empty($serverHost);

// Database host: localhost on production, remote for UAT/local development
define('DB_HOST', $isProduction ? 'localhost' : 'promanaged-it.com');
define('DB_USER', 'p601229');
define('DB_PASS', '2:p2WpmX[0YTs7');
define('DB_NAME', 'p601229_motorlinkmalawi_db');
define('SITE_NAME', 'MotorLink Malawi');
define('SITE_URL', 'https://promanaged-it.com/motorlink');
define('UPLOAD_PATH', 'uploads/');
define('MAX_VEHICLE_IMAGES', 5); // Maximum number of images per vehicle
$runtimeSchemaEnv = getenv('MOTORLINK_ENABLE_RUNTIME_SCHEMA_UPDATES');
define('ENABLE_RUNTIME_SCHEMA_UPDATES', $runtimeSchemaEnv !== false ? filter_var($runtimeSchemaEnv, FILTER_VALIDATE_BOOLEAN) : false);

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    // Configure session cookie for cross-origin support
    session_set_cookie_params([
        'lifetime' => 0, // Session cookie (expires when browser closes)
        'path' => '/',
        'domain' => '', // Allow all domains (for localhost and production)
        'secure' => $isProduction, // Auto-enables HTTPS in production
        'httponly' => true,
        'samesite' => 'Lax' // Allow cross-site requests
    ]);
    session_start();
    
    // Regenerate session ID periodically for security
    if (!isset($_SESSION['created'])) {
        $_SESSION['created'] = time();
    } else if (time() - $_SESSION['created'] > 1800) {
        // Regenerate session ID every 30 minutes
        session_regenerate_id(true);
        $_SESSION['created'] = time();
    }
}

/**
 * Database Connection Singleton Class
 */
class Database {
    private static $instance = null;
    private $connection;
    
    private function __construct() {
        try {
            // Increase timeout for remote connections during UAT
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 10 // 10 second timeout for remote connections
            ];

            $this->connection = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                $options
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
    echo json_encode(['success' => false, 'message' => $message, 'timestamp' => date('Y-m-d H:i:s')]);
    exit;
}

/**
 * Send error response with stable client-friendly error code and exit
 */
function sendErrorWithCode($message, $errorCode, $code = 500) {
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'message' => $message,
        'code' => $errorCode,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}

/**
 * Send success response and exit
 */
function sendSuccess($data = [], $code = 200) {
    http_response_code($code);
    echo json_encode(array_merge(['success' => true], $data), JSON_UNESCAPED_UNICODE);
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
        sendError('Database connection failed', 500);
    }
}

/**
 * Best-effort client IP resolution for abuse controls.
 */
function getClientIpAddress() {
    $headers = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'REMOTE_ADDR'
    ];

    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $raw = trim((string)$_SERVER[$header]);
            if ($header === 'HTTP_X_FORWARDED_FOR') {
                $parts = explode(',', $raw);
                $raw = trim((string)($parts[0] ?? ''));
            }

            if (filter_var($raw, FILTER_VALIDATE_IP)) {
                return $raw;
            }
        }
    }

    return '0.0.0.0';
}

/**
 * Check if migrated DB table for API rate limits is available.
 */
function isApiRateLimitTableAvailable($db) {
    static $checked = false;
    static $available = false;

    if ($checked) {
        return $available;
    }

    try {
        $stmt = $db->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'api_rate_limits' LIMIT 1");
        $available = (bool)$stmt->fetchColumn();
    } catch (Exception $e) {
        $available = false;
    }

    if (!$available) {
        error_log('api_rate_limits table missing. Using session fallback until DB migration is applied.');
    }

    $checked = true;
    return $available;
}

/**
 * Record security events to admin activity logs when available.
 */
function logSecurityRateLimitEvent($db, $actionType, $description, $details = []) {
    try {
        $stmt = $db->prepare("INSERT INTO activity_logs (admin_id, action_type, action_description, details, ip_address, user_agent, created_at) VALUES (NULL, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([
            $actionType,
            $description,
            json_encode($details, JSON_UNESCAPED_UNICODE),
            getClientIpAddress(),
            (string)($_SERVER['HTTP_USER_AGENT'] ?? 'api-client')
        ]);
    } catch (Exception $e) {
        error_log('Failed to write security activity log: ' . $e->getMessage());
    }
}

/**
 * Check if a rate-limit key is currently blocked.
 */
function isRateLimited($db, $actionKey, $identifierHash) {
    if (!isApiRateLimitTableAvailable($db)) {
        $sessionKey = 'rate_limit_' . $actionKey . '_' . $identifierHash;
        $bucket = $_SESSION[$sessionKey] ?? null;
        if (!is_array($bucket)) {
            return false;
        }

        $blockedUntilTs = (int)($bucket['blocked_until_ts'] ?? 0);
        return ($blockedUntilTs > time());
    }

    $stmt = $db->prepare("SELECT blocked_until FROM api_rate_limits WHERE action_key = ? AND identifier_hash = ? LIMIT 1");
    $stmt->execute([$actionKey, $identifierHash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || empty($row['blocked_until'])) {
        return false;
    }

    try {
        $now = new DateTime('now');
        $blockedUntil = new DateTime($row['blocked_until']);
        return $blockedUntil > $now;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Record failed attempt and block key when threshold is exceeded.
 */
function recordRateLimitFailure($db, $actionKey, $identifierHash, $maxAttempts, $windowSeconds, $blockSeconds) {
    $maxAttempts = max(1, (int)$maxAttempts);
    $windowSeconds = max(60, (int)$windowSeconds);
    $blockSeconds = max(60, (int)$blockSeconds);

    if (!isApiRateLimitTableAvailable($db)) {
        $sessionKey = 'rate_limit_' . $actionKey . '_' . $identifierHash;
        $bucket = $_SESSION[$sessionKey] ?? [
            'attempt_count' => 0,
            'window_started_ts' => time(),
            'blocked_until_ts' => 0
        ];

        $nowTs = time();
        $elapsed = $nowTs - (int)($bucket['window_started_ts'] ?? $nowTs);
        if ($elapsed > $windowSeconds) {
            $bucket['attempt_count'] = 0;
            $bucket['window_started_ts'] = $nowTs;
            $bucket['blocked_until_ts'] = 0;
        }

        $bucket['attempt_count'] = ((int)$bucket['attempt_count']) + 1;
        $isBlocked = false;
        if ((int)$bucket['attempt_count'] >= $maxAttempts) {
            $bucket['blocked_until_ts'] = $nowTs + $blockSeconds;
            $isBlocked = true;
            logSecurityRateLimitEvent($db, 'security_rate_limit', 'Rate limit triggered (session fallback)', [
                'action' => $actionKey,
                'identifier_hash' => $identifierHash,
                'attempt_count' => (int)$bucket['attempt_count'],
                'fallback' => 'session'
            ]);
        }

        $_SESSION[$sessionKey] = $bucket;
        return $isBlocked;
    }

    $stmt = $db->prepare("SELECT id, attempt_count, window_started_at FROM api_rate_limits WHERE action_key = ? AND identifier_hash = ? LIMIT 1");
    $stmt->execute([$actionKey, $identifierHash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $now = new DateTime('now');
    $windowStarted = clone $now;
    $attemptCount = 1;

    if ($row) {
        try {
            $existingWindow = new DateTime($row['window_started_at']);
            $elapsed = $now->getTimestamp() - $existingWindow->getTimestamp();
            if ($elapsed <= $windowSeconds) {
                $attemptCount = ((int)$row['attempt_count']) + 1;
                $windowStarted = $existingWindow;
            }
        } catch (Exception $e) {
            $attemptCount = 1;
        }
    }

    $blockedUntil = null;
    if ($attemptCount >= $maxAttempts) {
        $blockedAt = clone $now;
        $blockedAt->modify('+' . $blockSeconds . ' seconds');
        $blockedUntil = $blockedAt->format('Y-m-d H:i:s');
        logSecurityRateLimitEvent($db, 'security_rate_limit', 'Rate limit triggered', [
            'action' => $actionKey,
            'identifier_hash' => $identifierHash,
            'attempt_count' => $attemptCount,
            'block_seconds' => $blockSeconds
        ]);
    }

    if ($row) {
        $update = $db->prepare("UPDATE api_rate_limits SET attempt_count = ?, window_started_at = ?, blocked_until = ?, updated_at = NOW() WHERE id = ?");
        $update->execute([
            $attemptCount,
            $windowStarted->format('Y-m-d H:i:s'),
            $blockedUntil,
            (int)$row['id']
        ]);
    } else {
        $insert = $db->prepare("INSERT INTO api_rate_limits (action_key, identifier_hash, attempt_count, window_started_at, blocked_until) VALUES (?, ?, ?, ?, ?)");
        $insert->execute([
            $actionKey,
            $identifierHash,
            $attemptCount,
            $windowStarted->format('Y-m-d H:i:s'),
            $blockedUntil
        ]);
    }

    return !empty($blockedUntil);
}

/**
 * Clear failed-attempt state after a successful flow.
 */
function clearRateLimitState($db, $actionKey, $identifierHash) {
    if (!isApiRateLimitTableAvailable($db)) {
        $sessionKey = 'rate_limit_' . $actionKey . '_' . $identifierHash;
        unset($_SESSION[$sessionKey]);
        return;
    }

    $stmt = $db->prepare("DELETE FROM api_rate_limits WHERE action_key = ? AND identifier_hash = ?");
    $stmt->execute([$actionKey, $identifierHash]);
}

/**
 * Get SMTP settings from site_settings table.
 * Falls back to config-smtp.php values and seeds missing DB keys.
 */
function getSMTPSettings($db) {
    require_once(__DIR__ . '/config-smtp.php');

    $defaults = [
        'smtp_host' => SMTP_HOST,
        'smtp_port' => (string)SMTP_PORT,
        'smtp_username' => SMTP_USERNAME,
        'smtp_password' => SMTP_PASSWORD,
        'smtp_from_email' => SMTP_FROM_EMAIL,
        'smtp_from_name' => SMTP_FROM_NAME,
        'smtp_reply_to' => SMTP_REPLY_TO
    ];

    // Seed missing SMTP keys into site_settings so settings are centrally managed.
    // Password can still be overridden via environment variable in config-smtp.php.
    foreach ($defaults as $key => $value) {
        try {
            $stmt = $db->prepare("SELECT id FROM site_settings WHERE setting_key = ? LIMIT 1");
            $stmt->execute([$key]);
            if (!$stmt->fetch()) {
                $insertStmt = $db->prepare("INSERT INTO site_settings (setting_key, setting_value, setting_group, setting_type, description, is_public) VALUES (?, ?, 'smtp', 'string', ?, 0)");
                $insertStmt->execute([$key, $value, 'SMTP setting: ' . $key]);
            }
        } catch (Exception $e) {
            // Non-fatal: continue with fallback values.
            error_log('SMTP seed warning for ' . $key . ': ' . $e->getMessage());
        }
    }

    $settings = $defaults;

    try {
        $keys = array_keys($defaults);
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $db->prepare("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ($placeholders)");
        $stmt->execute($keys);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            if (array_key_exists($row['setting_key'], $settings) && $row['setting_value'] !== null && $row['setting_value'] !== '') {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        }
    } catch (Exception $e) {
        error_log('SMTP settings load warning: ' . $e->getMessage());
    }

    return [
        'host' => $settings['smtp_host'],
        'port' => (int)$settings['smtp_port'],
        'username' => $settings['smtp_username'],
        'password' => $settings['smtp_password'],
        'from_email' => $settings['smtp_from_email'],
        'from_name' => $settings['smtp_from_name'],
        'reply_to' => $settings['smtp_reply_to']
    ];
}

/**
 * Read a platform setting saved by admin/settings API.
 */
function getPlatformSetting($db, $settingKey, $default = null) {
    static $cache = [];

    if (array_key_exists($settingKey, $cache)) {
        return $cache[$settingKey];
    }

    try {
        $stmt = $db->prepare("SELECT setting_value, setting_type FROM settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$settingKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $cache[$settingKey] = $default;
            return $default;
        }

        $value = $row['setting_value'];
        $type = $row['setting_type'] ?? 'string';

        if ($type === 'boolean') {
            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        } elseif ($type === 'number') {
            $value = is_numeric($value) ? ((strpos((string)$value, '.') !== false) ? (float)$value : (int)$value) : $default;
        }

        $cache[$settingKey] = $value;
        return $value;
    } catch (Exception $e) {
        // Fallback safely when settings table does not exist yet.
        $cache[$settingKey] = $default;
        return $default;
    }
}

/**
 * Apply optional runtime schema update. Disabled by default for safer production traffic.
 */
function applyRuntimeSchemaChange($db, $sql) {
    if (!ENABLE_RUNTIME_SCHEMA_UPDATES) {
        return;
    }

    try {
        $db->exec($sql);
    } catch (Exception $e) {
        // Ignore duplicate-column/key failures while logging for visibility.
        error_log('Runtime schema update skipped: ' . $e->getMessage());
    }
}

/**
 * Resolve a runtime base URL for absolute links without hardcoding production domain.
 */
function getRuntimeBaseUrl() {
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
    if (empty($host)) {
        return rtrim(SITE_URL, '/');
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);

    return ($https ? 'https' : 'http') . '://' . $host;
}

/**
 * Determine if current runtime host is localhost/private network.
 */
function isLocalRuntimeHost() {
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
    if ($host === '') {
        return false;
    }

    $hostname = explode(':', $host)[0];
    if (in_array($hostname, ['localhost', '127.0.0.1', '::1'], true)) {
        return true;
    }

    return (bool)preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $hostname);
}

/**
 * Validate an uploaded image and return canonical mime + extension.
 */
function validateUploadedImageFile($tmpName, $originalName, $fileSize, $clientMimeType, $maxFileSize = 10485760) {
    $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    if (!is_uploaded_file($tmpName)) {
        return ['ok' => false, 'error' => 'File upload integrity check failed'];
    }

    if ($fileSize <= 0 || $fileSize > $maxFileSize) {
        return ['ok' => false, 'error' => 'File size is invalid or exceeds limit'];
    }

    $ext = strtolower(pathinfo((string)$originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExtensions, true)) {
        return ['ok' => false, 'error' => 'Invalid file extension'];
    }

    $mimeType = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detected = finfo_file($finfo, $tmpName);
            finfo_close($finfo);
            if (!empty($detected)) {
                $mimeType = $detected;
            }
        }
    }

    if (empty($mimeType)) {
        $imageInfo = @getimagesize($tmpName);
        $mimeType = $imageInfo['mime'] ?? '';
    }

    if (empty($mimeType)) {
        $mimeType = (string)$clientMimeType;
    }

    if ($mimeType === 'image/x-png') {
        $mimeType = 'image/png';
    }

    if (!in_array($mimeType, $allowedMimeTypes, true)) {
        return ['ok' => false, 'error' => 'Invalid image MIME type'];
    }

    $safeExt = match ($mimeType) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        default => $ext,
    };

    return [
        'ok' => true,
        'mime' => $mimeType,
        'extension' => $safeExt,
    ];
}

/**
 * Ensure listing email verification columns are present.
 */
function ensureListingEmailVerificationColumns($db) {
    applyRuntimeSchemaChange($db, "ALTER TABLE car_listings ADD COLUMN listing_email_verified TINYINT(1) DEFAULT 0");
    applyRuntimeSchemaChange($db, "ALTER TABLE car_listings ADD COLUMN listing_email_verification_token VARCHAR(128) DEFAULT NULL");
    applyRuntimeSchemaChange($db, "ALTER TABLE car_listings ADD COLUMN listing_email_verification_sent_to VARCHAR(255) DEFAULT NULL");
    applyRuntimeSchemaChange($db, "ALTER TABLE car_listings ADD COLUMN listing_email_verification_expires DATETIME DEFAULT NULL");
    applyRuntimeSchemaChange($db, "ALTER TABLE car_listings ADD COLUMN listing_email_verified_at DATETIME DEFAULT NULL");
}

/**
 * Ensure guest-listing lifecycle columns are present.
 */
function ensureGuestListingLifecycleColumns($db) {
    applyRuntimeSchemaChange($db, "ALTER TABLE car_listings ADD COLUMN guest_listing_expires_at DATETIME DEFAULT NULL");
    applyRuntimeSchemaChange($db, "ALTER TABLE car_listings ADD COLUMN guest_listing_expired_at DATETIME DEFAULT NULL");
}

/**
 * Ensure guest listing temporary-management columns are present.
 */
function ensureGuestListingManagementColumns($db) {
    applyRuntimeSchemaChange($db, "ALTER TABLE car_listings ADD COLUMN guest_manage_code_hash VARCHAR(255) DEFAULT NULL");
    applyRuntimeSchemaChange($db, "ALTER TABLE car_listings ADD COLUMN guest_manage_code_expires DATETIME DEFAULT NULL");
    applyRuntimeSchemaChange($db, "ALTER TABLE car_listings ADD COLUMN guest_manage_code_last_sent DATETIME DEFAULT NULL");
}

/**
 * Ensure listing-payment columns exist on car_listings.
 */
function ensureListingPaymentColumns($db) {
    applyRuntimeSchemaChange($db, "ALTER TABLE car_listings ADD COLUMN payment_required TINYINT(1) DEFAULT 0");
    applyRuntimeSchemaChange($db, "ALTER TABLE car_listings ADD COLUMN payment_amount DECIMAL(12,2) DEFAULT 0");
    applyRuntimeSchemaChange($db, "ALTER TABLE car_listings ADD COLUMN payment_method VARCHAR(50) DEFAULT NULL");
    applyRuntimeSchemaChange($db, "ALTER TABLE car_listings ADD COLUMN payment_reference VARCHAR(100) DEFAULT NULL");
    applyRuntimeSchemaChange($db, "ALTER TABLE car_listings ADD COLUMN payment_proof_path VARCHAR(255) DEFAULT NULL");
    applyRuntimeSchemaChange($db, "ALTER TABLE car_listings ADD COLUMN payment_status VARCHAR(30) DEFAULT 'not_required'");
    applyRuntimeSchemaChange($db, "ALTER TABLE car_listings ADD COLUMN payment_submitted_at DATETIME DEFAULT NULL");
}

/**
 * Ensure payments table exists for admin payment verification workflow.
 */
function ensurePaymentsTable($db) {
    $db->exec("CREATE TABLE IF NOT EXISTS payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        listing_id INT NULL,
        user_id INT NULL,
        user_name VARCHAR(120) DEFAULT NULL,
        user_email VARCHAR(255) DEFAULT NULL,
        service_type VARCHAR(60) NOT NULL DEFAULT 'listing_submission',
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        payment_method VARCHAR(50) DEFAULT NULL,
        reference VARCHAR(100) DEFAULT NULL,
        proof_path VARCHAR(255) DEFAULT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'pending',
        notes TEXT DEFAULT NULL,
        verified_at DATETIME DEFAULT NULL,
        verified_by INT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_listing_id (listing_id),
        INDEX idx_user_id (user_id),
        INDEX idx_status (status),
        INDEX idx_service_type (service_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * Save base64 payment proof file and return relative path.
 */
function persistPaymentProofFromDataUrl($dataUrl, $originalFilename = '') {
    if (empty($dataUrl) || strpos($dataUrl, 'data:') !== 0) {
        throw new Exception('Invalid payment proof payload');
    }

    if (!preg_match('/^data:([a-zA-Z0-9\/+\-.]+);base64,(.+)$/', $dataUrl, $matches)) {
        throw new Exception('Unsupported payment proof format');
    }

    $mimeType = strtolower(trim($matches[1]));
    $raw = base64_decode($matches[2], true);
    if ($raw === false) {
        throw new Exception('Could not decode payment proof file');
    }

    if (strlen($raw) > 5 * 1024 * 1024) {
        throw new Exception('Payment proof exceeds 5MB limit');
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf'
    ];

    if (!isset($allowed[$mimeType])) {
        throw new Exception('Unsupported payment proof file type');
    }

    $ext = $allowed[$mimeType];
    $safeName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', (string)$originalFilename);
    if (empty($safeName)) {
        $safeName = 'payment_proof';
    }

    $targetDir = __DIR__ . '/uploads/payment-proofs';
    if (!is_dir($targetDir) && !mkdir($targetDir, 0777, true) && !is_dir($targetDir)) {
        throw new Exception('Failed to create payment proof upload directory');
    }

    $uniqueName = 'proof_' . date('Ymd_His') . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
    $targetFile = $targetDir . '/' . $uniqueName;

    if (file_put_contents($targetFile, $raw) === false) {
        throw new Exception('Failed to save payment proof file');
    }

    return 'uploads/payment-proofs/' . $uniqueName;
}

/**
 * Check if a table column exists.
 */
function tableColumnExists($db, $tableName, $columnName) {
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$tableName, $columnName]);
        return ((int)$stmt->fetchColumn() > 0);
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Generate and persist a temporary guest listing management code.
 */
function issueGuestListingManageCode($db, $listingId) {
    ensureGuestListingManagementColumns($db);

    $hasCodeHash = tableColumnExists($db, 'car_listings', 'guest_manage_code_hash');
    $hasCodeExpiry = tableColumnExists($db, 'car_listings', 'guest_manage_code_expires');
    $hasCodeSent = tableColumnExists($db, 'car_listings', 'guest_manage_code_last_sent');

    if (!$hasCodeHash || !$hasCodeExpiry) {
        throw new Exception('Guest listing management columns are missing');
    }

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $codeHash = password_hash($code, PASSWORD_DEFAULT);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

    $setParts = [
        'guest_manage_code_hash = ?',
        'guest_manage_code_expires = ?'
    ];
    $params = [$codeHash, $expiresAt];

    if ($hasCodeSent) {
        $setParts[] = 'guest_manage_code_last_sent = NOW()';
    }

    $params[] = (int)$listingId;
    $sql = "UPDATE car_listings SET " . implode(', ', $setParts) . " WHERE id = ? LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return [
        'code' => $code,
        'expires_at' => $expiresAt
    ];
}

/**
 * Validate guest listing management session.
 */
function requireGuestListingManagerSession() {
    $listingId = (int)($_SESSION['guest_manage_listing_id'] ?? 0);
    $email = strtolower(trim((string)($_SESSION['guest_manage_email'] ?? '')));
    $authAt = (int)($_SESSION['guest_manage_authenticated_at'] ?? 0);

    if ($listingId <= 0 || empty($email) || $authAt <= 0) {
        sendError('Guest listing authentication required', 401);
    }

    // Hard timeout for guest manager sessions.
    if ((time() - $authAt) > 3600) {
        unset($_SESSION['guest_manage_listing_id'], $_SESSION['guest_manage_email'], $_SESSION['guest_manage_authenticated_at']);
        sendError('Guest listing session expired. Please login again.', 401);
    }

    return [
        'listing_id' => $listingId,
        'email' => $email
    ];
}

/**
 * Expire guest listings whose validity window has elapsed.
 */
function expireGuestListings($db) {
    ensureGuestListingLifecycleColumns($db);

    try {
        $stmt = $db->prepare("\n            UPDATE car_listings\n            SET\n                status = 'expired',\n                guest_listing_expired_at = COALESCE(guest_listing_expired_at, NOW())\n            WHERE is_guest = 1\n              AND guest_listing_expires_at IS NOT NULL\n              AND guest_listing_expires_at <= NOW()\n              AND status IN ('active', 'pending_approval', 'pending_email_verification')\n        ");
        $stmt->execute();
    } catch (Exception $e) {
        error_log('expireGuestListings warning: ' . $e->getMessage());
    }
}

/**
 * Request a temporary guest listing management login code.
 */
function requestGuestListingManageCode($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('POST method required', 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $email = strtolower(trim((string)($input['email'] ?? '')));

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sendError('Valid email is required', 400);
    }

    try {
        expireGuestListings($db);

        $stmt = $db->prepare("\n            SELECT id, reference_number, guest_seller_name\n            FROM car_listings\n            WHERE is_guest = 1\n              AND LOWER(guest_seller_email) = LOWER(?)\n              AND status != 'deleted'\n            ORDER BY created_at DESC\n            LIMIT 1\n        ");
        $stmt->execute([$email]);
        $listing = $stmt->fetch(PDO::FETCH_ASSOC);

        $debugCode = null;
        $debugExpiresAt = null;

        if ($listing) {
            $codePayload = issueGuestListingManageCode($db, (int)$listing['id']);
            $debugCode = $codePayload['code'];
            $debugExpiresAt = $codePayload['expires_at'];
            sendGuestListingManageCodeEmail(
                $db,
                $email,
                (string)($listing['guest_seller_name'] ?? 'Guest Seller'),
                (string)($listing['reference_number'] ?? ''),
                (int)$listing['id'],
                $codePayload['code'],
                $codePayload['expires_at']
            );
        }

        // Always return generic success to avoid account/listing enumeration.
        $response = ['message' => 'If a guest listing exists for this email, a temporary login code has been sent.'];

        // Localhost-only diagnostics for smoke testing without relying on email delivery.
        if (isLocalRuntimeHost() && $listing && $debugCode !== null) {
            $response['debug_guest_manage_code'] = $debugCode;
            $response['debug_guest_manage_code_expires_at'] = $debugExpiresAt;
            $response['debug_guest_listing_id'] = (int)$listing['id'];
        }

        sendSuccess($response);
    } catch (Exception $e) {
        error_log('requestGuestListingManageCode error: ' . $e->getMessage());
        sendError('Failed to request guest login code', 500);
    }
}

/**
 * Login guest listing manager with email + temporary code.
 */
function loginGuestListingManager($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('POST method required', 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $code = trim((string)($input['code'] ?? ''));

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sendError('Valid email is required', 400);
    }
    if (empty($code)) {
        sendError('Login code is required', 400);
    }

    try {
        expireGuestListings($db);

        $stmt = $db->prepare("\n            SELECT id, title, reference_number, status, approval_status, guest_seller_email, guest_manage_code_hash, guest_manage_code_expires, created_at\n            FROM car_listings\n            WHERE is_guest = 1\n              AND LOWER(guest_seller_email) = LOWER(?)\n              AND status != 'deleted'\n            ORDER BY created_at DESC\n            LIMIT 1\n        ");
        $stmt->execute([$email]);
        $listing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$listing) {
            sendError('Invalid email or login code', 401);
        }

        $codeHash = (string)($listing['guest_manage_code_hash'] ?? '');
        $codeExpires = (string)($listing['guest_manage_code_expires'] ?? '');

        if (empty($codeHash) || empty($codeExpires) || strtotime($codeExpires) < time() || !password_verify($code, $codeHash)) {
            sendError('Invalid or expired login code', 401);
        }

        $_SESSION['guest_manage_listing_id'] = (int)$listing['id'];
        $_SESSION['guest_manage_email'] = strtolower((string)$listing['guest_seller_email']);
        $_SESSION['guest_manage_authenticated_at'] = time();

        sendSuccess([
            'message' => 'Guest listing login successful',
            'listing' => [
                'id' => (int)$listing['id'],
                'title' => $listing['title'],
                'reference_number' => $listing['reference_number'],
                'status' => $listing['status'],
                'approval_status' => $listing['approval_status'],
                'created_at' => $listing['created_at']
            ]
        ]);
    } catch (Exception $e) {
        error_log('loginGuestListingManager error: ' . $e->getMessage());
        sendError('Failed to login guest listing manager', 500);
    }
}

/**
 * Return authenticated guest listing manager session state.
 */
function getGuestListingManagerSession($db) {
    try {
        expireGuestListings($db);
        $guestSession = requireGuestListingManagerSession();

        $stmt = $db->prepare("\n            SELECT id, title, reference_number, status, approval_status, created_at\n            FROM car_listings\n            WHERE id = ?\n              AND is_guest = 1\n              AND LOWER(guest_seller_email) = LOWER(?)\n            LIMIT 1\n        ");
        $stmt->execute([(int)$guestSession['listing_id'], $guestSession['email']]);
        $listing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$listing) {
            unset($_SESSION['guest_manage_listing_id'], $_SESSION['guest_manage_email'], $_SESSION['guest_manage_authenticated_at']);
            sendError('Guest listing session is no longer valid', 401);
        }

        sendSuccess([
            'authenticated' => true,
            'listing' => [
                'id' => (int)$listing['id'],
                'title' => $listing['title'],
                'reference_number' => $listing['reference_number'],
                'status' => $listing['status'],
                'approval_status' => $listing['approval_status'],
                'created_at' => $listing['created_at']
            ]
        ]);
    } catch (Exception $e) {
        error_log('getGuestListingManagerSession error: ' . $e->getMessage());
        sendError('Failed to load guest listing session', 500);
    }
}

/**
 * Logout guest listing manager session.
 */
function logoutGuestListingManager() {
    unset($_SESSION['guest_manage_listing_id'], $_SESSION['guest_manage_email'], $_SESSION['guest_manage_authenticated_at']);
    sendSuccess(['message' => 'Guest listing session ended']);
}

/**
 * Minimal guest listing management actions: sold, delete.
 */
function manageGuestListing($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('POST method required', 405);
    }

    $guestSession = requireGuestListingManagerSession();
    $input = json_decode(file_get_contents('php://input'), true);
    $action = strtolower(trim((string)($input['action'] ?? '')));

    if (!in_array($action, ['sold', 'delete'], true)) {
        sendError('Invalid guest listing action', 400);
    }

    try {
        $stmt = $db->prepare("\n            SELECT id, status\n            FROM car_listings\n            WHERE id = ?\n              AND is_guest = 1\n              AND LOWER(guest_seller_email) = LOWER(?)\n            LIMIT 1\n        ");
        $stmt->execute([(int)$guestSession['listing_id'], $guestSession['email']]);
        $listing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$listing) {
            sendError('Listing not found or access denied', 404);
        }

        if ($action === 'sold') {
            $updateStmt = $db->prepare("UPDATE car_listings SET status = 'sold', updated_at = NOW() WHERE id = ? LIMIT 1");
            $updateStmt->execute([(int)$listing['id']]);

            sendSuccess(['message' => 'Listing marked as sold']);
        }

        $db->beginTransaction();
        deleteListingImages($db, (int)$listing['id']);
        $deleteStmt = $db->prepare("UPDATE car_listings SET status = 'deleted', updated_at = NOW() WHERE id = ? LIMIT 1");
        $deleteStmt->execute([(int)$listing['id']]);
        $db->commit();

        unset($_SESSION['guest_manage_listing_id'], $_SESSION['guest_manage_email'], $_SESSION['guest_manage_authenticated_at']);
        sendSuccess(['message' => 'Listing deleted successfully']);
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('manageGuestListing error: ' . $e->getMessage());
        sendError('Failed to manage guest listing', 500);
    }
}

/**
 * Set admin session variables for cross-system authentication
 * 
 * Sets BOTH regular session format (user_id, user_type) AND admin-specific format
 * (admin_logged_in, admin_id, admin_name) to enable seamless navigation between
 * the main site and admin panel without requiring a second login.
 * 
 * Why both formats?
 * - Main site (index.html, script.js) expects: user_id, user_type='admin'
 * - Admin panel (admin.html, admin-api.php) expects: admin_logged_in, admin_id, admin_name
 * 
 * This approach ensures backward compatibility while enabling cross-system authentication.
 */
function setAdminSession($admin) {
    // Main site session format
    $_SESSION['user_id'] = $admin['id'];
    $_SESSION['full_name'] = $admin['full_name'];
    $_SESSION['email'] = $admin['email'];
    $_SESSION['user_type'] = 'admin';
    $_SESSION['last_activity'] = time();
    
    // Admin panel session format
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_id'] = $admin['id'];
    $_SESSION['admin_name'] = $admin['full_name'];
    $_SESSION['admin_email'] = $admin['email'];
    $_SESSION['admin_role'] = $admin['role'] ?? 'admin';
}

/**
 * Set regular user session variables
 */
function setUserSession($user) {
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['user_type'] = $user['user_type'];
    $_SESSION['last_activity'] = time();
}

/**
 * Check if user is logged in
 */
if (!function_exists('isLoggedIn')) {
    function isLoggedIn() {
        return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
    }
}

/**
 * Get current user data
 */
if (!function_exists('getCurrentUser')) {
    function getCurrentUser($required = true) {
        $user = [
            'id' => $_SESSION['user_id'] ?? null,
            'name' => $_SESSION['full_name'] ?? null,
            'email' => $_SESSION['email'] ?? null,
            'type' => $_SESSION['user_type'] ?? null
        ];

        if (!empty($user['id'])) {
            return $user;
        }

        if ($required) {
            sendError('Authentication required', 401);
        }

        return null;
    }
}

/**
 * Require authentication
 */
if (!function_exists('requireAuth')) {
    function requireAuth() {
        if (!isLoggedIn()) {
            sendError('Authentication required', 401);
        }
    }
}

/**
 * Require admin privileges
 */
if (!function_exists('requireAdmin')) {
    function requireAdmin() {
        requireAuth();
        $user = getCurrentUser();
        if ($user['type'] !== 'admin') {
            sendError('Admin access required', 403);
        }
    }
}

/**
 * Check whether current request should bypass maintenance lock.
 */
function isMaintenanceBypassAction($action) {
    $allowed = [
        'check_auth',
        'login',
        'logout',
        'get_public_client_config',
        'site_settings',
        'get_site_settings',
        'request_password_reset',
        'reset_password',
        'verify_listing_email'
    ];

    return in_array($action, $allowed, true);
}

/**
 * Read maintenance mode state from settings table.
 */
function getMaintenanceModeState($db) {
    $state = [
        'enabled' => false,
        'message' => 'We are currently performing scheduled maintenance. Please check back shortly.'
    ];

    try {
        $stmt = $db->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('maintenance_enabled', 'maintenance_message')");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['setting_key'] === 'maintenance_enabled') {
                $state['enabled'] = filter_var($row['setting_value'], FILTER_VALIDATE_BOOLEAN);
            } elseif ($row['setting_key'] === 'maintenance_message' && !empty($row['setting_value'])) {
                $state['message'] = (string)$row['setting_value'];
            }
        }
    } catch (Exception $e) {
        error_log('Maintenance state load warning: ' . $e->getMessage());
    }

    return $state;
}

/**
 * Enforce maintenance lock for non-admin users and non-whitelisted actions.
 */
function enforceMaintenanceMode($db, $action) {
    $maintenance = getMaintenanceModeState($db);
    if (!$maintenance['enabled']) {
        return;
    }

    // Admin sessions bypass maintenance mode.
    $isAdminSession = !empty($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin';
    if ($isAdminSession || isMaintenanceBypassAction($action)) {
        return;
    }

    sendErrorWithCode($maintenance['message'], 'MAINTENANCE_MODE', 503);
}

/**
 * Generate unique reference number for listings
 */
function generateReferenceNumber($db) {
    $prefix = 'ML';
    $year = date('Y');
    
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM car_listings WHERE YEAR(created_at) = ?");
        $stmt->execute([date('Y')]);
        $count = $stmt->fetchColumn() + 1;
    } catch (Exception $e) {
        $count = 1;
    }
    
    return $prefix . $year . str_pad($count, 4, '0', STR_PAD_LEFT);
}

/**
 * Serve placeholder image when actual image not found
 */
function servePlaceholderImage() {
    $placeholderPath = __DIR__ . '/assets/images/car-placeholder.jpg';
    
    if (file_exists($placeholderPath)) {
        header('Content-Type: image/jpeg');
        readfile($placeholderPath);
    } else {
        header('Content-Type: image/svg+xml');
        echo '<?xml version="1.0" encoding="UTF-8"?>
        <svg width="400" height="300" xmlns="http://www.w3.org/2000/svg">
            <rect width="400" height="300" fill="#f8f9fa"/>
            <text x="200" y="150" text-anchor="middle" font-family="Arial, sans-serif" font-size="16" fill="#6c757d">Car Image</text>
            <text x="200" y="170" text-anchor="middle" font-family="Arial, sans-serif" font-size="12" fill="#6c757d">Not Available</text>
        </svg>';
    }
    exit;
}

// ============================================================================
// API ROUTING & MAIN EXECUTION
// ============================================================================

// Allow other APIs (e.g. admin-api.php) to require this file purely for shared
// constants and function definitions without executing the action router.
if (defined('MOTORLINK_CONSTANTS_ONLY')) {
    return;
}

try {
    $db = getDB();

    // Try to get action from GET, POST, or JSON body
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    // If no action in GET/POST, check JSON body
    if (empty($action) && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $jsonInput = json_decode(file_get_contents('php://input'), true);
        if (isset($jsonInput['action'])) {
            $action = $jsonInput['action'];
        }
    }

    // Log the action for debugging
    error_log("API Request - Action: " . $action);
    error_log("Request Method: " . $_SERVER['REQUEST_METHOD']);
    error_log("GET params: " . json_encode($_GET));
    error_log("POST params: " . json_encode($_POST));

    if (empty($action)) {
        sendError('No action specified', 400);
    }

    // Enforce one-click maintenance mode globally at API layer.
    enforceMaintenanceMode($db, $action);

    // Route to appropriate handler
    switch ($action) {
        // ====================================================================
        // PUBLIC ENDPOINTS
        // ====================================================================
        case 'stats': getStats($db); break;
        case 'makes':
        case 'get_makes': getMakes($db); break;
        case 'models':
        case 'get_models': getModels($db); break;
        case 'get_model_engine_variations': getModelEngineVariations($db); break;
        case 'locations':
        case 'get_locations': getLocations($db); break;
        case 'listings': getListings($db); break;
        case 'listing': getListing($db); break;
        case 'dealer_other_listings': getDealerOtherListings($db); break;
        case 'garages': getGarages($db); break;
        case 'dealers': getDealers($db); break;
        case 'dealer_showroom': getDealerShowroom($db); break;
        case 'car_hire_stats':getCarHireStats($db);break;
        case 'car_hire_locations':getCarHireLocations($db);break;
        case 'car_hire': getCarHire($db); break;
        case 'car_hire_companies_with_fleet': getCarHireCompaniesWithFleet($db); break;
        case 'car_hire_company': getCarHireCompany($db); break;
        case 'car_hire_fleet': getCarHireFleet($db); break;
        case 'image': serveImage($db); break;
        case 'get_listing_images': getListingImages($db); break;
        case 'set_featured_image': setFeaturedImage($db); break;
        case 'delete_listing_image': deleteListingImage($db); break;
        case 'site_settings': 
        case 'get_site_settings': getSiteSettings($db); break;
        case 'listing_restrictions': getListingRestrictions($db); break;
        case 'check_guest_identity': checkGuestIdentity($db); break;
        case 'get_public_client_config': getPublicClientConfig($db); break;
        case 'get_ai_chat_status': getAIChatStatus($db); break;
        case 'verify_listing_email': verifyListingEmail($db); break;
        case 'guest_listing_request_code': requestGuestListingManageCode($db); break;
        case 'guest_listing_login': loginGuestListingManager($db); break;
        case 'guest_listing_session': getGuestListingManagerSession($db); break;
        case 'guest_listing_logout': logoutGuestListingManager(); break;
        case 'guest_listing_manage': manageGuestListing($db); break;
        case 'nhtsa': require_once __DIR__ . '/vin-decoder-api.php'; getNhtsaData($db); break;

        // ====================================================================
        // AUTHENTICATION ENDPOINTS
        // ====================================================================
        case 'check_auth': checkAuth(); break;
        case 'login': handleLogin($db); break;
        case 'register': handleRegister($db); break;
        case 'check_username': checkUsername($db); break;
        case 'check_email': checkEmail($db); break;
        case 'logout': handleLogout(); break;
        case 'request_password_reset': handleRequestPasswordReset($db); break;
        case 'reset_password': handleResetPassword($db); break;
        
        // ====================================================================
        // USER ENDPOINTS (Authentication required except guest listing)
        // ====================================================================
        case 'submit_listing': submitListing($db); break;
        case 'get_profile': requireAuth(); getProfile($db); break;
        case 'update_profile': requireAuth(); updateProfile($db); break;
        case 'change_password': requireAuth(); changePassword($db); break;
        case 'delete_account': requireAuth(); deleteAccount($db); break;
        case 'user_stats': 
            // Check auth but don't block - getUserStats handles its own auth
            if (isLoggedIn()) {
                getUserStats($db);
            } else {
                sendError('Authentication required', 401);
            }
            break;
        case 'upload_images':
        case 'upload_listing_images': handleUploadImages($db); break;
        case 'my_listings': requireAuth(); getMyListings($db); break;
        case 'update_listing_status': requireAuth(); updateListingStatus($db); break;
        case 'delete_listing': requireAuth(); deleteListing($db); break;
        case 'update_listing': requireAuth(); updateListing($db); break;
        case 'check_similar_listings': requireAuth(); checkSimilarListings($db); break;

        // ====================================================================
        // FAVORITES ENDPOINTS
        // ====================================================================
        case 'save_listing': requireAuth(); saveListing($db); break;
        case 'unsave_listing': requireAuth(); unsaveListing($db); break;
        case 'get_favorites': requireAuth(); getFavorites($db); break;

        // ====================================================================
        // AI CHAT ENDPOINTS
        // ====================================================================
        case 'get_ai_chat_usage_remaining':
            requireAuth();
            require_once __DIR__ . '/api-common.php';
            require_once __DIR__ . '/ai-car-chat-api.php';
            $user = getCurrentUser(true);
            if (!$user) {
                sendError('Authentication required', 401);
                break;
            }
            $usage = getUserAIChatUsageRemaining($db, $user['id']);
            sendSuccess(['usage' => $usage]);
            break;
        case 'ai_car_chat':
            // Enable error output buffering to catch fatal errors
            ob_start();
            register_shutdown_function(function() {
                $error = error_get_last();
                if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
                    ob_end_clean();
                    http_response_code(500);
                    header('Content-Type: application/json');
                    error_log("AI Car Chat Fatal Error: " . $error['message'] . " in " . $error['file'] . " on line " . $error['line']);
                    echo json_encode([
                        'success' => false,
                        'message' => 'A fatal error occurred: ' . $error['message'],
                        'file' => basename($error['file']),
                        'line' => $error['line']
                    ]);
                    exit;
                }
            });
            
            try {
                require_once __DIR__ . '/api-common.php';
                require_once __DIR__ . '/ai-car-chat-api.php';
                requireAuth();
                handleAICarChat($db);
                ob_end_flush();
                exit;
            } catch (Exception $e) {
                ob_end_clean();
                error_log("AI Car Chat Error: " . $e->getMessage());
                error_log("Stack trace: " . $e->getTraceAsString());
                http_response_code(500);
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => 'An error occurred processing your request. Please try again.',
                    'error' => $e->getMessage()
                ]);
                exit;
            } catch (Error $e) {
                ob_end_clean();
                error_log("AI Car Chat Fatal Error: " . $e->getMessage());
                error_log("Stack trace: " . $e->getTraceAsString());
                http_response_code(500);
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => 'A fatal error occurred. Please try again.',
                    'error' => $e->getMessage()
                ]);
                exit;
            }
            break;

        // ====================================================================
        // REPORTING ENDPOINTS
        // ====================================================================
        case 'report_listing': reportListing($db); break;

        // ====================================================================
        // MESSAGING ENDPOINTS
        // ====================================================================
        case 'get_conversations': requireAuth(); getConversations($db); break;
        case 'get_messages': requireAuth(); getMessages($db); break;
        case 'send_message': requireAuth(); sendMessage($db); break;
        case 'start_conversation': requireAuth(); startConversation($db); break;
        case 'search_message_recipients': requireAuth(); searchMessageRecipients($db); break;
        case 'mark_read': requireAuth(); markMessagesRead($db); break;
        case 'check_new_messages': requireAuth(); checkNewMessages($db); break;
        case 'delete_conversation': requireAuth(); deleteConversation($db); break;
        case 'archive_conversation': requireAuth(); archiveConversation($db); break;
        case 'get_auto_reply_settings': requireAuth(); getAutoReplySettings($db); break;
        case 'update_auto_reply_settings': requireAuth(); updateAutoReplySettings($db); break;
        
        // ====================================================================
        // ADMIN ENDPOINTS (Admin privileges required)
        // ====================================================================
        case 'admin_listings': requireAdmin(); getAdminListings($db); break;
        case 'approve_listing': requireAdmin(); approveListing($db); break;
        case 'deny_listing': requireAdmin(); denyListing($db); break;
        case 'admin_notifications': requireAdmin(); getAdminNotifications($db); break;

        // ====================================================================
        // DEALER DASHBOARD ENDPOINTS (Dealer access required)
        // ====================================================================
        case 'get_dealer_info': requireAuth(); getDealerInfo($db); break;
        case 'update_dealer_showroom': requireAuth(); updateDealerShowroom($db); break;
        case 'update_dealer_business': requireAuth(); updateDealerBusiness($db); break;
        case 'dealer_inventory': requireAuth(); getDealerInventory($db); break;
        case 'dealer_recent_activity': requireAuth(); getDealerRecentActivity($db); break;
        case 'dealer_add_car': requireAuth(); dealerAddCar($db); break;
        case 'dealer_delete_car': requireAuth(); dealerDeleteCar($db); break;
        case 'upload_dealer_logo': requireAuth(); uploadDealerLogo($db); break;
        case 'update_notification_preferences': requireAuth(); updateNotificationPreferences($db); break;
        case 'get_listing': requireAuth(); getListingForEdit($db); break;
        case 'update_car_listing': requireAuth(); updateCarListing($db); break;
        case 'delete_car_image': requireAuth(); deleteCarImage($db); break;

        // ====================================================================
        // GARAGE DASHBOARD ENDPOINTS (Garage access required)
        // ====================================================================
        case 'get_garage_info': requireAuth(); getGarageInfo($db); break;
        case 'update_garage_info': requireAuth(); updateGarageInfo($db); break;
        case 'update_garage_hours': requireAuth(); updateGarageHours($db); break;
        case 'update_garage_services': requireAuth(); updateGarageServices($db); break;
        case 'get_garage_reviews': requireAuth(); getGarageReviews($db); break;
        case 'upload_garage_logo': requireAuth(); uploadGarageLogo($db); break;

        // ====================================================================
        // CAR HIRE DASHBOARD ENDPOINTS (Car hire access required)
        // ====================================================================
        case 'get_car_hire_company_info': requireAuth(); getCarHireCompanyInfo($db); break;
        case 'update_car_hire_company': requireAuth(); updateCarHireCompany($db); break;
        case 'get_car_hire_fleet': requireAuth(); getCarHireFleetManagement($db); break;
        case 'add_car_hire_vehicle': requireAuth(); addCarHireVehicle($db); break;
        case 'update_vehicle_status': requireAuth(); updateVehicleStatus($db); break;
        case 'delete_car_hire_vehicle': requireAuth(); deleteCarHireVehicle($db); break;
        case 'get_car_hire_rentals': requireAuth(); getCarHireRentals($db); break;
        case 'complete_rental': requireAuth(); completeRental($db); break;
        case 'upload_car_hire_logo': requireAuth(); uploadCarHireLogo($db); break;
        case 'get_vehicle': requireAuth(); getVehicleForEdit($db); break;
        case 'update_vehicle': requireAuth(); updateVehicle($db); break;

        // ====================================================================
        // JOURNEY PLANNER ENDPOINTS
        // ====================================================================
        case 'get_fuel_prices': getFuelPrices($db); break;
        case 'calculate_journey': requireAuth(); calculateJourney($db); break;
        case 'get_journey_history': requireAuth(); getJourneyHistory($db); break;
        case 'scrape_fuel_prices': scrapeFuelPrices($db); break; // Can be called by cron

        // ====================================================================
        // USER VEHICLE MANAGEMENT ENDPOINTS
        // ====================================================================
        case 'get_user_vehicles': requireAuth(); getUserVehicles($db); break;
        case 'add_user_vehicle': requireAuth(); addUserVehicle($db); break;
        case 'update_user_vehicle': requireAuth(); updateUserVehicle($db); break;
        case 'delete_user_vehicle': requireAuth(); deleteUserVehicle($db); break;
        case 'set_primary_vehicle': requireAuth(); setPrimaryVehicle($db); break;

        default:
            sendError('Invalid action: ' . $action, 400);
    }
} catch (Exception $e) {
    error_log("API Fatal Error: " . $e->getMessage());
    sendError('Internal server error', 500);
}

// ============================================================================
// PUBLIC ENDPOINT HANDLERS
// ============================================================================

/**
 * Get platform statistics
 */
function getStats($db) {
    try {
        $stats = [];
        
        // Count active, approved listings
        $stmt = $db->query("SELECT COUNT(*) FROM car_listings WHERE status = 'active' AND approval_status = 'approved'");
        $stats['total_cars'] = (int)$stmt->fetchColumn();
        
        // Count active dealers
        $stmt = $db->query("SELECT COUNT(*) FROM car_dealers WHERE status = 'active'");
        $stats['total_dealers'] = (int)$stmt->fetchColumn();
        
        // Count active garages
        $stmt = $db->query("SELECT COUNT(*) FROM garages WHERE status = 'active'");
        $stats['total_garages'] = (int)$stmt->fetchColumn();
        
        // Count active car hire companies
        $stmt = $db->query("SELECT COUNT(*) FROM car_hire_companies WHERE status = 'active'");
        $stats['total_car_hire'] = (int)$stmt->fetchColumn();
        
        sendSuccess(['stats' => $stats]);
    } catch (Exception $e) {
        error_log("getStats error: " . $e->getMessage());
        // Return default stats on error
        sendSuccess(['stats' => [
            'total_cars' => 0, 'total_dealers' => 0, 'total_garages' => 0, 'total_car_hire' => 0
        ]]);
    }
}

/**
 * Get global AI chat availability status (public)
 */
function getAIChatStatus($db) {
    try {
        $stmt = $db->prepare("SELECT enabled FROM ai_chat_settings WHERE id = 1 LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $enabled = 1;
        if ($row && array_key_exists('enabled', $row)) {
            $enabled = (int)$row['enabled'] === 1 ? 1 : 0;
        }

        sendSuccess(['enabled' => $enabled]);
    } catch (Exception $e) {
        // Fail open to avoid blocking UX if table is not initialized yet.
        error_log("getAIChatStatus error: " . $e->getMessage());
        sendSuccess(['enabled' => 1]);
    }
}

/**
 * Get all active car makes
 */
function getMakes($db) {
    try {
        $stmt = $db->query("SELECT id, name FROM car_makes WHERE is_active = 1 ORDER BY sort_order ASC, name ASC");
        $makes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        sendSuccess(['makes' => $makes]);
    } catch (Exception $e) {
        error_log("getMakes error: " . $e->getMessage());
        sendError('Failed to load makes', 500);
    }
}

/**
 * Get models for a specific make
 */
function getModels($db) {
    $makeId = $_GET['make_id'] ?? '';
    if (empty($makeId) || !is_numeric($makeId)) {
        sendError('Valid make ID required', 400);
    }
    
    try {
        // Return unique model names (grouped by name to avoid duplicates)
        // Since we now have multiple rows per model (one per engine size variation),
        // we group by name and return the first model ID for each unique model name
        $stmt = $db->prepare("
            SELECT 
                MIN(id) as id, 
                name, 
                body_type, 
                MIN(year_start) as year_start, 
                MAX(year_end) as year_end,
                COUNT(*) as variation_count
            FROM car_models 
            WHERE make_id = ? AND is_active = 1 
            GROUP BY name, body_type
            ORDER BY name ASC
        ");
        $stmt->execute([$makeId]);
        $models = $stmt->fetchAll(PDO::FETCH_ASSOC);
        sendSuccess(['models' => $models]);
    } catch (Exception $e) {
        error_log("getModels error: " . $e->getMessage());
        sendError('Failed to load models', 500);
    }
}

function getModelEngineVariations($db) {
    $modelId = $_GET['model_id'] ?? '';
    if (empty($modelId) || !is_numeric($modelId)) {
        sendError('Valid model ID required', 400);
    }
    
    try {
        // Get model name first to find all variations
        $stmt = $db->prepare("SELECT make_id, name FROM car_models WHERE id = ? AND is_active = 1");
        $stmt->execute([$modelId]);
        $model = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$model) {
            sendError('Model not found', 404);
        }
        
        // Get all variations for this model (multiple rows with same make_id + name)
        $stmt = $db->prepare("
            SELECT DISTINCT 
                engine_size_liters,
                fuel_tank_capacity_liters,
                drive_type,
                transmission_type,
                fuel_type,
                fuel_consumption_combined_l100km,
                fuel_consumption_urban_l100km,
                fuel_consumption_highway_l100km,
                horsepower_hp,
                torque_nm
            FROM car_models 
            WHERE make_id = ? AND name = ? AND is_active = 1
                AND (engine_size_liters IS NOT NULL OR fuel_tank_capacity_liters IS NOT NULL 
                     OR drive_type IS NOT NULL OR transmission_type IS NOT NULL)
            ORDER BY engine_size_liters ASC, transmission_type ASC
        ");
        $stmt->execute([$model['make_id'], $model['name']]);
        $variations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Extract unique values for dropdowns (remove null values)
        $engineSizes = array_values(array_unique(array_filter(array_column($variations, 'engine_size_liters'), function($val) {
            return $val !== null && $val !== '';
        })));
        $fuelTanks = array_values(array_unique(array_filter(array_column($variations, 'fuel_tank_capacity_liters'), function($val) {
            return $val !== null && $val !== '';
        })));
        $driveTypes = array_values(array_unique(array_filter(array_column($variations, 'drive_type'), function($val) {
            return $val !== null && $val !== '';
        })));
        $transmissions = array_values(array_unique(array_filter(array_column($variations, 'transmission_type'), function($val) {
            return $val !== null && $val !== '';
        })));
        
        // Sort arrays
        sort($engineSizes);
        sort($fuelTanks);
        sort($driveTypes);
        sort($transmissions);
        
        // Log for debugging
        error_log("Model variations for ID {$modelId} (Make: {$model['make_id']}, Name: {$model['name']}): " . 
                  count($variations) . " variations found. Engine sizes: " . implode(', ', $engineSizes));
        
        sendSuccess([
            'variations' => $variations,
            'engine_sizes' => array_values($engineSizes),
            'fuel_tank_capacities' => array_values($fuelTanks),
            'drive_types' => array_values($driveTypes),
            'transmissions' => array_values($transmissions),
            'has_multiple' => count($variations) > 1,
            'model_name' => $model['name'],
            'make_id' => $model['make_id']
        ]);
    } catch (Exception $e) {
        error_log("getModelEngineVariations error: " . $e->getMessage());
        sendError('Failed to load engine variations', 500);
    }
}

// ============================================================================
// VIN DECODER FUNCTIONS - MOVED TO vin-decoder-api.php
// Functions getVinLookupData() and getNhtsaData() removed - see separate file
// Routing handled in proxy.php
// ============================================================================

/**
 * Get all active locations
 */
function getLocations($db) {
    try {
        // Try with is_active first, fallback to all if column doesn't exist
        try {
            $stmt = $db->query("SELECT id, name, region, district FROM locations WHERE is_active = 1 ORDER BY region ASC, name ASC");
        } catch (Exception $e) {
            // is_active column might not exist, get all locations
            $stmt = $db->query("SELECT id, name, region, district FROM locations ORDER BY region ASC, name ASC");
        }
        $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        sendSuccess(['locations' => $locations]);
    } catch (Exception $e) {
        error_log("getLocations error: " . $e->getMessage());
        error_log("getLocations trace: " . $e->getTraceAsString());
        sendError('Failed to load locations', 500);
    }
}

/**
 * Get car listings with filtering and pagination - FIXED FILTERS
 */
function getListings($db) {
    try {
        expireGuestListings($db);

        // Build filters - ONLY show approved listings
        $whereConditions = ["l.status = 'active'", "l.approval_status = 'approved'"];
        $params = [];
        
        // Search filter
        if (!empty($_GET['search'])) {
            $search = '%' . $_GET['search'] . '%';
            $whereConditions[] = "(l.title LIKE ? OR l.description LIKE ? OR m.name LIKE ? OR mo.name LIKE ?)";
            $params = array_merge($params, [$search, $search, $search, $search]);
        }
        
        // Category filter (body_type)
        if (!empty($_GET['category']) && $_GET['category'] !== 'all') {
            $whereConditions[] = "mo.body_type = ?";
            $params[] = $_GET['category'];
        }
        
        // Price range filters
        if (!empty($_GET['min_price']) && is_numeric($_GET['min_price'])) {
            $whereConditions[] = "l.price >= ?";
            $params[] = (float)$_GET['min_price'];
        }
        if (!empty($_GET['max_price']) && is_numeric($_GET['max_price'])) {
            $whereConditions[] = "l.price <= ?";
            $params[] = (float)$_GET['max_price'];
        }
        
        // Location filter - FIXED: Use location_id instead of name
        if (!empty($_GET['location'])) {
            if (is_numeric($_GET['location'])) {
                $whereConditions[] = "l.location_id = ?";
                $params[] = (int)$_GET['location'];
            } else {
                $whereConditions[] = "loc.name = ?";
                $params[] = $_GET['location'];
            }
        }
        
        // Make filter
        if (!empty($_GET['make']) && is_numeric($_GET['make'])) {
            $whereConditions[] = "l.make_id = ?";
            $params[] = (int)$_GET['make'];
        }
        
        // Model filter - FIXED: This was missing proper handling
        if (!empty($_GET['model']) && is_numeric($_GET['model'])) {
            $whereConditions[] = "l.model_id = ?";
            $params[] = (int)$_GET['model'];
        }
        
        // Year range filters - FIXED: Use exact year matching instead of range if single year provided
        if (!empty($_GET['year'])) {
            $whereConditions[] = "l.year = ?";
            $params[] = (int)$_GET['year'];
        } else {
            if (!empty($_GET['year_from']) && is_numeric($_GET['year_from'])) {
                $whereConditions[] = "l.year >= ?";
                $params[] = (int)$_GET['year_from'];
            }
            if (!empty($_GET['year_to']) && is_numeric($_GET['year_to'])) {
                $whereConditions[] = "l.year <= ?";
                $params[] = (int)$_GET['year_to'];
            }
        }
        
        // Transmission filter - NEW: Add transmission filtering
        if (!empty($_GET['transmission']) && $_GET['transmission'] !== 'all') {
            $whereConditions[] = "l.transmission = ?";
            $params[] = $_GET['transmission'];
        }
        
        // Fuel type filter - NEW: Add fuel type filtering
        if (!empty($_GET['fuel_type']) && $_GET['fuel_type'] !== 'all') {
            $whereConditions[] = "l.fuel_type = ?";
            $params[] = $_GET['fuel_type'];
        }
        
        // Condition filter - NEW: Add condition filtering
        if (!empty($_GET['condition']) && $_GET['condition'] !== 'all') {
            $whereConditions[] = "l.condition_type = ?";
            $params[] = $_GET['condition'];
        }
        
        // Seller type filter - filter by user_type (private seller vs dealer)
        if (!empty($_GET['seller_type']) && $_GET['seller_type'] !== 'all') {
            if ($_GET['seller_type'] === 'dealer') {
                $whereConditions[] = "EXISTS (SELECT 1 FROM users u WHERE u.id = l.user_id AND u.user_type = 'dealer')";
            } elseif ($_GET['seller_type'] === 'private') {
                $whereConditions[] = "EXISTS (SELECT 1 FROM users u WHERE u.id = l.user_id AND (u.user_type = 'individual' OR u.user_type IS NULL))";
            }
        }
        
        // Dealer filter - join with users to get dealer's user_id
        if (!empty($_GET['dealer_id']) && is_numeric($_GET['dealer_id'])) {
            $whereConditions[] = "EXISTS (SELECT 1 FROM users u WHERE u.id = l.user_id AND u.business_id = ? AND u.user_type = 'dealer')";
            $params[] = (int)$_GET['dealer_id'];
        }
        
        // Sorting
        $rawSort = strtolower(trim((string)($_GET['sort'] ?? 'newest')));
        $sortAliases = [
            'latest' => 'newest',
            'new' => 'newest',
            'newest' => 'newest',
            'old' => 'oldest',
            'oldest' => 'oldest',
            'price_low' => 'price_low',
            'price_high' => 'price_high',
            'year' => 'year_new',
            'year_desc' => 'year_new',
            'year_new' => 'year_new',
            'year_asc' => 'year_old',
            'year_old' => 'year_old',
            'views' => 'most_viewed',
            'most_views' => 'most_viewed',
            'most_viewed' => 'most_viewed'
        ];
        $sort = $sortAliases[$rawSort] ?? 'newest';

        $orderBy = match($sort) {
            'price_low' => 'l.price ASC',
            'price_high' => 'l.price DESC',
            'year_new' => 'l.year DESC',
            'year_old' => 'l.year ASC',
            'most_viewed' => 'l.views_count DESC',
            'oldest' => 'l.listing_type DESC, l.created_at ASC',
            default => 'l.listing_type DESC, l.created_at DESC'
        };
        
        // Pagination
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        
        $whereClause = implode(' AND ', $whereConditions);
        
        // Optionally backfill schema only when runtime schema updates are explicitly enabled.
        applyRuntimeSchemaChange($db, "ALTER TABLE car_listings ADD COLUMN is_featured TINYINT(1) DEFAULT 0");
        applyRuntimeSchemaChange($db, "ALTER TABLE car_listings ADD COLUMN is_premium TINYINT(1) DEFAULT 0");
        applyRuntimeSchemaChange($db, "ALTER TABLE car_listings ADD COLUMN premium_until DATETIME DEFAULT NULL");

        // Get listings - FIXED: Improved query with better field selection
        $sql = "
            SELECT
                l.id, l.title, l.price, l.year, l.mileage, l.fuel_type, l.transmission,
                l.condition_type, l.created_at, l.views_count, l.listing_type, l.negotiable,
                l.exterior_color, l.interior_color, l.engine_size, l.doors, l.seats, l.drivetrain,
                COALESCE(l.is_featured, 0) as is_featured,
                COALESCE(l.is_premium, 0) as is_premium,
                l.premium_until,
                m.name as make_name, mo.name as model_name, mo.body_type,
                loc.name as location_name, loc.region,
                u.user_type as seller_type,
                l.featured_image_id,
                (SELECT COUNT(*) FROM car_listing_images WHERE listing_id = l.id) as image_count,
                COALESCE(
                    (SELECT filename FROM car_listing_images WHERE id = l.featured_image_id),
                    (SELECT filename FROM car_listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1),
                    (SELECT filename FROM car_listing_images WHERE listing_id = l.id LIMIT 1)
                ) as featured_image
            FROM car_listings l
            INNER JOIN car_makes m ON l.make_id = m.id
            INNER JOIN car_models mo ON l.model_id = mo.id
            INNER JOIN locations loc ON l.location_id = loc.id
            LEFT JOIN users u ON l.user_id = u.id
            WHERE {$whereClause}
            ORDER BY COALESCE(l.is_premium, 0) DESC, COALESCE(l.is_featured, 0) DESC, {$orderBy}
            LIMIT ? OFFSET ?
        ";
        
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $listings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get total count for pagination
        $countSql = "SELECT COUNT(*) FROM car_listings l 
                     INNER JOIN car_makes m ON l.make_id = m.id
                     INNER JOIN car_models mo ON l.model_id = mo.id
                     INNER JOIN locations loc ON l.location_id = loc.id
                     LEFT JOIN users u ON l.user_id = u.id
                     WHERE {$whereClause}";
        $countParams = array_slice($params, 0, -2);
        $countStmt = $db->prepare($countSql);
        $countStmt->execute($countParams);
        $total = (int)$countStmt->fetchColumn();
        
        sendSuccess([
            'listings' => $listings,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => ceil($total / $limit),
                'has_more' => ($page * $limit) < $total
            ],
            'filters_applied' => [
                'where_conditions' => $whereConditions,
                'params_count' => count($params) - 2 // Exclude limit and offset
            ]
        ]);
    } catch (Exception $e) {
        error_log("getListings error: " . $e->getMessage());
        sendError('Failed to load listings: ' . $e->getMessage(), 500);
    }
}

/**
 * Get single listing details
 */
function getListing($db) {
    $id = $_GET['id'] ?? '';
    if (empty($id) || !is_numeric($id)) {
        sendError('Valid listing ID required', 400);
    }
    
    try {
        // Basic query - get listing with essential info
        $stmt = $db->prepare("
            SELECT l.*, m.name as make_name, mo.name as model_name, mo.body_type,
                   loc.name as location_name, loc.region,
                   u.full_name as seller_name, u.phone as seller_phone,
                   u.email as seller_email, u.user_type as seller_type,
                   u.business_id, u.business_name as user_business_name
            FROM car_listings l
            INNER JOIN car_makes m ON l.make_id = m.id
            INNER JOIN car_models mo ON l.model_id = mo.id
            INNER JOIN locations loc ON l.location_id = loc.id
            LEFT JOIN users u ON l.user_id = u.id
            WHERE l.id = ? AND l.status = 'active' AND l.approval_status = 'approved'
        ");
        $stmt->execute([$id]);
        $listing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$listing) {
            sendError('Listing not found', 404);
        }

        // Try to get business info if user has business_id
        $listing['business_name'] = null;
        $listing['dealer_id'] = null;

        if (!empty($listing['business_id']) && !empty($listing['seller_type'])) {
            try {
                if ($listing['seller_type'] === 'dealer') {
                    $bizStmt = $db->prepare("SELECT id, business_name FROM car_dealers WHERE id = ?");
                    $bizStmt->execute([$listing['business_id']]);
                    $biz = $bizStmt->fetch(PDO::FETCH_ASSOC);
                    if ($biz) {
                        $listing['business_name'] = $biz['business_name'];
                        $listing['dealer_id'] = $biz['id'];
                    }
                } elseif ($listing['seller_type'] === 'garage') {
                    $bizStmt = $db->prepare("SELECT id, business_name FROM garages WHERE id = ?");
                    $bizStmt->execute([$listing['business_id']]);
                    $biz = $bizStmt->fetch(PDO::FETCH_ASSOC);
                    if ($biz) {
                        $listing['business_name'] = $biz['business_name'];
                    }
                } elseif ($listing['seller_type'] === 'car_hire') {
                    $bizStmt = $db->prepare("SELECT id, business_name FROM car_hire_companies WHERE id = ?");
                    $bizStmt->execute([$listing['business_id']]);
                    $biz = $bizStmt->fetch(PDO::FETCH_ASSOC);
                    if ($biz) {
                        $listing['business_name'] = $biz['business_name'];
                    }
                }
            } catch (Exception $bizError) {
                // If business lookup fails, continue without it
                error_log("Business lookup error: " . $bizError->getMessage());
            }
        }

        // Fallback to user_business_name if no business table data
        if (empty($listing['business_name']) && !empty($listing['user_business_name'])) {
            $listing['business_name'] = $listing['user_business_name'];
        }

        // Get images
        $imageStmt = $db->prepare("SELECT id, filename, is_primary, sort_order FROM car_listing_images WHERE listing_id = ? ORDER BY is_primary DESC, sort_order ASC");
        $imageStmt->execute([$id]);
        $listing['images'] = $imageStmt->fetchAll(PDO::FETCH_ASSOC);

        // Increment view count only once per session
        if (!isset($_SESSION['viewed_listings'])) {
            $_SESSION['viewed_listings'] = [];
        }

        if (!in_array($id, $_SESSION['viewed_listings'])) {
            $updateStmt = $db->prepare("UPDATE car_listings SET views_count = views_count + 1 WHERE id = ?");
            $updateStmt->execute([$id]);
            $_SESSION['viewed_listings'][] = $id;
        }

        // Use guest info if guest listing
        if ($listing['is_guest'] == 1) {
            $listing['contact_name'] = $listing['guest_seller_name'];
            $listing['contact_phone'] = $listing['guest_seller_phone'];
            $listing['contact_email'] = $listing['guest_seller_email'];
        } else {
            $listing['contact_name'] = $listing['seller_name'];
            $listing['contact_phone'] = $listing['seller_phone'];
            $listing['contact_email'] = $listing['seller_email'];
        }

        sendSuccess(['listing' => $listing]);
    } catch (Exception $e) {
        error_log("getListing error: " . $e->getMessage());
        error_log("getListing trace: " . $e->getTraceAsString());
        sendError('Failed to load listing: ' . $e->getMessage(), 500);
    }
}

/**
 * Get other listings from the same dealer/seller
 */
function getDealerOtherListings($db) {
    $userId = $_GET['user_id'] ?? '';
    $currentListingId = $_GET['current_listing_id'] ?? '';
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int)$_GET['limit'] : 6;

    if (empty($userId) || !is_numeric($userId)) {
        sendError('Valid user ID required', 400);
    }

    try {
        // Get other active, approved listings from the same user
        $stmt = $db->prepare("
            SELECT l.id, l.title, l.price, l.year, l.mileage, l.fuel_type, l.transmission,
                   l.created_at, l.listing_type, l.status,
                   m.name as make_name, mo.name as model_name, mo.body_type,
                   loc.name as location_name,
                   (SELECT id FROM car_listing_images WHERE listing_id = l.id ORDER BY is_primary DESC, sort_order ASC LIMIT 1) as featured_image_id
            FROM car_listings l
            INNER JOIN car_makes m ON l.make_id = m.id
            INNER JOIN car_models mo ON l.model_id = mo.id
            INNER JOIN locations loc ON l.location_id = loc.id
            WHERE l.user_id = ?
              AND l.id != ?
              AND l.status = 'active'
              AND l.approval_status = 'approved'
            ORDER BY l.listing_type DESC, l.created_at DESC
            LIMIT ?
        ");

        $stmt->execute([$userId, $currentListingId, $limit]);
        $listings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        sendSuccess(['listings' => $listings, 'total' => count($listings)]);
    } catch (Exception $e) {
        error_log("getDealerOtherListings error: " . $e->getMessage());
        sendSuccess(['listings' => [], 'total' => 0]);
    }
}

/**
 * Get images for a listing (for edit modal)
 */
function getListingImages($db) {
    $listingId = $_GET['listing_id'] ?? '';

    if (empty($listingId) || !is_numeric($listingId)) {
        sendError('Valid listing ID required', 400);
    }

    try {
        $viewer = getCurrentUser(false);

        // Public can only view active+approved listing images.
        // Owner/admin can view all images for their listing (including pending states).
        $listingStmt = $db->prepare("SELECT id, user_id, status, approval_status FROM car_listings WHERE id = ? LIMIT 1");
        $listingStmt->execute([$listingId]);
        $listing = $listingStmt->fetch(PDO::FETCH_ASSOC);

        if (!$listing) {
            sendError('Listing not found', 404);
        }

        $isOwnerOrAdmin = $viewer && (($listing['user_id'] == $viewer['id']) || ($viewer['type'] === 'admin'));
        $isPubliclyVisible = ($listing['status'] === 'active' && $listing['approval_status'] === 'approved');

        $guestSellerEmail = trim($_GET['guest_seller_email'] ?? '');
        $isVerifiedGuestViewer = !$viewer
            && ((int)($listing['user_id'] ?? 0) === 0)
            && !empty($guestSellerEmail)
            && isset($listing['id'])
            && ($listing['approval_status'] === 'pending')
            && ($listing['status'] === 'pending_approval');

        if ($isVerifiedGuestViewer) {
            $emailStmt = $db->prepare("SELECT guest_seller_email, is_guest FROM car_listings WHERE id = ? LIMIT 1");
            $emailStmt->execute([$listingId]);
            $guestListing = $emailStmt->fetch(PDO::FETCH_ASSOC);
            $isVerifiedGuestViewer = $guestListing
                && (int)$guestListing['is_guest'] === 1
                && !empty($guestListing['guest_seller_email'])
                && strcasecmp($guestSellerEmail, $guestListing['guest_seller_email']) === 0;
        }

        if (!$isOwnerOrAdmin && !$isPubliclyVisible && !$isVerifiedGuestViewer) {
            sendError('Listing images are not available', 403);
        }

        // Get images with is_featured instead of is_primary
        $stmt = $db->prepare("
            SELECT id, filename, is_primary as is_featured, sort_order, file_path
            FROM car_listing_images
            WHERE listing_id = ?
            ORDER BY is_primary DESC, sort_order ASC
        ");
        $stmt->execute([$listingId]);
        $images = $stmt->fetchAll(PDO::FETCH_ASSOC);

        sendSuccess(['images' => $images]);
    } catch (Exception $e) {
        error_log("getListingImages error: " . $e->getMessage());
        sendError('Failed to load images', 500);
    }
}

/**
 * Set featured image for a listing
 */
function setFeaturedImage($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('POST method required', 405);
    }

    $user = getCurrentUser();
    $input = json_decode(file_get_contents('php://input'), true);

    $listingId = $input['listing_id'] ?? '';
    $imageId = $input['image_id'] ?? '';

    if (empty($listingId) || !is_numeric($listingId) || empty($imageId) || !is_numeric($imageId)) {
        sendError('Valid listing ID and image ID required', 400);
    }

    try {
        // Verify ownership
        $stmt = $db->prepare("SELECT user_id FROM car_listings WHERE id = ?");
        $stmt->execute([$listingId]);
        $listing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$listing || ($listing['user_id'] != $user['id'] && $user['type'] !== 'admin')) {
            sendError('Access denied', 403);
        }

        // Unset all is_primary for this listing
        $stmt = $db->prepare("UPDATE car_listing_images SET is_primary = 0 WHERE listing_id = ?");
        $stmt->execute([$listingId]);

        // Set the new featured image
        $stmt = $db->prepare("UPDATE car_listing_images SET is_primary = 1 WHERE id = ? AND listing_id = ?");
        $stmt->execute([$imageId, $listingId]);

        // Update listing's featured_image_id
        $stmt = $db->prepare("UPDATE car_listings SET featured_image_id = ? WHERE id = ?");
        $stmt->execute([$imageId, $listingId]);

        sendSuccess(['message' => 'Featured image updated successfully']);
    } catch (Exception $e) {
        error_log("setFeaturedImage error: " . $e->getMessage());
        sendError('Failed to set featured image', 500);
    }
}

/**
 * Delete a listing image
 */
function deleteListingImage($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('POST method required', 405);
    }

    $user = getCurrentUser();
    $input = json_decode(file_get_contents('php://input'), true);

    $imageId = $input['image_id'] ?? '';

    if (empty($imageId) || !is_numeric($imageId)) {
        sendError('Valid image ID required', 400);
    }

    try {
        // Get image and verify ownership
        $stmt = $db->prepare("
            SELECT cli.*, cl.user_id
            FROM car_listing_images cli
            INNER JOIN car_listings cl ON cli.listing_id = cl.id
            WHERE cli.id = ?
        ");
        $stmt->execute([$imageId]);
        $image = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$image || ($image['user_id'] != $user['id'] && $user['type'] !== 'admin')) {
            sendError('Access denied', 403);
        }

        // Delete the image file if it exists
        if (!empty($image['file_path']) && file_exists($image['file_path'])) {
            unlink($image['file_path']);
        } elseif (!empty($image['filename'])) {
            $filePath = __DIR__ . '/uploads/' . $image['filename'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }

        // Delete from database
        $stmt = $db->prepare("DELETE FROM car_listing_images WHERE id = ?");
        $stmt->execute([$imageId]);

        sendSuccess(['message' => 'Image deleted successfully']);
    } catch (Exception $e) {
        error_log("deleteListingImage error: " . $e->getMessage());
        sendError('Failed to delete image', 500);
    }
}

/**
 * Get garage listings with filtering
 */
function getGarages($db) {
    try {
        // Build the query with filters
        $whereConditions = ["g.status = 'active'"];
        $params = [];
        
        // District filter
        if (!empty($_GET['district'])) {
            $whereConditions[] = "loc.district = ?";
            $params[] = $_GET['district'];
        }
        
        // Specialization filter
        if (!empty($_GET['specialization'])) {
            $whereConditions[] = "JSON_CONTAINS(g.specialization, ?)";
            $params[] = json_encode($_GET['specialization']);
        }
        
        // Service filter
        if (!empty($_GET['service'])) {
            $whereConditions[] = "JSON_CONTAINS(g.services, ?)";
            $params[] = json_encode($_GET['service']);
        }
        
        // Emergency services filter
        if (!empty($_GET['emergency'])) {
            $whereConditions[] = "JSON_CONTAINS(g.emergency_services, ?)";
            $params[] = json_encode($_GET['emergency']);
        }
        
        // Car brand filter
        if (!empty($_GET['car_brand'])) {
            $whereConditions[] = "JSON_CONTAINS(g.specializes_in_cars, ?)";
            $params[] = json_encode($_GET['car_brand']);
        }
        
        $whereClause = implode(' AND ', $whereConditions);
        
        $sql = "
            SELECT g.*, loc.name as location_name, loc.region, loc.district
            FROM garages g
            INNER JOIN locations loc ON g.location_id = loc.id
            WHERE {$whereClause}
            ORDER BY g.featured DESC, g.certified DESC, g.verified DESC, g.name ASC
            LIMIT 50
        ";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $garages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format the response
        $formattedGarages = array_map(function($garage) {
            return [
                'id' => $garage['id'],
                'name' => $garage['name'],
                'owner_name' => $garage['owner_name'],
                'email' => $garage['email'],
                'phone' => $garage['phone'],
                'recovery_number' => $garage['recovery_number'],
                'whatsapp' => $garage['whatsapp'],
                'address' => $garage['address'],
                'location' => [
                    'id' => $garage['location_id'],
                    'name' => $garage['location_name'],
                    'region' => $garage['region'],
                    'district' => $garage['district']
                ],
                'services' => $garage['services'] ? json_decode($garage['services'], true) : [],
                'emergency_services' => $garage['emergency_services'] ? json_decode($garage['emergency_services'], true) : [],
                'specialization' => $garage['specialization'] ? json_decode($garage['specialization'], true) : [],
                'specializes_in_cars' => $garage['specializes_in_cars'] ? json_decode($garage['specializes_in_cars'], true) : [],
                'years_experience' => $garage['years_experience'],
                'facebook_url' => $garage['facebook_url'] ?? null,
                'instagram_url' => $garage['instagram_url'] ?? null,
                'twitter_url' => $garage['twitter_url'] ?? null,
                'linkedin_url' => $garage['linkedin_url'] ?? null,
                'operating_hours' => $garage['operating_hours'],
                'business_hours' => $garage['business_hours'],
                'website' => $garage['website'],
                'description' => $garage['description'],
                'verified' => (bool)$garage['verified'],
                'certified' => (bool)$garage['certified'],
                'featured' => (bool)$garage['featured'],
                'status' => $garage['status'],
                'created_at' => $garage['created_at'],
                'updated_at' => $garage['updated_at']
            ];
        }, $garages);
        
        sendSuccess(['garages' => $formattedGarages]);
    } catch (Exception $e) {
        error_log("getGarages error: " . $e->getMessage());
        sendSuccess(['garages' => []]);
    }
}

/**
 * Get car dealers list
 */
function getDealers($db) {
    try {
        $stmt = $db->query("
            SELECT d.*, loc.name as location_name, loc.region,
                   (SELECT COUNT(*) 
                    FROM car_listings cl 
                    INNER JOIN users u ON cl.user_id = u.id 
                    WHERE u.business_id = d.id AND u.user_type = 'dealer' 
                    AND cl.status = 'active' AND cl.approval_status = 'approved') as total_cars
            FROM car_dealers d
            INNER JOIN locations loc ON d.location_id = loc.id
            WHERE d.status = 'active'
            ORDER BY d.featured DESC, d.certified DESC, d.verified DESC, d.business_name ASC
            LIMIT 50
        ");
        $dealers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        sendSuccess(['dealers' => $dealers]);
    } catch (Exception $e) {
        error_log("getDealers error: " . $e->getMessage());
        sendSuccess(['dealers' => []]);
    }
}

/**
 * Get dealer showroom with their cars
 */
function getDealerShowroom($db) {
    $dealerId = $_GET['dealer_id'] ?? '';
    if (empty($dealerId) || !is_numeric($dealerId)) {
        sendError('Valid dealer ID required', 400);
    }
    
    try {
        // Get dealer info and associated user_id
        $stmt = $db->prepare("
            SELECT d.*, loc.name as location_name, loc.region, u.id as user_id
            FROM car_dealers d
            INNER JOIN locations loc ON d.location_id = loc.id
            LEFT JOIN users u ON u.business_id = d.id AND u.user_type = 'dealer'
            WHERE d.id = ? AND d.status = 'active'
        ");
        $stmt->execute([$dealerId]);
        $dealer = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$dealer) {
            sendError('Dealer not found', 404);
        }
        
        // Get dealer's car listings (approved only) using user_id
        $stmt = $db->prepare("
            SELECT l.id, l.title, l.price, l.year, l.mileage, l.fuel_type, l.transmission,
                   l.created_at, l.views_count, l.listing_type, l.negotiable,
                   m.name as make_name, mo.name as model_name, mo.body_type,
                   (SELECT filename FROM car_listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) as primary_image
            FROM car_listings l
            INNER JOIN car_makes m ON l.make_id = m.id
            INNER JOIN car_models mo ON l.model_id = mo.id
            WHERE l.user_id = ? AND l.status = 'active' AND l.approval_status = 'approved'
            ORDER BY l.listing_type DESC, l.created_at DESC
        ");
        $stmt->execute([$dealer['user_id']]);
        $cars = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $uploadBase = rtrim(getRuntimeBaseUrl(), '/') . '/uploads/';
        foreach ($cars as &$car) {
            $car['image_url'] = !empty($car['primary_image']) ? ($uploadBase . ltrim($car['primary_image'], '/')) : null;
        }
        unset($car);
        
        sendSuccess([
            'dealer' => $dealer,
            'cars' => $cars
        ]);
    } catch (Exception $e) {
        error_log("getDealerShowroom error: " . $e->getMessage());
        sendError('Failed to load showroom', 500);
    }
}

/**
 * Get car hire companies
 */
function getCarHire($db) {
    try {
        $stmt = $db->query("
            SELECT c.*, loc.name as location_name, loc.region
            FROM car_hire_companies c
            INNER JOIN locations loc ON c.location_id = loc.id
            WHERE c.status = 'active'
            ORDER BY c.featured DESC, c.certified DESC, c.verified DESC, c.business_name ASC
            LIMIT 50
        ");
        $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
        sendSuccess(['car_hire' => $companies]);
    } catch (Exception $e) {
        error_log("getCarHire error: " . $e->getMessage());
        sendSuccess(['car_hire' => []]);
    }
}

/**
 * Get car hire companies with fleet summary and vehicle details
 */
function getCarHireCompaniesWithFleet($db) {
    try {
        $stmt = $db->query("
            SELECT c.*, loc.name as location_name, loc.region,
                   COUNT(f.id) as total_vehicles,
                   MIN(f.daily_rate) as daily_rate_from,
                   MAX(f.daily_rate) as daily_rate_to,
                   MIN(f.weekly_rate) as weekly_rate_from,
                   MAX(f.weekly_rate) as weekly_rate_to
            FROM car_hire_companies c
            INNER JOIN locations loc ON c.location_id = loc.id
            LEFT JOIN car_hire_fleet f ON c.id = f.company_id AND f.is_active = 1
            WHERE c.status = 'active'
            GROUP BY c.id
            ORDER BY c.featured DESC, c.certified DESC, c.verified DESC, c.business_name ASC
        ");
        $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch fleet details for each company to enable filtering by vehicle attributes
        foreach ($companies as &$company) {
            $fleetStmt = $db->prepare("
                SELECT id, transmission, fuel_type, seats, year, daily_rate,
                       status, make_name, model_name
                FROM car_hire_fleet
                WHERE company_id = ? AND is_active = 1
                ORDER BY daily_rate ASC
            ");
            $fleetStmt->execute([$company['id']]);
            $company['fleet'] = $fleetStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        sendSuccess(['companies' => $companies]);
    } catch (Exception $e) {
        error_log("getCarHireCompaniesWithFleet error: " . $e->getMessage());
        sendSuccess(['companies' => []]);
    }
}

/**
 * Get single car hire company details
 */
function getCarHireCompany($db) {
    $id = $_GET['id'] ?? '';
    if (empty($id) || !is_numeric($id)) {
        sendError('Valid company ID required', 400);
    }
    
    try {
        $stmt = $db->prepare("
            SELECT c.*, loc.name as location_name, loc.region,
                   COUNT(f.id) as total_vehicles
            FROM car_hire_companies c
            INNER JOIN locations loc ON c.location_id = loc.id
            LEFT JOIN car_hire_fleet f ON c.id = f.company_id AND f.is_active = 1
            WHERE c.id = ? AND c.status = 'active'
            GROUP BY c.id
        ");
        $stmt->execute([$id]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$company) {
            sendError('Company not found', 404);
        }
        
        sendSuccess(['company' => $company]);
    } catch (Exception $e) {
        error_log("getCarHireCompany error: " . $e->getMessage());
        sendError('Failed to load company', 500);
    }
}

/**
 * Get car hire fleet for a company
 */
function getCarHireFleet($db) {
    $companyId = $_GET['company_id'] ?? '';
    if (empty($companyId) || !is_numeric($companyId)) {
        sendError('Valid company ID required', 400);
    }
    
    try {
        $stmt = $db->prepare("
            SELECT f.*, m.name as make_name, mo.name as model_name, mo.body_type,
                   CASE 
                       WHEN f.status = 'available' THEN 1
                       ELSE 0
                   END as is_available
            FROM car_hire_fleet f
            INNER JOIN car_makes m ON f.make_id = m.id
            INNER JOIN car_models mo ON f.model_id = mo.id
            WHERE f.company_id = ? AND f.is_active = 1
            ORDER BY f.daily_rate ASC
        ");
        $stmt->execute([$companyId]);
        $fleet = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        sendSuccess(['fleet' => $fleet]);
    } catch (Exception $e) {
        error_log("getCarHireFleet error: " . $e->getMessage());
        sendSuccess(['fleet' => []]);
    }
}

/**
 * Get car hire statistics
 */
function getCarHireStats($db) {
    try {
        $stats = [];

        // Count active car hire companies
        $stmt = $db->query("SELECT COUNT(*) FROM car_hire_companies WHERE status = 'active'");
        $stats['total_companies'] = (int)$stmt->fetchColumn();

        // Count total vehicles from car_hire_fleet for active companies
        $stmt = $db->query("
            SELECT COUNT(*)
            FROM car_hire_fleet f
            INNER JOIN car_hire_companies c ON f.company_id = c.id
            WHERE c.status = 'active' AND f.is_active = 1
        ");
        $stats['total_vehicles'] = (int)$stmt->fetchColumn();

        // Count distinct cities/locations with active car hire companies
        $stmt = $db->query("
            SELECT COUNT(DISTINCT c.location_id)
            FROM car_hire_companies c
            WHERE c.status = 'active'
        ");
        $stats['total_cities'] = (int)$stmt->fetchColumn();

        // Count featured car hire companies
        $stmt = $db->query("
            SELECT COUNT(*)
            FROM car_hire_companies
            WHERE status = 'active' AND featured = 1
        ");
        $stats['featured_companies'] = (int)$stmt->fetchColumn();

        sendSuccess(['stats' => $stats]);
    } catch (Exception $e) {
        error_log("getCarHireStats error: " . $e->getMessage());
        // Return default stats on error
        sendSuccess(['stats' => [
            'total_companies' => 0,
            'total_vehicles' => 0,
            'total_cities' => 0,
            'featured_companies' => 0
        ]]);
    }
}

/**
 * Get locations that have active car hire companies
 */
function getCarHireLocations($db) {
    try {
        $stmt = $db->query("
            SELECT DISTINCT loc.id, loc.name, loc.region, loc.district
            FROM locations loc
            INNER JOIN car_hire_companies chc ON chc.location_id = loc.id
            WHERE chc.status = 'active'
            ORDER BY loc.name ASC
        ");
        $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        sendSuccess(['locations' => $locations]);
    } catch (Exception $e) {
        error_log("getCarHireLocations error: " . $e->getMessage());
        sendSuccess(['locations' => []]);
    }
}

/**
 * Serve car images
 */
function serveImage($db) {
    $imageId = $_GET['id'] ?? 0;
    
    if (empty($imageId) || !is_numeric($imageId)) {
        http_response_code(400);
        exit('Invalid image ID');
    }
    
    try {
        // Get image data from car_listing_images table
        $stmt = $db->prepare("SELECT filename, file_path, mime_type FROM car_listing_images WHERE id = ?");
        $stmt->execute([$imageId]);
        $image = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($image) {
            // If we have a file_path, use it
            if (!empty($image['file_path']) && file_exists($image['file_path'])) {
                header('Content-Type: ' . $image['mime_type']);
                readfile($image['file_path']);
                exit;
            }

            // If we have a filename, look in uploads directory
            elseif (!empty($image['filename'])) {
                $uploadPath = __DIR__ . '/uploads/' . $image['filename'];
                if (file_exists($uploadPath)) {
                    header('Content-Type: ' . ($image['mime_type'] ?? 'image/jpeg'));
                    readfile($uploadPath);
                    exit;
                }
            }
        }
        
        // If image not found, serve placeholder
        servePlaceholderImage();
        
    } catch (Exception $e) {
        error_log("Image serving error: " . $e->getMessage());
        servePlaceholderImage();
    }
}

// ============================================================================
// AUTHENTICATION HANDLERS
// ============================================================================

/**
 * Check authentication status
 */
function checkAuth() {
    // Check for session timeout (30 minutes of inactivity)
    $sessionTimeout = 1800; // 30 minutes in seconds

    if (isLoggedIn()) {
        // Check for session timeout
        if (isset($_SESSION['last_activity'])) {
            $inactiveTime = time() - $_SESSION['last_activity'];

            if ($inactiveTime > $sessionTimeout) {
                // Session has expired
                session_unset();
                session_destroy();

                sendSuccess([
                    'authenticated' => false,
                    'message' => 'Session expired due to inactivity'
                ]);
                return;
            }
        }

        // Update last activity time
        $_SESSION['last_activity'] = time();

        sendSuccess(['authenticated' => true, 'user' => getCurrentUser()]);
    } else {
        sendSuccess(['authenticated' => false]);
    }
}

/**
 * Handle user login
 */
function handleLogin($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('POST method required', 405);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $password = $input['password'] ?? '';

    $clientIp = getClientIpAddress();
    $loginRateMaxAttempts = (int)(getenv('MOTORLINK_LOGIN_RATE_MAX_ATTEMPTS') ?: 8);
    $loginRateWindowSeconds = (int)(getenv('MOTORLINK_LOGIN_RATE_WINDOW_SECONDS') ?: 900);
    $loginRateBlockSeconds = (int)(getenv('MOTORLINK_LOGIN_RATE_BLOCK_SECONDS') ?: 900);
    $loginIdentifierHash = hash('sha256', $email . '|' . $clientIp);

    if (isRateLimited($db, 'login', $loginIdentifierHash)) {
        sendErrorWithCode('Too many login attempts. Please try again later.', 'RATE_LIMITED', 429);
    }
    
    if (empty($email) || empty($password)) {
        sendError('Email and password required', 400);
    }
    
    try {
        // First, try to find user in the admin_users table
        $stmt = $db->prepare("SELECT * FROM admin_users WHERE email = ? AND status = 'active'");
        $stmt->execute([$email]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($admin && password_verify($password, $admin['password_hash'])) {
            // Admin user found - set both regular and admin sessions
            setAdminSession($admin);
            clearRateLimitState($db, 'login', $loginIdentifierHash);
            
            // Update last login for admin
            $updateStmt = $db->prepare("UPDATE admin_users SET last_login = NOW() WHERE id = ?");
            $updateStmt->execute([$admin['id']]);
            
            sendSuccess([
                'message' => 'Admin login successful',
                'user' => [
                    'id' => $admin['id'],
                    'name' => $admin['full_name'],
                    'email' => $admin['email'],
                    'type' => 'admin',
                    'role' => $admin['role'] ?? 'admin'
                ]
            ]);
            return;
        }
        
        // If not found in admin_users, check regular users table
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && password_verify($password, $user['password_hash'])) {
            // Check if email is verified
            if (!$user['email_verified']) {
                recordRateLimitFailure($db, 'login', $loginIdentifierHash, $loginRateMaxAttempts, $loginRateWindowSeconds, $loginRateBlockSeconds);
                sendError('Please verify your email address before logging in. Check your inbox for the verification link.', 403);
                return;
            }
            
            // Check if account is suspended or banned
            if ($user['status'] === 'suspended' || $user['status'] === 'banned') {
                recordRateLimitFailure($db, 'login', $loginIdentifierHash, $loginRateMaxAttempts, $loginRateWindowSeconds, $loginRateBlockSeconds);
                sendError('Your account has been ' . $user['status'] . '. Please contact support for assistance.', 403);
                return;
            }
            
            // Only allow active users to login
            if ($user['status'] !== 'active') {
                recordRateLimitFailure($db, 'login', $loginIdentifierHash, $loginRateMaxAttempts, $loginRateWindowSeconds, $loginRateBlockSeconds);
                sendError('Your account is not active. Please contact support for assistance.', 403);
                return;
            }
            
            setUserSession($user);
            clearRateLimitState($db, 'login', $loginIdentifierHash);
            
            sendSuccess([
                'message' => 'Login successful',
                'user' => [
                    'id' => $user['id'],
                    'name' => $user['full_name'],
                    'email' => $user['email'],
                    'type' => $user['user_type']
                ]
            ]);
        } else {
            recordRateLimitFailure($db, 'login', $loginIdentifierHash, $loginRateMaxAttempts, $loginRateWindowSeconds, $loginRateBlockSeconds);
            sendError('Invalid credentials', 401);
        }
    } catch (Exception $e) {
        error_log("Login error: " . $e->getMessage());
        sendError('Login failed', 500);
    }
}

/**
 * Handle user registration
 */
function handleRegister($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('POST method required', 405);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $required = ['full_name', 'username', 'email', 'password', 'phone', 'city', 'user_type'];
    
    foreach ($required as $field) {
        if (empty(trim($input[$field] ?? ''))) {
            sendError("Field '$field' is required", 400);
        }
    }

    $input['email'] = strtolower(trim($input['email'] ?? ''));
    $input['username'] = trim($input['username'] ?? '');

    if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
        sendError('Please enter a valid email address', 400);
    }
    
    try {
        // Check existing email first (case-insensitive).
        $stmt = $db->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
        $stmt->execute([$input['email']]);
        if ($stmt->fetch()) {
            sendError('This email is already registered. Please use login or password reset.', 409);
        }

        // Prevent conflicts with admin accounts too.
        $stmt = $db->prepare("SELECT id FROM admin_users WHERE LOWER(email) = LOWER(?) LIMIT 1");
        $stmt->execute([$input['email']]);
        if ($stmt->fetch()) {
            sendError('This email is already registered. Please use login or password reset.', 409);
        }

        // Check existing username (case-insensitive).
        $stmt = $db->prepare("SELECT id FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1");
        $stmt->execute([$input['username']]);
        if ($stmt->fetch()) {
            sendError('Username already exists', 409);
        }
        
        // Generate verification token
        $verificationToken = bin2hex(random_bytes(32));
        
        // Start transaction to ensure atomicity
        $db->beginTransaction();
        
        try {
            // Create user with pending status
            $stmt = $db->prepare("
                INSERT INTO users (username, email, password_hash, full_name, phone, whatsapp,
                                  city, address, user_type, business_name, status, verification_token, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW())
            ");
            
            $stmt->execute([
                $input['username'],
                $input['email'],
                password_hash($input['password'], PASSWORD_DEFAULT),
                $input['full_name'],
                $input['phone'],
                $input['whatsapp'] ?? null,
                $input['city'],
                $input['address'] ?? null,
                $input['user_type'],
                $input['business_name'] ?? null,
                $verificationToken
            ]);
            
            $userId = $db->lastInsertId();
            
            // Verify user was actually created
            if (!$userId || $userId <= 0) {
                throw new Exception('Failed to get user ID after insert. User may not have been created.');
            }
            
            // Verify the user exists in database
            $verifyStmt = $db->prepare("SELECT id, username, email FROM users WHERE id = ?");
            $verifyStmt->execute([$userId]);
            $verifiedUser = $verifyStmt->fetch();
            
            if (!$verifiedUser) {
                throw new Exception('User was not found in database after insert. Registration failed.');
            }
            
            // Commit transaction - user is now in database
            $db->commit();
            
            // Log successful user creation
            error_log("User created successfully: ID=$userId, Email={$input['email']}, Username={$input['username']}");
            
            // Note: AI chat usage tracking is automatically handled by logAIChatUsage() 
            // in ai-car-chat-api.php when the user first uses the AI chat feature.
            // No initialization needed - tracking starts on first AI chat interaction.
            
        } catch (Exception $e) {
            // Rollback transaction on any error
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("Registration transaction error: " . $e->getMessage());
            throw $e; // Re-throw to be caught by outer catch
        }
        
        // Generate verification link for potential fallback
        $serverHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        $isProduction = (strpos($serverHost, 'promanaged-it.com') !== false);
        if ($isProduction) {
            $baseUrl = 'https://promanaged-it.com/motorlink/';
        } else {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            
            // HTTP_HOST already includes port if present (e.g., "127.0.0.1:8000")
            // So we don't need to add port again
            $baseUrl = $protocol . '://' . $serverHost . '/';
        }
        $verificationLink = $baseUrl . "verify-email.php?token=" . urlencode($verificationToken) . "&id=" . urlencode($userId);
        
        // Send verification email (outside transaction - email failure shouldn't rollback user creation)
        $emailSent = sendVerificationEmail($db, $input['email'], $input['full_name'], $verificationToken, $userId);
        
        // Log email status
        if (!$emailSent) {
            error_log("WARNING: Verification email may not have been sent to: " . $input['email']);
        }
        
        // Do NOT auto-login - user must verify email first
        $responseMessage = 'Registration submitted successfully. ';
        if ($emailSent) {
            $responseMessage .= 'Please check your email (and spam folder) for the verification link. Once verified, your account will be activated and you\'ll be automatically logged in.';
        } else {
            $responseMessage .= 'A verification email has been queued. If you don\'t receive it within a few minutes, please contact support.';
        }
        
        sendSuccess([
            'message' => $responseMessage,
            'email_sent' => $emailSent,
            'verification_link' => $emailSent ? null : $verificationLink, // Include link if email failed (for testing)
            'user' => [
                'id' => $userId,
                'name' => $input['full_name'],
                'email' => $input['email'],
                'type' => $input['user_type'],
                'status' => 'pending'
            ]
        ]);
    } catch (Exception $e) {
        error_log("Registration error: " . $e->getMessage());
        error_log("Registration error trace: " . $e->getTraceAsString());
        sendError('Registration failed: ' . $e->getMessage(), 500);
    }
}

/**
 * Handle password reset request
 */
function handleRequestPasswordReset($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        $input = $_POST;
    }
    
    $email = trim($input['email'] ?? '');

    $clientIp = getClientIpAddress();
    $resetRateMaxAttempts = (int)(getenv('MOTORLINK_RESET_RATE_MAX_ATTEMPTS') ?: 5);
    $resetRateWindowSeconds = (int)(getenv('MOTORLINK_RESET_RATE_WINDOW_SECONDS') ?: 1800);
    $resetRateBlockSeconds = (int)(getenv('MOTORLINK_RESET_RATE_BLOCK_SECONDS') ?: 1800);
    $resetIdentifierHash = hash('sha256', strtolower($email) . '|' . $clientIp);

    if (isRateLimited($db, 'password_reset_request', $resetIdentifierHash)) {
        sendErrorWithCode('Too many reset requests. Please try again later.', 'RATE_LIMITED', 429);
    }
    
    if (empty($email)) {
        sendError('Email address is required', 400);
    }
    
    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sendError('Invalid email format', 400);
    }
    
    try {
        // Consume one budget unit per request to mitigate reset flooding.
        recordRateLimitFailure(
            $db,
            'password_reset_request',
            $resetIdentifierHash,
            $resetRateMaxAttempts,
            $resetRateWindowSeconds,
            $resetRateBlockSeconds
        );

        // Find user by email
        $stmt = $db->prepare("SELECT id, email, full_name, status FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Always return success message (security: don't reveal if email exists)
        // But only send email if user exists and is active
        $emailSent = false;
        $resetToken = null;
        $debugResetUserId = 0;
        if ($user && ($user['status'] === 'active' || $user['status'] === 'pending')) {
            // Generate reset token
            $resetToken = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour')); // Token expires in 1 hour
            
            // Store reset token in database
            $stmt = $db->prepare("
                UPDATE users 
                SET reset_token = ?, 
                    reset_token_expires = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$resetToken, $expiresAt, $user['id']]);
            $debugResetUserId = (int)$user['id'];
            
            // Send password reset email
            $emailSent = sendPasswordResetEmail($db, $user['email'], $user['full_name'], $resetToken, $user['id']);
            
            if ($emailSent) {
                error_log("Password reset email sent to: " . $user['email']);
            } else {
                error_log("WARNING: Password reset email may not have been sent to: " . $user['email']);
            }
        }
        
        // Always return success (security best practice - don't reveal if email exists)
        $response = [
            'message' => 'If an account with that email exists, a password reset link has been sent. Please check your email (and spam folder).'
        ];

        // Localhost-only diagnostics for end-to-end reset testing.
        if (isLocalRuntimeHost()) {
            $response['debug_email_sent'] = $emailSent;
            $response['debug_account_found'] = $user ? true : false;
            if (!empty($resetToken) && $debugResetUserId > 0) {
                $response['debug_reset_link'] = rtrim(getRuntimeBaseUrl(), '/') . '/reset-password.php?token=' . urlencode($resetToken) . '&id=' . urlencode((string)$debugResetUserId);
                $response['debug_reset_token'] = $resetToken;
                $response['debug_reset_user_id'] = $debugResetUserId;
            }
        }

        sendSuccess($response);
        
    } catch (Exception $e) {
        error_log("Request password reset error: " . $e->getMessage());
        sendError('Failed to process password reset request', 500);
    }
}

/**
 * Handle password reset (with token verification)
 */
function handleResetPassword($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        $input = $_POST;
    }
    
    $token = trim($input['token'] ?? '');
    $userId = intval($input['user_id'] ?? $input['id'] ?? 0);
    $newPassword = $input['password'] ?? '';
    $confirmPassword = $input['confirm_password'] ?? '';

    $clientIp = getClientIpAddress();
    $resetAttemptMax = (int)(getenv('MOTORLINK_RESET_SUBMIT_MAX_ATTEMPTS') ?: 10);
    $resetAttemptWindow = (int)(getenv('MOTORLINK_RESET_SUBMIT_WINDOW_SECONDS') ?: 900);
    $resetAttemptBlock = (int)(getenv('MOTORLINK_RESET_SUBMIT_BLOCK_SECONDS') ?: 900);
    $resetSubmitIdentifier = hash('sha256', (string)$userId . '|' . $clientIp);

    if (isRateLimited($db, 'password_reset_submit', $resetSubmitIdentifier)) {
        sendErrorWithCode('Too many password reset attempts. Please try again later.', 'RATE_LIMITED', 429);
    }
    
    if (empty($token) || $userId <= 0) {
        sendError('Invalid reset token or user ID', 400);
    }
    
    if (empty($newPassword)) {
        sendError('New password is required', 400);
    }
    
    if (strlen($newPassword) < 6) {
        sendError('Password must be at least 6 characters long', 400);
    }
    
    if ($newPassword !== $confirmPassword) {
        sendError('Passwords do not match', 400);
    }
    
    try {
        // Verify token and get user
        $stmt = $db->prepare("
            SELECT id, email, full_name, reset_token, reset_token_expires, status
            FROM users 
            WHERE id = ? AND reset_token = ?
        ");
        $stmt->execute([$userId, $token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            recordRateLimitFailure($db, 'password_reset_submit', $resetSubmitIdentifier, $resetAttemptMax, $resetAttemptWindow, $resetAttemptBlock);
            sendError('Invalid or expired reset token', 400);
        }
        
        // Check if token has expired
        $now = new DateTime();
        $expiresAt = new DateTime($user['reset_token_expires']);
        
        if ($now > $expiresAt) {
            recordRateLimitFailure($db, 'password_reset_submit', $resetSubmitIdentifier, $resetAttemptMax, $resetAttemptWindow, $resetAttemptBlock);
            sendError('Reset token has expired. Please request a new password reset.', 400);
        }
        
        // Check if user account is active or pending
        if ($user['status'] !== 'active' && $user['status'] !== 'pending') {
            recordRateLimitFailure($db, 'password_reset_submit', $resetSubmitIdentifier, $resetAttemptMax, $resetAttemptWindow, $resetAttemptBlock);
            sendError('Account is not active. Please contact support.', 403);
        }
        
        // Hash new password
        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        
        // Update password and clear reset token
        $stmt = $db->prepare("
            UPDATE users 
            SET password_hash = ?,
                reset_token = NULL,
                reset_token_expires = NULL,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$passwordHash, $user['id']]);
        clearRateLimitState($db, 'password_reset_submit', $resetSubmitIdentifier);
        
        // Log password reset
        error_log("Password reset successful for user: " . $user['email'] . " (ID: " . $user['id'] . ")");
        
        sendSuccess([
            'message' => 'Password has been reset successfully. You can now login with your new password.'
        ]);
        
    } catch (Exception $e) {
        error_log("Reset password error: " . $e->getMessage());
        sendError('Failed to reset password', 500);
    }
}

/**
 * Check if username already exists
 */
function checkUsername($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('POST method required', 405);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $username = trim($input['username'] ?? '');
    
    if (empty($username)) {
        sendSuccess(['exists' => false]);
        return;
    }
    
    try {
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $exists = $stmt->fetch() !== false;
        
        sendSuccess(['exists' => $exists]);
    } catch (Exception $e) {
        error_log("Check username error: " . $e->getMessage());
        sendSuccess(['exists' => false]); // Fail silently for real-time checks
    }
}

/**
 * Check if email already exists
 */
function checkEmail($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('POST method required', 405);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $email = strtolower(trim($input['email'] ?? ''));
    
    if (empty($email)) {
        sendSuccess(['exists' => false]);
        return;
    }
    
    try {
        $stmt = $db->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
        $stmt->execute([$email]);
        $exists = $stmt->fetch() !== false;

        if (!$exists) {
            $stmt = $db->prepare("SELECT id FROM admin_users WHERE LOWER(email) = LOWER(?) LIMIT 1");
            $stmt->execute([$email]);
            $exists = $stmt->fetch() !== false;
        }
        
        sendSuccess(['exists' => $exists]);
    } catch (Exception $e) {
        error_log("Check email error: " . $e->getMessage());
        sendSuccess(['exists' => false]); // Fail silently for real-time checks
    }
}

/**
 * Handle user logout
 */
function handleLogout() {
    // Properly clear all session data
    session_unset();
    session_destroy();
    sendSuccess(['message' => 'Logout successful']);
}

// ============================================================================
// USER ENDPOINT HANDLERS
// ============================================================================

/**
 * Submit a new car listing (guest or authenticated)
 */
function submitListing($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('POST method required', 405);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $isGuest = !isLoggedIn();

    $allowGuestListings = (bool)getPlatformSetting($db, 'user_allowGuestListings', true);
    $maxGuestListings = 1;
    $maxRegisteredListings = (int)getPlatformSetting($db, 'listing_maxRegisteredListings', 10);
    $requireListingEmailValidation = (bool)getPlatformSetting($db, 'listing_requireEmailValidation', true);
    $guestListingValidityDays = max(1, (int)getPlatformSetting($db, 'listing_expiryDays', 30));
    $featuredBonusVisibilityDays = max(0, (int)getPlatformSetting($db, 'listing_featuredBonusDays', 15));
    $paymentsEnabled = (bool)getPlatformSetting($db, 'listing_paymentsEnabled', false);
    $freeListingPrice = max(0, (float)getPlatformSetting($db, 'listing_freeListingPrice', 0));
    $featuredListingPrice = max(0, (float)getPlatformSetting($db, 'listing_featuredPrice', 0));
    $paymentMethodsRaw = (string)getPlatformSetting($db, 'listing_paymentMethods', 'mobile_money,bank_transfer');
    $allowedPaymentMethods = array_values(array_filter(array_map('trim', explode(',', $paymentMethodsRaw))));
    if (empty($allowedPaymentMethods)) {
        $allowedPaymentMethods = ['mobile_money', 'bank_transfer'];
    }
    
    // Validate required fields
    $required = ['title', 'make_id', 'model_id', 'year', 'price', 'location_id', 'fuel_type', 'transmission', 'condition_type'];
    
    if ($isGuest) {
        $required = array_merge($required, ['seller_name', 'seller_phone', 'seller_email']);
    }
    
    foreach ($required as $field) {
        if (empty($input[$field])) {
            sendError("Field '$field' is required", 400);
        }
    }

    if ($isGuest && !$allowGuestListings) {
        sendError('Guest listings are currently disabled. Please register to continue.', 403);
    }

    $listingType = strtolower(trim((string)($input['listing_type'] ?? 'free')));
    $payableAmount = ($listingType === 'featured') ? $featuredListingPrice : $freeListingPrice;
    $paymentRequired = $paymentsEnabled && $payableAmount > 0;

    $paymentMethod = trim((string)($input['payment_method'] ?? ''));
    $paymentReference = trim((string)($input['payment_reference'] ?? ''));
    $paymentProofDataUrl = (string)($input['payment_proof_data_url'] ?? '');
    $paymentProofFilename = (string)($input['payment_proof_filename'] ?? '');

    if ($paymentRequired) {
        if (empty($paymentMethod) || !in_array($paymentMethod, $allowedPaymentMethods, true)) {
            sendError('A valid payment method is required for this listing.', 400);
        }
        if (strlen($paymentReference) < 4) {
            sendError('A valid payment reference is required for this listing.', 400);
        }
        if (empty($paymentProofDataUrl)) {
            sendError('Proof of payment (POP) is required for this listing.', 400);
        }
    }
    
    try {
        // Ensure schema columns outside transaction because ALTER TABLE causes implicit commits.
        ensureListingEmailVerificationColumns($db);
        ensureGuestListingLifecycleColumns($db);
        if ($paymentsEnabled) {
            ensureListingPaymentColumns($db);
            ensurePaymentsTable($db);
        }
        expireGuestListings($db);

        $db->beginTransaction();
        
        $user = $isGuest ? null : getCurrentUser();
        $listingValidationEmail = '';

        if ($isGuest) {
            $listingValidationEmail = strtolower(trim($input['seller_email'] ?? ''));
            if (!filter_var($listingValidationEmail, FILTER_VALIDATE_EMAIL)) {
                sendError('Please provide a valid seller email address', 400);
            }

            if ($maxGuestListings > 0) {
                $limitStmt = $db->prepare("\n                    SELECT COUNT(*)\n                    FROM car_listings\n                    WHERE is_guest = 1\n                      AND LOWER(guest_seller_email) = LOWER(?)\n                ");
                $limitStmt->execute([$listingValidationEmail]);
                $currentGuestListings = (int)$limitStmt->fetchColumn();
                if ($currentGuestListings >= $maxGuestListings) {
                    sendError('Guest listing lifetime limit reached for this email. Please register to continue.', 403);
                }
            }
        } else {
            $listingValidationEmail = strtolower(trim($user['email'] ?? ''));

            if ($maxRegisteredListings > 0) {
                $limitStmt = $db->prepare("SELECT COUNT(*) FROM car_listings WHERE user_id = ? AND status != 'deleted'");
                $limitStmt->execute([(int)$user['id']]);
                $currentUserListings = (int)$limitStmt->fetchColumn();
                if ($currentUserListings >= $maxRegisteredListings) {
                    sendError("Listing limit reached ({$maxRegisteredListings}) for your account.", 403);
                }
            }
        }

        $listingEmailToken = null;
        $listingEmailExpiry = null;
        $listingEmailVerified = $requireListingEmailValidation ? 0 : 1;
        $effectiveGuestVisibilityDays = $guestListingValidityDays;
        if ($listingType === 'featured') {
            $effectiveGuestVisibilityDays += $featuredBonusVisibilityDays;
        }
        $guestListingExpiresAt = $isGuest ? date('Y-m-d H:i:s', strtotime('+' . $effectiveGuestVisibilityDays . ' days')) : null;

        $status = 'pending_approval';
        $approvalStatus = 'pending';
        if ($requireListingEmailValidation) {
            try {
                $statusTypeStmt = $db->query("SHOW COLUMNS FROM car_listings LIKE 'status'");
                $statusTypeRow = $statusTypeStmt ? $statusTypeStmt->fetch(PDO::FETCH_ASSOC) : null;
                $statusType = strtolower((string)($statusTypeRow['Type'] ?? ''));
                if (strpos($statusType, 'pending_email_verification') !== false) {
                    $status = 'pending_email_verification';
                }

                $approvalTypeStmt = $db->query("SHOW COLUMNS FROM car_listings LIKE 'approval_status'");
                $approvalTypeRow = $approvalTypeStmt ? $approvalTypeStmt->fetch(PDO::FETCH_ASSOC) : null;
                $approvalType = strtolower((string)($approvalTypeRow['Type'] ?? ''));
                if (strpos($approvalType, 'pending_email_verification') !== false) {
                    $approvalStatus = 'pending_email_verification';
                }
            } catch (Exception $e) {
                error_log('submitListing status enum check warning: ' . $e->getMessage());
            }
        }

        if ($requireListingEmailValidation) {
            $listingEmailToken = bin2hex(random_bytes(32));
            $listingEmailExpiry = date('Y-m-d H:i:s', strtotime('+24 hours'));
        }

        $paymentProofPath = null;
        $paymentStatus = $paymentRequired ? 'pending_verification' : 'not_required';
        $paymentSubmittedAt = $paymentRequired ? date('Y-m-d H:i:s') : null;

        if ($paymentRequired) {
            $paymentProofPath = persistPaymentProofFromDataUrl($paymentProofDataUrl, $paymentProofFilename);
        }
        
        // Generate a unique reference number
        $referenceNumber = generateReferenceNumber($db);
        
        // Check if dealer_id field exists
        try {
            $columnsStmt = $db->query("SHOW COLUMNS FROM car_listings LIKE 'dealer_id'");
            $hasDealerId = $columnsStmt->rowCount() > 0;
        } catch (Exception $e) {
            $hasDealerId = false;
        }
        
        // Build the SQL query dynamically based on available columns
        $columns = [
            'user_id', 'reference_number', 'title', 'make_id', 'model_id', 'year', 
            'price', 'negotiable', 'mileage', 'location_id', 'fuel_type', 
            'transmission', 'condition_type', 'exterior_color', 'interior_color',
            'engine_size', 'doors', 'seats', 'drivetrain', 'description', 
            'listing_type', 'is_guest', 'guest_seller_name', 'guest_seller_phone', 
            'guest_seller_email', 'guest_seller_whatsapp', 'approval_status', 
            'status', 'created_at'
        ];

        $columns[] = 'listing_email_verified';
        $columns[] = 'listing_email_verification_token';
        $columns[] = 'listing_email_verification_sent_to';
        $columns[] = 'listing_email_verification_expires';
        $columns[] = 'guest_listing_expires_at';

        $hasPaymentRequiredCol = tableColumnExists($db, 'car_listings', 'payment_required');
        $hasPaymentAmountCol = tableColumnExists($db, 'car_listings', 'payment_amount');
        $hasPaymentMethodCol = tableColumnExists($db, 'car_listings', 'payment_method');
        $hasPaymentReferenceCol = tableColumnExists($db, 'car_listings', 'payment_reference');
        $hasPaymentProofPathCol = tableColumnExists($db, 'car_listings', 'payment_proof_path');
        $hasPaymentStatusCol = tableColumnExists($db, 'car_listings', 'payment_status');
        $hasPaymentSubmittedAtCol = tableColumnExists($db, 'car_listings', 'payment_submitted_at');
        $canPersistPaymentFields = $hasPaymentRequiredCol && $hasPaymentAmountCol && $hasPaymentMethodCol && $hasPaymentReferenceCol && $hasPaymentProofPathCol && $hasPaymentStatusCol && $hasPaymentSubmittedAtCol;

        if ($paymentRequired && !$canPersistPaymentFields) {
            throw new Exception('Paid listings are temporarily unavailable. Please contact support.');
        }

        if ($hasPaymentRequiredCol) $columns[] = 'payment_required';
        if ($hasPaymentAmountCol) $columns[] = 'payment_amount';
        if ($hasPaymentMethodCol) $columns[] = 'payment_method';
        if ($hasPaymentReferenceCol) $columns[] = 'payment_reference';
        if ($hasPaymentProofPathCol) $columns[] = 'payment_proof_path';
        if ($hasPaymentStatusCol) $columns[] = 'payment_status';
        if ($hasPaymentSubmittedAtCol) $columns[] = 'payment_submitted_at';

        $hasIsFeaturedColumn = tableColumnExists($db, 'car_listings', 'is_featured');
        if ($hasIsFeaturedColumn) {
            $columns[] = 'is_featured';
        }
        
        if ($hasDealerId) {
            $columns[] = 'dealer_id';
        }
        
        $placeholders = str_repeat('?,', count($columns) - 1) . '?';
        $columnList = implode(', ', $columns);
        
        $sql = "INSERT INTO car_listings ({$columnList}) VALUES ({$placeholders})";
        $stmt = $db->prepare($sql);
        
        // Prepare values in the correct order
        $values = [
            $isGuest ? null : $user['id'],
            $referenceNumber,
            $input['title'],
            $input['make_id'],
            $input['model_id'],
            $input['year'],
            $input['price'],
            isset($input['negotiable']) ? 1 : 0,
            $input['mileage'] ?? null,
            $input['location_id'],
            $input['fuel_type'],
            $input['transmission'],
            $input['condition_type'],
            $input['exterior_color'] ?? null,
            $input['interior_color'] ?? null,
            $input['engine_size'] ?? null,
            !empty($input['doors']) ? (int)$input['doors'] : null,
            !empty($input['seats']) ? (int)$input['seats'] : null,
            $input['drivetrain'] ?? null,
            $input['description'] ?? null,
            $input['listing_type'] ?? 'free',
            $isGuest ? 1 : 0,
            $isGuest ? $input['seller_name'] : null,
            $isGuest ? $input['seller_phone'] : null,
            $isGuest ? $listingValidationEmail : null,
            $input['seller_whatsapp'] ?? null,
            $approvalStatus,
            $status,
            date('Y-m-d H:i:s'),
            $listingEmailVerified,
            $listingEmailToken,
            $listingValidationEmail,
            $listingEmailExpiry,
            $guestListingExpiresAt
        ];

        if ($hasPaymentRequiredCol) $values[] = $paymentRequired ? 1 : 0;
        if ($hasPaymentAmountCol) $values[] = $paymentRequired ? $payableAmount : 0;
        if ($hasPaymentMethodCol) $values[] = $paymentRequired ? $paymentMethod : null;
        if ($hasPaymentReferenceCol) $values[] = $paymentRequired ? $paymentReference : null;
        if ($hasPaymentProofPathCol) $values[] = $paymentProofPath;
        if ($hasPaymentStatusCol) $values[] = $paymentStatus;
        if ($hasPaymentSubmittedAtCol) $values[] = $paymentSubmittedAt;
        if ($hasIsFeaturedColumn) $values[] = ($listingType === 'featured') ? 1 : 0;
        
        if ($hasDealerId) {
            $values[] = null; // dealer_id is null for now
        }
        
        $stmt->execute($values);
        $listingId = $db->lastInsertId();

        if ($paymentRequired && $canPersistPaymentFields) {
            $payStmt = $db->prepare("INSERT INTO payments (listing_id, user_id, user_name, user_email, service_type, amount, payment_method, reference, proof_path, status, created_at) VALUES (?, ?, ?, ?, 'listing_submission', ?, ?, ?, ?, 'pending', NOW())");
            $payStmt->execute([
                (int)$listingId,
                $isGuest ? null : (int)($user['id'] ?? 0),
                $isGuest ? (string)($input['seller_name'] ?? 'Guest Seller') : (string)($user['name'] ?? $user['full_name'] ?? 'Registered Seller'),
                (string)$listingValidationEmail,
                $payableAmount,
                $paymentMethod,
                $paymentReference,
                $paymentProofPath
            ]);
        }

        $guestManageCode = null;
        $guestManageCodeExpiresAt = null;
        if ($isGuest) {
            try {
                $manageCodePayload = issueGuestListingManageCode($db, (int)$listingId);
                $guestManageCode = $manageCodePayload['code'];
                $guestManageCodeExpiresAt = $manageCodePayload['expires_at'];
            } catch (Exception $e) {
                error_log('Guest manage code provisioning warning: ' . $e->getMessage());
            }
        }
        
        $db->commit();

        $listingEmailSent = true;
        $listingVerificationLink = null;

        if ($requireListingEmailValidation && !empty($listingValidationEmail) && !empty($listingEmailToken)) {
            $listingEmailSent = sendListingVerificationEmail($db, $listingValidationEmail, $input['seller_name'] ?? ($user['name'] ?? $user['full_name'] ?? 'Seller'), $listingEmailToken, $listingId, $referenceNumber);

            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $serverHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
            $isProduction = (strpos($serverHost, 'promanaged-it.com') !== false);
            $baseUrl = $isProduction ? 'https://promanaged-it.com/motorlink/' : ($protocol . '://' . $serverHost . '/');
            $listingVerificationLink = $baseUrl . 'verify-listing-email.html?token=' . urlencode($listingEmailToken);
        }

        $guestManageLink = null;
        $guestManageCodeSent = false;
        if ($isGuest && !empty($listingValidationEmail) && !empty($guestManageCode)) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $serverHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
            $isProduction = (strpos($serverHost, 'promanaged-it.com') !== false);
            $baseUrl = $isProduction ? 'https://promanaged-it.com/motorlink/' : ($protocol . '://' . $serverHost . '/');
            $guestManageLink = $baseUrl . 'guest-manage.html?email=' . urlencode($listingValidationEmail);

            $guestManageCodeSent = sendGuestListingManageCodeEmail(
                $db,
                $listingValidationEmail,
                (string)($input['seller_name'] ?? 'Guest Seller'),
                (string)$referenceNumber,
                (int)$listingId,
                (string)$guestManageCode,
                (string)$guestManageCodeExpiresAt
            );
        }
        
        sendSuccess([
            'message' => $requireListingEmailValidation
                ? 'Your listing is almost ready. Please verify your email to continue to admin review.'
                : ($isGuest ? 'Your listing has been submitted for review. You will receive an email once approved.' : 'Your listing has been submitted and will be reviewed shortly.'),
            'listing_id' => $listingId,
            'reference_number' => $referenceNumber,
            'guest_listing_expires_at' => $guestListingExpiresAt,
            'payment_required' => $paymentRequired,
            'payment_amount' => $paymentRequired ? $payableAmount : 0,
            'payment_status' => $paymentStatus,
            'email_verification_required' => $requireListingEmailValidation,
            'email_sent' => $listingEmailSent,
            'verification_link' => $listingEmailSent ? null : $listingVerificationLink,
            'guest_manage_code_sent' => $guestManageCodeSent,
            'guest_manage_code_expires_at' => $guestManageCodeExpiresAt,
            'guest_manage_link' => $guestManageLink
        ]);
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Submit listing error: " . $e->getMessage());
        sendError('Failed to submit listing: ' . $e->getMessage(), 500);
    }
}

/**
 * Verify listing email token and move listing to admin review queue.
 */
function verifyListingEmail($db) {
    $token = trim($_GET['token'] ?? $_POST['token'] ?? '');

    if (empty($token)) {
        sendError('Verification token is required', 400);
    }

    ensureListingEmailVerificationColumns($db);
    ensureGuestListingLifecycleColumns($db);
    expireGuestListings($db);

    try {
        $stmt = $db->prepare("\n            SELECT id, status, approval_status, listing_email_verified, listing_email_verification_expires, is_guest, guest_listing_expires_at\n            FROM car_listings\n            WHERE listing_email_verification_token = ?\n            LIMIT 1\n        ");
        $stmt->execute([$token]);
        $listing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$listing) {
            sendError('Invalid or expired verification link', 400);
        }

        if ((int)($listing['listing_email_verified'] ?? 0) === 1) {
            sendSuccess([
                'message' => 'Listing email already verified.',
                'listing_id' => (int)$listing['id'],
                'already_verified' => true
            ]);
        }

        if (!empty($listing['listing_email_verification_expires']) && strtotime($listing['listing_email_verification_expires']) < time()) {
            sendError('This verification link has expired. Please submit your listing again.', 400);
        }

        if ((int)($listing['is_guest'] ?? 0) === 1 && !empty($listing['guest_listing_expires_at']) && strtotime($listing['guest_listing_expires_at']) <= time()) {
            $expireStmt = $db->prepare("UPDATE car_listings SET status = 'expired', guest_listing_expired_at = COALESCE(guest_listing_expired_at, NOW()) WHERE id = ?");
            $expireStmt->execute([(int)$listing['id']]);
            sendError('This guest listing has expired. Please register and post a new listing.', 400);
        }

        $stmt = $db->prepare("UPDATE car_listings
                              SET listing_email_verified = 1,
                                  listing_email_verification_token = NULL,
                                  listing_email_verified_at = NOW(),
                                  status = 'pending_approval',
                                  approval_status = 'pending'
                              WHERE id = ?");
        $stmt->execute([(int)$listing['id']]);

        sendSuccess([
            'message' => 'Email verified successfully. Your listing is now queued for admin review.',
            'listing_id' => (int)$listing['id']
        ]);
    } catch (Exception $e) {
        error_log('verifyListingEmail error: ' . $e->getMessage());
        sendError('Failed to verify listing email', 500);
    }
}

/**
 * Get user profile
 */
function getProfile($db) {
    $user = getCurrentUser();
    
    try {
        $stmt = $db->prepare("SELECT full_name, email, phone, whatsapp, city, address, bio, user_type FROM users WHERE id = ?");
        $stmt->execute([$user['id']]);
        $profile = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$profile) {
            sendError('Profile not found', 404);
        }

        // Provide dealer-specific profile fields expected by the profile page.
        if (($profile['user_type'] ?? '') === 'dealer') {
            $dealerStmt = $db->prepare("\n                SELECT business_name, years_established, phone, whatsapp, address, description,\n                       facebook_url, instagram_url, twitter_url, website\n                FROM car_dealers\n                WHERE user_id = ?\n                LIMIT 1\n            ");
            $dealerStmt->execute([$user['id']]);
            $dealer = $dealerStmt->fetch(PDO::FETCH_ASSOC);

            if ($dealer) {
                $profile['years_in_business'] = $dealer['years_established'] ?? null;
                $profile['business_name'] = $dealer['business_name'] ?? null;
                $profile['business_phone'] = $dealer['phone'] ?? null;
                $profile['business_whatsapp'] = $dealer['whatsapp'] ?? null;
                $profile['business_address'] = $dealer['address'] ?? null;
                $profile['business_description'] = $dealer['description'] ?? null;
                $profile['description'] = $dealer['description'] ?? null;
                $profile['facebook_url'] = $dealer['facebook_url'] ?? null;
                $profile['instagram_url'] = $dealer['instagram_url'] ?? null;
                $profile['twitter_url'] = $dealer['twitter_url'] ?? null;
                $profile['website_url'] = $dealer['website'] ?? null;
                $profile['website'] = $dealer['website'] ?? null;
            }
        }

        unset($profile['user_type']);
        sendSuccess(['profile' => $profile]);
    } catch (Exception $e) {
        error_log("Get profile error: " . $e->getMessage());
        sendError('Failed to load profile', 500);
    }
}

/**
 * Update user profile
 */
/**
 * Update user profile with password change support
 */
function updateProfile($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('POST method required', 405);
    }
    
    $user = getCurrentUser();
    $input = json_decode(file_get_contents('php://input'), true);

    if (!is_array($input)) {
        sendError('Invalid request body', 400);
    }

    $fullName = trim((string)($input['full_name'] ?? ''));
    if ($fullName === '') {
        sendError('Full name is required', 400);
    }

    $isPasswordChangeRequested = !empty($input['current_password']) || !empty($input['new_password']);
    if ($isPasswordChangeRequested) {
        if (empty($input['current_password']) || empty($input['new_password'])) {
            sendError('Current password and new password are required', 400);
        }
        if (strlen((string)$input['new_password']) < 6) {
            sendError('New password must be at least 6 characters long', 400);
        }
    }
    
    try {
        // Check if password change is requested
        if ($isPasswordChangeRequested) {
            // Verify current password
            $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
            $stmt->execute([$user['id']]);
            $userData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$userData || !password_verify($input['current_password'], $userData['password_hash'])) {
                sendError('Current password is incorrect', 400);
            }
        }

        // Start transaction
        $db->beginTransaction();

        if ($isPasswordChangeRequested) {
            
            // Update password
            $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $stmt->execute([
                password_hash($input['new_password'], PASSWORD_DEFAULT),
                $user['id']
            ]);
        }
        
        // Update profile information
        $stmt = $db->prepare("
            UPDATE users SET full_name = ?, phone = ?, whatsapp = ?, city = ?, address = ?, bio = ?, updated_at = NOW()
            WHERE id = ?
        ");
        
        $stmt->execute([
            $fullName,
            isset($input['phone']) ? trim((string)$input['phone']) : null,
            isset($input['whatsapp']) ? trim((string)$input['whatsapp']) : null,
            isset($input['city']) ? trim((string)$input['city']) : null,
            isset($input['address']) ? trim((string)$input['address']) : null,
            isset($input['bio']) ? trim((string)$input['bio']) : null,
            $user['id']
        ]);

        // Update dealer profile fields when provided.
        $dealerFieldMap = [
            'business_phone' => 'phone',
            'business_whatsapp' => 'whatsapp',
            'business_address' => 'address',
            'business_description' => 'description',
            'facebook_url' => 'facebook_url',
            'instagram_url' => 'instagram_url',
            'twitter_url' => 'twitter_url',
            'website_url' => 'website'
        ];

        $dealerUpdateFields = [];
        $dealerParams = [];
        foreach ($dealerFieldMap as $inputKey => $columnName) {
            if (array_key_exists($inputKey, $input)) {
                $dealerUpdateFields[] = $columnName . ' = ?';
                $dealerParams[] = trim((string)$input[$inputKey]);
            }
        }

        if (!empty($dealerUpdateFields)) {
            $dealerCheck = $db->prepare("SELECT id FROM car_dealers WHERE user_id = ? LIMIT 1");
            $dealerCheck->execute([$user['id']]);
            $dealerRow = $dealerCheck->fetch(PDO::FETCH_ASSOC);

            if ($dealerRow) {
                $dealerUpdateFields[] = 'updated_at = NOW()';
                $dealerParams[] = $user['id'];
                $dealerSql = "UPDATE car_dealers SET " . implode(', ', $dealerUpdateFields) . " WHERE user_id = ?";
                $dealerStmt = $db->prepare($dealerSql);
                $dealerStmt->execute($dealerParams);
            }
        }
        
        $db->commit();

        // Keep session display name aligned with latest profile update.
        $_SESSION['full_name'] = $fullName;
        
        sendSuccess(['message' => 'Profile updated successfully']);
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Update profile error: " . $e->getMessage());
        sendError('Failed to update profile', 500);
    }
}

/**
 * Change user password (dedicated endpoint)
 */
function changePassword($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('POST method required', 405);
    }
    $user = getCurrentUser();
    $input = json_decode(file_get_contents('php://input'), true);

    $currentPassword = trim($input['current_password'] ?? '');
    $newPassword     = $input['new_password'] ?? '';

    if ($currentPassword === '' || $newPassword === '') {
        sendError('Current password and new password are required', 400);
    }
    if (strlen($newPassword) < 8) {
        sendError('New password must be at least 8 characters', 400);
    }

    try {
        $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([$user['id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
            sendError('Current password is incorrect', 400);
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $db->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$newHash, $user['id']]);

        sendSuccess(['message' => 'Password changed successfully']);
    } catch (Exception $e) {
        error_log("changePassword error: " . $e->getMessage());
        sendError('Failed to change password', 500);
    }
}

/**
 * Delete (anonymise) the authenticated user's account
 */
function deleteAccount($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        sendError('DELETE method required', 405);
    }
    $user = getCurrentUser();

    // Require password confirmation sent as JSON body
    $input = json_decode(file_get_contents('php://input'), true);
    $confirmPassword = $input['password'] ?? '';

    if ($confirmPassword === '') {
        sendError('Password confirmation is required', 400);
    }

    try {
        $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([$user['id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || !password_verify($confirmPassword, $row['password_hash'])) {
            sendError('Password confirmation is incorrect', 400);
        }

        $db->beginTransaction();

        // Anonymise user data (preserves relational integrity)
        $anonEmail = 'deleted_' . $user['id'] . '_' . time() . '@deleted.local';
        $stmt = $db->prepare(
            "UPDATE users SET full_name = 'Deleted User', email = ?, phone = NULL, whatsapp = NULL,
             city = NULL, address = NULL, bio = NULL, password_hash = '',
             status = 'deleted', updated_at = NOW() WHERE id = ?"
        );
        $stmt->execute([$anonEmail, $user['id']]);

        // Soft-delete all their listings
        $stmt = $db->prepare("UPDATE listings SET status = 'deleted', updated_at = NOW() WHERE user_id = ?");
        $stmt->execute([$user['id']]);

        $db->commit();

        // Destroy session
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']);
        }
        session_destroy();

        sendSuccess(['message' => 'Account deleted successfully']);
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log("deleteAccount error: " . $e->getMessage());
        sendError('Failed to delete account', 500);
    }
}

/**
 * Get user statistics
 */
function getUserStats($db) {
    // Debug logging
    error_log("getUserStats called. Session ID: " . session_id());
    error_log("Session data: " . json_encode($_SESSION));
    
    // Check authentication first
    if (!isLoggedIn()) {
        error_log("getUserStats: isLoggedIn() returned false");
        sendError('Authentication required', 401);
        return;
    }
    
    // Get user ID directly from session
    $userId = $_SESSION['user_id'] ?? null;
    
    if (!$userId) {
        error_log("getUserStats: No user_id in session");
        sendError('User not found', 401);
        return;
    }
    
    error_log("getUserStats: User ID from session: $userId");
    
    // Get user data from database to ensure it exists
    // Don't require 'active' status - just check if user exists
    // This allows stats to be retrieved even if user is pending approval
    try {
        $stmt = $db->prepare("SELECT id, user_type, status FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $userData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$userData) {
            error_log("getUserStats: User ID $userId not found in database");
            sendError('User not found', 401);
            return;
        }
        
        // Log for debugging
        error_log("getUserStats: User ID $userId found, type: {$userData['user_type']}, status: {$userData['status']}");
    } catch (Exception $e) {
        error_log("getUserStats user check error: " . $e->getMessage());
        sendError('Failed to verify user', 500);
        return;
    }

    try {
        // Count user's listings
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM car_listings WHERE user_id = ?");
        $stmt->execute([$userId]);
        $listings = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];

        // Count unread messages
        $messages = 0;
        try {
            $stmt = $db->prepare("
                SELECT COUNT(*) as count
                FROM messages m
                INNER JOIN conversations c ON m.conversation_id = c.id
                WHERE (c.buyer_id = ? OR c.seller_id = ?)
                AND m.sender_id != ?
                AND m.is_read = 0
            ");
            $stmt->execute([$userId, $userId, $userId]);
            $messages = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];
        } catch (Exception $e) {
            // Table might not exist yet
            error_log("Messages count error: " . $e->getMessage());
        }

        // Count saved listings (favorites)
        $favorites = 0;
        try {
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM saved_listings WHERE user_id = ?");
            $stmt->execute([$userId]);
            $favorites = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];
        } catch (Exception $e) {
            // Table might not exist yet
            error_log("Favorites count error: " . $e->getMessage());
        }

        sendSuccess(['stats' => [
            'listings' => $listings,
            'messages' => $messages,
            'favorites' => $favorites
        ]]);
    } catch (Exception $e) {
        error_log("User stats error: " . $e->getMessage());
        sendError('Failed to load stats', 500);
    }
}

/**
 * Handle image uploads for listings
 */
function handleUploadImages($db) {
    $user = getCurrentUser(false);
    
    // Ensure the upload directory exists
    $uploadDir = __DIR__ . '/uploads/';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        sendError('Failed to create upload directory', 500);
    }

    $savedFiles = [];

    // Try to get listing_id from POST or REQUEST
    $listingId = intval($_POST['listing_id'] ?? $_REQUEST['listing_id'] ?? 0);

    // Debug logging
    error_log("Upload Images - POST data: " . print_r($_POST, true));
    error_log("Upload Images - FILES data: " . print_r($_FILES, true));
    error_log("Upload Images - Listing ID: " . $listingId);

    if (!$listingId) {
        sendError('Listing ID missing', 400);
    }

    // Verify ownership of the listing
    try {
        $stmt = $db->prepare("SELECT id, user_id, is_guest, guest_seller_email, created_at FROM car_listings WHERE id = ?");
        $stmt->execute([$listingId]);
        $listing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$listing) {
            sendError('Listing not found', 404);
        }
        
        // Authenticated owner/admin can upload images.
        $isOwnerOrAdmin = $user && (($listing['user_id'] == $user['id']) || ($user['type'] === 'admin'));

        // Guest upload support: allow only for guest listings, with email verification,
        // and only within 2 hours of listing creation.
        $guestSellerEmail = trim($_POST['guest_seller_email'] ?? $_REQUEST['guest_seller_email'] ?? '');
        $isGuestUploadAllowed = false;

        if (!$user && (int)$listing['is_guest'] === 1) {
            $createdAt = strtotime($listing['created_at'] ?? '');
            $withinWindow = $createdAt ? ((time() - $createdAt) <= 7200) : false;
            $emailMatches = !empty($guestSellerEmail) && !empty($listing['guest_seller_email']) && (strcasecmp($guestSellerEmail, $listing['guest_seller_email']) === 0);
            $isGuestUploadAllowed = $withinWindow && $emailMatches;
        }

        if (!$isOwnerOrAdmin && !$isGuestUploadAllowed) {
            sendError('Access denied - upload is allowed only for listing owner/admin or verified guest uploader', 403);
        }
    } catch (Exception $e) {
        error_log("Upload Images - Ownership check error: " . $e->getMessage());
        sendError('Failed to verify listing ownership', 500);
    }

    if (!isset($_FILES['images'])) {
        sendError('No files uploaded', 400);
    }

    $uploadErrors = [];
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $maxFileSize = 10 * 1024 * 1024; // 10MB

    // Normalize upload arrays for both single-file and multi-file uploads.
    $fileErrors = $_FILES['images']['error'];
    $fileNames = $_FILES['images']['name'];
    $tmpNames = $_FILES['images']['tmp_name'];
    $fileSizes = $_FILES['images']['size'];
    $fileTypes = $_FILES['images']['type'];

    if (!is_array($fileErrors)) {
        $fileErrors = [$fileErrors];
        $fileNames = [$fileNames];
        $tmpNames = [$tmpNames];
        $fileSizes = [$fileSizes];
        $fileTypes = [$fileTypes];
    }

    foreach ($fileErrors as $index => $error) {
        $origName = basename($fileNames[$index]);
        
        // Check for upload errors
        if ($error !== UPLOAD_ERR_OK) {
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => 'File size exceeds server limit',
                UPLOAD_ERR_FORM_SIZE => 'File size exceeds form limit',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
            ];
            $uploadErrors[] = $origName . ': ' . ($errorMessages[$error] ?? 'Unknown upload error');
            continue;
        }

        $tmpName = $tmpNames[$index];
        
        // Validate file extension
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExtensions)) {
            $uploadErrors[] = $origName . ': Invalid file type. Allowed types: ' . implode(', ', $allowedExtensions);
            continue;
        }

        // Validate file size
        $fileSize = $fileSizes[$index];
        if ($fileSize > $maxFileSize) {
            $uploadErrors[] = $origName . ': File too large. Maximum size is 10MB';
            continue;
        }

        // Validate MIME type using file content first (more reliable than browser-provided type)
        $mimeType = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $detected = finfo_file($finfo, $tmpName);
                finfo_close($finfo);
                if ($detected) {
                    $mimeType = $detected;
                }
            }
        }

        if (empty($mimeType)) {
            $mimeType = $fileTypes[$index];
        }
        $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($mimeType, $allowedMimeTypes)) {
            $imageInfo = @getimagesize($tmpName);
            $detectedImageMime = $imageInfo['mime'] ?? '';

            // Normalize common non-standard png mime labels.
            if ($detectedImageMime === 'image/x-png') {
                $detectedImageMime = 'image/png';
            }

            if ($detectedImageMime && in_array($detectedImageMime, $allowedMimeTypes)) {
                $mimeType = $detectedImageMime;
            } else {
                $uploadErrors[] = $origName . ': Invalid file type detected';
                continue;
            }
        }

        // Generate a unique filename
        $newName = uniqid('img_', true) . '.' . $ext;
        $dest = $uploadDir . $newName;

        if (move_uploaded_file($tmpName, $dest)) {
            try {
            // Insert into database
            $stmt = $db->prepare("
                INSERT INTO car_listing_images (listing_id, filename, original_filename, file_path, file_size, mime_type, is_primary, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, 0, 0)
            ");
            $stmt->execute([$listingId, $newName, $origName, $dest, $fileSize, $mimeType]);
            $savedFiles[] = $newName;
            } catch (Exception $e) {
                // Delete uploaded file if database insert fails
                @unlink($dest);
                error_log("Upload Images - Database error for $origName: " . $e->getMessage());
                $uploadErrors[] = $origName . ': Failed to save to database';
            }
        } else {
            $uploadErrors[] = $origName . ': Failed to move uploaded file';
        }
    }

    if (empty($savedFiles) && !empty($uploadErrors)) {
        $errorMessage = 'Upload failed. Reasons: ' . implode('; ', $uploadErrors);
        $errorMessage .= '. Common issues: File size exceeds 10MB, invalid format (only JPG/PNG/GIF/WEBP), server storage full, or network interruption.';
        sendError($errorMessage, 400);
    }

    if (empty($savedFiles)) {
        sendError('No images uploaded successfully. Please check file formats and sizes.', 400);
    }

    // If some files failed, include warnings
    if (!empty($uploadErrors)) {
        $warningMessage = 'Some files failed to upload: ' . implode(', ', $uploadErrors);
        error_log("Upload Images - Warnings: " . $warningMessage);
    }

    sendSuccess(['files' => $savedFiles, 'count' => count($savedFiles)]);
}

// ============================================================================
// ADMIN ENDPOINT HANDLERS
// ============================================================================

/**
 * Get listings for admin approval
 */
function getAdminListings($db) {
    $status = $_GET['status'] ?? 'pending';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;
    
    try {
        $stmt = $db->prepare("
            SELECT l.*, m.name as make_name, mo.name as model_name, loc.name as location_name,
                   u.full_name as user_name, u.email as user_email
            FROM car_listings l
            LEFT JOIN car_makes m ON l.make_id = m.id
            LEFT JOIN car_models mo ON l.model_id = mo.id
            LEFT JOIN locations loc ON l.location_id = loc.id
            LEFT JOIN users u ON l.user_id = u.id
            WHERE l.approval_status = ?
            ORDER BY l.created_at DESC
            LIMIT ? OFFSET ?
        ");
        
        $stmt->execute([$status, $limit, $offset]);
        $listings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        sendSuccess(['listings' => $listings]);
    } catch (Exception $e) {
        error_log("Get admin listings error: " . $e->getMessage());
        sendError('Failed to fetch listings', 500);
    }
}

/**
 * Approve a listing
 */
function approveListing($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('POST method required', 405);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $listingId = $input['listing_id'] ?? null;
    $user = getCurrentUser();
    
    if (!$listingId) {
        sendError('Listing ID required', 400);
    }
    
    try {
        $db->beginTransaction();
        
        $stmt = $db->prepare("
            UPDATE car_listings 
            SET approval_status = 'approved', status = 'active', approved_by = ?, approval_date = NOW()
            WHERE id = ? AND approval_status = 'pending'
        ");
        
        $stmt->execute([$user['id'], $listingId]);
        
        $stmt = $db->prepare("INSERT INTO listing_approval_history (listing_id, admin_user_id, action, created_at) VALUES (?, ?, 'approved', NOW())");
        $stmt->execute([$listingId, $user['id']]);
        
        $db->commit();
        
        sendSuccess(['message' => 'Listing approved successfully']);
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Approve listing error: " . $e->getMessage());
        sendError('Failed to approve listing', 500);
    }
}

/**
 * Deny a listing
 */
function denyListing($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('POST method required', 405);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $listingId = $input['listing_id'] ?? null;

    $reason = $input['denial_reason'] ?? null;
    $user = getCurrentUser();
    
    if (!$listingId || !$reason) {
        sendError('Listing ID and reason required', 400);
    }
    
    try {
        $db->beginTransaction();
        
        $stmt = $db->prepare("
            UPDATE car_listings 
            SET approval_status = 'denied', denial_reason = ?, approved_by = ?, approval_date = NOW()
            WHERE id = ? AND approval_status = 'pending'
        ");
        
        $stmt->execute([$reason, $user['id'], $listingId]);
        
        $stmt = $db->prepare("INSERT INTO listing_approval_history (listing_id, admin_user_id, action, reason, created_at) VALUES (?, ?, 'denied', ?, NOW())");
        $stmt->execute([$listingId, $user['id'], $reason]);
        
        $db->commit();
        
        sendSuccess(['message' => 'Listing denied successfully']);
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Deny listing error: " . $e->getMessage());
        sendError('Failed to deny listing', 500);
    }
}

/**
 * Get admin notifications (pending listings count)
 */
function getAdminNotifications($db) {
    try {
        $stmt = $db->query("
            SELECT COUNT(*) as pending_count
            FROM car_listings
            WHERE approval_status = 'pending'
        ");
        $pendingCount = $stmt->fetch(PDO::FETCH_ASSOC)['pending_count'];

        sendSuccess(['pending_count' => $pendingCount]);
    } catch (Exception $e) {
        error_log("Get admin notifications error: " . $e->getMessage());
        sendError('Failed to fetch notifications', 500);
    }
}

// ============================================================================
// MY LISTINGS HANDLERS
// ============================================================================

/**
 * Get current user's listings
 */
function getMyListings($db) {
    $user = getCurrentUser();

    // Debug logging
    error_log("=== getMyListings START ===");
    error_log("User ID: " . $user['id']);
    error_log("User email: " . ($user['email'] ?? 'N/A'));
    error_log("Full user data: " . json_encode($user));

    try {
        $sql = "
            SELECT l.*, m.name as make_name, mo.name as model_name, mo.body_type,
                   loc.name as location_name,
                   l.featured_image_id,
                   (SELECT COUNT(*) FROM car_listing_images WHERE listing_id = l.id) as image_count,
                   COALESCE(
                       (SELECT filename FROM car_listing_images WHERE id = l.featured_image_id),
                       (SELECT filename FROM car_listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1),
                       (SELECT filename FROM car_listing_images WHERE listing_id = l.id LIMIT 1)
                   ) as featured_image,
                   (SELECT COUNT(*) FROM saved_listings WHERE listing_id = l.id) as saves
            FROM car_listings l
            INNER JOIN car_makes m ON l.make_id = m.id
            INNER JOIN car_models mo ON l.model_id = mo.id
            INNER JOIN locations loc ON l.location_id = loc.id
            WHERE l.user_id = ? AND l.status != 'deleted'
            ORDER BY l.created_at DESC
        ";

        error_log("SQL Query: " . $sql);
        error_log("Binding user_id parameter: " . $user['id']);

        $stmt = $db->prepare($sql);
        $stmt->execute([$user['id']]);
        $listings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        error_log("Query returned " . count($listings) . " listings");

        // Log user_ids of all returned listings
        if (count($listings) > 0) {
            $userIds = array_map(function($l) { return $l['user_id']; }, $listings);
            error_log("Listing user_ids: " . implode(', ', array_unique($userIds)));
            error_log("First listing: ID=" . $listings[0]['id'] . ", Title=" . $listings[0]['title'] . ", user_id=" . $listings[0]['user_id']);
        } else {
            error_log("No listings found for user " . $user['id']);
        }

        error_log("=== getMyListings END ===");

        sendSuccess(['listings' => $listings, 'debug' => ['user_id' => $user['id'], 'count' => count($listings)]]);
    } catch (Exception $e) {
        error_log("getMyListings error: " . $e->getMessage());
        sendError('Failed to load listings', 500);
    }
}

/**
 * Check for similar listings (duplicate detection)
 */
function checkSimilarListings($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('POST method required', 405);
    }

    $user = getCurrentUser();
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        sendError('Invalid request data', 400);
    }

    $make = trim($input['make'] ?? '');
    $model = trim($input['model'] ?? '');
    $year = trim($input['year'] ?? '');
    $title = trim($input['title'] ?? '');
    $mileage = $input['mileage'] ?? null;
    $description = trim($input['description'] ?? '');
    $strictCheck = $input['strict_check'] ?? false;

    if (empty($make) || empty($model) || empty($year)) {
        sendError('Make, model, and year are required', 400);
    }

    try {
        // Build query to find similar listings from the same user
        $whereConditions = [
            "l.user_id = ?",
            "l.status != 'deleted'",
            "m.name = ?",
            "mo.name = ?",
            "l.year = ?"
        ];
        $params = [$user['id'], $make, $model, $year];

        // If strict check, also match mileage and description similarity
        if ($strictCheck && $mileage !== null && $mileage !== '') {
            // Match mileage within 5% tolerance
            $mileageInt = (int)$mileage;
            $whereConditions[] = "l.mileage BETWEEN ? AND ?";
            $params[] = max(0, $mileageInt * 0.95);
            $params[] = $mileageInt * 1.05;
        }

        $whereClause = implode(' AND ', $whereConditions);

        $sql = "
            SELECT 
                l.id,
                l.title,
                l.year,
                l.mileage,
                l.price,
                l.created_at,
                m.name as make_name,
                mo.name as model_name,
                85 as similarity_score
            FROM car_listings l
            INNER JOIN car_makes m ON l.make_id = m.id
            INNER JOIN car_models mo ON l.model_id = mo.id
            WHERE {$whereClause}
            ORDER BY l.created_at DESC
            LIMIT 5
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $similarListings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        sendSuccess([
            'similar_listings' => $similarListings,
            'count' => count($similarListings),
            'strict_check' => $strictCheck
        ]);

    } catch (Exception $e) {
        error_log("Check similar listings error: " . $e->getMessage());
        sendError('Failed to check for similar listings', 500);
    }
}

/**
 * Update listing status (mark as sold, etc.)
 */
function updateListingStatus($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('POST method required', 405);
    }

    $user = getCurrentUser();
    $input = json_decode(file_get_contents('php://input'), true);
    $listingId = $input['listing_id'] ?? null;
    $status = $input['status'] ?? null;

    if (!$listingId || !$status) {
        sendError('Listing ID and status required', 400);
    }

    $allowedStatuses = ['active', 'sold', 'pending', 'inactive'];
    if (!in_array($status, $allowedStatuses)) {
        sendError('Invalid status', 400);
    }

    try {
        // Verify ownership
        $stmt = $db->prepare("SELECT id FROM car_listings WHERE id = ? AND user_id = ?");
        $stmt->execute([$listingId, $user['id']]);
        if (!$stmt->fetch()) {
            sendError('Listing not found or access denied', 404);
        }

        $stmt = $db->prepare("UPDATE car_listings SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$status, $listingId]);

        sendSuccess(['message' => 'Listing status updated']);
    } catch (Exception $e) {
        error_log("updateListingStatus error: " . $e->getMessage());
        sendError('Failed to update listing', 500);
    }
}

/**
 * Delete a listing
 */
/**
 * Helper function to delete all images for a listing from both database and filesystem
 * @param PDO $db Database connection
 * @param int $listingId The ID of the listing
 * @return bool True if successful
 */
function deleteListingImages($db, $listingId) {
    try {
        // Get all images for this listing
        $stmt = $db->prepare("SELECT id, file_path, thumbnail_path FROM car_listing_images WHERE listing_id = ?");
        $stmt->execute([$listingId]);
        $images = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Delete each image file from filesystem
        foreach ($images as $image) {
            // Delete main image file
            if (!empty($image['file_path']) && file_exists($image['file_path'])) {
                @unlink($image['file_path']);
            }
            
            // Delete thumbnail if exists
            if (!empty($image['thumbnail_path']) && file_exists($image['thumbnail_path'])) {
                @unlink($image['thumbnail_path']);
            }
        }
        
        // Delete all image records from database
        $stmt = $db->prepare("DELETE FROM car_listing_images WHERE listing_id = ?");
        $stmt->execute([$listingId]);
        
        return true;
    } catch (Exception $e) {
        error_log("deleteListingImages error: " . $e->getMessage());
        return false;
    }
}

function deleteListing($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('POST method required', 405);
    }

    $user = getCurrentUser();
    $input = json_decode(file_get_contents('php://input'), true);
    $listingId = $input['listing_id'] ?? null;

    if (!$listingId) {
        sendError('Listing ID required', 400);
    }

    try {
        // Verify ownership
        $stmt = $db->prepare("SELECT id FROM car_listings WHERE id = ? AND user_id = ?");
        $stmt->execute([$listingId, $user['id']]);
        if (!$stmt->fetch()) {
            sendError('Listing not found or access denied', 404);
        }

        $db->beginTransaction();

        // Delete images from both database and filesystem
        deleteListingImages($db, $listingId);

        // Delete the listing
        $stmt = $db->prepare("DELETE FROM car_listings WHERE id = ?");
        $stmt->execute([$listingId]);

        $db->commit();

        sendSuccess(['message' => 'Listing deleted successfully']);
    } catch (Exception $e) {
        $db->rollBack();
        error_log("deleteListing error: " . $e->getMessage());
        sendError('Failed to delete listing', 500);
    }
}

/**
 * Update an existing listing (for all user types)
 */
function updateListing($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('POST method required', 405);
    }

    $user = getCurrentUser();
    $input = json_decode(file_get_contents('php://input'), true);
    
    $listingId = $input['listing_id'] ?? null;
    if (!$listingId) {
        sendError('Listing ID required', 400);
    }

    try {
        // Verify ownership and get original listing details
        $stmt = $db->prepare("
            SELECT l.*, m.name as make_name, mo.name as model_name
            FROM car_listings l
            LEFT JOIN car_makes m ON l.make_id = m.id
            LEFT JOIN car_models mo ON l.model_id = mo.id
            WHERE l.id = ?
        ");
        $stmt->execute([$listingId]);
        $listing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$listing) {
            sendError('Listing not found', 404);
        }

        if ($listing['user_id'] != $user['id'] && $user['type'] !== 'admin') {
            sendError('Access denied - You can only edit your own listings', 403);
        }

        // STRICT VALIDATION: Prevent changing to a completely different car
        // This prevents fraud where users change a cheap car listing to an expensive one

        // 1. MAKE CANNOT BE CHANGED - You can't change a Toyota to a BMW
        if (isset($input['make_id']) && $input['make_id'] != $listing['make_id']) {
            sendError(
                'Cannot change car make. You cannot change a ' . $listing['make_name'] . ' to a different brand. ' .
                'This is to prevent fraud. If you made a mistake, please delete and create a new listing.',
                400
            );
        }

        // 2. MODEL CANNOT BE CHANGED - Prevents listing swaps
        if (isset($input['model_id']) && $input['model_id'] != $listing['model_id']) {
            sendError(
                'Cannot change car model. You cannot change a ' . $listing['model_name'] . ' to a different model. ' .
                'This is to prevent listing fraud. If you made a mistake, please delete and create a new listing.',
                400
            );
        }

        // 3. YEAR CANNOT BE CHANGED - Prevents vehicle swaps
        if (isset($input['year']) && $input['year'] != $listing['year']) {
            sendError(
                'Cannot change vehicle year. You cannot change a ' . $listing['year'] . ' model to a different year. ' .
                'This is to prevent listing fraud. If you made a mistake, please delete and create a new listing.',
                400
            );
        }

        // 4. PRICE: Maximum 50% change to prevent fraud
        if (isset($input['price'])) {
            $originalPrice = floatval($listing['price']);
            $newPrice = floatval($input['price']);

            if ($originalPrice > 0) {
                $priceChangePercent = abs(($newPrice - $originalPrice) / $originalPrice * 100);

                if ($priceChangePercent > 50) {
                    sendError(
                        'Price change too large. You can only change price by up to 50% (±' . number_format($originalPrice * 0.5) . ' MWK). ' .
                        'Original: ' . number_format($originalPrice) . ' MWK, New: ' . number_format($newPrice) . ' MWK. ' .
                        'This prevents changing a cheap car to an expensive one.',
                        400
                    );
                }
            }
        }

        // 5. MILEAGE: Can only increase or decrease by max 50,000 km
        if (isset($input['mileage']) && !empty($listing['mileage'])) {
            $originalMileage = intval($listing['mileage']);
            $newMileage = intval($input['mileage']);

            if (abs($newMileage - $originalMileage) > 50000) {
                sendError(
                    'Mileage change too large. Maximum change is ±50,000 km. ' .
                    'Original: ' . number_format($originalMileage) . ' km, New: ' . number_format($newMileage) . ' km. ' .
                    'This prevents changing to a completely different vehicle.',
                    400
                );
            }
        }

        // Build update query dynamically
        $updateFields = [];
        $params = [];

        $allowedFields = [
            'title', 'description', 'make_id', 'model_id', 'year', 'price',
            'negotiable', 'mileage', 'fuel_type', 'transmission', 'condition_type',
            'exterior_color', 'interior_color', 'engine_size', 'doors', 'seats',
            'drivetrain', 'location_id', 'listing_type'
        ];

        foreach ($allowedFields as $field) {
            if (isset($input[$field])) {
                $updateFields[] = "$field = ?";
                $params[] = $input[$field];
            }
        }

        if (empty($updateFields)) {
            sendError('No fields to update', 400);
        }

        // Always update the updated_at timestamp
        $updateFields[] = "updated_at = NOW()";
        $params[] = $listingId;

        $sql = "UPDATE car_listings SET " . implode(', ', $updateFields) . " WHERE id = ?";
        
        error_log("Update listing SQL: " . $sql);
        error_log("Update listing params: " . json_encode($params));
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        sendSuccess([
            'message' => 'Listing updated successfully',
            'listing_id' => $listingId
        ]);
    } catch (Exception $e) {
        error_log("updateListing error: " . $e->getMessage());
        sendError('Failed to update listing: ' . $e->getMessage(), 500);
    }
}

// ============================================================================
// FAVORITES HANDLERS
// ============================================================================

/**
 * Save a listing to favorites
 */
function saveListing($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('POST method required', 405);
    }

    $user = getCurrentUser();
    $input = json_decode(file_get_contents('php://input'), true);
    $listingId = $input['listing_id'] ?? null;

    if (!$listingId) {
        sendError('Listing ID required', 400);
    }

    try {
        // Check if already saved
        $stmt = $db->prepare("SELECT id FROM saved_listings WHERE user_id = ? AND listing_id = ?");
        $stmt->execute([$user['id'], $listingId]);

        if ($stmt->fetch()) {
            sendSuccess(['message' => 'Already saved']);
            return;
        }

        $stmt = $db->prepare("INSERT INTO saved_listings (user_id, listing_id, created_at) VALUES (?, ?, NOW())");
        $stmt->execute([$user['id'], $listingId]);

        sendSuccess(['message' => 'Listing saved']);
    } catch (Exception $e) {
        error_log("saveListing error: " . $e->getMessage());
        sendError('Failed to save listing', 500);
    }
}

/**
 * Remove a listing from favorites
 */
function unsaveListing($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('POST method required', 405);
    }

    $user = getCurrentUser();
    $input = json_decode(file_get_contents('php://input'), true);
    $listingId = $input['listing_id'] ?? null;

    if (!$listingId) {
        sendError('Listing ID required', 400);
    }

    try {
        $stmt = $db->prepare("DELETE FROM saved_listings WHERE user_id = ? AND listing_id = ?");
        $stmt->execute([$user['id'], $listingId]);

        sendSuccess(['message' => 'Listing removed from favorites']);
    } catch (Exception $e) {
        error_log("unsaveListing error: " . $e->getMessage());
        sendError('Failed to remove listing', 500);
    }
}

/**
 * Get user's favorite listings
 */
function getFavorites($db) {
    $user = getCurrentUser();

    try {
        $stmt = $db->prepare("
            SELECT l.*, m.name as make_name, mo.name as model_name, mo.body_type,
                   loc.name as location_name,
                   COALESCE(
                       (SELECT filename FROM car_listing_images WHERE id = l.featured_image_id),
                       (SELECT filename FROM car_listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1),
                       (SELECT filename FROM car_listing_images WHERE listing_id = l.id LIMIT 1)
                   ) as featured_image
            FROM saved_listings sl
            INNER JOIN car_listings l ON sl.listing_id = l.id
            INNER JOIN car_makes m ON l.make_id = m.id
            INNER JOIN car_models mo ON l.model_id = mo.id
            INNER JOIN locations loc ON l.location_id = loc.id
                        WHERE sl.user_id = ?
                            AND l.approval_status = 'approved'
                            AND l.status IN ('active', 'sold')
            ORDER BY sl.created_at DESC
        ");
        $stmt->execute([$user['id']]);
        $listings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        sendSuccess(['listings' => $listings]);
    } catch (Exception $e) {
        error_log("getFavorites error: " . $e->getMessage());
        sendError('Failed to load favorites', 500);
    }
}

// ============================================================================
// REPORTING HANDLERS
// ============================================================================

/**
 * Report a car listing for spam, fraud, or other issues
 * REQUIRES AUTHENTICATION - users must be logged in to report
 */
function reportListing($db) {
    try {
        // Require user to be logged in
        $user = getCurrentUser(true); // Require authentication

        // Ensure reporting storage exists before any report queries.
        $db->exec("CREATE TABLE IF NOT EXISTS listing_reports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            listing_id INT NOT NULL,
            user_id INT NOT NULL,
            reason VARCHAR(100) NOT NULL,
            details TEXT NOT NULL,
            reporter_email VARCHAR(255) DEFAULT NULL,
            reporter_ip VARCHAR(45) DEFAULT NULL,
            status VARCHAR(50) DEFAULT 'pending',
            admin_notes TEXT DEFAULT NULL,
            reviewed_by INT DEFAULT NULL,
            reviewed_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_listing_id (listing_id),
            INDEX idx_user_id (user_id),
            UNIQUE KEY uniq_listing_user_report (listing_id, user_id),
            INDEX idx_status (status),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Backfill legacy schema only when runtime updates are explicitly enabled.
        applyRuntimeSchemaChange($db, "ALTER TABLE listing_reports ADD UNIQUE KEY uniq_listing_user_report (listing_id, user_id)");
        applyRuntimeSchemaChange($db, "ALTER TABLE car_listings ADD COLUMN report_count INT DEFAULT 0");
        
        // Get JSON input
        $input = json_decode(file_get_contents('php://input'), true);
        
        // Validate required fields
        if (empty($input['listing_id']) || empty($input['reason']) || empty($input['details'])) {
            sendErrorWithCode('Missing required fields: listing_id, reason, and details are required', 'MISSING_REPORT_FIELDS', 400);
            return;
        }

        $listingId = (int)$input['listing_id'];
        $reason = trim($input['reason']);
        $details = trim($input['details']);
        $reporterEmail = !empty($input['email']) ? trim($input['email']) : $user['email'];
        
        // Validate details length
        if (strlen($details) < 10) {
            sendErrorWithCode('Please provide at least 10 characters in your report details', 'REPORT_DETAILS_TOO_SHORT', 400);
            return;
        }

        // Verify listing exists and check ownership
        $stmt = $db->prepare("SELECT id, title, user_id FROM car_listings WHERE id = ?");
        $stmt->execute([$listingId]);
        $listing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$listing) {
            sendErrorWithCode('Listing not found', 'LISTING_NOT_FOUND', 404);
            return;
        }

        // Cannot report your own listing
        if ($listing['user_id'] == $user['id']) {
            sendErrorWithCode('Cannot report your own listing', 'CANNOT_REPORT_OWN_LISTING', 400);
            return;
        }

        // User is logged in, use their ID
        $userId = $user['id'];

        // Get reporter IP
        $reporterIp = $_SERVER['REMOTE_ADDR'] ?? null;

        // Enforce one report per user per listing.
        $stmt = $db->prepare("
            SELECT COUNT(*) as count 
            FROM listing_reports 
            WHERE listing_id = ? 
            AND user_id = ?
        ");
        $stmt->execute([$listingId, $userId]);
        $recent = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($recent['count'] > 0) {
            sendErrorWithCode('You have already reported this listing.', 'REPORT_ALREADY_EXISTS', 409);
            return;
        }

        // Begin transaction
        $db->beginTransaction();

        try {
            // Insert report
            $stmt = $db->prepare("
                INSERT INTO listing_reports 
                (listing_id, user_id, reason, details, reporter_email, reporter_ip, status) 
                VALUES (?, ?, ?, ?, ?, ?, 'pending')
            ");
            $stmt->execute([$listingId, $userId, $reason, $details, $reporterEmail, $reporterIp]);
            
            // Update report count on listing
            $stmt = $db->prepare("
                UPDATE car_listings 
                SET report_count = report_count + 1 
                WHERE id = ?
            ");
            $stmt->execute([$listingId]);

            // Get updated report count
            $stmt = $db->prepare("SELECT report_count FROM car_listings WHERE id = ?");
            $stmt->execute([$listingId]);
            $reportCount = $stmt->fetchColumn();

            // Commit transaction
            $db->commit();

            // Send email notification to support
            sendReportNotificationEmail($db, $listingId, $listing['title'], $reason, $details, $reporterEmail, $reportCount);

            sendSuccess([
                'message' => 'Report submitted successfully. Thank you for helping us maintain quality listings.',
                'report_count' => $reportCount
            ]);

        } catch (PDOException $e) {
            $db->rollBack();

            // Handle race conditions when unique key exists.
            if ($e->getCode() === '23000') {
                sendErrorWithCode('You have already reported this listing.', 'REPORT_ALREADY_EXISTS', 409);
                return;
            }

            throw $e;
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }

    } catch (Exception $e) {
        error_log("reportListing error: " . $e->getMessage());
        sendErrorWithCode('Failed to submit report. Please try again.', 'REPORT_SUBMIT_FAILED', 500);
    }
}

/**
 * Send email notification for listing report
 */
function sendReportNotificationEmail($db, $listingId, $listingTitle, $reason, $details, $reporterEmail, $reportCount) {
    try {
        // Get support email from site settings
        $stmt = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'contact_support_email'");
        $stmt->execute();
        $supportEmail = $stmt->fetchColumn();
        
        if (!$supportEmail) {
            $supportEmail = 'support@motorlink.mw'; // Fallback
        }

        // Prepare email
        $subject = "MotorLink: Listing Reported - #$listingId";
        $reasonLabels = [
            'spam' => 'Spam or Duplicate Listing',
            'fraud' => 'Suspected Fraud or Scam',
            'incorrect' => 'Incorrect or Misleading Information',
            'stolen' => 'Suspected Stolen Vehicle',
            'inappropriate' => 'Inappropriate Content or Images',
            'sold' => 'Already Sold (not removed)',
            'other' => 'Other'
        ];
        $reasonText = $reasonLabels[$reason] ?? $reason;
        
        $message = "A car listing has been reported on MotorLink Malawi.\n\n";
        $message .= "Listing Details:\n";
        $message .= "- ID: $listingId\n";
        $message .= "- Title: $listingTitle\n";
        $message .= "- Total Reports: $reportCount\n";
        $message .= "- View Listing: " . SITE_URL . "/car.html?id=$listingId\n\n";
        $message .= "Report Details:\n";
        $message .= "- Reason: $reasonText\n";
        $message .= "- Details: $details\n";
        if ($reporterEmail) {
            $message .= "- Reporter Email: $reporterEmail\n";
        }
        $message .= "\nPlease review this report and take appropriate action.\n\n";
        $message .= "---\n";
        $message .= "MotorLink Malawi - Automated System";

        // Email headers
        $headers = "From: MotorLink <noreply@motorlink.mw>\r\n";
        $headers .= "Reply-To: noreply@motorlink.mw\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion();

        // Send email
        @mail($supportEmail, $subject, $message, $headers);
        
    } catch (Exception $e) {
        error_log("sendReportNotificationEmail error: " . $e->getMessage());
        // Don't throw - email failure shouldn't stop the report
    }
}

/**
 * Send verification email to new user via SMTP
 */
function sendVerificationEmail($db, $email, $fullName, $verificationToken, $userId) {
    try {
        // Include SMTP mailer and config
        require_once(__DIR__ . '/includes/smtp-mailer.php');
        $smtp = getSMTPSettings($db);

        $smtpHost = $smtp['host'];
        $smtpPort = $smtp['port'];
        $smtpUsername = $smtp['username'];
        $smtpPassword = $smtp['password'];
        $fromEmail = $smtp['from_email'];
        $fromName = $smtp['from_name'];
        // Determine base URL based on environment (same logic as api.php)
        $serverHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        $isProduction = (strpos($serverHost, 'promanaged-it.com') !== false);
        
        if ($isProduction) {
            $baseUrl = 'https://promanaged-it.com/motorlink/';
        } else {
            // UAT/Local - use current protocol and host
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            
            // HTTP_HOST already includes port if present (e.g., "127.0.0.1:8000")
            // So we don't need to add port again
            $baseUrl = $protocol . '://' . $serverHost . '/';
        }
        
        $verificationLink = $baseUrl . "verify-email.php?token=" . urlencode($verificationToken) . "&id=" . urlencode($userId);
        
        $subject = "MotorLink Malawi - Verify Your Email Address";
        
        // Create HTML email for better formatting
        $htmlMessage = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #212121; margin: 0; padding: 0; background: #f8f9fa; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #00c853 0%, #00a843 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .header h1 { margin: 0; font-size: 28px; font-weight: 700; }
                .content { background: #ffffff; padding: 30px; border-radius: 0 0 10px 10px; border: 1px solid #e0e0e0; }
                .content h2 { color: #00c853; margin-top: 0; }
                .button { display: inline-block; padding: 14px 30px; background: linear-gradient(135deg, #00c853 0%, #00a843 100%); color: white; text-decoration: none; border-radius: 8px; margin: 20px 0; font-weight: 600; box-shadow: 0 4px 12px rgba(0, 200, 83, 0.3); }
                .button:hover { background: linear-gradient(135deg, #00a843 0%, #008f38 100%); box-shadow: 0 6px 16px rgba(0, 200, 83, 0.4); }
                .link-text { word-break: break-all; color: #00c853; font-size: 14px; }
                .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #e0e0e0; font-size: 12px; color: #757575; text-align: center; }
                .info-box { background: rgba(0, 200, 83, 0.08); border: 1px solid #00c853; padding: 15px; border-radius: 8px; margin: 20px 0; }
                .info-box ul { margin: 10px 0 0 20px; padding: 0; }
                .info-box li { margin: 5px 0; color: #212121; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1><i class='fas fa-car'></i> Welcome to MotorLink Malawi!</h1>
                </div>
                <div class='content'>
                    <h2>Verify Your Email Address</h2>
                    <p>Hello <strong>$fullName</strong>,</p>
                    <p>Thank you for registering with MotorLink Malawi!</p>
                    <p>To complete your registration, please verify your email address by clicking the button below:</p>
                    <p style='text-align: center;'>
                        <a href='$verificationLink' class='button'>Verify Email Address</a>
                    </p>
                    <p>Or copy and paste this link into your browser:</p>
                    <p class='link-text'>$verificationLink</p>
                    <div class='info-box'>
                        <strong><i class='fas fa-info-circle'></i> Important Information:</strong>
                        <ul>
                            <li>This link will expire in 7 days</li>
                            <li>After email verification, your account will be immediately activated</li>
                            <li>You'll be automatically logged in after verification</li>
                        </ul>
                    </div>
                    <p>If you didn't create an account with MotorLink, please ignore this email.</p>
                    <p>Best regards,<br><strong>The MotorLink Team</strong></p>
                    <div class='footer'>
                        <p>&copy; " . date('Y') . " MotorLink Malawi. All rights reserved.</p>
                        <p>This is an automated email, please do not reply.</p>
                    </div>
                </div>
            </div>
        </body>
        </html>";
        
        // Plain text version
        $textMessage = "Hello $fullName,\n\n";
        $textMessage .= "Thank you for registering with MotorLink Malawi!\n\n";
        $textMessage .= "To complete your registration, please verify your email address by clicking the link below:\n\n";
        $textMessage .= "$verificationLink\n\n";
        $textMessage .= "This link will expire in 7 days.\n\n";
        $textMessage .= "After email verification, your account will be reviewed by our admin team. ";
        $textMessage .= "You'll receive another email once your account is approved and activated.\n\n";
        $textMessage .= "If you didn't create an account with MotorLink, please ignore this email.\n\n";
        $textMessage .= "Best regards,\n";
        $textMessage .= "MotorLink Malawi Team";
        
        // Create SMTP mailer instance
        $mailer = new SMTPMailer($smtpHost, $smtpPort, $smtpUsername, $smtpPassword, $fromEmail, $fromName);
        
        // Send email via SMTP
        $mailSent = $mailer->send($email, $subject, $htmlMessage, $textMessage);
        
        // Log email attempt
        $logMessage = "Verification email sent to: $email | User ID: $userId | Token: " . substr($verificationToken, 0, 10) . "... | Sent: " . ($mailSent ? 'YES' : 'NO');
        error_log($logMessage);
        
        // Also log to a dedicated file
        $logFile = __DIR__ . '/logs/email_verification.log';
        if (!is_dir(__DIR__ . '/logs')) {
            @mkdir(__DIR__ . '/logs', 0755, true);
        }
        @file_put_contents($logFile, date('Y-m-d H:i:s') . " - $logMessage\n", FILE_APPEND);
        
        // If email sending failed, log detailed error
        if (!$mailSent) {
            $errorDetails = "Email sending failed for: $email | Check SMTP logs for details";
            error_log("sendVerificationEmail FAILED: $errorDetails");
            @file_put_contents($logFile, date('Y-m-d H:i:s') . " - ERROR: $errorDetails\n", FILE_APPEND);
        }
        
        return $mailSent;
        
    } catch (Exception $e) {
        $errorMsg = "sendVerificationEmail exception: " . $e->getMessage();
        error_log($errorMsg);
        
        // Log to dedicated file
        $logFile = __DIR__ . '/logs/email_verification.log';
        if (!is_dir(__DIR__ . '/logs')) {
            @mkdir(__DIR__ . '/logs', 0755, true);
        }
        @file_put_contents($logFile, date('Y-m-d H:i:s') . " - EXCEPTION: $errorMsg\n", FILE_APPEND);
        
        // Don't throw - email failure shouldn't stop registration
        return false;
    }
}

/**
 * Send password reset email to user via SMTP
 */
function sendPasswordResetEmail($db, $email, $fullName, $resetToken, $userId) {
    try {
        // Include SMTP mailer and config
        require_once(__DIR__ . '/includes/smtp-mailer.php');
        $smtp = getSMTPSettings($db);

        $smtpHost = $smtp['host'];
        $smtpPort = $smtp['port'];
        $smtpUsername = $smtp['username'];
        $smtpPassword = $smtp['password'];
        $fromEmail = $smtp['from_email'];
        $fromName = $smtp['from_name'];
        
        // Determine base URL based on environment
        $serverHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        $isProduction = (strpos($serverHost, 'promanaged-it.com') !== false);
        
        if ($isProduction) {
            $baseUrl = 'https://promanaged-it.com/motorlink/';
        } else {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            // HTTP_HOST already includes port if present (e.g., "127.0.0.1:8000")
            $baseUrl = $protocol . '://' . $serverHost . '/';
        }
        
        $resetLink = $baseUrl . "reset-password.php?token=" . urlencode($resetToken) . "&id=" . urlencode($userId);
        
        $subject = "MotorLink Malawi - Reset Your Password";
        
        // HTML email
        $htmlMessage = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #212121; margin: 0; padding: 0; background: #f8f9fa; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #00c853 0%, #00a843 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .header h1 { margin: 0; font-size: 28px; font-weight: 700; }
                .content { background: #ffffff; padding: 30px; border-radius: 0 0 10px 10px; border: 1px solid #e0e0e0; }
                .content h2 { color: #00c853; margin-top: 0; }
                .button { display: inline-block; padding: 14px 30px; background: linear-gradient(135deg, #00c853 0%, #00a843 100%); color: white; text-decoration: none; border-radius: 8px; margin: 20px 0; font-weight: 600; box-shadow: 0 4px 12px rgba(0, 200, 83, 0.3); }
                .button:hover { background: linear-gradient(135deg, #00a843 0%, #008f38 100%); box-shadow: 0 6px 16px rgba(0, 200, 83, 0.4); }
                .link-text { word-break: break-all; color: #00c853; font-size: 14px; }
                .footer { text-align: center; margin-top: 20px; color: #757575; font-size: 12px; }
                .warning { background: #fff3cd; border: 1px solid #ffc107; padding: 15px; border-radius: 8px; margin: 20px 0; color: #856404; }
                .warning strong { color: #856404; }
                .warning ul { margin: 10px 0 0 20px; padding: 0; }
                .warning li { margin: 5px 0; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1><i class='fas fa-car'></i> MotorLink Malawi</h1>
                </div>
                <div class='content'>
                    <h2>Password Reset Request</h2>
                    <p>Hello $fullName,</p>
                    <p>We received a request to reset your password for your MotorLink account.</p>
                    <p>Click the button below to reset your password:</p>
                    <p style='text-align: center;'>
                        <a href='$resetLink' class='button'>Reset Password</a>
                    </p>
                    <p>Or copy and paste this link into your browser:</p>
                    <p class='link-text'>$resetLink</p>
                    <div class='warning'>
                        <strong><i class='fas fa-exclamation-triangle'></i> Security Notice:</strong>
                        <ul>
                            <li>This link will expire in 1 hour</li>
                            <li>If you didn't request this, please ignore this email</li>
                            <li>Your password will not change until you click the link above</li>
                        </ul>
                    </div>
                    <p>If you have any questions, please contact our support team.</p>
                    <p>Best regards,<br><strong>The MotorLink Team</strong></p>
                </div>
                <div class='footer'>
                    <p>&copy; " . date('Y') . " MotorLink Malawi. All rights reserved.</p>
                    <p>This is an automated email, please do not reply.</p>
                </div>
            </div>
        </body>
        </html>";
        
        // Plain text version
        $textMessage = "MotorLink Malawi - Password Reset Request\n\n";
        $textMessage .= "Hello $fullName,\n\n";
        $textMessage .= "We received a request to reset your password for your MotorLink account.\n\n";
        $textMessage .= "Click the following link to reset your password:\n";
        $textMessage .= "$resetLink\n\n";
        $textMessage .= "This link will expire in 1 hour.\n";
        $textMessage .= "If you didn't request this, please ignore this email.\n\n";
        $textMessage .= "Best regards,\nThe MotorLink Team";
        
        // Create SMTP mailer instance
        $mailer = new SMTPMailer($smtpHost, $smtpPort, $smtpUsername, $smtpPassword, $fromEmail, $fromName);
        
        // Send email via SMTP
        $mailSent = $mailer->send($email, $subject, $htmlMessage, $textMessage);
        
        // Log email attempt
        $logFile = __DIR__ . '/logs/password_reset_emails.log';
        if (!is_dir(__DIR__ . '/logs')) {
            @mkdir(__DIR__ . '/logs', 0755, true);
        }
        
        if ($mailSent) {
            @file_put_contents($logFile, date('Y-m-d H:i:s') . " - Password reset email sent to: $email (User ID: $userId)\n", FILE_APPEND);
        } else {
            @file_put_contents($logFile, date('Y-m-d H:i:s') . " - FAILED to send password reset email to: $email (User ID: $userId)\n", FILE_APPEND);
        }
        
        return $mailSent;
        
    } catch (Exception $e) {
        $errorMsg = "sendPasswordResetEmail error for $email: " . $e->getMessage();
        error_log($errorMsg);
        
        // Log to dedicated file
        $logFile = __DIR__ . '/logs/password_reset_emails.log';
        if (!is_dir(__DIR__ . '/logs')) {
            @mkdir(__DIR__ . '/logs', 0755, true);
        }
        @file_put_contents($logFile, date('Y-m-d H:i:s') . " - EXCEPTION: $errorMsg\n", FILE_APPEND);
        
        // Don't throw - email failure shouldn't stop password reset request
        return false;
    }
}

/**
 * Send listing verification email (guest and registered listing submissions).
 */
function sendListingVerificationEmail($db, $email, $fullName, $verificationToken, $listingId, $referenceNumber) {
    try {
        require_once(__DIR__ . '/includes/smtp-mailer.php');
        $smtp = getSMTPSettings($db);

        $smtpHost = $smtp['host'];
        $smtpPort = $smtp['port'];
        $smtpUsername = $smtp['username'];
        $smtpPassword = $smtp['password'];
        $fromEmail = $smtp['from_email'];
        $fromName = $smtp['from_name'];

        $serverHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        $isProduction = (strpos($serverHost, 'promanaged-it.com') !== false);
        if ($isProduction) {
            $baseUrl = 'https://promanaged-it.com/motorlink/';
        } else {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $baseUrl = $protocol . '://' . $serverHost . '/';
        }

        $verificationLink = $baseUrl . 'verify-listing-email.html?token=' . urlencode($verificationToken);
        $subject = 'MotorLink Malawi - Verify Your Listing Email';

        $safeName = htmlspecialchars($fullName ?: 'Seller', ENT_QUOTES, 'UTF-8');
        $safeRef = htmlspecialchars($referenceNumber, ENT_QUOTES, 'UTF-8');

        $htmlMessage = "
        <html>
        <body style='font-family: Arial, sans-serif; color: #212121;'>
            <h2>Verify Your Listing Email</h2>
            <p>Hello <strong>{$safeName}</strong>,</p>
            <p>We received a listing submission (Reference: <strong>{$safeRef}</strong>).</p>
            <p>Please verify your email to continue with admin review:</p>
            <p><a href='{$verificationLink}' style='display:inline-block;padding:12px 22px;background:#00c853;color:#fff;text-decoration:none;border-radius:6px;'>Verify Listing Email</a></p>
            <p>If the button does not work, use this link:</p>
            <p>{$verificationLink}</p>
            <p>This link expires in 24 hours.</p>
        </body>
        </html>";

        $textMessage = "MotorLink Malawi - Verify Your Listing Email\n\n";
        $textMessage .= "Hello {$fullName},\n\n";
        $textMessage .= "We received your listing submission (Reference: {$referenceNumber}).\n";
        $textMessage .= "Please verify your email to continue with admin review:\n\n";
        $textMessage .= $verificationLink . "\n\n";
        $textMessage .= "This link expires in 24 hours.\n";

        $mailer = new SMTPMailer($smtpHost, $smtpPort, $smtpUsername, $smtpPassword, $fromEmail, $fromName);
        $mailSent = $mailer->send($email, $subject, $htmlMessage, $textMessage);

        if (!$mailSent) {
            error_log("sendListingVerificationEmail failed for listing {$listingId} ({$email})");
        }

        return $mailSent;
    } catch (Exception $e) {
        error_log('sendListingVerificationEmail exception: ' . $e->getMessage());
        return false;
    }
}

/**
 * Send guest listing management login code.
 */
function sendGuestListingManageCodeEmail($db, $email, $fullName, $referenceNumber, $listingId, $code, $expiresAt) {
    try {
        require_once(__DIR__ . '/includes/smtp-mailer.php');
        $smtp = getSMTPSettings($db);

        $smtpHost = $smtp['host'];
        $smtpPort = $smtp['port'];
        $smtpUsername = $smtp['username'];
        $smtpPassword = $smtp['password'];
        $fromEmail = $smtp['from_email'];
        $fromName = $smtp['from_name'];

        $serverHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        $isProduction = (strpos($serverHost, 'promanaged-it.com') !== false);
        if ($isProduction) {
            $baseUrl = 'https://promanaged-it.com/motorlink/';
        } else {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $baseUrl = $protocol . '://' . $serverHost . '/';
        }

        $manageLink = $baseUrl . 'guest-manage.html?email=' . urlencode($email);
        $safeName = htmlspecialchars($fullName ?: 'Guest Seller', ENT_QUOTES, 'UTF-8');
        $safeRef = htmlspecialchars($referenceNumber ?: ('Listing #' . (int)$listingId), ENT_QUOTES, 'UTF-8');
        $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
        $safeExpires = htmlspecialchars((string)$expiresAt, ENT_QUOTES, 'UTF-8');

        $subject = 'MotorLink Malawi - Guest Listing Login Code';

        $htmlMessage = "
        <html>
        <body style='font-family: Arial, sans-serif; color: #212121;'>
            <h2>Your Guest Listing Login Code</h2>
            <p>Hello <strong>{$safeName}</strong>,</p>
            <p>Use this temporary code to manage your guest listing (<strong>{$safeRef}</strong>):</p>
            <p style='font-size: 26px; letter-spacing: 6px; font-weight: 700; color: #00a843;'>{$safeCode}</p>
            <p>This code expires on: <strong>{$safeExpires}</strong>.</p>
            <p>You can only perform minimal actions: mark as sold or delete listing.</p>
            <p><a href='{$manageLink}' style='display:inline-block;padding:12px 22px;background:#00c853;color:#fff;text-decoration:none;border-radius:6px;'>Open Guest Listing Manager</a></p>
            <p>If you did not request this code, you can ignore this email.</p>
        </body>
        </html>";

        $textMessage = "MotorLink Malawi - Guest Listing Login Code\n\n";
        $textMessage .= "Hello {$fullName},\n\n";
        $textMessage .= "Use this temporary code to manage your guest listing ({$referenceNumber}): {$code}\n";
        $textMessage .= "Code expires on: {$expiresAt}\n\n";
        $textMessage .= "Manage link: {$manageLink}\n";

        $mailer = new SMTPMailer($smtpHost, $smtpPort, $smtpUsername, $smtpPassword, $fromEmail, $fromName);
        $mailSent = $mailer->send($email, $subject, $htmlMessage, $textMessage);

        if (!$mailSent) {
            error_log("sendGuestListingManageCodeEmail failed for listing {$listingId} ({$email})");
        }

        return $mailSent;
    } catch (Exception $e) {
        error_log('sendGuestListingManageCodeEmail exception: ' . $e->getMessage());
        return false;
    }
}

/**
 * Send approval notification email to user via SMTP
 */
function sendApprovalEmail($db, $email, $fullName) {
    try {
        // Include SMTP mailer and config
        require_once(__DIR__ . '/includes/smtp-mailer.php');
        $smtp = getSMTPSettings($db);

        $smtpHost = $smtp['host'];
        $smtpPort = $smtp['port'];
        $smtpUsername = $smtp['username'];
        $smtpPassword = $smtp['password'];
        $fromEmail = $smtp['from_email'];
        $fromName = $smtp['from_name'];
        
        // Determine base URL based on environment
        $serverHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        $isProduction = (strpos($serverHost, 'promanaged-it.com') !== false);
        
        if ($isProduction) {
            $baseUrl = 'https://promanaged-it.com/motorlink/';
        } else {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            
            // HTTP_HOST already includes port if present (e.g., "127.0.0.1:8000")
            // So we don't need to add port again
            $baseUrl = $protocol . '://' . $serverHost . '/';
        }
        
        $loginLink = $baseUrl . "login.html";
        $subject = "MotorLink Malawi - Your Account Has Been Approved!";
        
        // HTML email
        $htmlMessage = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #212121; margin: 0; padding: 0; background: #f8f9fa; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #00c853 0%, #00a843 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .header h1 { margin: 0; font-size: 28px; font-weight: 700; }
                .content { background: #ffffff; padding: 30px; border-radius: 0 0 10px 10px; border: 1px solid #e0e0e0; }
                .content h2 { color: #00c853; margin-top: 0; }
                .button { display: inline-block; padding: 14px 30px; background: linear-gradient(135deg, #00c853 0%, #00a843 100%); color: white; text-decoration: none; border-radius: 8px; margin: 20px 0; font-weight: 600; box-shadow: 0 4px 12px rgba(0, 200, 83, 0.3); }
                .button:hover { background: linear-gradient(135deg, #00a843 0%, #008f38 100%); box-shadow: 0 6px 16px rgba(0, 200, 83, 0.4); }
                .success-box { background: rgba(0, 200, 83, 0.08); border: 1px solid #00c853; padding: 15px; border-radius: 8px; margin: 20px 0; color: #212121; }
                .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #e0e0e0; font-size: 12px; color: #757575; text-align: center; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1><i class='fas fa-check-circle'></i> Account Approved!</h1>
                </div>
                <div class='content'>
                    <h2>Your Account is Now Active!</h2>
                    <p>Hello <strong>$fullName</strong>,</p>
                    <p>Great news! Your MotorLink account has been reviewed and approved by our admin team.</p>
                    <div class='success-box'>
                        <strong><i class='fas fa-check-circle'></i> Your account is now active!</strong>
                        <p style='margin: 10px 0 0 0;'>You can now start using all our services:</p>
                        <ul style='margin: 10px 0 0 20px; padding: 0;'>
                            <li>List your vehicles for sale</li>
                            <li>Browse car listings</li>
                            <li>Connect with buyers and sellers</li>
                            <li>Access exclusive features</li>
                        </ul>
                    </div>
                    <p style='text-align: center;'>
                        <a href='$loginLink' class='button'>Log In to Your Account</a>
                    </p>
                    <p>Welcome to MotorLink Malawi!</p>
                    <p>Best regards,<br><strong>The MotorLink Team</strong></p>
                    <div class='footer'>
                        <p>&copy; " . date('Y') . " MotorLink Malawi. All rights reserved.</p>
                        <p>This is an automated email, please do not reply.</p>
                    </div>
                </div>
            </div>
        </body>
        </html>";
        
        // Plain text version
        $textMessage = "Hello $fullName,\n\n";
        $textMessage .= "Great news! Your MotorLink account has been reviewed and approved by our admin team.\n\n";
        $textMessage .= "Your account is now active and you can start using all our services:\n";
        $textMessage .= "- List your vehicles for sale\n";
        $textMessage .= "- Browse car listings\n";
        $textMessage .= "- Connect with buyers and sellers\n";
        $textMessage .= "- Access exclusive features\n\n";
        $textMessage .= "You can now log in to your account:\n";
        $textMessage .= "$loginLink\n\n";
        $textMessage .= "Welcome to MotorLink Malawi!\n\n";
        $textMessage .= "Best regards,\n";
        $textMessage .= "MotorLink Malawi Team";
        
        // Create SMTP mailer instance
        $mailer = new SMTPMailer($smtpHost, $smtpPort, $smtpUsername, $smtpPassword, $fromEmail, $fromName);
        
        // Send email via SMTP
        $mailSent = $mailer->send($email, $subject, $htmlMessage, $textMessage);
        
        if (!$mailSent) {
            error_log("sendApprovalEmail FAILED for: $email");
        }
        
    } catch (Exception $e) {
        error_log("sendApprovalEmail error: " . $e->getMessage());
        // Don't throw - email failure shouldn't stop approval
    }
}

/**
 * Send a messaging notification email when a user receives a new message.
 */
function sendMessageNotificationEmail($db, $recipientEmail, $recipientName, $senderName, $listingTitle, $messageText, $conversationId) {
    try {
        if (empty($recipientEmail)) {
            return false;
        }

        require_once(__DIR__ . '/includes/smtp-mailer.php');
        $smtp = getSMTPSettings($db);

        $smtpHost = $smtp['host'];
        $smtpPort = $smtp['port'];
        $smtpUsername = $smtp['username'];
        $smtpPassword = $smtp['password'];
        $fromEmail = $smtp['from_email'];
        $fromName = $smtp['from_name'];

        $serverHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        $isProduction = (strpos($serverHost, 'promanaged-it.com') !== false);
        if ($isProduction) {
            $baseUrl = 'https://promanaged-it.com/motorlink/';
        } else {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $baseUrl = $protocol . '://' . $serverHost . '/';
        }

        $messagesLink = $baseUrl . 'chat_system.html';
        $subject = 'MotorLink Malawi - New Message Alert';
        $safeRecipient = htmlspecialchars((string)$recipientName, ENT_QUOTES, 'UTF-8');
        $safeSender = htmlspecialchars((string)$senderName, ENT_QUOTES, 'UTF-8');
        $safeListing = htmlspecialchars((string)($listingTitle ?: 'Direct Conversation'), ENT_QUOTES, 'UTF-8');
        $messagePreview = trim((string)$messageText);
        if (strlen($messagePreview) > 300) {
            $messagePreview = substr($messagePreview, 0, 297) . '...';
        }
        $safePreview = nl2br(htmlspecialchars($messagePreview, ENT_QUOTES, 'UTF-8'));

        $htmlMessage = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #1f1f1f; margin: 0; padding: 0; background: #f4faf6; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #00c853 0%, #00a843 100%); color: white; padding: 24px; text-align: center; border-radius: 12px 12px 0 0; }
                .header h2 { margin: 0; font-size: 26px; letter-spacing: 0.2px; }
                .content { background: #ffffff; padding: 24px; border-radius: 0 0 12px 12px; border: 1px solid #d7eadf; }
                .message-box { background: #eefaf2; border: 1px solid #b7e7c6; border-radius: 10px; padding: 14px; margin: 16px 0; color: #1f1f1f; }
                .icon-row { margin: 12px 0 2px 0; color: #2f5d3f; font-size: 14px; }
                .icon-row span { display: inline-block; margin-right: 14px; }
                .button { display: inline-block; padding: 12px 22px; background: linear-gradient(135deg, #00c853 0%, #00a843 100%); color: white; text-decoration: none; border-radius: 8px; font-weight: 700; }
                .meta { color: #5f6368; font-size: 13px; margin-top: 10px; }
                .footer { margin-top: 18px; color: #6f6f6f; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2>&#128172; You Have a New Message</h2>
                </div>
                <div class='content'>
                    <p>Hello <strong>{$safeRecipient}</strong>,</p>
                    <p><strong>{$safeSender}</strong> sent you a new message on MotorLink Malawi.</p>
                    <p><strong>Conversation:</strong> {$safeListing}</p>
                    <div class='icon-row'>
                        <span>&#128100; Sender: {$safeSender}</span>
                        <span>&#128663; Listing: {$safeListing}</span>
                    </div>
                    <div class='message-box'>{$safePreview}</div>
                    <p><a href='{$messagesLink}' class='button'>&#128233; Open Message Inbox</a></p>
                    <p class='meta'>Conversation ID: {$conversationId}</p>
                    <div class='footer'>This is an automated notification email from MotorLink Malawi.</div>
                </div>
            </div>
        </body>
        </html>";

        $textMessage = "Hello {$recipientName},\n\n";
        $textMessage .= "{$senderName} sent you a new message on MotorLink Malawi.\n";
        $textMessage .= "Conversation: " . ($listingTitle ?: 'Direct Conversation') . "\n\n";
        $textMessage .= "Message preview:\n{$messagePreview}\n\n";
        $textMessage .= "Open messages: {$messagesLink}\n";
        $textMessage .= "Conversation ID: {$conversationId}\n\n";
        $textMessage .= "This is an automated notification email from MotorLink Malawi.";

        $mailer = new SMTPMailer($smtpHost, $smtpPort, $smtpUsername, $smtpPassword, $fromEmail, $fromName);
        $sent = $mailer->send($recipientEmail, $subject, $htmlMessage, $textMessage);

        if (!$sent) {
            error_log('sendMessageNotificationEmail failed for: ' . $recipientEmail);
        }

        return $sent;
    } catch (Exception $e) {
        error_log('sendMessageNotificationEmail error: ' . $e->getMessage());
        return false;
    }
}

// ============================================================================
// MESSAGING HANDLERS
// ============================================================================

/**
 * Get user's conversations
 */
function getConversations($db) {
    $user = getCurrentUser();

    try {
        $stmt = $db->prepare("
            SELECT c.*,
                   CASE
                       WHEN c.buyer_id = ? THEN c.seller_id
                       ELSE c.buyer_id
                   END as other_user_id,
                   CASE
                       WHEN c.buyer_id = ? THEN seller.full_name
                       ELSE buyer.full_name
                   END as other_user_name,
                   l.title as listing_title,
                   l.id as listing_id,
                   (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = c.id AND m.sender_id != ? AND m.is_read = 0) as unread_count
            FROM conversations c
            LEFT JOIN users buyer ON c.buyer_id = buyer.id
            LEFT JOIN users seller ON c.seller_id = seller.id
            LEFT JOIN car_listings l ON c.listing_id = l.id
            WHERE c.buyer_id = ? OR c.seller_id = ?
            ORDER BY c.last_message_at DESC
        ");
        $stmt->execute([$user['id'], $user['id'], $user['id'], $user['id'], $user['id']]);
        $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        sendSuccess(['conversations' => $conversations]);
    } catch (Exception $e) {
        error_log("getConversations error: " . $e->getMessage());
        sendError('Failed to load conversations', 500);
    }
}

/**
 * Get messages for a conversation
 */
function getMessages($db) {
    $user = getCurrentUser();
    $conversationId = $_GET['conversation_id'] ?? null;

    if (!$conversationId) {
        sendError('Conversation ID required', 400);
    }

    try {
        // Verify user is part of conversation
        $stmt = $db->prepare("SELECT id FROM conversations WHERE id = ? AND (buyer_id = ? OR seller_id = ?)");
        $stmt->execute([$conversationId, $user['id'], $user['id']]);
        if (!$stmt->fetch()) {
            sendError('Conversation not found', 404);
        }

        $stmt = $db->prepare("
            SELECT m.*, u.full_name as sender_name
            FROM messages m
            LEFT JOIN users u ON m.sender_id = u.id
            WHERE m.conversation_id = ?
            ORDER BY m.created_at ASC
        ");
        $stmt->execute([$conversationId]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        sendSuccess(['messages' => $messages]);
    } catch (Exception $e) {
        error_log("getMessages error: " . $e->getMessage());
        sendError('Failed to load messages', 500);
    }
}

/**
 * Send a message in an existing conversation
 */
function sendMessage($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('POST method required', 405);
    }

    $user = getCurrentUser();
    $input = json_decode(file_get_contents('php://input'), true);
    $conversationId = $input['conversation_id'] ?? null;
    $message = trim($input['message'] ?? '');

    if (!$conversationId || empty($message)) {
        sendError('Conversation ID and message required', 400);
    }

    try {
        // Verify user is part of conversation and load participants for notifications.
        $stmt = $db->prepare("\n            SELECT c.id, c.buyer_id, c.seller_id, c.listing_id,\n                   l.title AS listing_title,\n                   b.full_name AS buyer_name, b.email AS buyer_email,\n                   s.full_name AS seller_name, s.email AS seller_email\n            FROM conversations c\n            LEFT JOIN car_listings l ON l.id = c.listing_id\n            LEFT JOIN users b ON b.id = c.buyer_id\n            LEFT JOIN users s ON s.id = c.seller_id\n            WHERE c.id = ? AND (c.buyer_id = ? OR c.seller_id = ?)\n            LIMIT 1\n        ");
        $stmt->execute([$conversationId, $user['id'], $user['id']]);
        $conversation = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$conversation) {
            sendError('Conversation not found', 404);
        }

        $db->beginTransaction();

        // Insert message
        $stmt = $db->prepare("INSERT INTO messages (conversation_id, sender_id, message, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$conversationId, $user['id'], $message]);
        $messageId = $db->lastInsertId();

        // Update conversation
        $stmt = $db->prepare("UPDATE conversations SET last_message = ?, last_message_at = NOW() WHERE id = ?");
        $stmt->execute([$message, $conversationId]);

        $db->commit();

        $senderName = $user['name'] ?? ($user['full_name'] ?? 'MotorLink User');
        $isBuyerSender = ((int)$conversation['buyer_id'] === (int)$user['id']);
        $recipientEmail = $isBuyerSender ? ($conversation['seller_email'] ?? '') : ($conversation['buyer_email'] ?? '');
        $recipientName = $isBuyerSender ? ($conversation['seller_name'] ?? 'User') : ($conversation['buyer_name'] ?? 'User');
        $listingTitle = $conversation['listing_title'] ?? 'Direct Conversation';

        // Best effort email notification for the recipient.
        sendMessageNotificationEmail(
            $db,
            $recipientEmail,
            $recipientName,
            $senderName,
            $listingTitle,
            $message,
            $conversationId
        );

        // Get the inserted message
        $stmt = $db->prepare("SELECT * FROM messages WHERE id = ?");
        $stmt->execute([$messageId]);
        $newMessage = $stmt->fetch(PDO::FETCH_ASSOC);

        sendSuccess(['message' => $newMessage]);
    } catch (Exception $e) {
        $db->rollBack();
        error_log("sendMessage error: " . $e->getMessage());
        sendError('Failed to send message', 500);
    }
}

/**
 * Start a new conversation about a listing
 */
function startConversation($db) {
    error_log("=== startConversation called ===");
    error_log("Request method: " . $_SERVER['REQUEST_METHOD']);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('POST method required', 405);
    }

    $user = getCurrentUser();
    $rawInput = file_get_contents('php://input');
    error_log("Raw input: " . $rawInput);

    $input = json_decode($rawInput, true);
    error_log("Decoded input: " . json_encode($input));

    $listingId = $input['listing_id'] ?? null;
    $sellerId = $input['seller_id'] ?? null;
    $message = trim($input['message'] ?? '');

    error_log("listing_id: " . ($listingId ?? 'NULL'));
    error_log("seller_id: " . ($sellerId ?? 'NULL'));
    error_log("message: " . ($message ?? 'EMPTY'));
    error_log("Current user ID: " . $user['id']);

    if (!$sellerId || empty($message)) {
        error_log("Validation failed - missing required fields");
        sendError('Seller ID and message required', 400);
    }

    // Can't message yourself
    if ($sellerId == $user['id']) {
        sendError('Cannot message yourself', 400);
    }

    // If listing_id is provided, verify ownership and seller consistency.
    if ($listingId) {
        $stmt = $db->prepare("SELECT user_id FROM car_listings WHERE id = ?");
        $stmt->execute([$listingId]);
        $listing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$listing) {
            sendError('Listing not found', 404);
        }

        $listingOwnerId = (int)($listing['user_id'] ?? 0);
        if ($listingOwnerId <= 0) {
            sendError('This listing owner is not available for in-app chat', 400);
        }
        
        if ($listingOwnerId == (int)$user['id']) {
            sendError('Cannot message yourself about your own listing', 400);
        }

        // Always enforce listing owner as the seller.
        if ($sellerId && (int)$sellerId !== $listingOwnerId) {
            sendError('Invalid seller for this listing', 400);
        }
        $sellerId = $listingOwnerId;
    }

    // Validate seller exists
    $sellerStmt = $db->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
    $sellerStmt->execute([(int)$sellerId]);
    if (!$sellerStmt->fetch(PDO::FETCH_ASSOC)) {
        sendError('Recipient not found', 404);
    }

    try {
        // First, ensure conversations table exists
        $db->exec("
            CREATE TABLE IF NOT EXISTS conversations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                listing_id INT NULL,
                buyer_id INT NOT NULL,
                seller_id INT NOT NULL,
                last_message TEXT,
                last_message_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_buyer_id (buyer_id),
                INDEX idx_seller_id (seller_id),
                INDEX idx_listing_id (listing_id),
                INDEX idx_last_message_at (last_message_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Ensure messages table exists
        $db->exec("
            CREATE TABLE IF NOT EXISTS messages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                conversation_id INT NOT NULL,
                sender_id INT NOT NULL,
                message TEXT NOT NULL,
                is_read TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_conversation_id (conversation_id),
                INDEX idx_sender_id (sender_id),
                INDEX idx_is_read (is_read),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->beginTransaction();

        // Check if conversation already exists (with or without listing)
        if ($listingId) {
            $stmt = $db->prepare("
                SELECT id FROM conversations
                WHERE listing_id = ? AND buyer_id = ? AND seller_id = ?
            ");
            $stmt->execute([$listingId, $user['id'], $sellerId]);
        } else {
            $stmt = $db->prepare("
                SELECT id FROM conversations
                WHERE listing_id IS NULL AND buyer_id = ? AND seller_id = ?
            ");
            $stmt->execute([$user['id'], $sellerId]);
        }
        $existing = $stmt->fetch();

        if ($existing) {
            // Add message to existing conversation
            $conversationId = $existing['id'];
            error_log("Using existing conversation: " . $conversationId);
        } else {
            // Create new conversation
            $stmt = $db->prepare("
                INSERT INTO conversations (listing_id, buyer_id, seller_id, last_message, last_message_at, created_at)
                VALUES (?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt->execute([$listingId, $user['id'], $sellerId, $message]);
            $conversationId = $db->lastInsertId();
            error_log("Created new conversation: " . $conversationId);
        }

        // Insert the message
        $stmt = $db->prepare("INSERT INTO messages (conversation_id, sender_id, message, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$conversationId, $user['id'], $message]);
        $messageId = $db->lastInsertId();
        error_log("Inserted message: " . $messageId);

        // Update conversation last message
        $stmt = $db->prepare("UPDATE conversations SET last_message = ?, last_message_at = NOW() WHERE id = ?");
        $stmt->execute([$message, $conversationId]);

        // Check if seller has auto-reply enabled
        $autoReplyStmt = $db->prepare("SELECT auto_reply_enabled, auto_reply_message FROM users WHERE id = ?");
        $autoReplyStmt->execute([$sellerId]);
        $sellerSettings = $autoReplyStmt->fetch(PDO::FETCH_ASSOC);

        $autoReplySent = false;
        if ($sellerSettings && $sellerSettings['auto_reply_enabled'] && !empty($sellerSettings['auto_reply_message'])) {
            // Send auto-reply from seller
            $autoReplyMsg = $sellerSettings['auto_reply_message'];
            $stmt = $db->prepare("INSERT INTO messages (conversation_id, sender_id, message, is_read, created_at) VALUES (?, ?, ?, 0, NOW())");
            $stmt->execute([$conversationId, $sellerId, $autoReplyMsg]);

            // Update conversation with auto-reply
            $stmt = $db->prepare("UPDATE conversations SET last_message = ?, last_message_at = NOW() WHERE id = ?");
            $stmt->execute([$autoReplyMsg, $conversationId]);

            $autoReplySent = true;
            error_log("Auto-reply sent from seller: " . $sellerId);
        }

        $db->commit();

        // Load participants/listing metadata for email notifications.
        $metaStmt = $db->prepare("\n            SELECT c.id, c.buyer_id, c.seller_id,\n                   l.title AS listing_title,\n                   b.full_name AS buyer_name, b.email AS buyer_email,\n                   s.full_name AS seller_name, s.email AS seller_email\n            FROM conversations c\n            LEFT JOIN car_listings l ON l.id = c.listing_id\n            LEFT JOIN users b ON b.id = c.buyer_id\n            LEFT JOIN users s ON s.id = c.seller_id\n            WHERE c.id = ?\n            LIMIT 1\n        ");
        $metaStmt->execute([$conversationId]);
        $conversationMeta = $metaStmt->fetch(PDO::FETCH_ASSOC);

        if ($conversationMeta) {
            $senderName = $user['name'] ?? ($user['full_name'] ?? 'MotorLink User');

            // Notify seller about buyer's initial message.
            sendMessageNotificationEmail(
                $db,
                $conversationMeta['seller_email'] ?? '',
                $conversationMeta['seller_name'] ?? 'User',
                $senderName,
                $conversationMeta['listing_title'] ?? 'Direct Conversation',
                $message,
                $conversationId
            );

            // If auto-reply was sent, notify buyer about seller's auto-reply.
            if ($autoReplySent && !empty($autoReplyMsg)) {
                sendMessageNotificationEmail(
                    $db,
                    $conversationMeta['buyer_email'] ?? '',
                    $conversationMeta['buyer_name'] ?? 'User',
                    $conversationMeta['seller_name'] ?? 'Seller',
                    $conversationMeta['listing_title'] ?? 'Direct Conversation',
                    $autoReplyMsg,
                    $conversationId
                );
            }
        }

        sendSuccess([
            'conversation_id' => $conversationId,
            'message' => 'Conversation started',
            'auto_reply_sent' => $autoReplySent
        ]);
    } catch (Exception $e) {
        $db->rollBack();
        error_log("startConversation error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        sendError('Failed to start conversation: ' . $e->getMessage(), 500);
    }
}

/**
 * Search message recipients for the new-conversation modal
 */
function searchMessageRecipients($db) {
    $user = getCurrentUser();
    $query = trim($_GET['q'] ?? '');

    if (strlen($query) < 2) {
        sendSuccess(['recipients' => []]);
    }

    try {
        $like = '%' . $query . '%';
        $stmt = $db->prepare("\n            SELECT id, full_name, email, type\n            FROM users\n            WHERE id != ?\n              AND (full_name LIKE ? OR email LIKE ?)\n            ORDER BY\n              CASE\n                WHEN full_name LIKE ? THEN 0\n                WHEN email LIKE ? THEN 1\n                ELSE 2\n              END,\n              full_name ASC\n            LIMIT 10\n        ");
        $startsWith = $query . '%';
        $stmt->execute([$user['id'], $like, $like, $startsWith, $startsWith]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $recipients = array_map(function($row) {
            return [
                'id' => (int)$row['id'],
                'display_name' => $row['full_name'] ?: $row['email'],
                'email' => $row['email'] ?? '',
                'type' => $row['type'] ?? 'user'
            ];
        }, $rows ?: []);

        sendSuccess(['recipients' => $recipients]);
    } catch (Exception $e) {
        error_log("searchMessageRecipients error: " . $e->getMessage());
        sendError('Failed to search recipients', 500);
    }
}

/**
 * Mark messages as read
 */
function markMessagesRead($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('POST method required', 405);
    }

    $user = getCurrentUser();
    $conversationId = $_GET['conversation_id'] ?? null;

    if (!$conversationId) {
        sendError('Conversation ID required', 400);
    }

    try {
        // Verify user is part of conversation
        $stmt = $db->prepare("SELECT id FROM conversations WHERE id = ? AND (buyer_id = ? OR seller_id = ?)");
        $stmt->execute([$conversationId, $user['id'], $user['id']]);
        if (!$stmt->fetch()) {
            sendError('Conversation not found', 404);
        }

        // Mark messages from the other user as read
        $stmt = $db->prepare("UPDATE messages SET is_read = 1 WHERE conversation_id = ? AND sender_id != ?");
        $stmt->execute([$conversationId, $user['id']]);

        sendSuccess(['message' => 'Messages marked as read']);
    } catch (Exception $e) {
        error_log("markMessagesRead error: " . $e->getMessage());
        sendError('Failed to mark messages', 500);
    }
}

/**
 * Check for new messages
 */
function checkNewMessages($db) {
    $user = getCurrentUser();

    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) as count
            FROM messages m
            INNER JOIN conversations c ON m.conversation_id = c.id
            WHERE (c.buyer_id = ? OR c.seller_id = ?)
            AND m.sender_id != ?
            AND m.is_read = 0
        ");
        $stmt->execute([$user['id'], $user['id'], $user['id']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        sendSuccess(['has_new' => $result['count'] > 0, 'count' => (int)$result['count']]);
    } catch (Exception $e) {
        error_log("checkNewMessages error: " . $e->getMessage());
        sendSuccess(['has_new' => false, 'count' => 0]);
    }
}

/**
 * Delete a conversation
 */
function deleteConversation($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('DELETE or POST method required', 405);
    }

    $user = getCurrentUser();
    $conversationId = $_GET['conversation_id'] ?? null;

    if (!$conversationId) {
        sendError('Conversation ID required', 400);
    }

    try {
        // Verify user is part of conversation
        $stmt = $db->prepare("SELECT id FROM conversations WHERE id = ? AND (buyer_id = ? OR seller_id = ?)");
        $stmt->execute([$conversationId, $user['id'], $user['id']]);
        if (!$stmt->fetch()) {
            sendError('Conversation not found', 404);
        }

        $db->beginTransaction();

        // Delete messages first
        $stmt = $db->prepare("DELETE FROM messages WHERE conversation_id = ?");
        $stmt->execute([$conversationId]);

        // Delete conversation
        $stmt = $db->prepare("DELETE FROM conversations WHERE id = ?");
        $stmt->execute([$conversationId]);

        $db->commit();

        sendSuccess(['message' => 'Conversation deleted']);
    } catch (Exception $e) {
        $db->rollBack();
        error_log("deleteConversation error: " . $e->getMessage());
        sendError('Failed to delete conversation', 500);
    }
}

function archiveConversation($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('POST method required', 405);
    }

    $user = getCurrentUser();
    $conversationId = $_GET['conversation_id'] ?? null;
    $archive = isset($_GET['archive']) ? (int)$_GET['archive'] : 1;

    if (!$conversationId) {
        sendError('Conversation ID required', 400);
    }

    try {
        applyRuntimeSchemaChange($db, "ALTER TABLE conversations ADD COLUMN archived TINYINT(1) DEFAULT 0");

        $stmt = $db->prepare("SELECT id FROM conversations WHERE id = ? AND (buyer_id = ? OR seller_id = ?)");
        $stmt->execute([$conversationId, $user['id'], $user['id']]);
        if (!$stmt->fetch()) {
            sendError('Conversation not found', 404);
        }

        $stmt = $db->prepare("UPDATE conversations SET archived = ? WHERE id = ?");
        $stmt->execute([$archive ? 1 : 0, $conversationId]);

        sendSuccess(['message' => $archive ? 'Conversation archived' : 'Conversation unarchived']);
    } catch (Exception $e) {
        error_log("archiveConversation error: " . $e->getMessage());
        sendError('Failed to archive conversation', 500);
    }
}

/**
 * Get user's auto-reply settings
 */
function getAutoReplySettings($db) {
    $user = getCurrentUser();

    try {
        // Ensure columns exist only when explicitly enabled for runtime schema updates.
        applyRuntimeSchemaChange($db, "ALTER TABLE users ADD COLUMN auto_reply_enabled TINYINT(1) DEFAULT 0");
        applyRuntimeSchemaChange($db, "ALTER TABLE users ADD COLUMN auto_reply_message TEXT DEFAULT NULL");

        $stmt = $db->prepare("SELECT auto_reply_enabled, auto_reply_message FROM users WHERE id = ?");
        $stmt->execute([$user['id']]);
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);

        sendSuccess([
            'auto_reply_enabled' => (bool)($settings['auto_reply_enabled'] ?? false),
            'auto_reply_message' => $settings['auto_reply_message'] ?? ''
        ]);
    } catch (Exception $e) {
        error_log("getAutoReplySettings error: " . $e->getMessage());
        sendSuccess([
            'auto_reply_enabled' => false,
            'auto_reply_message' => ''
        ]);
    }
}

/**
 * Update user's auto-reply settings
 */
function updateAutoReplySettings($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('POST method required', 405);
    }

    $user = getCurrentUser();
    $input = json_decode(file_get_contents('php://input'), true);

    $enabled = isset($input['auto_reply_enabled']) ? (bool)$input['auto_reply_enabled'] : false;
    $message = trim($input['auto_reply_message'] ?? '');

    try {
        // Ensure columns exist only when explicitly enabled for runtime schema updates.
        applyRuntimeSchemaChange($db, "ALTER TABLE users ADD COLUMN auto_reply_enabled TINYINT(1) DEFAULT 0");
        applyRuntimeSchemaChange($db, "ALTER TABLE users ADD COLUMN auto_reply_message TEXT DEFAULT NULL");

        $stmt = $db->prepare("UPDATE users SET auto_reply_enabled = ?, auto_reply_message = ? WHERE id = ?");
        $stmt->execute([$enabled ? 1 : 0, $message, $user['id']]);

        sendSuccess([
            'message' => 'Auto-reply settings updated',
            'auto_reply_enabled' => $enabled,
            'auto_reply_message' => $message
        ]);
    } catch (Exception $e) {
        error_log("updateAutoReplySettings error: " . $e->getMessage());
        sendError('Failed to update auto-reply settings', 500);
    }
}

// ============================================================================
// DEALER DASHBOARD ENDPOINT HANDLERS
// ============================================================================

/**
 * Get dealer information for logged-in dealer
 */
function getDealerInfo($db) {
    $user = getCurrentUser();

    if ($user['type'] !== 'dealer') {
        sendError('Access denied. Dealer account required.', 403);
    }

    try {
        // Ensure user_id column exists only when explicitly enabled for runtime schema updates.
        applyRuntimeSchemaChange($db, "ALTER TABLE car_dealers ADD COLUMN user_id INT DEFAULT NULL");

        // Try to find dealer by user_id or email, include user's full_name
        $stmt = $db->prepare("
            SELECT cd.*,
                   loc.name as location_name,
                   loc.region,
                   u.full_name as user_full_name
            FROM car_dealers cd
            LEFT JOIN locations loc ON cd.location_id = loc.id
            LEFT JOIN users u ON cd.user_id = u.id
            WHERE cd.user_id = ? OR cd.email = ?
            LIMIT 1
        ");
        $stmt->execute([$user['id'], $user['email']]);
        $dealer = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($dealer && !$dealer['user_id']) {
            // Link existing dealer to user
            $stmt = $db->prepare("UPDATE car_dealers SET user_id = ? WHERE id = ?");
            $stmt->execute([$user['id'], $dealer['id']]);
            $dealer['user_id'] = $user['id'];
        }

        if (!$dealer) {
            // Get default location_id
            $stmt = $db->prepare("SELECT id FROM locations LIMIT 1");
            $stmt->execute();
            $location = $stmt->fetch(PDO::FETCH_ASSOC);
            $locationId = $location ? $location['id'] : 1;

            // Create dealer record
            $stmt = $db->prepare("
                INSERT INTO car_dealers (user_id, business_name, owner_name, email, phone, address, location_id, status)
                VALUES (?, ?, ?, ?, ?, '', ?, 'active')
            ");
            $stmt->execute([
                $user['id'],
                $user['name'] . "'s Showroom",
                $user['name'],
                $user['email'],
                '',
                $locationId
            ]);

            // Fetch the newly created dealer with user's full_name
            $stmt = $db->prepare("
                SELECT cd.*,
                       loc.name as location_name,
                       loc.region,
                       u.full_name as user_full_name
                FROM car_dealers cd
                LEFT JOIN locations loc ON cd.location_id = loc.id
                LEFT JOIN users u ON cd.user_id = u.id
                WHERE cd.user_id = ?
            ");
            $stmt->execute([$user['id']]);
            $dealer = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        sendSuccess(['dealer' => $dealer]);
    } catch (Exception $e) {
        error_log("getDealerInfo error: " . $e->getMessage());
        sendError('Failed to load dealer information', 500);
    }
}

/**
 * Update dealer showroom information
 */
function updateDealerShowroom($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('POST method required', 405);
    }

    $user = getCurrentUser();

    if ($user['type'] !== 'dealer') {
        sendError('Access denied. Dealer account required.', 403);
    }

    try {
        $showroomName = trim($_POST['showroom_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $district = trim($_POST['district'] ?? '');
        $locationId = !empty($_POST['location_id']) ? (int)$_POST['location_id'] : null;
        $weekdayHours = trim($_POST['weekday_hours'] ?? '');
        $saturdayHours = trim($_POST['saturday_hours'] ?? '');
        $sundayHours = trim($_POST['sunday_hours'] ?? '');

        // If district is provided but location_id is not, try to find location_id from district name
        if (!empty($district) && empty($locationId)) {
            $locStmt = $db->prepare("SELECT id FROM locations WHERE LOWER(name) LIKE ? OR LOWER(district) LIKE ? LIMIT 1");
            $locStmt->execute(['%' . strtolower($district) . '%', '%' . strtolower($district) . '%']);
            $location = $locStmt->fetch(PDO::FETCH_ASSOC);
            if ($location) {
                $locationId = $location['id'];
            }
        }

        // Build update query - only update columns that exist in car_dealers table
        // Based on schema: description, phone, email, address, location_id exist
        // showroom_name, city, weekday_hours, saturday_hours, sunday_hours do NOT exist
        $updateFields = [
            'description' => $description,
            'phone' => $phone,
            'email' => $email,
            'address' => $address
        ];

        $setParts = [];
        $params = [];
        foreach ($updateFields as $field => $value) {
            $setParts[] = "{$field} = ?";
            $params[] = $value;
        }

        // Add location_id if we have it (this is the correct column name)
        if ($locationId) {
            $setParts[] = "location_id = ?";
            $params[] = $locationId;
        }

        $setParts[] = "updated_at = NOW()";
        $params[] = $user['id'];

        $sql = "UPDATE car_dealers SET " . implode(', ', $setParts) . " WHERE user_id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        sendSuccess(['message' => 'Showroom details updated successfully']);
    } catch (Exception $e) {
        error_log("updateDealerShowroom error: " . $e->getMessage());
        sendError('Failed to update showroom details', 500);
    }
}

/**
 * Update dealer business information
 */
function updateDealerBusiness($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('POST method required', 405);
    }

    $user = getCurrentUser();

    if ($user['type'] !== 'dealer') {
        sendError('Access denied. Dealer account required.', 403);
    }

    try {
        $businessName = trim($_POST['business_name'] ?? '');
        $yearsInBusiness = intval($_POST['years_in_business'] ?? 0);

        $setParts = ['business_name = ?'];
        $params = [$businessName];

        // Align with current schema variants across environments.
        $yearsField = 'years_established';
        $colCheck = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'car_dealers' AND COLUMN_NAME = 'years_in_business'");
        $colCheck->execute();
        if ((int)$colCheck->fetchColumn() > 0) {
            $yearsField = 'years_in_business';
        }

        $setParts[] = "{$yearsField} = ?";
        $params[] = $yearsInBusiness;
        $setParts[] = 'updated_at = NOW()';
        $params[] = $user['id'];

        $sql = "UPDATE car_dealers SET " . implode(', ', $setParts) . " WHERE user_id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        sendSuccess(['message' => 'Business information updated successfully']);
    } catch (Exception $e) {
        error_log("updateDealerBusiness error: " . $e->getMessage());
        sendError('Failed to update business information', 500);
    }
}

/**
 * Get dealer inventory (all cars listed by this dealer)
 */
function getDealerInventory($db) {
    $user = getCurrentUser();

    if ($user['type'] !== 'dealer') {
        sendError('Access denied. Dealer account required.', 403);
    }

    try {
        // Get all listings for this dealer user by user_id
        $stmt = $db->prepare("
            SELECT cl.*,
                   m.name as make_name,
                   mo.name as model_name,
                   (SELECT file_path FROM car_listing_images WHERE listing_id = cl.id ORDER BY is_primary DESC, id ASC LIMIT 1) as image_url
            FROM car_listings cl
            LEFT JOIN car_makes m ON cl.make_id = m.id
            LEFT JOIN car_models mo ON cl.model_id = mo.id
            WHERE cl.user_id = ?
            ORDER BY cl.created_at DESC
        ");
        $stmt->execute([$user['id']]);
        $cars = $stmt->fetchAll(PDO::FETCH_ASSOC);

        sendSuccess(['cars' => $cars]);
    } catch (Exception $e) {
        error_log("getDealerInventory error: " . $e->getMessage());
        sendError('Failed to load inventory', 500);
    }
}

/**
 * Get dealer recent activity
 */
function getDealerRecentActivity($db) {
    $user = getCurrentUser();

    if ($user['type'] !== 'dealer') {
        sendError('Access denied. Dealer account required.', 403);
    }

    try {
        $activities = [];

        // Get recently added listings
        $stmt = $db->prepare("
            SELECT 'listing_added' as activity_type,
                   cl.id as listing_id,
                   cl.year,
                   m.name as make_name,
                   mo.name as model_name,
                   cl.created_at as activity_date,
                   COALESCE(cl.views_count, 0) as views
            FROM car_listings cl
            LEFT JOIN car_makes m ON cl.make_id = m.id
            LEFT JOIN car_models mo ON cl.model_id = mo.id
            WHERE cl.user_id = ?
            ORDER BY cl.created_at DESC
            LIMIT 10
        ");
        $stmt->execute([$user['id']]);
        $listings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format activities for frontend display
        foreach ($listings as $listing) {
            $carName = trim("{$listing['year']} {$listing['make_name']} {$listing['model_name']}");
            $activityDate = new DateTime($listing['activity_date']);
            $now = new DateTime();
            $diff = $now->diff($activityDate);
            
            // Format time difference
            if ($diff->days == 0) {
                $timeAgo = 'Today';
            } elseif ($diff->days == 1) {
                $timeAgo = 'Yesterday';
            } elseif ($diff->days < 7) {
                $timeAgo = $diff->days . ' days ago';
            } elseif ($diff->days < 30) {
                $weeks = floor($diff->days / 7);
                $timeAgo = $weeks . ($weeks == 1 ? ' week ago' : ' weeks ago');
            } else {
                $timeAgo = $activityDate->format('M j, Y');
            }
            
            $activities[] = [
                'type' => 'listing_added',
                'icon' => 'fa-plus-circle',
                'color' => 'success',
                'title' => 'New Listing Added',
                'description' => $carName . ' - ' . number_format($listing['views']) . ' views',
                'time' => $timeAgo,
                'listing_id' => $listing['listing_id']
            ];
        }

        sendSuccess(['activities' => $activities]);
    } catch (Exception $e) {
        error_log("getDealerRecentActivity error: " . $e->getMessage());
        sendError('Failed to load recent activity', 500);
    }
}

/**
 * Add a new car to dealer inventory
 */
function dealerAddCar($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('POST method required', 405);
    }

    $user = getCurrentUser();

    if ($user['type'] !== 'dealer') {
        sendError('Access denied. Dealer account required.', 403);
    }

    try {
        // Get dealer profile info used for listing defaults.
        $stmt = $db->prepare("SELECT id, location_id FROM car_dealers WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        $dealer = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$dealer) {
            sendError('Dealer record not found', 404);
        }

        $make = trim($_POST['make'] ?? '');
        $model = trim($_POST['model'] ?? '');
        $makeId = intval($_POST['make_id'] ?? 0);
        $modelId = intval($_POST['model_id'] ?? 0);
        $year = intval($_POST['year'] ?? 0);
        $price = floatval($_POST['price'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $mileage = intval($_POST['mileage'] ?? 0);
        $fuelType = strtolower(trim($_POST['fuel_type'] ?? 'petrol'));
        $transmission = strtolower(trim($_POST['transmission'] ?? 'manual'));
        $color = trim($_POST['color'] ?? '');
        $conditionType = strtolower(trim($_POST['condition_type'] ?? 'good'));

        if ($makeId <= 0 && $make !== '') {
            $mkStmt = $db->prepare("SELECT id FROM car_makes WHERE LOWER(name) = LOWER(?) LIMIT 1");
            $mkStmt->execute([$make]);
            $makeRow = $mkStmt->fetch(PDO::FETCH_ASSOC);
            $makeId = $makeRow ? (int)$makeRow['id'] : 0;
        }

        if ($modelId <= 0 && $model !== '' && $makeId > 0) {
            $mdStmt = $db->prepare("SELECT id FROM car_models WHERE make_id = ? AND LOWER(name) = LOWER(?) LIMIT 1");
            $mdStmt->execute([$makeId, $model]);
            $modelRow = $mdStmt->fetch(PDO::FETCH_ASSOC);
            $modelId = $modelRow ? (int)$modelRow['id'] : 0;
        }

        if ($modelId <= 0 && $makeId > 0) {
            $mdStmt = $db->prepare("SELECT id FROM car_models WHERE make_id = ? ORDER BY id ASC LIMIT 1");
            $mdStmt->execute([$makeId]);
            $modelRow = $mdStmt->fetch(PDO::FETCH_ASSOC);
            $modelId = $modelRow ? (int)$modelRow['id'] : 0;
        }

        $locationId = !empty($dealer['location_id']) ? (int)$dealer['location_id'] : 0;
        if ($locationId <= 0) {
            $locStmt = $db->query("SELECT id FROM locations ORDER BY id ASC LIMIT 1");
            $locRow = $locStmt->fetch(PDO::FETCH_ASSOC);
            $locationId = $locRow ? (int)$locRow['id'] : 0;
        }

        if (!in_array($fuelType, ['petrol', 'diesel', 'hybrid', 'electric', 'lpg'], true)) {
            $fuelType = 'petrol';
        }
        if (!in_array($transmission, ['manual', 'automatic', 'cvt', 'semi-automatic'], true)) {
            $transmission = 'manual';
        }
        if (!in_array($conditionType, ['excellent', 'very_good', 'good', 'fair', 'poor'], true)) {
            $conditionType = 'good';
        }

        if ($makeId <= 0 || $modelId <= 0 || $locationId <= 0 || $year < 1990 || $price <= 0) {
            sendError('Invalid car details', 400);
        }

        if ($make === '' || $model === '') {
            $nameStmt = $db->prepare("SELECT m.name AS make_name, mo.name AS model_name FROM car_makes m INNER JOIN car_models mo ON mo.make_id = m.id WHERE m.id = ? AND mo.id = ? LIMIT 1");
            $nameStmt->execute([$makeId, $modelId]);
            $nameRow = $nameStmt->fetch(PDO::FETCH_ASSOC);
            if ($nameRow) {
                $make = $nameRow['make_name'];
                $model = $nameRow['model_name'];
            }
        }

        $title = trim("$year $make $model");
        $refNumber = generateReferenceNumber($db);

        // Insert as dealer-owned listing using normalized schema fields.
        $stmt = $db->prepare("
            INSERT INTO car_listings (
                user_id, dealer_id, reference_number, title, make_id, model_id, year,
                price, description, mileage, fuel_type, transmission, condition_type, exterior_color,
                location_id, status, approval_status, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending_approval', 'pending', NOW())
        ");

        $stmt->execute([
            $user['id'],
            $dealer['id'],
            $refNumber,
            $title,
            $makeId,
            $modelId,
            $year,
            $price,
            $description,
            $mileage,
            $fuelType,
            $transmission,
            $conditionType,
            $color,
            $locationId
        ]);

        $carId = $db->lastInsertId();

        // Handle image uploads with server-side MIME and extension validation.
        if (!empty($_FILES['images'])) {
            $uploadDir = UPLOAD_PATH . 'cars/';
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $imageCount = count($_FILES['images']['name']);
            for ($i = 0; $i < min($imageCount, MAX_VEHICLE_IMAGES); $i++) {
                if ($_FILES['images']['error'][$i] === UPLOAD_ERR_OK) {
                    $validation = validateUploadedImageFile(
                        $_FILES['images']['tmp_name'][$i],
                        $_FILES['images']['name'][$i],
                        (int)$_FILES['images']['size'][$i],
                        $_FILES['images']['type'][$i] ?? '',
                        10 * 1024 * 1024
                    );
                    if (!$validation['ok']) {
                        continue;
                    }

                    $filename = $carId . '_' . time() . '_' . $i . '.' . $validation['extension'];
                    $filepath = $uploadDir . $filename;

                    if (move_uploaded_file($_FILES['images']['tmp_name'][$i], $filepath)) {
                        $isPrimary = ($i === 0) ? 1 : 0;
                        $imgStmt = $db->prepare("
                            INSERT INTO car_listing_images (listing_id, filename, original_filename, file_path, file_size, mime_type, is_primary, sort_order)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $imgStmt->execute([
                            $carId,
                            $filename,
                            $_FILES['images']['name'][$i],
                            $filepath,
                            (int)$_FILES['images']['size'][$i],
                            $validation['mime'],
                            $isPrimary,
                            $i
                        ]);
                    }
                }
            }
        }

        sendSuccess([
            'message' => 'Car added successfully! Pending admin approval.',
            'car_id' => $carId,
            'reference_number' => $refNumber
        ]);
    } catch (Exception $e) {
        error_log("dealerAddCar error: " . $e->getMessage());
        sendError('Failed to add car', 500);
    }
}

/**
 * Delete a car from dealer inventory
 */
function dealerDeleteCar($db) {
    $user = getCurrentUser();

    if ($user['type'] !== 'dealer') {
        sendError('Access denied. Dealer account required.', 403);
    }

    $carId = intval($_GET['car_id'] ?? $_POST['car_id'] ?? 0);

    if ($carId <= 0) {
        sendError('Invalid car ID', 400);
    }

    try {
        // Verify ownership
        $stmt = $db->prepare("SELECT id FROM car_listings WHERE id = ? AND user_id = ?");
        $stmt->execute([$carId, $user['id']]);
        $car = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$car) {
            sendError('Car not found or access denied', 404);
        }

        $db->beginTransaction();

        // Delete car images from both database and filesystem
        deleteListingImages($db, $carId);

        // Delete the listing
        $stmt = $db->prepare("DELETE FROM car_listings WHERE id = ?");
        $stmt->execute([$carId]);

        $db->commit();

        sendSuccess(['message' => 'Car deleted successfully']);
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("dealerDeleteCar error: " . $e->getMessage());
        sendError('Failed to delete car', 500);
    }
}

/**
 * Upload dealer logo
 */
function uploadDealerLogo($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('POST method required', 405);
    }

    $user = getCurrentUser();

    if ($user['type'] !== 'dealer') {
        sendError('Access denied. Dealer account required.', 403);
    }

    if (empty($_FILES['logo'])) {
        sendError('No file uploaded', 400);
    }

    $file = $_FILES['logo'];

    $validation = validateUploadedImageFile(
        $file['tmp_name'],
        $file['name'] ?? 'logo',
        (int)($file['size'] ?? 0),
        $file['type'] ?? '',
        2 * 1024 * 1024
    );
    if (!$validation['ok']) {
        sendError('Invalid logo image: ' . $validation['error'], 400);
    }

    try {
        // Get dealer ID
        $stmt = $db->prepare("SELECT id, logo_url FROM car_dealers WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        $dealer = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$dealer) {
            sendError('Dealer record not found', 404);
        }

        // Create upload directory
        $uploadDir = UPLOAD_PATH . 'dealer_logos/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Delete old logo
        if (!empty($dealer['logo_url']) && file_exists($dealer['logo_url'])) {
            unlink($dealer['logo_url']);
        }

        // Upload new logo
        $filename = 'dealer_' . $dealer['id'] . '_' . time() . '.' . $validation['extension'];
        $filepath = $uploadDir . $filename;

        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            $stmt = $db->prepare("UPDATE car_dealers SET logo_url = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$filepath, $dealer['id']]);

            sendSuccess([
                'message' => 'Logo uploaded successfully',
                'logo_url' => $filepath
            ]);
        } else {
            sendError('Failed to upload file', 500);
        }
    } catch (Exception $e) {
        error_log("uploadDealerLogo error: " . $e->getMessage());
        sendError('Failed to upload logo', 500);
    }
}

/**
 * Update notification preferences
 */
function updateNotificationPreferences($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('POST method required', 405);
    }

    $user = getCurrentUser();

    try {
        $emailNotifications = isset($_POST['email_notifications']) ? 1 : 0;
        $listingUpdates = isset($_POST['listing_updates']) ? 1 : 0;
        $marketingEmails = isset($_POST['marketing_emails']) ? 1 : 0;

        // Ensure columns exist only when explicitly enabled for runtime schema updates.
        applyRuntimeSchemaChange($db, "ALTER TABLE users ADD COLUMN email_notifications TINYINT(1) DEFAULT 1");
        applyRuntimeSchemaChange($db, "ALTER TABLE users ADD COLUMN listing_updates TINYINT(1) DEFAULT 1");
        applyRuntimeSchemaChange($db, "ALTER TABLE users ADD COLUMN marketing_emails TINYINT(1) DEFAULT 0");

        $stmt = $db->prepare("
            UPDATE users
            SET email_notifications = ?,
                listing_updates = ?,
                marketing_emails = ?
            WHERE id = ?
        ");

        $stmt->execute([
            $emailNotifications,
            $listingUpdates,
            $marketingEmails,
            $user['id']
        ]);

        sendSuccess(['message' => 'Notification preferences updated successfully']);
    } catch (Exception $e) {
        error_log("updateNotificationPreferences error: " . $e->getMessage());
        sendError('Failed to update notification preferences', 500);
    }
}

// ============================================================================
// GARAGE DASHBOARD ENDPOINT HANDLERS
// ============================================================================

/**
 * Get garage information for logged-in garage owner
 */
function getGarageInfo($db) {
    $user = getCurrentUser();

    if ($user['type'] !== 'garage') {
        sendError('Access denied. Garage account required.', 403);
    }

    try {
        // Ensure user_id column exists only when explicitly enabled for runtime schema updates.
        applyRuntimeSchemaChange($db, "ALTER TABLE garages ADD COLUMN user_id INT DEFAULT NULL");

        // Try to find garage by user_id or email
        $stmt = $db->prepare("
            SELECT g.*,
                   loc.name as location_name,
                   loc.region
            FROM garages g
            LEFT JOIN locations loc ON g.location_id = loc.id
            WHERE g.user_id = ? OR g.email = ?
            LIMIT 1
        ");
        $stmt->execute([$user['id'], $user['email']]);
        $garage = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($garage && !$garage['user_id']) {
            // Link existing garage to user
            $stmt = $db->prepare("UPDATE garages SET user_id = ? WHERE id = ?");
            $stmt->execute([$user['id'], $garage['id']]);
            $garage['user_id'] = $user['id'];
        }

        if (!$garage) {
            // Get default location_id
            $stmt = $db->prepare("SELECT id FROM locations LIMIT 1");
            $stmt->execute();
            $location = $stmt->fetch(PDO::FETCH_ASSOC);
            $locationId = $location ? $location['id'] : 1;

            // Create garage record
            $stmt = $db->prepare("
                INSERT INTO garages (user_id, name, owner_name, email, phone, address, location_id)
                VALUES (?, ?, ?, ?, ?, '', ?)
            ");
            $stmt->execute([
                $user['id'],
                $user['name'] . "'s Garage",
                $user['name'],
                $user['email'],
                '',
                $locationId
            ]);

            // Fetch the newly created garage
            $stmt = $db->prepare("
                SELECT g.*,
                       loc.name as location_name,
                       loc.region
                FROM garages g
                LEFT JOIN locations loc ON g.location_id = loc.id
                WHERE g.user_id = ?
            ");
            $stmt->execute([$user['id']]);
            $garage = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        sendSuccess(['garage' => $garage]);
    } catch (Exception $e) {
        error_log("getGarageInfo error: " . $e->getMessage());
        sendError('Failed to load garage information', 500);
    }
}

/**
 * Update garage information
 */
function updateGarageInfo($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('POST method required', 405);
    }

    $user = getCurrentUser();

    if ($user['type'] !== 'garage') {
        sendError('Access denied. Garage account required.', 403);
    }

    try {
        $garageName = trim($_POST['garage_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $district = trim($_POST['district'] ?? '');
        $locationId = !empty($_POST['location_id']) ? (int)$_POST['location_id'] : null;

        // If district is provided but location_id is not, try to find location_id from district name
        if (!empty($district) && empty($locationId)) {
            $locStmt = $db->prepare("SELECT id FROM locations WHERE LOWER(name) LIKE ? OR LOWER(district) LIKE ? LIMIT 1");
            $locStmt->execute(['%' . strtolower($district) . '%', '%' . strtolower($district) . '%']);
            $location = $locStmt->fetch(PDO::FETCH_ASSOC);
            if ($location) {
                $locationId = $location['id'];
            }
        }

        // Build update query - only update columns that exist in garages table
        $updateFields = [
            'name' => $garageName,
            'description' => $description,
            'phone' => $phone,
            'email' => $email,
            'address' => $address
        ];

        $setParts = [];
        $params = [];
        foreach ($updateFields as $field => $value) {
            $setParts[] = "{$field} = ?";
            $params[] = $value;
        }

        // Add location_id if we have it (this is the correct column name)
        if ($locationId) {
            $setParts[] = "location_id = ?";
            $params[] = $locationId;
        }

        $setParts[] = "updated_at = NOW()";
        $params[] = $user['id'];

        $sql = "UPDATE garages SET " . implode(', ', $setParts) . " WHERE user_id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        sendSuccess(['message' => 'Garage details updated successfully']);
    } catch (Exception $e) {
        error_log("updateGarageInfo error: " . $e->getMessage());
        sendError('Failed to update garage details', 500);
    }
}

/**
 * Update garage operating hours
 */
function updateGarageHours($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('POST method required', 405);
    }

    $user = getCurrentUser();

    if ($user['type'] !== 'garage') {
        sendError('Access denied. Garage account required.', 403);
    }

    try {
        $operatingHours = trim($_POST['operating_hours'] ?? '');

        $stmt = $db->prepare("
            UPDATE garages
            SET operating_hours = ?,
                updated_at = NOW()
            WHERE user_id = ?
        ");

        $stmt->execute([$operatingHours, $user['id']]);

        sendSuccess(['message' => 'Operating hours updated successfully']);
    } catch (Exception $e) {
        error_log("updateGarageHours error: " . $e->getMessage());
        sendError('Failed to update operating hours', 500);
    }
}

/**
 * Update garage services
 */
function updateGarageServices($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('POST method required', 405);
    }

    $user = getCurrentUser();

    if ($user['type'] !== 'garage') {
        sendError('Access denied. Garage account required.', 403);
    }

    try {
        $servicesInput = $_POST['services'] ?? [];
        if (is_array($servicesInput)) {
            $servicesList = array_values(array_filter(array_map('trim', $servicesInput), function ($value) {
                return $value !== '';
            }));
            $services = json_encode($servicesList, JSON_UNESCAPED_UNICODE);
        } else {
            $servicesRaw = trim((string)$servicesInput);

            if ($servicesRaw === '') {
                $services = '[]';
            } else {
                $decoded = json_decode($servicesRaw, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $services = json_encode(array_values($decoded), JSON_UNESCAPED_UNICODE);
                } else {
                    $servicesList = preg_split('/\s*,\s*/', $servicesRaw, -1, PREG_SPLIT_NO_EMPTY);
                    $services = json_encode(array_values($servicesList ?: []), JSON_UNESCAPED_UNICODE);
                }
            }
        }

        $stmt = $db->prepare("
            UPDATE garages
            SET services = ?,
                updated_at = NOW()
            WHERE user_id = ?
        ");

        $stmt->execute([$services, $user['id']]);

        sendSuccess(['message' => 'Services updated successfully']);
    } catch (Exception $e) {
        error_log("updateGarageServices error: " . $e->getMessage());
        sendError('Failed to update services', 500);
    }
}

/**
 * Get garage reviews
 */
function getGarageReviews($db) {
    $user = getCurrentUser();

    if ($user['type'] !== 'garage') {
        sendError('Access denied. Garage account required.', 403);
    }

    try {
        // Get garage ID
        $stmt = $db->prepare("SELECT id FROM garages WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        $garage = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$garage) {
            sendSuccess(['reviews' => []]);
            return;
        }

        // Try to create garage_reviews table if it doesn't exist
        try {
            $db->exec("
                CREATE TABLE IF NOT EXISTS garage_reviews (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    garage_id INT NOT NULL,
                    user_id INT,
                    rating INT NOT NULL CHECK (rating BETWEEN 1 AND 5),
                    review_text TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (garage_id) REFERENCES garages(id) ON DELETE CASCADE,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
                )
            ");
        } catch (Exception $e) {
            // Table might already exist, continue
        }

        // Get reviews
        $stmt = $db->prepare("
            SELECT gr.*,
                   u.full_name as customer_name
            FROM garage_reviews gr
            LEFT JOIN users u ON gr.user_id = u.id
            WHERE gr.garage_id = ?
            ORDER BY gr.created_at DESC
        ");
        $stmt->execute([$garage['id']]);
        $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

        sendSuccess(['reviews' => $reviews]);
    } catch (Exception $e) {
        error_log("getGarageReviews error: " . $e->getMessage());
        sendError('Failed to load reviews', 500);
    }
}

/**
 * Upload garage logo
 */
function uploadGarageLogo($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('POST method required', 405);
    }

    $user = getCurrentUser();

    if ($user['type'] !== 'garage') {
        sendError('Access denied. Garage account required.', 403);
    }

    if (empty($_FILES['logo'])) {
        sendError('No file uploaded', 400);
    }

    $file = $_FILES['logo'];

    $validation = validateUploadedImageFile(
        $file['tmp_name'],
        $file['name'] ?? 'logo',
        (int)($file['size'] ?? 0),
        $file['type'] ?? '',
        2 * 1024 * 1024
    );
    if (!$validation['ok']) {
        sendError('Invalid logo image: ' . $validation['error'], 400);
    }

    try {
        // Backward-compatible schema fix for deployments missing logo_url.
        try {
            $db->exec("ALTER TABLE garages ADD COLUMN logo_url VARCHAR(255) DEFAULT NULL");
        } catch (Exception $e) {
            // Ignore if column already exists.
        }

        // Get garage ID
        $stmt = $db->prepare("SELECT id, logo_url FROM garages WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        $garage = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$garage) {
            sendError('Garage record not found', 404);
        }

        // Create upload directory
        $uploadDir = UPLOAD_PATH . 'garage_logos/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Delete old logo
        if (!empty($garage['logo_url']) && file_exists($garage['logo_url'])) {
            unlink($garage['logo_url']);
        }

        // Upload new logo
        $filename = 'garage_' . $garage['id'] . '_' . time() . '.' . $validation['extension'];
        $filepath = $uploadDir . $filename;

        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            $stmt = $db->prepare("UPDATE garages SET logo_url = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$filepath, $garage['id']]);

            sendSuccess([
                'message' => 'Logo uploaded successfully',
                'logo_url' => $filepath
            ]);
        } else {
            sendError('Failed to upload file', 500);
        }
    } catch (Exception $e) {
        error_log("uploadGarageLogo error: " . $e->getMessage());
        sendError('Failed to upload logo', 500);
    }
}

// ============================================================================
// CAR HIRE DASHBOARD ENDPOINT HANDLERS
// ============================================================================

/**
 * Get car hire company information for logged-in owner
 */
function getCarHireCompanyInfo($db) {
    $user = getCurrentUser();

    if ($user['type'] !== 'car_hire') {
        sendError('Access denied. Car hire account required.', 403);
    }

    try {
        // Ensure user_id column exists only when explicitly enabled for runtime schema updates.
        applyRuntimeSchemaChange($db, "ALTER TABLE car_hire_companies ADD COLUMN user_id INT DEFAULT NULL");

        // Try to find company by user_id or email
        $stmt = $db->prepare("
            SELECT chc.*,
                   loc.name as location_name,
                   loc.region
            FROM car_hire_companies chc
            LEFT JOIN locations loc ON chc.location_id = loc.id
            WHERE chc.user_id = ? OR chc.email = ?
            LIMIT 1
        ");
        $stmt->execute([$user['id'], $user['email']]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($company && !$company['user_id']) {
            // Link existing company to user
            $stmt = $db->prepare("UPDATE car_hire_companies SET user_id = ? WHERE id = ?");
            $stmt->execute([$user['id'], $company['id']]);
            $company['user_id'] = $user['id'];
        }

        if (!$company) {
            // Get default location_id
            $stmt = $db->prepare("SELECT id FROM locations LIMIT 1");
            $stmt->execute();
            $location = $stmt->fetch(PDO::FETCH_ASSOC);
            $locationId = $location ? $location['id'] : 1;

            // Create company record
            $stmt = $db->prepare("
                INSERT INTO car_hire_companies (user_id, business_name, owner_name, email, phone, address, location_id, status)
                VALUES (?, ?, ?, ?, ?, '', ?, 'active')
            ");
            $stmt->execute([
                $user['id'],
                $user['name'] . "'s Car Hire",
                $user['name'],
                $user['email'],
                '',
                $locationId
            ]);

            // Fetch the newly created company
            $stmt = $db->prepare("
                SELECT chc.*,
                       loc.name as location_name,
                       loc.region
                FROM car_hire_companies chc
                LEFT JOIN locations loc ON chc.location_id = loc.id
                WHERE chc.user_id = ?
            ");
            $stmt->execute([$user['id']]);
            $company = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        sendSuccess(['company' => $company]);
    } catch (Exception $e) {
        error_log("getCarHireCompanyInfo error: " . $e->getMessage());
        sendError('Failed to load company information', 500);
    }
}

/**
 * Update car hire company information
 */
function updateCarHireCompany($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('POST method required', 405);
    }

    $user = getCurrentUser();

    if ($user['type'] !== 'car_hire') {
        sendError('Access denied. Car hire account required.', 403);
    }

    try {
        $companyName = trim($_POST['company_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $district = trim($_POST['district'] ?? '');
        $locationId = !empty($_POST['location_id']) ? (int)$_POST['location_id'] : null;
        $weekdayHours = trim($_POST['weekday_hours'] ?? '');
        $saturdayHours = trim($_POST['saturday_hours'] ?? '');
        $sundayHours = trim($_POST['sunday_hours'] ?? '');

        // If district is provided but location_id is not, try to find location_id from district name
        if (!empty($district) && empty($locationId)) {
            $locStmt = $db->prepare("SELECT id FROM locations WHERE LOWER(name) LIKE ? OR LOWER(district) LIKE ? LIMIT 1");
            $locStmt->execute(['%' . strtolower($district) . '%', '%' . strtolower($district) . '%']);
            $location = $locStmt->fetch(PDO::FETCH_ASSOC);
            if ($location) {
                $locationId = $location['id'];
            }
        }

        // Build update query - only update columns that exist in car_hire_companies table
        // Based on schema: business_name, description, phone, email, address, location_id exist
        // company_name, city, weekday_hours, saturday_hours, sunday_hours, district do NOT exist
        $updateFields = [
            'business_name' => $companyName,
            'description' => $description,
            'phone' => $phone,
            'email' => $email,
            'address' => $address
        ];

        $setParts = [];
        $params = [];
        foreach ($updateFields as $field => $value) {
            $setParts[] = "{$field} = ?";
            $params[] = $value;
        }

        // Add location_id if we have it (this is the correct column name)
        if ($locationId) {
            $setParts[] = "location_id = ?";
            $params[] = $locationId;
        }

        $setParts[] = "updated_at = NOW()";
        $params[] = $user['id'];

        $sql = "UPDATE car_hire_companies SET " . implode(', ', $setParts) . " WHERE user_id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        sendSuccess(['message' => 'Company details updated successfully']);
    } catch (Exception $e) {
        error_log("updateCarHireCompany error: " . $e->getMessage());
        sendError('Failed to update company details', 500);
    }
}

/**
 * Get car hire fleet for management
 */
function getCarHireFleetManagement($db) {
    $user = getCurrentUser();

    if ($user['type'] !== 'car_hire') {
        sendError('Access denied. Car hire account required.', 403);
    }

    try {
        // Get company profile details for fleet denormalized fields.
        $stmt = $db->prepare("SELECT id, business_name, phone, email, location_id FROM car_hire_companies WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$company) {
            sendSuccess(['fleet' => []]);
            return;
        }

        $stmt = $db->prepare("
            SELECT chf.*,
                   m.name as make_name,
                   mo.name as model_name
            FROM car_hire_fleet chf
            LEFT JOIN car_makes m ON chf.make_id = m.id
            LEFT JOIN car_models mo ON chf.model_id = mo.id
            WHERE chf.company_id = ?
            ORDER BY chf.created_at DESC
        ");
        $stmt->execute([$company['id']]);
        $fleet = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Map image field to image_url for frontend
        foreach ($fleet as &$vehicle) {
            if (!empty($vehicle['image'])) {
                $vehicle['image_url'] = UPLOAD_PATH . 'fleet/' . $vehicle['image'];
            }
        }

        sendSuccess(['fleet' => $fleet]);
    } catch (Exception $e) {
        error_log("getCarHireFleetManagement error: " . $e->getMessage());
        sendError('Failed to load fleet', 500);
    }
}

/**
 * Add vehicle to car hire fleet
 */
function addCarHireVehicle($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('POST method required', 405);
    }

    $user = getCurrentUser();

    if ($user['type'] !== 'car_hire') {
        sendError('Access denied. Car hire account required.', 403);
    }

    try {
        // Get company ID
        $stmt = $db->prepare("SELECT id FROM car_hire_companies WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$company) {
            sendError('Company record not found', 404);
        }

        $makeId = intval($_POST['make_id'] ?? 0);
        $modelId = intval($_POST['model_id'] ?? 0);
        $year = intval($_POST['year'] ?? 0);
        $dailyRate = floatval($_POST['daily_rate'] ?? 0);
        $licensePlate = trim($_POST['license_plate'] ?? '');
        $seats = intval($_POST['seats'] ?? 4);
        $fuelType = trim($_POST['fuel_type'] ?? 'petrol');
        $transmission = trim($_POST['transmission'] ?? 'manual');
        $color = trim($_POST['color'] ?? '');
        $status = trim($_POST['status'] ?? 'available');

        // Validate required fields
        if ($makeId <= 0 || $modelId <= 0 || $year < 1990 || $dailyRate <= 0) {
            sendError('Invalid vehicle details. Please provide make, model, year, and daily rate.', 400);
        }

        // Validate make and model exist and match
        $stmt = $db->prepare("SELECT id, name FROM car_makes WHERE id = ? AND is_active = 1");
        $stmt->execute([$makeId]);
        $make = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$make) {
            sendError('Invalid make selected', 400);
        }

        $stmt = $db->prepare("SELECT id, name FROM car_models WHERE id = ? AND make_id = ? AND is_active = 1");
        $stmt->execute([$modelId, $makeId]);
        $model = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$model) {
            sendError('Invalid model selected or model does not belong to selected make', 400);
        }

        // Build vehicle name
        $vehicleName = "$year {$make['name']} {$model['name']}";

        // Insert vehicle using live schema columns.
        $stmt = $db->prepare("
            INSERT INTO car_hire_fleet (
                company_id, company_name, company_phone, company_email, company_location_id,
                make_id, model_id, make_name, model_name, year, vehicle_name,
                registration_number, transmission, fuel_type, seats, exterior_color,
                daily_rate, is_available, status, is_active, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, 1, NOW())
        ");

        $stmt->execute([
            $company['id'],
            $company['business_name'] ?? null,
            $company['phone'] ?? null,
            $company['email'] ?? null,
            !empty($company['location_id']) ? (int)$company['location_id'] : null,
            $makeId,
            $modelId,
            $make['name'],
            $model['name'],
            $year,
            $vehicleName,
            $licensePlate,
            $transmission,
            $fuelType,
            max(1, $seats),
            $color,
            $dailyRate,
            $status
        ]);

        $vehicleId = $db->lastInsertId();

        // Handle image uploads with server-side MIME and extension validation.
        if (!empty($_FILES['images'])) {
            $uploadDir = UPLOAD_PATH . 'fleet/';
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $imageCount = count($_FILES['images']['name']);
            $firstUploadedFilename = null;
            for ($i = 0; $i < min($imageCount, MAX_VEHICLE_IMAGES); $i++) {
                if ($_FILES['images']['error'][$i] === UPLOAD_ERR_OK) {
                    $validation = validateUploadedImageFile(
                        $_FILES['images']['tmp_name'][$i],
                        $_FILES['images']['name'][$i],
                        (int)$_FILES['images']['size'][$i],
                        $_FILES['images']['type'][$i] ?? '',
                        10 * 1024 * 1024
                    );
                    if (!$validation['ok']) {
                        continue;
                    }

                    $filename = 'fleet_' . $vehicleId . '_' . time() . '_' . $i . '.' . $validation['extension'];
                    $filepath = $uploadDir . $filename;

                    if (move_uploaded_file($_FILES['images']['tmp_name'][$i], $filepath) && $firstUploadedFilename === null) {
                        $firstUploadedFilename = $filename;
                    }
                }
            }

            // Persist primary image so fleet cards and edit modal have a stable thumbnail.
            if ($firstUploadedFilename !== null) {
                $imageStmt = $db->prepare("UPDATE car_hire_fleet SET image = ?, updated_at = NOW() WHERE id = ?");
                $imageStmt->execute([$firstUploadedFilename, $vehicleId]);
            }
        }

        sendSuccess([
            'message' => 'Vehicle added to fleet successfully!',
            'vehicle_id' => $vehicleId
        ]);
    } catch (Exception $e) {
        error_log("addCarHireVehicle error: " . $e->getMessage());
        sendError('Failed to add vehicle', 500);
    }
}

/**
 * Update vehicle status (available, rented, maintenance)
 */
function updateVehicleStatus($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('POST method required', 405);
    }

    $user = getCurrentUser();

    if ($user['type'] !== 'car_hire') {
        sendError('Access denied. Car hire account required.', 403);
    }

    $vehicleId = intval($_GET['vehicle_id'] ?? $_POST['vehicle_id'] ?? 0);
    $status = trim($_GET['status'] ?? $_POST['status'] ?? '');

    if ($vehicleId <= 0) {
        sendError('Invalid vehicle ID', 400);
    }

    if (!in_array($status, ['available', 'rented', 'maintenance', 'not_available'])) {
        sendError('Invalid status', 400);
    }

    try {
        // First, get the user's car hire company
        $companyStmt = $db->prepare("
            SELECT id 
            FROM car_hire_companies 
            WHERE user_id = ? OR email = ?
            LIMIT 1
        ");
        $companyStmt->execute([$user['id'], $user['email']]);
        $company = $companyStmt->fetch(PDO::FETCH_ASSOC);

        if (!$company) {
            sendError('Car hire company not found for this user', 404);
        }

        // Verify ownership - check if vehicle belongs to this company
        $stmt = $db->prepare("
            SELECT id
            FROM car_hire_fleet
            WHERE id = ? AND company_id = ?
        ");
        $stmt->execute([$vehicleId, $company['id']]);
        $vehicle = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$vehicle) {
            sendError('Vehicle not found or access denied', 404);
        }

        // Update the status
        $stmt = $db->prepare("UPDATE car_hire_fleet SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$status, $vehicleId]);

        sendSuccess(['message' => 'Vehicle status updated successfully']);
    } catch (Exception $e) {
        error_log("updateVehicleStatus error: " . $e->getMessage());
        sendError('Failed to update vehicle status: ' . $e->getMessage(), 500);
    }
}

/**
 * Delete vehicle from fleet
 */
function deleteCarHireVehicle($db) {
    $user = getCurrentUser();

    if ($user['type'] !== 'car_hire') {
        sendError('Access denied. Car hire account required.', 403);
    }

    $vehicleId = intval($_GET['vehicle_id'] ?? $_POST['vehicle_id'] ?? 0);

    if ($vehicleId <= 0) {
        sendError('Invalid vehicle ID', 400);
    }

    try {
        // Verify ownership
        $stmt = $db->prepare("
            SELECT chf.id, chf.image
            FROM car_hire_fleet chf
            INNER JOIN car_hire_companies chc ON chf.company_id = chc.id
            WHERE chf.id = ? AND chc.user_id = ?
        ");
        $stmt->execute([$vehicleId, $user['id']]);
        $vehicle = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$vehicle) {
            sendError('Vehicle not found or access denied', 404);
        }

        $db->beginTransaction();

        // Delete vehicle image from filesystem
        if (!empty($vehicle['image'])) {
            $imagePath = 'uploads/' . $vehicle['image'];
            if (file_exists($imagePath)) {
                @unlink($imagePath);
            }
        }

        // Delete the fleet vehicle
        $stmt = $db->prepare("DELETE FROM car_hire_fleet WHERE id = ?");
        $stmt->execute([$vehicleId]);

        $db->commit();

        sendSuccess(['message' => 'Vehicle deleted successfully']);
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("deleteCarHireVehicle error: " . $e->getMessage());
        sendError('Failed to delete vehicle', 500);
    }
}

/**
 * Get car hire rentals
 */
function getCarHireRentals($db) {
    $user = getCurrentUser();

    if ($user['type'] !== 'car_hire') {
        sendError('Access denied. Car hire account required.', 403);
    }

    try {
        // Get company ID
        $stmt = $db->prepare("SELECT id FROM car_hire_companies WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$company) {
            sendSuccess(['rentals' => []]);
            return;
        }

        // For now, return empty array as rental system is not fully implemented
        // This would connect to a bookings/rentals table when available
        sendSuccess(['rentals' => []]);
    } catch (Exception $e) {
        error_log("getCarHireRentals error: " . $e->getMessage());
        sendError('Failed to load rentals', 500);
    }
}

/**
 * Complete a rental
 */
function completeRental($db) {
    $user = getCurrentUser();

    if ($user['type'] !== 'car_hire') {
        sendError('Access denied. Car hire account required.', 403);
    }

    $rentalId = intval($_GET['rental_id'] ?? $_POST['rental_id'] ?? 0);

    if ($rentalId <= 0) {
        sendError('Invalid rental ID', 400);
    }

    // Placeholder for rental completion logic
    // Would update rental status and vehicle availability
    sendSuccess(['message' => 'Rental completed successfully']);
}

/**
 * Upload car hire company logo
 */
function uploadCarHireLogo($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('POST method required', 405);
    }

    $user = getCurrentUser();

    if ($user['type'] !== 'car_hire') {
        sendError('Access denied. Car hire account required.', 403);
    }

    if (empty($_FILES['logo'])) {
        sendError('No file uploaded', 400);
    }

    $file = $_FILES['logo'];

    $validation = validateUploadedImageFile(
        $file['tmp_name'],
        $file['name'] ?? 'logo',
        (int)($file['size'] ?? 0),
        $file['type'] ?? '',
        2 * 1024 * 1024
    );
    if (!$validation['ok']) {
        sendError('Invalid logo image: ' . $validation['error'], 400);
    }

    try {
        // Backward-compatible schema fix for deployments missing logo_url.
        try {
            $db->exec("ALTER TABLE car_hire_companies ADD COLUMN logo_url VARCHAR(255) DEFAULT NULL");
        } catch (Exception $e) {
            // Ignore if column already exists.
        }

        // Get company ID
        $stmt = $db->prepare("SELECT id, logo_url FROM car_hire_companies WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$company) {
            sendError('Company record not found', 404);
        }

        // Create upload directory
        $uploadDir = UPLOAD_PATH . 'car_hire_logos/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Delete old logo
        if (!empty($company['logo_url']) && file_exists($company['logo_url'])) {
            unlink($company['logo_url']);
        }

        // Upload new logo
        $filename = 'car_hire_' . $company['id'] . '_' . time() . '.' . $validation['extension'];
        $filepath = $uploadDir . $filename;

        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            $stmt = $db->prepare("UPDATE car_hire_companies SET logo_url = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$filepath, $company['id']]);

            sendSuccess([
                'message' => 'Logo uploaded successfully',
                'logo_url' => $filepath
            ]);
        } else {
            sendError('Failed to upload file', 500);
        }
    } catch (Exception $e) {
        error_log("uploadCarHireLogo error: " . $e->getMessage());
        sendError('Failed to upload logo', 500);
    }
}

// ============================================================================
// EDIT MODAL ENDPOINTS
// ============================================================================

/**
 * Get listing details for editing (includes images)
 */
function getListingForEdit($db) {
    $id = $_GET['id'] ?? '';
    if (empty($id) || !is_numeric($id)) {
        sendError('Valid listing ID required', 400);
    }

    $user = getCurrentUser();

    try {
        // Fetch listing - verify ownership or admin
        $stmt = $db->prepare("
            SELECT l.*, m.name as make_name, mo.name as model_name
            FROM car_listings l
            LEFT JOIN car_makes m ON l.make_id = m.id
            LEFT JOIN car_models mo ON l.model_id = mo.id
            WHERE l.id = ?
        ");
        $stmt->execute([$id]);
        $listing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$listing) {
            sendError('Listing not found', 404);
        }

        // Check ownership - all user types (individual, dealer, garage, car_hire) use user_id
        $hasAccess = false;
        
        // Check if user owns this listing directly by user_id
        if ($listing['user_id'] == $user['id']) {
            $hasAccess = true;
        }
        
        // Admin can access everything
        if ($user['type'] === 'admin') {
            $hasAccess = true;
        }
        
        if (!$hasAccess) {
            sendError('Access denied - You can only edit your own listings', 403);
        }

        // Get images with full file path
        $imageStmt = $db->prepare("
            SELECT id, filename, is_primary, sort_order, file_path
            FROM car_listing_images
            WHERE listing_id = ?
            ORDER BY is_primary DESC, sort_order ASC
        ");
        $imageStmt->execute([$id]);
        $images = $imageStmt->fetchAll(PDO::FETCH_ASSOC);

        // Add full file path for frontend if not already set
        foreach ($images as &$image) {
            if (empty($image['file_path'])) {
                $image['file_path'] = UPLOAD_PATH . $image['filename'];
            }
        }

        $listing['images'] = $images;

        sendSuccess(['listing' => $listing]);
    } catch (Exception $e) {
        error_log("getListingForEdit error: " . $e->getMessage());
        sendError('Failed to load listing', 500);
    }
}

/**
 * Update car listing
 */
function updateCarListing($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('POST method required', 405);
    }

    $user = getCurrentUser();
    $carId = $_POST['car_id'] ?? '';

    if (empty($carId) || !is_numeric($carId)) {
        sendError('Valid car ID required', 400);
    }

    try {
        // Verify ownership
        $stmt = $db->prepare("SELECT user_id FROM car_listings WHERE id = ?");
        $stmt->execute([$carId]);
        $listing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$listing) {
            sendError('Listing not found', 404);
        }

        // Check ownership - all user types use user_id
        if ($listing['user_id'] != $user['id'] && $user['type'] !== 'admin') {
            sendError('Access denied', 403);
        }

        // Build update query
        $updateFields = [];
        $params = [];

        // Update fields from POST data
        $allowedFields = [
            'title', 'price', 'year', 'mileage', 'fuel_type', 'transmission',
            'condition_type', 'color', 'description', 'status', 'make_id', 'model_id',
            'location_id', 'features', 'interior_color', 'drive_type', 'drivetrain', 'engine_size',
            'doors', 'seats', 'vin', 'fuel_tank_capacity'
        ];
        
        // Map drivetrain to drive_type for database compatibility
        if (isset($_POST['drivetrain']) && !isset($_POST['drive_type'])) {
            $_POST['drive_type'] = $_POST['drivetrain'];
        }

        foreach ($allowedFields as $field) {
            if (isset($_POST[$field])) {
                $updateFields[] = "$field = ?";
                $params[] = $_POST[$field];
            }
        }

        if (empty($updateFields)) {
            sendError('No fields to update', 400);
        }

        $updateFields[] = "updated_at = NOW()";
        $params[] = $carId;

        $sql = "UPDATE car_listings SET " . implode(', ', $updateFields) . " WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        // Handle new image uploads
        if (!empty($_FILES['images']['name'][0])) {
            $uploadDir = __DIR__ . '/' . UPLOAD_PATH . 'cars/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            foreach ($_FILES['images']['tmp_name'] as $key => $tmpName) {
                if ($_FILES['images']['error'][$key] === UPLOAD_ERR_OK) {
                    $ext = pathinfo($_FILES['images']['name'][$key], PATHINFO_EXTENSION);
                    $filename = 'car_' . $carId . '_' . time() . '_' . $key . '.' . $ext;
                    $filepath = $uploadDir . $filename;

                    if (move_uploaded_file($tmpName, $filepath)) {
                        // Get current max sort order
                        $sortStmt = $db->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 as next_sort FROM car_listing_images WHERE listing_id = ?");
                        $sortStmt->execute([$carId]);
                        $sortOrder = $sortStmt->fetchColumn();

                        // Insert image record
                        $imgStmt = $db->prepare("
                            INSERT INTO car_listing_images (listing_id, filename, is_primary, sort_order)
                            VALUES (?, ?, 0, ?)
                        ");
                        $imgStmt->execute([$carId, 'cars/' . $filename, $sortOrder]);
                    }
                }
            }
        }

        sendSuccess(['message' => 'Listing updated successfully']);
    } catch (Exception $e) {
        error_log("updateCarListing error: " . $e->getMessage());
        sendError('Failed to update listing', 500);
    }
}

/**
 * Delete a car listing image
 */
function deleteCarImage($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('POST method required', 405);
    }

    $imageId = $_POST['image_id'] ?? '';

    if (empty($imageId) || !is_numeric($imageId)) {
        sendError('Valid image ID required', 400);
    }

    $user = getCurrentUser();

    try {
        // Get image details and verify ownership
        $stmt = $db->prepare("
            SELECT cli.*, cl.user_id
            FROM car_listing_images cli
            INNER JOIN car_listings cl ON cli.listing_id = cl.id
            WHERE cli.id = ?
        ");
        $stmt->execute([$imageId]);
        $image = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$image) {
            sendError('Image not found', 404);
        }

        // Check ownership - all user types use user_id
        if ($image['user_id'] != $user['id'] && $user['type'] !== 'admin') {
            sendError('Access denied', 403);
        }

        // Delete file from filesystem
        $filepath = __DIR__ . '/' . UPLOAD_PATH . $image['filename'];
        if (file_exists($filepath)) {
            unlink($filepath);
        }

        // Delete database record
        $deleteStmt = $db->prepare("DELETE FROM car_listing_images WHERE id = ?");
        $deleteStmt->execute([$imageId]);

        sendSuccess(['message' => 'Image deleted successfully']);
    } catch (Exception $e) {
        error_log("deleteCarImage error: " . $e->getMessage());
        sendError('Failed to delete image', 500);
    }
}

/**
 * Get car hire vehicle details for editing
 */
function getVehicleForEdit($db) {
    $id = $_GET['id'] ?? '';
    if (empty($id) || !is_numeric($id)) {
        sendError('Valid vehicle ID required', 400);
    }

    $user = getCurrentUser();

    if ($user['type'] !== 'car_hire') {
        sendError('Access denied. Car hire account required.', 403);
    }

    try {
        // Get company ID
        $companyStmt = $db->prepare("SELECT id FROM car_hire_companies WHERE user_id = ?");
        $companyStmt->execute([$user['id']]);
        $company = $companyStmt->fetch(PDO::FETCH_ASSOC);

        if (!$company) {
            sendError('Car hire company not found', 404);
        }

        // Fetch vehicle - verify ownership
        $stmt = $db->prepare("
            SELECT chf.*, m.name as make_name, mo.name as model_name
            FROM car_hire_fleet chf
            LEFT JOIN car_makes m ON chf.make_id = m.id
            LEFT JOIN car_models mo ON chf.model_id = mo.id
            WHERE chf.id = ? AND chf.company_id = ?
        ");
        $stmt->execute([$id, $company['id']]);
        $vehicle = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$vehicle) {
            sendError('Vehicle not found', 404);
        }

        // Add full file path for image
        if (!empty($vehicle['image'])) {
            $vehicle['image_url'] = UPLOAD_PATH . 'fleet/' . $vehicle['image'];
        }
        
        // Map registration_number to license_plate for frontend compatibility
        if (!empty($vehicle['registration_number'])) {
            $vehicle['license_plate'] = $vehicle['registration_number'];
        }
        
        // Map database field names to frontend field names
        $vehicle['make'] = $vehicle['make_name'] ?? '';
        $vehicle['model'] = $vehicle['model_name'] ?? '';
        
        // Map exterior_color to color
        if (!empty($vehicle['exterior_color'])) {
            $vehicle['color'] = $vehicle['exterior_color'];
        }

        sendSuccess(['vehicle' => $vehicle]);
    } catch (Exception $e) {
        error_log("getVehicleForEdit error: " . $e->getMessage());
        sendError('Failed to load vehicle', 500);
    }
}

/**
 * Update car hire vehicle
 */
function updateVehicle($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('POST method required', 405);
    }

    $user = getCurrentUser();

    if ($user['type'] !== 'car_hire') {
        sendError('Access denied. Car hire account required.', 403);
    }

    $vehicleId = $_POST['vehicle_id'] ?? '';

    if (empty($vehicleId) || !is_numeric($vehicleId)) {
        sendError('Valid vehicle ID required', 400);
    }

    try {
        // Get company ID and verify ownership
        $companyStmt = $db->prepare("SELECT id FROM car_hire_companies WHERE user_id = ?");
        $companyStmt->execute([$user['id']]);
        $company = $companyStmt->fetch(PDO::FETCH_ASSOC);

        if (!$company) {
            sendError('Car hire company not found', 404);
        }

        // Verify vehicle ownership
        $vehicleStmt = $db->prepare("SELECT id FROM car_hire_fleet WHERE id = ? AND company_id = ?");
        $vehicleStmt->execute([$vehicleId, $company['id']]);

        if (!$vehicleStmt->fetch()) {
            sendError('Vehicle not found or access denied', 404);
        }

        // Build update query
        $updateFields = [];
        $params = [];

        // Update fields from POST data - only fields that exist in car_hire_fleet table
        $allowedFields = [
            'transmission', 'fuel_type', 'seats', 'daily_rate', 'weekly_rate', 
            'monthly_rate', 'features', 'status', 'registration_number', 'exterior_color'
        ];

        foreach ($allowedFields as $field) {
            if (isset($_POST[$field])) {
                $updateFields[] = "$field = ?";
                $params[] = $_POST[$field];
            }
        }
        
        // Handle license_plate -> registration_number mapping
        if (isset($_POST['license_plate'])) {
            $updateFields[] = "registration_number = ?";
            $params[] = $_POST['license_plate'];
        }
        
        // Handle color -> exterior_color mapping
        if (isset($_POST['color'])) {
            $updateFields[] = "exterior_color = ?";
            $params[] = $_POST['color'];
        }

        if (empty($updateFields)) {
            sendError('No fields to update', 400);
        }

        $updateFields[] = "updated_at = NOW()";
        $params[] = $vehicleId;

        $sql = "UPDATE car_hire_fleet SET " . implode(', ', $updateFields) . " WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        // Handle new image uploads (multiple images)
        if (!empty($_FILES['images']['name']) && is_array($_FILES['images']['name'])) {
            $uploadDir = __DIR__ . '/' . UPLOAD_PATH . 'fleet/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $imageCount = count($_FILES['images']['name']);
            $featuredImageSet = false;
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            
            for ($i = 0; $i < min($imageCount, MAX_VEHICLE_IMAGES); $i++) {
                if ($_FILES['images']['error'][$i] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES['images']['name'][$i], PATHINFO_EXTENSION));
                    
                    // Validate file extension
                    if (!in_array($ext, $allowedExtensions)) {
                        continue; // Skip invalid file types
                    }
                    
                    $filename = 'fleet_' . $vehicleId . '_' . time() . '_' . $i . '.' . $ext;
                    $filepath = $uploadDir . $filename;

                    if (move_uploaded_file($_FILES['images']['tmp_name'][$i], $filepath)) {
                        // Set first uploaded image as featured image in car_hire_fleet table
                        if (!$featuredImageSet) {
                            $imgStmt = $db->prepare("UPDATE car_hire_fleet SET image = ?, updated_at = NOW() WHERE id = ?");
                            $imgStmt->execute([$filename, $vehicleId]);
                            $featuredImageSet = true;
                        }
                    }
                }
            }
        }

        sendSuccess(['message' => 'Vehicle updated successfully']);
    } catch (Exception $e) {
        error_log("updateVehicle error: " . $e->getMessage());
        sendError('Failed to update vehicle', 500);
    }
}

/**
 * Get site settings for footer and global configuration
 * Returns public settings grouped by category
 */
function getSiteSettings($db) {
    try {
        $group = $_GET['group'] ?? '';
        
        // Build query based on group filter
        if (!empty($group)) {
            $stmt = $db->prepare("
                SELECT setting_key, setting_value, setting_group, setting_type 
                FROM site_settings 
                WHERE is_public = 1 AND setting_group = ?
                ORDER BY setting_group, setting_key
            ");
            $stmt->execute([$group]);
        } else {
            $stmt = $db->query("
                SELECT setting_key, setting_value, setting_group, setting_type 
                FROM site_settings 
                WHERE is_public = 1
                ORDER BY setting_group, setting_key
            ");
        }
        
        $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format settings into nested structure by group
        $grouped = [];
        foreach ($settings as $setting) {
            $group = $setting['setting_group'];
            if (!isset($grouped[$group])) {
                $grouped[$group] = [];
            }
            $grouped[$group][$setting['setting_key']] = $setting['setting_value'];
        }
        
        sendSuccess([
            'settings' => $grouped,
            'flat' => array_column($settings, 'setting_value', 'setting_key')
        ]);
    } catch (Exception $e) {
        error_log("getSiteSettings error: " . $e->getMessage());
        sendSuccess(['settings' => [], 'flat' => []]);
    }
}

/**
 * Get effective listing restriction settings and current usage.
 * Used by sell flow to align client-side UX with backend-enforced limits.
 */
function getListingRestrictions($db) {
    try {
        ensureGuestListingLifecycleColumns($db);
        expireGuestListings($db);

        $allowGuestListings = (bool)getPlatformSetting($db, 'user_allowGuestListings', true);
        $maxGuestListings = 1;
        $maxRegisteredListings = (int)getPlatformSetting($db, 'listing_maxRegisteredListings', 10);
        $requireListingEmailValidation = (bool)getPlatformSetting($db, 'listing_requireEmailValidation', true);
        $guestListingValidityDays = max(1, (int)getPlatformSetting($db, 'listing_expiryDays', 30));
        $featuredBonusVisibilityDays = max(0, (int)getPlatformSetting($db, 'listing_featuredBonusDays', 15));
        $paymentsEnabled = (bool)getPlatformSetting($db, 'listing_paymentsEnabled', false);
        $freeListingPrice = max(0, (float)getPlatformSetting($db, 'listing_freeListingPrice', 0));
        $featuredListingPrice = max(0, (float)getPlatformSetting($db, 'listing_featuredPrice', 0));
        $paymentMethodsRaw = (string)getPlatformSetting($db, 'listing_paymentMethods', 'mobile_money,bank_transfer');
        $paymentMethods = array_values(array_filter(array_map('trim', explode(',', $paymentMethodsRaw))));
        if (empty($paymentMethods)) {
            $paymentMethods = ['mobile_money', 'bank_transfer'];
        }
        $paymentInstructions = (string)getPlatformSetting($db, 'listing_paymentInstructions', '');
        $paymentReferencePrefix = (string)getPlatformSetting($db, 'listing_paymentReferencePrefix', 'ML');

        $isAuthenticated = isLoggedIn();
        $currentUser = getCurrentUser(false);

        $currentRegisteredCount = null;
        $remainingRegisteredListings = null;

        if ($isAuthenticated && !empty($currentUser['id'])) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM car_listings WHERE user_id = ? AND status != 'deleted'");
            $stmt->execute([(int)$currentUser['id']]);
            $currentRegisteredCount = (int)$stmt->fetchColumn();

            if ($maxRegisteredListings > 0) {
                $remainingRegisteredListings = max(0, $maxRegisteredListings - $currentRegisteredCount);
            }
        }

        $guestEmail = strtolower(trim($_GET['guest_email'] ?? ''));
        $currentGuestCount = null;
        $remainingGuestListings = null;
        $guestLifetimeLimitReached = false;
        $guestHasExpiredListing = false;
        $guestLastExpiredAt = null;
        $guestActiveListingExpiresAt = null;

        if (!empty($guestEmail) && filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
            $stmt = $db->prepare("\n                SELECT COUNT(*)\n                FROM car_listings\n                WHERE is_guest = 1\n                  AND LOWER(guest_seller_email) = LOWER(?)\n            ");
            $stmt->execute([$guestEmail]);
            $currentGuestCount = (int)$stmt->fetchColumn();
            $guestLifetimeLimitReached = $currentGuestCount >= $maxGuestListings;

            $activeExpiryStmt = $db->prepare("\n                SELECT guest_listing_expires_at\n                FROM car_listings\n                WHERE is_guest = 1\n                  AND LOWER(guest_seller_email) = LOWER(?)\n                  AND status NOT IN ('deleted', 'rejected', 'expired')\n                ORDER BY COALESCE(guest_listing_expires_at, created_at) DESC\n                LIMIT 1\n            ");
            $activeExpiryStmt->execute([$guestEmail]);
            $guestActiveListingExpiresAt = $activeExpiryStmt->fetchColumn() ?: null;

            $expiredStmt = $db->prepare("\n                SELECT guest_listing_expired_at\n                FROM car_listings\n                WHERE is_guest = 1\n                  AND LOWER(guest_seller_email) = LOWER(?)\n                  AND status = 'expired'\n                ORDER BY guest_listing_expired_at DESC\n                LIMIT 1\n            ");
            $expiredStmt->execute([$guestEmail]);
            $guestLastExpiredAt = $expiredStmt->fetchColumn() ?: null;
            $guestHasExpiredListing = !empty($guestLastExpiredAt);

            if ($maxGuestListings > 0) {
                $remainingGuestListings = max(0, $maxGuestListings - $currentGuestCount);
            }
        }

        sendSuccess([
            'restrictions' => [
                'allow_guest_listings' => $allowGuestListings,
                'max_guest_listings' => $maxGuestListings,
                'guest_listing_validity_days' => $guestListingValidityDays,
                'featured_bonus_visibility_days' => $featuredBonusVisibilityDays,
                'max_registered_listings' => $maxRegisteredListings,
                'payments_enabled' => $paymentsEnabled,
                'free_listing_price' => $freeListingPrice,
                'featured_listing_price' => $featuredListingPrice,
                'payment_methods' => $paymentMethods,
                'payment_instructions' => $paymentInstructions,
                'payment_reference_prefix' => $paymentReferencePrefix,
                'require_listing_email_validation' => $requireListingEmailValidation,
                'is_authenticated' => $isAuthenticated,
                'current_registered_count' => $currentRegisteredCount,
                'remaining_registered_listings' => $remainingRegisteredListings,
                'current_guest_count' => $currentGuestCount,
                'remaining_guest_listings' => $remainingGuestListings,
                'guest_lifetime_limit_reached' => $guestLifetimeLimitReached,
                'guest_has_expired_listing' => $guestHasExpiredListing,
                'guest_last_expired_at' => $guestLastExpiredAt,
                'guest_active_listing_expires_at' => $guestActiveListingExpiresAt
            ]
        ]);
    } catch (Exception $e) {
        error_log('getListingRestrictions error: ' . $e->getMessage());
        sendError('Failed to load listing restrictions', 500);
    }
}

/**
 * Instant identity check for guest listing form.
 * - email: is it a registered account? (blocks guest submission)
 * - phone: is it already tied to an active listing? (duplicate-person warning)
 * - name: fuzzy match against existing guest listing names for same phone/email domain?
 *   (not needed server-side; JS handles name format validation; we only return phone signals here)
 * Intentionally minimal — no account detail is returned.
 */
function checkGuestIdentity($db) {
    $email = strtolower(trim($_GET['email'] ?? ''));
    $phone  = trim($_GET['phone'] ?? '');
    // Normalise phone: strip all non-digit chars except leading +
    $phoneNorm = preg_replace('/[^\d]/', '', $phone);

    $result = [
        'email_registered'   => false,
        'phone_in_use'       => false,
        'phone_listing_count' => 0,
    ];

    // --- email check ----------------------------------------------------------
    if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $stmt = $db->prepare("SELECT 1 FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
        $stmt->execute([$email]);
        $result['email_registered'] = (bool)$stmt->fetchColumn();
    }

    // --- phone check ----------------------------------------------------------
    if (strlen($phoneNorm) >= 7) {
        // Match last 7 digits of stored guest_seller_phone against all active listings
        // Multiple REPLACE() strips common formatting chars (space, dash, parens, plus)
        $suffix = '%' . substr($phoneNorm, -7);
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM car_listings
             WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                   COALESCE(guest_seller_phone,''),' ',''),'-',''),'(',''),')',''),'+','') LIKE ?
               AND status NOT IN ('deleted','rejected','expired')"
        );
        $stmt->execute([$suffix]);
        $count = (int)$stmt->fetchColumn();
        $result['phone_in_use']        = $count > 0;
        $result['phone_listing_count'] = $count;
    }

    sendSuccess($result);
}

/**
 * Get allowlisted public runtime config values needed by frontend scripts.
 * This keeps API keys out of source code while still allowing client-side SDK loading.
 */
function getPublicClientConfig($db) {
    try {
        $allowedKeys = [
            'google_maps_api_key',
            'google_maps_map_id'
        ];

        $placeholders = implode(',', array_fill(0, count($allowedKeys), '?'));
        $stmt = $db->prepare("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ($placeholders)");
        $stmt->execute($allowedKeys);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $config = [
            'google_maps_api_key' => '',
            'google_maps_map_id' => ''
        ];

        foreach ($rows as $row) {
            if (array_key_exists($row['setting_key'], $config)) {
                $config[$row['setting_key']] = (string)($row['setting_value'] ?? '');
            }
        }

        $maintenance = getMaintenanceModeState($db);
        $config['maintenance_enabled'] = (bool)$maintenance['enabled'];
        $config['maintenance_message'] = (string)$maintenance['message'];

        sendSuccess(['config' => $config]);
    } catch (Exception $e) {
        error_log("getPublicClientConfig error: " . $e->getMessage());
        sendError('Failed to load runtime client config', 500);
    }
}

// ============================================================================
// AI CAR CHAT FUNCTION - MOVED TO ai-car-chat-api.php
// Function handleAICarChat() removed - see separate file
// Routing handled in proxy.php
// File updated: 2026-01-02 - Cache refresh
// ============================================================================


/**
 * Get current fuel prices (public endpoint)
 */
function getFuelPrices($db) {
    try {
        $stmt = $db->prepare("
            SELECT fuel_type, price_per_liter_mwk, price_per_liter_usd, 
                   currency, last_updated, date
            FROM fuel_prices 
            WHERE is_active = 1 AND date = CURDATE()
            ORDER BY fuel_type
        ");
        $stmt->execute();
        $prices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // If no prices for today, get most recent
        if (empty($prices)) {
            $stmt = $db->prepare("
                SELECT fuel_type, price_per_liter_mwk, price_per_liter_usd, 
                       currency, last_updated, date
                FROM fuel_prices 
                WHERE is_active = 1
                ORDER BY date DESC, fuel_type
                LIMIT 10
            ");
            $stmt->execute();
            $prices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        sendSuccess(['prices' => $prices]);
    } catch (Exception $e) {
        error_log("getFuelPrices error: " . $e->getMessage());
        sendError('Failed to load fuel prices', 500);
    }
}

/**
 * Calculate journey fuel cost
 */
function calculateJourney($db) {
    try {
        $user = getCurrentUser(true);
        $input = json_decode(file_get_contents('php://input'), true);
        
        // Validate required fields
        if (empty($input['origin']) || empty($input['destination'])) {
            sendError('Origin and destination are required', 400);
            return;
        }
        
        $distanceKm = (float)($input['distance_km'] ?? 0);
        $fuelType = $input['fuel_type'] ?? 'petrol';
        $fuelConsumption = !empty($input['fuel_consumption']) ? (float)$input['fuel_consumption'] : null;
        
        // Default fuel consumption if not provided (average for Malawi)
        if (empty($fuelConsumption)) {
            $fuelConsumption = $fuelType === 'diesel' ? 8.5 : 9.5; // L/100km
        }
        
        // Get current fuel price
        $stmt = $db->prepare("
            SELECT price_per_liter_mwk 
            FROM fuel_prices 
            WHERE fuel_type = ? AND is_active = 1 
            ORDER BY date DESC 
            LIMIT 1
        ");
        $stmt->execute([$fuelType]);
        $priceData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$priceData) {
            // Fallback to default prices
            $fuelPricePerLiter = $fuelType === 'diesel' ? 1950.00 : 1850.00;
        } else {
            $fuelPricePerLiter = (float)$priceData['price_per_liter_mwk'];
        }
        
        // Calculate fuel needed
        $fuelNeededLiters = ($distanceKm / 100) * $fuelConsumption;
        $fuelCostMWK = $fuelNeededLiters * $fuelPricePerLiter;
        
        // Save to journey history if requested
        $journeyId = null;
        if (!empty($input['save_to_history'])) {
            $stmt = $db->prepare("
                INSERT INTO journey_history (
                    user_id, origin_location, origin_lat, origin_lng,
                    destination_location, destination_lat, destination_lng,
                    distance_km, duration_minutes, fuel_type, fuel_needed_liters,
                    fuel_cost_mwk, fuel_price_per_liter, fuel_consumption_used, notes
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $user['id'],
                $input['origin'],
                !empty($input['origin_lat']) ? (float)$input['origin_lat'] : null,
                !empty($input['origin_lng']) ? (float)$input['origin_lng'] : null,
                $input['destination'],
                !empty($input['destination_lat']) ? (float)$input['destination_lat'] : null,
                !empty($input['destination_lng']) ? (float)$input['destination_lng'] : null,
                $distanceKm,
                !empty($input['duration_minutes']) ? (int)$input['duration_minutes'] : null,
                $fuelType,
                $fuelNeededLiters,
                $fuelCostMWK,
                $fuelPricePerLiter,
                $fuelConsumption,
                $input['notes'] ?? null
            ]);
            $journeyId = $db->lastInsertId();
        }
        
        sendSuccess([
            'distance_km' => round($distanceKm, 2),
            'fuel_type' => $fuelType,
            'fuel_consumption_liters_per_100km' => round($fuelConsumption, 2),
            'fuel_needed_liters' => round($fuelNeededLiters, 2),
            'fuel_price_per_liter_mwk' => round($fuelPricePerLiter, 2),
            'fuel_cost_mwk' => round($fuelCostMWK, 2),
            'journey_id' => $journeyId
        ]);
    } catch (Exception $e) {
        error_log("calculateJourney error: " . $e->getMessage());
        sendError('Failed to calculate journey: ' . $e->getMessage(), 500);
    }
}

/**
 * Get journey history for logged-in user
 */
function getJourneyHistory($db) {
    try {
        $user = getCurrentUser(true);
        $limit = (int)($_GET['limit'] ?? 20);
        
        $stmt = $db->prepare("
            SELECT j.*
            FROM journey_history j
            WHERE j.user_id = ?
            ORDER BY j.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$user['id'], $limit]);
        $journeys = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        sendSuccess(['journeys' => $journeys]);
    } catch (Exception $e) {
        error_log("getJourneyHistory error: " . $e->getMessage());
        sendError('Failed to load journey history', 500);
    }
}

/**
 * Scrape fuel prices from globalpetrolprices.com
 * This should be called daily via cron job
 */
function scrapeFuelPrices($db) {
    try {
        // Optional: Add API key check for security
        $apiKey = $_GET['api_key'] ?? '';
        $expectedKey = 'MOTORLINK_FUEL_SCRAPER_KEY_2025'; // Change this!
        
        if ($apiKey !== $expectedKey) {
            sendError('Unauthorized', 401);
            return;
        }
        
        // Scrape from globalpetrolprices.com
        $url = 'https://www.globalpetrolprices.com/Malawi/gasoline_prices/';
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || empty($html)) {
            error_log("Failed to fetch fuel prices: HTTP $httpCode");
            sendError('Failed to fetch fuel prices', 500);
            return;
        }
        
        // Parse HTML to extract prices (this is a simplified version)
        // Note: You may need to adjust the parsing based on the actual HTML structure
        $prices = [];
        
        // Try to extract petrol price
        if (preg_match('/gasoline.*?(\d+\.?\d*)\s*MWK/i', $html, $matches)) {
            $prices['petrol'] = (float)$matches[1];
        }
        
        // Try to extract diesel price
        $dieselUrl = 'https://www.globalpetrolprices.com/Malawi/diesel_prices/';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $dieselUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $dieselHtml = curl_exec($ch);
        curl_close($ch);
        
        if (preg_match('/diesel.*?(\d+\.?\d*)\s*MWK/i', $dieselHtml, $matches)) {
            $prices['diesel'] = (float)$matches[1];
        }
        
        // If parsing failed, use fallback prices
        if (empty($prices['petrol'])) {
            $prices['petrol'] = 1850.00; // Fallback
        }
        if (empty($prices['diesel'])) {
            $prices['diesel'] = 1950.00; // Fallback
        }
        
        // Deactivate old prices
        $db->prepare("UPDATE fuel_prices SET is_active = 0 WHERE date = CURDATE()")->execute();
        
        // Insert new prices
        $today = date('Y-m-d');
        foreach ($prices as $fuelType => $price) {
            $stmt = $db->prepare("
                INSERT INTO fuel_prices 
                (fuel_type, price_per_liter_mwk, date, is_active, source, source_url)
                VALUES (?, ?, ?, 1, 'globalpetrolprices.com', ?)
                ON DUPLICATE KEY UPDATE 
                    price_per_liter_mwk = VALUES(price_per_liter_mwk),
                    is_active = 1,
                    last_updated = NOW()
            ");
            $stmt->execute([
                $fuelType,
                $price,
                $today,
                "https://www.globalpetrolprices.com/Malawi/{$fuelType}_prices/"
            ]);
        }
        
        sendSuccess([
            'message' => 'Fuel prices updated successfully',
            'prices' => $prices,
            'date' => $today
        ]);
    } catch (Exception $e) {
        error_log("scrapeFuelPrices error: " . $e->getMessage());
        sendError('Failed to scrape fuel prices: ' . $e->getMessage(), 500);
    }
}

// ============================================================================
// USER VEHICLE MANAGEMENT FUNCTIONS
// ============================================================================

/**
 * Get all vehicles for the logged-in user
 */
function getUserVehicles($db) {
    try {
        $user = getCurrentUser(true);
        
        $stmt = $db->prepare("
            SELECT 
                id,
                user_id,
                vin,
                make,
                model,
                year,
                fuel_type,
                engine_size_liters,
                transmission,
                body_type,
                fuel_consumption_liters_per_100km,
                fuel_tank_capacity_liters,
                is_primary,
                created_at,
                updated_at
            FROM user_vehicles
            WHERE user_id = ?
            ORDER BY is_primary DESC, created_at DESC
        ");
        $stmt->execute([$user['id']]);
        $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        sendSuccess(['vehicles' => $vehicles]);
    } catch (Exception $e) {
        error_log("getUserVehicles error: " . $e->getMessage());
        sendError('Failed to load vehicles', 500);
    }
}

/**
 * Add a new vehicle for the logged-in user
 */
function addUserVehicle($db) {
    try {
        $user = getCurrentUser(true);
        $input = json_decode(file_get_contents('php://input'), true);
        
        // Validate required fields
        if (empty($input['make_id']) || empty($input['model_id'])) {
            sendError('Make and Model are required', 400);
            return;
        }
        
        $makeId = (int)$input['make_id'];
        $modelId = (int)$input['model_id'];
        $year = !empty($input['year']) ? (int)$input['year'] : null;
        $vin = !empty($input['vin']) ? trim($input['vin']) : null;
        $userFuelTankCapacity = !empty($input['fuel_tank_capacity_liters']) ? (float)$input['fuel_tank_capacity_liters'] : null;
        $userEngineSize = !empty($input['engine_size_liters']) ? (float)$input['engine_size_liters'] : null;
        $userFuelConsumption = !empty($input['fuel_consumption_liters_per_100km']) ? (float)$input['fuel_consumption_liters_per_100km'] : null;
        $userTransmission = !empty($input['transmission']) ? strtolower(trim($input['transmission'])) : null;
        
        // Get make and model details
        $stmt = $db->prepare("SELECT name FROM car_makes WHERE id = ?");
        $stmt->execute([$makeId]);
        $make = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$make) {
            sendError('Invalid make ID', 400);
            return;
        }
        
        $stmt = $db->prepare("
            SELECT 
                name, 
                fuel_type,
                engine_size_liters,
                transmission_type as transmission,
                body_type,
                fuel_consumption_combined_l100km as fuel_consumption_liters_per_100km,
                fuel_tank_capacity_liters,
                year_start
            FROM car_models 
            WHERE id = ? AND make_id = ?
        ");
        $stmt->execute([$modelId, $makeId]);
        $model = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$model) {
            sendError('Invalid model ID', 400);
            return;
        }
        
        // If no year provided, use year_start from model, or current year as fallback
        if (!$year) {
            if (!empty($model['year_start'])) {
                $year = (int)$model['year_start'];
            } else {
                $year = (int)date('Y'); // Fallback to current year if no year_start
            }
        }
        
        // Ensure year is valid (not null, not 0)
        if (!$year || $year < 1900 || $year > (int)date('Y') + 1) {
            $year = (int)date('Y'); // Default to current year if invalid
        }
        
        // Determine fuel type (use from model if available, otherwise default to petrol)
        $fuelType = !empty($model['fuel_type']) ? $model['fuel_type'] : 'petrol';
        // Map fuel_type values if needed
        if ($fuelType === 'gasoline') $fuelType = 'petrol';
        if (!in_array($fuelType, ['petrol', 'diesel', 'electric', 'hybrid', 'lpg', 'cng'])) {
            $fuelType = 'petrol';
        }
        
        // Get engine size/capacity (vital for journey planner)
        $engineSizeLiters = !empty($model['engine_size_liters']) ? (float)$model['engine_size_liters'] : null;
        if ($userEngineSize && $userEngineSize > 0) {
            $engineSizeLiters = $userEngineSize;
        }
        
        // Get fuel consumption (prefer combined, fallback to other values) - VITAL for journey planner
        $fuelConsumption = null;
        if (!empty($model['fuel_consumption_liters_per_100km'])) {
            $fuelConsumption = (float)$model['fuel_consumption_liters_per_100km'];
        }
        // Also check urban/highway if combined is not available
        if (!$fuelConsumption) {
            $stmt = $db->prepare("
                SELECT fuel_consumption_urban_l100km, fuel_consumption_highway_l100km, 
                       fuel_consumption_combined_l100km
                FROM car_models WHERE id = ?
            ");
            $stmt->execute([$modelId]);
            $consumptionData = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($consumptionData) {
                if (!empty($consumptionData['fuel_consumption_combined_l100km'])) {
                    $fuelConsumption = (float)$consumptionData['fuel_consumption_combined_l100km'];
                } elseif (!empty($consumptionData['fuel_consumption_urban_l100km']) && !empty($consumptionData['fuel_consumption_highway_l100km'])) {
                    // Average of urban and highway
                    $fuelConsumption = ((float)$consumptionData['fuel_consumption_urban_l100km'] + (float)$consumptionData['fuel_consumption_highway_l100km']) / 2;
                } elseif (!empty($consumptionData['fuel_consumption_urban_l100km'])) {
                    $fuelConsumption = (float)$consumptionData['fuel_consumption_urban_l100km'];
                } elseif (!empty($consumptionData['fuel_consumption_highway_l100km'])) {
                    $fuelConsumption = (float)$consumptionData['fuel_consumption_highway_l100km'];
                }
            }
        }
        // Final fallback - default based on fuel type
        if (!$fuelConsumption) {
            $fuelConsumption = $fuelType === 'diesel' ? 8.5 : 9.5; // Default
        }
        if ($userFuelConsumption && $userFuelConsumption > 0) {
            $fuelConsumption = $userFuelConsumption;
        }
        
        // Get fuel tank capacity - VITAL for journey planner
        // Use user-provided value if available, otherwise use model value, otherwise default
        $fuelTankCapacity = null;
        if ($userFuelTankCapacity && $userFuelTankCapacity > 0) {
            $fuelTankCapacity = $userFuelTankCapacity; // User's custom value takes priority
        } elseif (!empty($model['fuel_tank_capacity_liters'])) {
            $fuelTankCapacity = (float)$model['fuel_tank_capacity_liters'];
        }
        // Fallback default based on fuel type
        if (!$fuelTankCapacity || $fuelTankCapacity <= 0) {
            $fuelTankCapacity = $fuelType === 'diesel' ? 50.0 : 45.0; // Default
        }
        
        // Get transmission
        $transmission = null;
        if (!empty($model['transmission'])) {
            $trans = strtolower($model['transmission']);
            if (in_array($trans, ['manual', 'automatic', 'cvt', 'semi-automatic', 'dct'])) {
                $transmission = $trans;
            }
        }
        if ($userTransmission && in_array($userTransmission, ['manual', 'automatic', 'cvt', 'semi-automatic', 'dct'])) {
            $transmission = $userTransmission;
        }
        
        // Check if user wants this as primary vehicle
        $isPrimary = !empty($input['is_primary']) ? 1 : 0;
        
        // If setting as primary, unset other primary vehicles
        if ($isPrimary) {
            $stmt = $db->prepare("UPDATE user_vehicles SET is_primary = 0 WHERE user_id = ?");
            $stmt->execute([$user['id']]);
        }
        
        // Insert vehicle
        $stmt = $db->prepare("
            INSERT INTO user_vehicles (
                user_id, vin, make, model, year, fuel_type, 
                engine_size_liters, transmission, body_type,
                fuel_consumption_liters_per_100km, fuel_tank_capacity_liters, is_primary
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $user['id'],
            $vin,
            $make['name'],
            $model['name'],
            $year,
            $fuelType,
            $engineSizeLiters, // Engine capacity in liters - vital for journey planner
            $transmission,
            !empty($model['body_type']) ? $model['body_type'] : null,
            $fuelConsumption, // Fuel consumption - vital for journey planner
            $fuelTankCapacity, // Fuel tank capacity - vital for journey planner
            $isPrimary
        ]);
        
        $vehicleId = $db->lastInsertId();
        
        sendSuccess([
            'message' => 'Vehicle added successfully',
            'vehicle_id' => $vehicleId
        ]);
    } catch (Exception $e) {
        error_log("addUserVehicle error: " . $e->getMessage());
        sendError('Failed to add vehicle: ' . $e->getMessage(), 500);
    }
}

/**
 * Update a user's vehicle
 */
function updateUserVehicle($db) {
    try {
        $user = getCurrentUser(true);
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (empty($input['vehicle_id'])) {
            sendError('Vehicle ID is required', 400);
            return;
        }
        
        $vehicleId = (int)$input['vehicle_id'];
        
        // Verify ownership
        $stmt = $db->prepare("SELECT user_id FROM user_vehicles WHERE id = ?");
        $stmt->execute([$vehicleId]);
        $vehicle = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$vehicle || $vehicle['user_id'] != $user['id']) {
            sendError('Vehicle not found or access denied', 404);
            return;
        }
        
        // Build update query dynamically
        $updates = [];
        $params = [];
        
        if (isset($input['year'])) {
            $updates[] = "year = ?";
            $params[] = (int)$input['year'];
        }
        
        if (isset($input['vin'])) {
            $updates[] = "vin = ?";
            $params[] = !empty($input['vin']) ? trim($input['vin']) : null;
        }
        
        if (isset($input['fuel_consumption_liters_per_100km'])) {
            $updates[] = "fuel_consumption_liters_per_100km = ?";
            $params[] = (float)$input['fuel_consumption_liters_per_100km'];
        }
        
        if (!empty($updates)) {
            $params[] = $vehicleId;
            $stmt = $db->prepare("UPDATE user_vehicles SET " . implode(', ', $updates) . " WHERE id = ?");
            $stmt->execute($params);
        }
        
        sendSuccess(['message' => 'Vehicle updated successfully']);
    } catch (Exception $e) {
        error_log("updateUserVehicle error: " . $e->getMessage());
        sendError('Failed to update vehicle: ' . $e->getMessage(), 500);
    }
}

/**
 * Delete a user's vehicle
 */
function deleteUserVehicle($db) {
    try {
        $user = getCurrentUser(true);
        $vehicleId = (int)($_GET['vehicle_id'] ?? 0);
        
        if (!$vehicleId) {
            sendError('Vehicle ID is required', 400);
            return;
        }
        
        // Verify ownership
        $stmt = $db->prepare("SELECT user_id FROM user_vehicles WHERE id = ?");
        $stmt->execute([$vehicleId]);
        $vehicle = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$vehicle || $vehicle['user_id'] != $user['id']) {
            sendError('Vehicle not found or access denied', 404);
            return;
        }
        
        $stmt = $db->prepare("DELETE FROM user_vehicles WHERE id = ?");
        $stmt->execute([$vehicleId]);
        
        sendSuccess(['message' => 'Vehicle deleted successfully']);
    } catch (Exception $e) {
        error_log("deleteUserVehicle error: " . $e->getMessage());
        sendError('Failed to delete vehicle: ' . $e->getMessage(), 500);
    }
}

/**
 * Set a vehicle as primary
 */
function setPrimaryVehicle($db) {
    try {
        $user = getCurrentUser(true);
        $vehicleId = (int)($_GET['vehicle_id'] ?? 0);
        
        if (!$vehicleId) {
            sendError('Vehicle ID is required', 400);
            return;
        }
        
        // Verify ownership
        $stmt = $db->prepare("SELECT user_id FROM user_vehicles WHERE id = ?");
        $stmt->execute([$vehicleId]);
        $vehicle = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$vehicle || $vehicle['user_id'] != $user['id']) {
            sendError('Vehicle not found or access denied', 404);
            return;
        }
        
        // Unset all primary vehicles
        $stmt = $db->prepare("UPDATE user_vehicles SET is_primary = 0 WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        
        // Set this vehicle as primary
        $stmt = $db->prepare("UPDATE user_vehicles SET is_primary = 1 WHERE id = ?");
        $stmt->execute([$vehicleId]);
        
        sendSuccess(['message' => 'Primary vehicle set successfully']);
    } catch (Exception $e) {
        error_log("setPrimaryVehicle error: " . $e->getMessage());
        sendError('Failed to set primary vehicle: ' . $e->getMessage(), 500);
    }
}

?>
