<?php
// admin-config.php - Database Configuration for Admin Dashboard
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Start the admin session with the correct cookie params BEFORE including api-common.php
// so that api-common.php's session_start() guard sees PHP_SESSION_ACTIVE and skips.
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

// Reuse the shared bootstrap in api-common.php so that admin-api.php gets its
// DB credentials from exactly the same place as api.php and api-common.php.
// Credentials resolve: env vars → admin-secrets.local.php → admin-secrets.example.php → site_settings.
// No separate credential-loading logic lives here.
if (!defined('DB_HOST')) {
    require_once __DIR__ . '/../api-common.php';
}

function getAdminBootstrapDbConfig($defaultHost) {
    // Delegate entirely to api-common.php's already-resolved constants.
    return [
        'host' => DB_HOST,
        'user' => DB_USER,
        'pass' => DB_PASS,
        'name' => DB_NAME
    ];
}

// Utility function to get database connection
function getDatabase() {
    return Database::getInstance()->getConnection();
}

/**
 * Database Connection Singleton Class
 * Uses the credentials already resolved by api-common.php.
 */
class Database {
    private static $instance = null;
    private $connection;

    private function __construct() {
        try {
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 10
            ];
            $this->connection = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                $options
            );
        } catch (PDOException $e) {
            error_log('Admin DB connection failed: ' . $e->getMessage());
            throw new Exception('Database connection failed: ' . $e->getMessage());
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

/**
 * Load platform constants from DB-backed settings with safe runtime fallbacks.
 */
function initializeAdminPlatformConstants() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';

    $defaults = [
        'site_name' => 'Marketplace',
        'site_url' => $scheme . '://' . $host . '/motorlink',
        'upload_path' => '../uploads/'
    ];

    $resolved = $defaults;

    try {
        $db = getDatabase();

        // First priority: site_settings (centralized settings store).
        $keys = ['site_name', 'site_url', 'upload_path'];
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $db->prepare("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ($placeholders)");
        $stmt->execute($keys);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = (string)($row['setting_key'] ?? '');
            $value = trim((string)($row['setting_value'] ?? ''));
            if (array_key_exists($key, $resolved) && $value !== '') {
                $resolved[$key] = $value;
            }
        }

        // Fallback priority: legacy admin settings table keys.
        try {
            $legacyStmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('general_siteName','general_siteUrl','general_uploadPath')");
            $legacyStmt->execute();
            foreach ($legacyStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $legacyKey = (string)($row['setting_key'] ?? '');
                $legacyValue = trim((string)($row['setting_value'] ?? ''));
                if ($legacyValue === '') {
                    continue;
                }

                if ($legacyKey === 'general_siteName' && $resolved['site_name'] === $defaults['site_name']) {
                    $resolved['site_name'] = $legacyValue;
                }

                if ($legacyKey === 'general_siteUrl' && $resolved['site_url'] === $defaults['site_url']) {
                    $resolved['site_url'] = $legacyValue;
                }

                if ($legacyKey === 'general_uploadPath' && $resolved['upload_path'] === $defaults['upload_path']) {
                    $resolved['upload_path'] = $legacyValue;
                }
            }
        } catch (Exception $e) {
            // Ignore missing/legacy table issues.
        }
    } catch (Exception $e) {
        error_log('Admin platform settings load warning: ' . $e->getMessage());
    }

    if (!defined('SITE_NAME')) {
        define('SITE_NAME', $resolved['site_name']);
    }

    if (!defined('SITE_URL')) {
        define('SITE_URL', $resolved['site_url']);
    }

    if (!defined('UPLOAD_PATH')) {
        define('UPLOAD_PATH', $resolved['upload_path']);
    }
}

initializeAdminPlatformConstants();