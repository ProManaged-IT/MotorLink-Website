<?php
// admin-config.php - Database Configuration for Admin Dashboard
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

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

// Bootstrap DB connection configuration (env only, no hardcoded secrets).
// Final admin DB credentials are loaded from site_settings after bootstrap connection.
$defaultDbHost = $isProduction ? 'localhost' : 'promanaged-it.com';

$adminLocalSecrets = [];
foreach ([__DIR__ . '/admin-secrets.local.php', __DIR__ . '/admin-secrets.example.php'] as $__adminSecretPath) {
    if (file_exists($__adminSecretPath)) {
        $__loadedSecrets = require $__adminSecretPath;
        if (is_array($__loadedSecrets)) {
            $adminLocalSecrets = $__loadedSecrets;
            break;
        }
    }
}
unset($__adminSecretPath, $__loadedSecrets);

function getAdminBootstrapDbConfig($defaultHost) {
    $local = $GLOBALS['adminLocalSecrets'] ?? [];

    $config = [
        'host' => getenv('MOTORLINK_DB_HOST') ?: ($local['MOTORLINK_DB_HOST'] ?? $defaultHost),
        'user' => getenv('MOTORLINK_DB_USER') ?: ($local['MOTORLINK_DB_USER'] ?? ''),
        'pass' => getenv('MOTORLINK_DB_PASS') ?: ($local['MOTORLINK_DB_PASS'] ?? ''),
        'name' => getenv('MOTORLINK_DB_NAME') ?: ($local['MOTORLINK_DB_NAME'] ?? '')
    ];

    // Fallback: read shared DB constants from api.php without executing it.
    // Executing api.php here starts the main API session/bootstrap and breaks
    // admin session cookie setup on live requests.
    if ($config['user'] === '' || $config['pass'] === '' || $config['name'] === '') {
        $sharedConfig = readSharedApiDbConfig(__DIR__ . '/../api.php', $defaultHost);
        if ($config['user'] === '' && !empty($sharedConfig['user'])) {
            $config['user'] = $sharedConfig['user'];
        }
        if ($config['pass'] === '' && !empty($sharedConfig['pass'])) {
            $config['pass'] = $sharedConfig['pass'];
        }
        if ($config['name'] === '' && !empty($sharedConfig['name'])) {
            $config['name'] = $sharedConfig['name'];
        }
        if ($config['host'] === $defaultHost && !empty($sharedConfig['host'])) {
            $config['host'] = $sharedConfig['host'];
        }
    }

    if ($config['user'] === '' || $config['pass'] === '' || $config['name'] === '') {
        throw new Exception('Missing bootstrap DB credentials. Set MOTORLINK_DB_* env vars or provide admin/admin-secrets.local.php.');
    }

    return $config;
}

function readSharedApiDbConfig($apiFilePath, $defaultHost) {
    $config = [
        'host' => $defaultHost,
        'user' => '',
        'pass' => '',
        'name' => ''
    ];

    if (!is_file($apiFilePath) || !is_readable($apiFilePath)) {
        return $config;
    }

    $contents = @file_get_contents($apiFilePath);
    if ($contents === false || $contents === '') {
        return $config;
    }

    $patterns = [
        'user' => "/define\('DB_USER',\s*'([^']*)'\);/",
        'pass' => "/define\('DB_PASS',\s*'([^']*)'\);/",
        'name' => "/define\('DB_NAME',\s*'([^']*)'\);/",
        'host' => "/define\('DB_HOST',\s*'([^']*)'\);/"
    ];

    foreach ($patterns as $key => $pattern) {
        if (preg_match($pattern, $contents, $matches)) {
            $config[$key] = $matches[1];
        }
    }

    return $config;
}


// Note: Session is started in admin-api.php after setting session parameters
// Do not start session here to avoid conflicts

/**
 * Database Connection Singleton Class
 */
class Database {
    private static $instance = null;
    private $connection;

    private function createConnection(array $config) {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 10
        ];

        return new PDO(
            "mysql:host={$config['host']};dbname={$config['name']};charset=utf8mb4",
            $config['user'],
            $config['pass'],
            $options
        );
    }

    private function seedAdminDbCredentialSettings(PDO $db, array $bootstrapConfig) {
        $defaults = [
            'admin_db_host' => $bootstrapConfig['host'],
            'admin_db_user' => $bootstrapConfig['user'],
            'admin_db_pass' => $bootstrapConfig['pass'],
            'admin_db_name' => $bootstrapConfig['name']
        ];

        foreach ($defaults as $key => $value) {
            try {
                $stmt = $db->prepare("SELECT id FROM site_settings WHERE setting_key = ? LIMIT 1");
                $stmt->execute([$key]);

                if (!$stmt->fetch()) {
                    $insert = $db->prepare("INSERT INTO site_settings (setting_key, setting_value, setting_group, setting_type, description, is_public) VALUES (?, ?, 'security', 'string', ?, 0)");
                    $insert->execute([$key, $value, 'Admin DB credential setting: ' . $key]);
                }
            } catch (Exception $e) {
                error_log('Admin DB settings seed warning for ' . $key . ': ' . $e->getMessage());
            }
        }
    }

    private function loadFinalAdminDbConfig(PDO $db, array $fallbackConfig) {
        $keyMap = [
            'admin_db_host' => 'host',
            'admin_db_user' => 'user',
            'admin_db_pass' => 'pass',
            'admin_db_name' => 'name'
        ];

        $resolved = $fallbackConfig;

        try {
            $keys = array_keys($keyMap);
            $placeholders = implode(',', array_fill(0, count($keys), '?'));
            $stmt = $db->prepare("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ($placeholders)");
            $stmt->execute($keys);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $targetKey = $keyMap[$row['setting_key']] ?? null;
                if ($targetKey && $row['setting_value'] !== null && $row['setting_value'] !== '') {
                    $resolved[$targetKey] = $row['setting_value'];
                }
            }
        } catch (Exception $e) {
            error_log('Admin DB settings load warning: ' . $e->getMessage());
        }

        return $resolved;
    }
    
    private function __construct() {
        try {
            $bootstrapConfig = getAdminBootstrapDbConfig($GLOBALS['defaultDbHost']);
            $bootstrapDb = $this->createConnection($bootstrapConfig);

            // Ensure DB-backed credentials exist, then load final admin DB config from site_settings.
            $this->seedAdminDbCredentialSettings($bootstrapDb, $bootstrapConfig);
            $finalConfig = $this->loadFinalAdminDbConfig($bootstrapDb, $bootstrapConfig);

            $usesBootstrap = (
                $finalConfig['host'] === $bootstrapConfig['host'] &&
                $finalConfig['user'] === $bootstrapConfig['user'] &&
                $finalConfig['pass'] === $bootstrapConfig['pass'] &&
                $finalConfig['name'] === $bootstrapConfig['name']
            );

            if ($usesBootstrap) {
                $this->connection = $bootstrapDb;
                return;
            }

            try {
                $this->connection = $this->createConnection($finalConfig);
            } catch (PDOException $e) {
                error_log('Admin final DB settings failed, falling back to bootstrap connection: ' . $e->getMessage());
                $this->connection = $bootstrapDb;
            }
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

// Utility function to get database connection
function getDatabase() {
    return Database::getInstance()->getConnection();
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