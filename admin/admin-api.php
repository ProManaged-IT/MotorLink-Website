<?php
// Enable error reporting for debugging but log only, don't display
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display to avoid breaking JSON
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/admin_errors.log');

// MotorLink Admin API
require_once 'admin-config.php';
require_once __DIR__ . '/../includes/runtime-site-config.php';

// Headers - set these BEFORE any output
header('Content-Type: application/json; charset=utf-8');

// CORS Configuration - Must use specific origin when credentials are enabled
// Cannot use '*' when Access-Control-Allow-Credentials is true
$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? null;

// Determine the origin to use for CORS header
$originToUse = null;

// Determine the server's own host for same-origin checks
$_serverHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
$_serverHostOnly = explode(':', $_serverHost)[0];

// Priority 1: Use the Origin header from the request (most reliable)
if ($requestOrigin) {
    $parsedOrigin = parse_url($requestOrigin);
    if ($parsedOrigin) {
        $originHost = $parsedOrigin['host'] ?? '';
        // Allow localhost, 127.0.0.1, and the server's own domain (same-origin on any deployment)
        if (in_array($originHost, ['localhost', '127.0.0.1']) || $originHost === $_serverHostOnly) {
            $originToUse = $requestOrigin;
        }
    }
}

// Priority 2: If no Origin header, try to get from Referer
if (!$originToUse && !empty($_SERVER['HTTP_REFERER'])) {
    $parsedUrl = parse_url($_SERVER['HTTP_REFERER']);
    if ($parsedUrl) {
        $scheme = $parsedUrl['scheme'] ?? 'http';
        $host = $parsedUrl['host'] ?? '';
        $port = !empty($parsedUrl['port']) ? ':' . $parsedUrl['port'] : '';
        if (in_array($host, ['localhost', '127.0.0.1']) || $host === $_serverHostOnly) {
            $originToUse = $scheme . '://' . $host . $port;
        }
    }
}

// Priority 3: Construct from current request (same-origin fallback — always valid)
if (!$originToUse) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? null) == 443)
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        ? 'https' : 'http';
    $originToUse = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
}

// Set CORS headers - MUST be specific origin, never '*' when credentials are enabled
header("Access-Control-Allow-Origin: $originToUse");

header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cookie');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Enhanced session configuration - set cookie params only before session starts.
if (session_status() !== PHP_SESSION_ACTIVE) {
    $isHTTPS = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? null) == 443)
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    session_set_cookie_params([
        'lifetime' => 86400,
        'path' => '/',
        'domain' => '',  // Empty domain for compatibility
        'secure' => $isHTTPS,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    // Start session early
    session_start();
}

// Get action
$action = $_GET['action'] ?? '';

if (empty($action)) {
    echo json_encode(['success' => false, 'message' => 'No action specified']);
    exit();
}

try {
    $actionsWithoutDatabase = ['admin_logout', 'check_admin_auth'];
    $db = null;

    if (!in_array($action, $actionsWithoutDatabase, true)) {
        $db = getDatabase();

        // Test database connection first
        $db->query("SELECT 1");
    }
    
    switch ($action) {
        case 'admin_login':
            handleAdminLogin($db);
            break;

        case 'admin_register':
            handleAdminRegister($db);
            break;

        case 'admin_logout':
            handleAdminLogout();
            break;

        case 'check_admin_auth':
            handleCheckAdminAuth($db);
            break;
            
        case 'dashboard_stats':
            handleDashboardStats($db);
            break;
            
        case 'recent_activities':
            getRecentActivities($db);
            break;
            
        case 'pending_approvals':
            getPendingApprovals($db);
            break;
            
        case 'get_cars':
            handleGetCars($db);
            break;
            
        case 'get_car':
            getCar($db);
            break;
            
        case 'approve_car':
            handleApproveCar($db);
            break;

        case 'bulk_approve_cars':
            handleBulkApproveCars($db);
            break;

        case 'bulk_update_cars':
            handleBulkUpdateCars($db);
            break;

        case 'bulk_delete_cars':
            handleBulkDeleteCars($db);
            break;
            
        case 'get_users':
            getUsers($db);
            break;

        case 'get_admins':
            getAdmins($db);
            break;

        case 'approve_user':
            approveUser($db);
            break;
            
        case 'approve_garage':
            approveGarage($db);
            break;
            
        case 'bulk_approve_garages':
            handleBulkApproveGarages($db);
            break;

        case 'bulk_update_garages':
            handleBulkUpdateGarages($db);
            break;

        case 'bulk_delete_garages':
            handleBulkDeleteGarages($db);
            break;
            
        case 'approve_dealer':
            approveDealer($db);
            break;

        case 'bulk_approve_dealers':
            handleBulkApproveDealers($db);
            break;

        case 'bulk_update_dealers':
            handleBulkUpdateDealers($db);
            break;

        case 'bulk_delete_dealers':
            handleBulkDeleteDealers($db);
            break;
            
        case 'approve_car_hire':
            approveCarHire($db);
            break;
            
        case 'get_garages':
            loadGarages($db);
            break;
            
        case 'get_payments':
            getPayments($db);
            break;

        case 'payment_stats':
            getPaymentStats($db);
            break;

        case 'verify_payment':
            handleVerifyPayment($db);
            break;

        case 'reject_payment':
            handleRejectPayment($db);
            break;

        case 'record_manual_payment':
            handleRecordManualPayment($db);
            break;
            
        case 'get_dealers':
            loadDealers($db);
            break;
            
        case 'get_car_hire':
            loadCarHire($db);
            break;

        case 'get_deleted_cars':
            getDeletedCars($db);
            break;

        case 'update_user':
            handleUpdateUser($db);
            break;

        case 'delete_user':
            handleDeleteUser($db);
            break;

        case 'delete_garage':
            handleDeleteGarage($db);
            break;

        case 'delete_dealer':
            handleDeleteDealer($db);
            break;

        case 'delete_car_hire':
            handleDeleteCarHire($db);
            break;

        case 'get_car_makes':
    getCarMakes($db);
    break;
            
        case 'get_makes_models':
            loadMakesModels($db);
            break;
            
        case 'get_locations':
            loadLocations($db);
            break;
            
        case 'update_car':
            handleUpdateCar($db);
            break;
            
        case 'delete_car':
            handleDeleteCar($db);
            break;

        case 'restore_car':
            handleRestoreCar($db);
            break;
            
        case 'get_car_images':
            handleGetCarImages($db);
            break;
            
        case 'upload_car_images':
            handleUploadCarImages($db);
            break;
            
        case 'delete_car_image':
            handleDeleteCarImage($db);
            break;
            
        case 'set_primary_image':
            handleSetPrimaryImage($db);
            break;

        case 'add_car':
            handleAddCar($db);
            break;

        case 'add_garage':
            handleAddGarage($db);
            break;

        case 'add_dealer':
            handleAddDealer($db);
            break;

        case 'add_car_hire':
            handleAddCarHire($db);
            break;

        case 'add_user':
            handleAddUser($db);
            break;

        case 'add_admin':
            handleAddAdmin($db);
            break;

        case 'add_make':
            handleAddMake($db);
            break;

        case 'add_model':
            handleAddModel($db);
            break;

        case 'add_location':
            handleAddLocation($db);
            break;

        case 'feature_car':
            handleFeatureCar($db);
            break;

        case 'premium_car':
            handlePremiumCar($db);
            break;
            
        // Garage feature/verify/certify
        case 'feature_garage':
            handleFeatureGarage($db);
            break;
            
        case 'verify_garage':
            handleVerifyGarage($db);
            break;
            
        case 'certify_garage':
            handleCertifyGarage($db);
            break;
            
        // Dealer feature/verify/certify
        case 'feature_dealer':
            handleFeatureDealer($db);
            break;
            
        case 'verify_dealer':
            handleVerifyDealer($db);
            break;
            
        case 'certify_dealer':
            handleCertifyDealer($db);
            break;
            
        // Car Hire feature/verify/certify
        case 'feature_car_hire':
            handleFeatureCarHire($db);
            break;
            
        case 'verify_car_hire':
            handleVerifyCarHire($db);
            break;
            
        case 'certify_car_hire':
            handleCertifyCarHire($db);
            break;

        case 'update_make':
            handleUpdateMake($db);
            break;

        case 'update_model':
            handleUpdateModel($db);
            break;

        case 'save_settings':
            handleSaveSettings($db);
            break;

        case 'get_settings':
            handleGetSettings($db);
            break;

        case 'get_fuel_price_settings':
            handleGetFuelPriceSettings($db);
            break;

        case 'save_fuel_price_settings':
            handleSaveFuelPriceSettings($db);
            break;

        case 'fetch_live_fuel_prices':
            handleFetchLiveFuelPrices($db);
            break;

        case 'get_admin_db_credentials':
            handleGetAdminDbCredentials($db);
            break;

        case 'test_admin_db_credentials':
            handleTestAdminDbCredentials($db);
            break;

        case 'save_admin_db_credentials':
            handleSaveAdminDbCredentials($db);
            break;

        case 'get_footer_support_settings':
            handleGetFooterSupportSettings($db);
            break;

        case 'save_footer_support_settings':
            handleSaveFooterSupportSettings($db);
            break;

        case 'get_whatsapp_settings':
            handleGetWhatsAppSettings($db);
            break;

        case 'save_whatsapp_settings':
            handleSaveWhatsAppSettings($db);
            break;

        case 'test_whatsapp_message':
            handleTestWhatsAppMessage($db);
            break;

        case 'get_whatsapp_bookings':
            handleGetWhatsAppBookings($db);
            break;

        case 'update_whatsapp_booking_status':
            handleUpdateWhatsAppBookingStatus($db);
            break;

        case 'get_system_info':
            handleGetSystemInfo($db);
            break;

        case 'export_database':
            handleExportDatabase($db);
            break;

        case 'get_activity_logs':
            handleGetActivityLogs($db);
            break;

        case 'get_listing_reports':
            handleGetListingReports($db);
            break;

        case 'update_listing_report_status':
            handleUpdateListingReportStatus($db);
            break;

        case 'get_business_reviews':
            handleGetBusinessReviews($db);
            break;

        case 'update_review_status':
            handleUpdateBusinessReviewStatus($db);
            break;

        case 'delete_review':
            handleDeleteBusinessReview($db);
            break;

        case 'get_ai_chat_settings':
            handleGetAIChatSettings($db);
            break;

        case 'get_ai_provider_health':
            handleGetAIProviderHealth($db);
            break;

        case 'save_ai_chat_settings':
            handleSaveAIChatSettings($db);
            break;

        case 'set_ai_chat_enabled':
            handleSetAIChatEnabled($db);
            break;

        case 'get_ai_chat_usage':
            handleGetAIChatUsage($db);
            break;
            
        case 'get_ai_chat_users':
            handleGetAIChatUsers($db);
            break;
            
        case 'get_user_ai_restriction':
            handleGetUserAIRestriction($db);
            break;
            
        case 'set_user_ai_restriction':
            handleSetUserAIRestriction($db);
            break;

        case 'trigger_ai_web_learning':
            handleTriggerAIWebLearning($db);
            break;

        case 'trigger_ai_parts_learning':
            handleTriggerAIPartsLearning($db);
            break;

        case 'get_ai_learning_settings':
            handleGetAILearningSettings($db);
            break;

        case 'save_ai_learning_settings':
            handleSaveAILearningSettings($db);
            break;

        case 'get_ai_learning_stats':
            handleGetAILearningStats($db);
            break;

        case 'manage_guest_listing_auth':
            handleManageGuestListingAuth($db);
            break;

        case 'clear_all_caches':
            handleClearAllCaches($db);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action: ' . $action]);
            exit();
    }

} catch (Exception $e) {
    error_log("Admin API Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Server error',
        'error' => $e->getMessage()
    ]);
    exit();
}

// Session management functions

/**
 * Check if current session is from main site admin login
 */
function isMainSiteAdminSession() {
    return isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin' && isset($_SESSION['user_id']);
}

/**
 * Check if current session is from admin panel login
 */
function isAdminPanelSession() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true
           && isset($_SESSION['admin_id']) && isset($_SESSION['admin_email']);
}

/**
 * Sync admin session variables from main site format to admin panel format
 *
 * When admin logs in through main site (index.html), the session uses main site format
 * (user_type='admin'). This function converts it to admin panel format (admin_logged_in=true)
 * so the admin panel can access it without requiring a second login.
 *
 * Note: Only syncs if not already in admin panel format to avoid redundant operations.
 */
function syncAdminSession() {
    // Only sync if coming from main site and not already synced
    if (isMainSiteAdminSession() && !isset($_SESSION['admin_logged_in'])) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_name'] = $_SESSION['full_name'] ?? 'Admin';
        $_SESSION['admin_email'] = $_SESSION['email'] ?? '';
        $_SESSION['admin_id'] = $_SESSION['user_id'];
        $_SESSION['admin_role'] = $_SESSION['admin_role'] ?? 'admin';
    }
}

function checkSession() {
    // Allow seamless admin portal access when an admin has already logged in
    // through the main site and the shared PHP session is still valid.
    syncAdminSession();
    return isAdminPanelSession();
}

function requireAdmin() {
    if (!checkSession()) {
        error_log("Admin session check failed - no active admin session");

        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Admin access required. Please login.',
            'code' => 'ADMIN_AUTH_REQUIRED'
        ]);
        exit();
    }
}

function sendAdminError($message, $errorCode, $httpCode = 400) {
    http_response_code($httpCode);
    echo json_encode([
        'success' => false,
        'message' => $message,
        'code' => $errorCode
    ]);
    exit();
}

function adminTableColumnExists($db, $tableName, $columnName) {
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$tableName, $columnName]);
        return ((int)$stmt->fetchColumn() > 0);
    } catch (Exception $e) {
        return false;
    }
}

function ensureGuestListingAuthColumns($db) {
    try {
        $db->exec("ALTER TABLE car_listings ADD COLUMN listing_email_verified TINYINT(1) DEFAULT 0");
    } catch (Exception $e) {
        // Column already exists.
    }

    try {
        $db->exec("ALTER TABLE car_listings ADD COLUMN listing_email_verified_at DATETIME DEFAULT NULL");
    } catch (Exception $e) {
        // Column already exists.
    }

    try {
        $db->exec("ALTER TABLE car_listings ADD COLUMN listing_email_verification_token VARCHAR(128) DEFAULT NULL");
    } catch (Exception $e) {
        // Column already exists.
    }

    try {
        $db->exec("ALTER TABLE car_listings ADD COLUMN listing_email_verification_expires DATETIME DEFAULT NULL");
    } catch (Exception $e) {
        // Column already exists.
    }

    try {
        $db->exec("ALTER TABLE car_listings ADD COLUMN guest_manage_code_hash VARCHAR(255) DEFAULT NULL");
    } catch (Exception $e) {
        // Column already exists.
    }

    try {
        $db->exec("ALTER TABLE car_listings ADD COLUMN guest_manage_code_expires DATETIME DEFAULT NULL");
    } catch (Exception $e) {
        // Column already exists.
    }

    try {
        $db->exec("ALTER TABLE car_listings ADD COLUMN guest_manage_code_last_sent DATETIME DEFAULT NULL");
    } catch (Exception $e) {
        // Column already exists.
    }
}

function requireSuperAdmin($db) {
    requireAdmin();
    
    // Get admin role from database
    $adminId = $_SESSION['admin_id'] ?? null;
    if (!$adminId) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Super admin access required.',
            'code' => 'SUPER_ADMIN_REQUIRED'
        ]);
        exit();
    }
    
    $stmt = $db->prepare("SELECT role FROM admin_users WHERE id = ?");
    $stmt->execute([$adminId]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$admin || $admin['role'] !== 'super_admin') {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Super admin access required. Only super administrators can access this feature.',
            'code' => 'SUPER_ADMIN_REQUIRED'
        ]);
        exit();
    }
}

// ===== CHECK ADMIN AUTH FUNCTION =====
function handleCheckAdminAuth($db) {
    // Check if session has expired (30 minutes of inactivity)
    $sessionTimeout = 1800; // 30 minutes in seconds

    if (checkSession()) {
        // Check for session timeout
        if (isset($_SESSION['last_activity'])) {
            $inactiveTime = time() - $_SESSION['last_activity'];

            if ($inactiveTime > $sessionTimeout) {
                // Session has expired
                session_unset();
                session_destroy();

                echo json_encode([
                    'success' => true,
                    'authenticated' => false,
                    'message' => 'Session expired due to inactivity'
                ]);
                exit();
            }
        }

        // Update last activity time
        $_SESSION['last_activity'] = time();

        // Return admin data from session
        echo json_encode([
            'success' => true,
            'authenticated' => true,
            'admin' => [
                'name' => $_SESSION['admin_name'] ?? 'Admin',
                'email' => $_SESSION['admin_email'] ?? '',
                'role' => $_SESSION['admin_role'] ?? 'admin'
            ]
        ]);
        exit();
    } else {
        // No valid session - ensure it's cleared
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_unset();
            session_destroy();
        }

        echo json_encode([
            'success' => true,
            'authenticated' => false
        ]);
        exit();
    }
}

// ===== ADMIN LOGOUT FUNCTION =====
function handleAdminLogout() {
    // Clear all session data
    $_SESSION = array();

    // Delete session cookie
    if (isset($_COOKIE[session_name()])) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    // Destroy the session
    session_unset();
    session_destroy();

    echo json_encode([
        'success' => true,
        'message' => 'Logout successful'
    ]);
    exit();
}

// ===== LOGIN FUNCTION (WORKING VERSION) =====
function handleAdminLogin($db) {
    // Get input data safely
    $inputData = file_get_contents('php://input');
    
    if (empty($inputData)) {
        echo json_encode(['success' => false, 'message' => 'No data received']);
        exit();
    }

    $input = json_decode($inputData, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
        exit();
    }

    $email = strtolower(trim((string)($input['email'] ?? '')));
    $password = $input['password'] ?? '';

    if (empty($email) || empty($password)) {
        echo json_encode(['success' => false, 'message' => 'Email and password required']);
        exit();
    }
    
    try {
        // Check admin_users table
        $stmt = $db->prepare("SELECT * FROM admin_users WHERE LOWER(email) = LOWER(?) AND status = 'active'");
        $stmt->execute([$email]);
        $admin = $stmt->fetch();
        
        if ($admin && isset($admin['password_hash'])) {
            // Verify password
            if (password_verify($password, $admin['password_hash'])) {
                // Set session data
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_name'] = $admin['full_name'];
                $_SESSION['admin_email'] = $admin['email'];
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_role'] = $admin['role'] ?? 'admin';
                $_SESSION['last_activity'] = time();

                // Mirror the main-site session format so the frontend and admin
                // portal stay in sync without requiring a second login.
                $_SESSION['user_id'] = $admin['id'];
                $_SESSION['full_name'] = $admin['full_name'];
                $_SESSION['email'] = $admin['email'];
                $_SESSION['user_type'] = 'admin';
                
                // Verify session was set
                if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
                    echo json_encode([
                        'success' => true,
                        'message' => 'Login successful',
                        'admin' => [
                            'name' => $admin['full_name'],
                            'email' => $admin['email'],
                            'role' => $admin['role'] ?? 'admin'
                        ]
                    ]);
                    exit();
                } else {
                    throw new Exception('Session could not be established');
                }
            }
        }

        // Fallback: support legacy admin accounts stored in users.
        $stmt = $db->prepare("SELECT * FROM users WHERE LOWER(email) = LOWER(?) AND user_type = 'admin' LIMIT 1");
        $stmt->execute([$email]);
        $legacyAdmin = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($legacyAdmin && isset($legacyAdmin['password_hash']) && password_verify($password, $legacyAdmin['password_hash'])) {
            if (($legacyAdmin['status'] ?? '') !== 'active') {
                echo json_encode(['success' => false, 'message' => 'Admin user not found or inactive']);
                exit();
            }

            $_SESSION['admin_id'] = $legacyAdmin['id'];
            $_SESSION['admin_name'] = $legacyAdmin['full_name'];
            $_SESSION['admin_email'] = $legacyAdmin['email'];
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_role'] = 'admin';
            $_SESSION['last_activity'] = time();
            $_SESSION['user_id'] = $legacyAdmin['id'];
            $_SESSION['full_name'] = $legacyAdmin['full_name'];
            $_SESSION['email'] = $legacyAdmin['email'];
            $_SESSION['user_type'] = 'admin';

            echo json_encode([
                'success' => true,
                'message' => 'Login successful',
                'admin' => [
                    'name' => $legacyAdmin['full_name'],
                    'email' => $legacyAdmin['email'],
                    'role' => 'admin'
                ]
            ]);
            exit();
        }

        echo json_encode(['success' => false, 'message' => 'Invalid email or password']);
        exit();

    } catch (Exception $e) {
        error_log("Login error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Login failed: ' . $e->getMessage()]);
        exit();
    }
}

// ===== ALL YOUR OTHER FUNCTIONS =====

function handleAdminRegister($db) {
    $input = json_decode(file_get_contents('php://input'), true);

    $required = ['full_name', 'email', 'password', 'phone', 'admin_key'];
    foreach ($required as $field) {
        if (empty(trim($input[$field] ?? ''))) {
            echo json_encode(['success' => false, 'message' => "Field '{$field}' is required"]);
            exit();
        }
    }

    // Verify admin registration key
    if ($input['admin_key'] !== 'MOTORLINK_ADMIN_KEY_2025') {
        echo json_encode(['success' => false, 'message' => 'Invalid admin registration key']);
        exit();
    }
    
    $email = trim($input['email']);
    $password = $input['password'];
    $fullName = trim($input['full_name']);
    $phone = trim($input['phone']);
    
    try {
        // Check if email already exists
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Email address is already registered']);
            exit();
        }

        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $db->prepare("
            INSERT INTO users (full_name, email, password_hash, phone, user_type, status, created_at)
            VALUES (?, ?, ?, ?, 'admin', 'pending_approval', NOW())
        ");

        $result = $stmt->execute([$fullName, $email, $hashedPassword, $phone]);

        if ($result) {
            echo json_encode([
                'success' => true,
                'message' => 'Admin registration submitted successfully. Your account is pending approval by the super admin.'
            ]);
            exit();
        } else {
            echo json_encode(['success' => false, 'message' => 'Admin registration failed']);
            exit();
        }

    } catch (Exception $e) {
        error_log("adminRegister error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Registration failed']);
        exit();
    }
}

function handleDashboardStats($db) {
    requireAdmin();
    
    try {
        try {
            $db->exec("ALTER TABLE car_listings ADD COLUMN guest_listing_expires_at DATETIME DEFAULT NULL");
        } catch (Exception $e) {
            // Column already exists, ignore.
        }

        try {
            $db->exec("ALTER TABLE car_listings ADD COLUMN guest_listing_expired_at DATETIME DEFAULT NULL");
        } catch (Exception $e) {
            // Column already exists, ignore.
        }

        try {
            $db->exec("\n                UPDATE car_listings\n                SET\n                    status = 'expired',\n                    guest_listing_expired_at = COALESCE(guest_listing_expired_at, NOW())\n                WHERE is_guest = 1\n                  AND guest_listing_expires_at IS NOT NULL\n                  AND guest_listing_expires_at <= NOW()\n                  AND status IN ('active', 'pending_approval', 'pending_email_verification')\n            ");
        } catch (Exception $e) {
            // Non-blocking.
        }

        $stats = [];
        
        // Get basic counts
        $stats['total_cars'] = (int)$db->query("SELECT COUNT(*) FROM car_listings WHERE status != 'deleted'")->fetchColumn();
        $stats['active_cars'] = (int)$db->query("SELECT COUNT(*) FROM car_listings WHERE status = 'active' AND approval_status = 'approved'")->fetchColumn();
        $stats['pending_cars'] = (int)$db->query("SELECT COUNT(*) FROM car_listings WHERE status = 'pending_approval'")->fetchColumn();
        $stats['pending_guest_listings'] = (int)$db->query("SELECT COUNT(*) FROM car_listings WHERE status = 'pending_approval' AND is_guest = 1")->fetchColumn();
        
        $stats['total_users'] = (int)$db->query("SELECT COUNT(*) FROM users WHERE user_type != 'admin'")->fetchColumn();
        $stats['active_users'] = (int)$db->query("SELECT COUNT(*) FROM users WHERE user_type != 'admin' AND status = 'active'")->fetchColumn();
        $stats['pending_users'] = (int)$db->query("SELECT COUNT(*) FROM users WHERE status = 'pending_approval'")->fetchColumn();
        
        // Get garage stats
        try {
            $stats['total_garages'] = (int)$db->query("SELECT COUNT(*) FROM garages")->fetchColumn();
            $stats['pending_garages'] = (int)$db->query("SELECT COUNT(*) FROM garages WHERE status = 'pending_approval'")->fetchColumn();
        } catch (Exception $e) {
            $stats['total_garages'] = 0;
            $stats['pending_garages'] = 0;
        }
        
        // Get dealer stats
        try {
            $stats['total_dealers'] = (int)$db->query("SELECT COUNT(*) FROM car_dealers")->fetchColumn();
            $stats['pending_dealers'] = (int)$db->query("SELECT COUNT(*) FROM car_dealers WHERE status = 'pending_approval'")->fetchColumn();
        } catch (Exception $e) {
            $stats['total_dealers'] = 0;
            $stats['pending_dealers'] = 0;
        }
        
        // Get car hire stats
        try {
            $stats['total_car_hire'] = (int)$db->query("SELECT COUNT(*) FROM car_hire_companies")->fetchColumn();
            $stats['pending_car_hire'] = (int)$db->query("SELECT COUNT(*) FROM car_hire_companies WHERE status = 'pending_approval'")->fetchColumn();
        } catch (Exception $e) {
            $stats['total_car_hire'] = 0;
            $stats['pending_car_hire'] = 0;
        }
        
        // Revenue stats (from payments if table exists)
        try {
            $todayStart = date('Y-m-d 00:00:00');
            $stats['today_revenue'] = (float)$db->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE created_at >= '$todayStart' AND status = 'verified'")->fetchColumn();
            $stats['total_revenue'] = (float)$db->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'verified'")->fetchColumn();
            $stats['pending_payments'] = (int)$db->query("SELECT COUNT(*) FROM payments WHERE status = 'pending'")->fetchColumn();
        } catch (Exception $e) {
            $stats['today_revenue'] = 0;
            $stats['total_revenue'] = 0;
            $stats['pending_payments'] = 0;
        }
        
        // Recent activity stats (last 7 days)
        try {
            $weekAgo = date('Y-m-d H:i:s', strtotime('-7 days'));
            $stats['new_listings_week'] = (int)$db->query("SELECT COUNT(*) FROM car_listings WHERE created_at >= '$weekAgo'")->fetchColumn();
            $stats['new_users_week'] = (int)$db->query("SELECT COUNT(*) FROM users WHERE created_at >= '$weekAgo' AND user_type != 'admin'")->fetchColumn();
        } catch (Exception $e) {
            $stats['new_listings_week'] = 0;
            $stats['new_users_week'] = 0;
        }

        echo json_encode(['success' => true, 'stats' => $stats]);
        exit();
    } catch (Exception $e) {
        error_log("handleDashboardStats error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to load dashboard stats']);
        exit();
    }
}

function handleGetCars($db) {
    requireAdmin();

    try {
        // Ensure featured/premium columns exist using compatible syntax
        try {
            $db->exec("ALTER TABLE car_listings ADD COLUMN is_featured TINYINT(1) DEFAULT 0");
        } catch (Exception $e) {
            // Column already exists, ignore
        }

        try {
            $db->exec("ALTER TABLE car_listings ADD COLUMN is_premium TINYINT(1) DEFAULT 0");
        } catch (Exception $e) {
            // Column already exists, ignore
        }

        try {
            $db->exec("ALTER TABLE car_listings ADD COLUMN premium_until DATETIME DEFAULT NULL");
        } catch (Exception $e) {
            // Column already exists, ignore
        }

        try {
            $db->exec("ALTER TABLE car_listings ADD COLUMN guest_listing_expires_at DATETIME DEFAULT NULL");
        } catch (Exception $e) {
            // Column already exists, ignore
        }

        try {
            $db->exec("ALTER TABLE car_listings ADD COLUMN guest_listing_expired_at DATETIME DEFAULT NULL");
        } catch (Exception $e) {
            // Column already exists, ignore
        }

        try {
            $db->exec("ALTER TABLE car_listings ADD COLUMN payment_required TINYINT(1) DEFAULT 0");
        } catch (Exception $e) {
            // Column already exists, ignore
        }

        try {
            $db->exec("ALTER TABLE car_listings ADD COLUMN payment_amount DECIMAL(12,2) DEFAULT 0");
        } catch (Exception $e) {
            // Column already exists, ignore
        }

        try {
            $db->exec("ALTER TABLE car_listings ADD COLUMN payment_status VARCHAR(30) DEFAULT 'not_required'");
        } catch (Exception $e) {
            // Column already exists, ignore
        }

        try {
            $db->exec("\n                UPDATE car_listings\n                SET\n                    status = 'expired',\n                    guest_listing_expired_at = COALESCE(guest_listing_expired_at, NOW())\n                WHERE is_guest = 1\n                  AND guest_listing_expires_at IS NOT NULL\n                  AND guest_listing_expires_at <= NOW()\n                  AND status IN ('active', 'pending_approval', 'pending_email_verification')\n            ");
        } catch (Exception $e) {
            // Non-blocking.
        }

        $where = ["1=1"];
        $params = [];

        // Apply filters
        if (!empty($_GET['status'])) {
            $where[] = "cl.status = ?";
            $params[] = $_GET['status'];
        }
        
        if (!empty($_GET['approval_status'])) {
            $where[] = "cl.approval_status = ?";
            $params[] = $_GET['approval_status'];
        }
        
        // If filtering for rejected status, also filter by denied approval_status to be safe
        if (!empty($_GET['status']) && $_GET['status'] === 'rejected') {
            $where[] = "cl.approval_status = 'denied'";
        }
        
        // Exclude deleted cars by default unless specifically filtering for deleted status
        if (empty($_GET['status']) || $_GET['status'] !== 'deleted') {
            $where[] = "cl.status != 'deleted'";
        }

        if (!empty($_GET['make_id'])) {
            $where[] = "cl.make_id = ?";
            $params[] = $_GET['make_id'];
        }

        if (!empty($_GET['search'])) {
            $where[] = "(cl.title LIKE ? OR cl.description LIKE ? OR cl.reference_number LIKE ?)";
            $searchTerm = "%" . $_GET['search'] . "%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        if (isset($_GET['is_guest']) && $_GET['is_guest'] !== '') {
            $isGuest = (int)$_GET['is_guest'] === 1 ? 1 : 0;
            $where[] = "COALESCE(cl.is_guest, 0) = ?";
            $params[] = $isGuest;
        }

        if (!empty($_GET['payment_status'])) {
            $paymentStatus = strtolower(trim((string)$_GET['payment_status']));

            if ($paymentStatus === 'not_required') {
                $where[] = "(COALESCE(cl.payment_required, 0) = 0 OR COALESCE(cl.payment_amount, 0) <= 0)";
            } elseif ($paymentStatus === 'pending') {
                $where[] = "COALESCE(cl.payment_required, 0) = 1";
                $where[] = "LOWER(COALESCE(cl.payment_status, 'pending')) IN ('pending', 'pending_verification')";
            } elseif ($paymentStatus === 'verified' || $paymentStatus === 'rejected') {
                $where[] = "COALESCE(cl.payment_required, 0) = 1";
                $where[] = "LOWER(COALESCE(cl.payment_status, '')) = ?";
                $params[] = $paymentStatus;
            }
        }

        $whereClause = implode(' AND ', $where);

        $sql = "SELECT
            cl.*,
            cm.name as make_name,
            cmo.name as model_name,
            loc.name as location_name,
            u.full_name as owner_name,
            u.email as owner_email,
            u.phone as owner_phone,
            u.user_type,
            COALESCE(cl.is_guest, 0) as is_guest,
            cl.guest_seller_name,
            cl.guest_seller_email,
            cl.guest_seller_phone,
            cl.guest_listing_expires_at,
            cl.guest_listing_expired_at,
            COALESCE(cl.listing_email_verified, 0) as listing_email_verified,
            (
                SELECT COALESCE(
                    NULLIF(filename, ''),
                    NULLIF(thumbnail_path, ''),
                    NULLIF(file_path, '')
                )
                FROM car_listing_images
                WHERE id = cl.featured_image_id
                LIMIT 1
            ) as featured_image,
            (
                SELECT COALESCE(
                    NULLIF(filename, ''),
                    NULLIF(thumbnail_path, ''),
                    NULLIF(file_path, '')
                )
                FROM car_listing_images
                WHERE listing_id = cl.id AND is_primary = 1
                ORDER BY id DESC
                LIMIT 1
            ) as primary_image,
            (
                SELECT COALESCE(
                    NULLIF(filename, ''),
                    NULLIF(thumbnail_path, ''),
                    NULLIF(file_path, '')
                )
                FROM car_listing_images
                WHERE listing_id = cl.id
                ORDER BY is_primary DESC, sort_order ASC, id ASC
                LIMIT 1
            ) as fallback_image
        FROM car_listings cl
        LEFT JOIN car_makes cm ON cl.make_id = cm.id
        LEFT JOIN car_models cmo ON cl.model_id = cmo.id
        LEFT JOIN locations loc ON cl.location_id = loc.id
        LEFT JOIN users u ON cl.user_id = u.id
        WHERE {$whereClause}
        ORDER BY
            COALESCE(cl.is_premium, 0) DESC,
            COALESCE(cl.is_featured, 0) DESC,
            cl.created_at DESC
        LIMIT 100";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $cars = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'cars' => $cars]);
        exit();
    } catch (Exception $e) {
        error_log("handleGetCars error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to load cars']);
        exit();
    }
}

function handleApproveCar($db) {
    requireAdmin();
    
    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? '';
    $action = $input['action'] ?? '';
    
    if (empty($id) || empty($action)) {
        echo json_encode(['success' => false, 'message' => 'ID and action required']);
        return;
    }
    
    try {
        // Fetch listing meta — handle missing optional columns gracefully.
        $listingMeta = null;
        try {
            $listingMetaStmt = $db->prepare("SELECT COALESCE(is_guest, 0) AS is_guest, COALESCE(listing_email_verified, 0) AS listing_email_verified, COALESCE(payment_required, 0) AS payment_required, COALESCE(payment_status, 'not_required') AS payment_status FROM car_listings WHERE id = ? LIMIT 1");
            $listingMetaStmt->execute([$id]);
            $listingMeta = $listingMetaStmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $colErr) {
            // Fallback: select only guaranteed columns if optional ones don't exist yet.
            $listingMetaStmt = $db->prepare("SELECT id FROM car_listings WHERE id = ? LIMIT 1");
            $listingMetaStmt->execute([$id]);
            $row = $listingMetaStmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $listingMeta = [
                    'is_guest' => 0,
                    'listing_email_verified' => 1,
                    'payment_required' => 0,
                    'payment_status' => 'not_required'
                ];
            }
        }

        if (!$listingMeta) {
            echo json_encode(['success' => false, 'message' => 'Car listing not found']);
            exit();
        }

        if ($action === 'approve' && (int)$listingMeta['is_guest'] === 1 && (int)$listingMeta['listing_email_verified'] !== 1) {
            echo json_encode(['success' => false, 'message' => 'Guest listing must complete email verification before approval']);
            exit();
        }

        if ($action === 'approve' && (int)$listingMeta['payment_required'] === 1 && strtolower((string)$listingMeta['payment_status']) !== 'verified') {
            echo json_encode(['success' => false, 'message' => 'Listing payment has not been verified yet']);
            exit();
        }

        $newStatus = ($action === 'approve') ? 'active' : 'rejected';
        $approvalStatus = ($action === 'approve') ? 'approved' : 'denied';
        $adminId = $_SESSION['admin_id'];
        $rejectionReason = $input['rejection_reason'] ?? '';

        // If rejecting, save rejection reason to admin_notes
        if ($action === 'reject' && !empty($rejectionReason)) {
            $rejectionNote = "Rejection reason: " . trim($rejectionReason);
            // First, get existing admin_notes
            $getNotesStmt = $db->prepare("SELECT admin_notes FROM car_listings WHERE id = ?");
            $getNotesStmt->execute([$id]);
            $existingNotes = $getNotesStmt->fetchColumn();
            
            // Append rejection reason to admin_notes
            if (empty($existingNotes)) {
                $newNotes = $rejectionNote;
            } else {
                $newNotes = $existingNotes . "\n\n" . $rejectionNote;
            }
            
            $stmt = $db->prepare("
                UPDATE car_listings
                SET status = ?, approval_status = ?, approved_by = ?, approved_at = NOW(), 
                    admin_notes = ?
                WHERE id = ?
            ");
            $result = $stmt->execute([$newStatus, $approvalStatus, $adminId, $newNotes, $id]);
        } else {
            $stmt = $db->prepare("
                UPDATE car_listings
                SET status = ?, approval_status = ?, approved_by = ?, approved_at = NOW()
                WHERE id = ?
            ");
            $result = $stmt->execute([$newStatus, $approvalStatus, $adminId, $id]);
        }

        if ($result) {
            $actionText = ($action === 'approve') ? 'approved' : 'rejected';
            echo json_encode(['success' => true, 'message' => "Car $actionText successfully"]);
            exit();
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update car']);
            exit();
        }
    } catch (Exception $e) {
        error_log("handleApproveCar error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to approve car']);
        exit();
    }
}

function getRecentActivities($db) {
    requireAdmin();
    
    try {
        // Simple recent activities - you can expand this later
        $sql = "
            (SELECT 'car' as type, CONCAT('New car listing: ', title) as title, 
                    'New car listing added' as description, created_at
             FROM car_listings 
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             ORDER BY created_at DESC LIMIT 5)
            
            UNION ALL
            
            (SELECT 'user' as type, CONCAT('New user: ', full_name) as title,
                    'New user registered' as description, created_at
             FROM users 
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND user_type != 'admin'
             ORDER BY created_at DESC LIMIT 5)
            
            ORDER BY created_at DESC LIMIT 10
        ";
        
        $stmt = $db->query($sql);
        $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'activities' => $activities]);
        exit();
    } catch (Exception $e) {
        error_log("getRecentActivities error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to load activities']);
        exit();
    }
}

function getPendingApprovals($db) {
    requireAdmin();
    
    try {
        $pending = [];
        
        // Pending cars
        $stmt = $db->query("
            SELECT 'car' as type, id, title as name, created_at, 
                   (SELECT full_name FROM users WHERE id = user_id) as owner_name
            FROM car_listings 
                WHERE status = 'pending_approval' AND COALESCE(is_guest, 0) = 0
            ORDER BY created_at DESC
            LIMIT 10
        ");
        $pendingCars = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $pending = array_merge($pending, $pendingCars);

        $stmt = $db->query("\n            SELECT\n                CASE\n                    WHEN status = 'pending_email_verification' THEN 'guest_listing_email_verification'\n                    ELSE 'guest_listing'\n                END as type,\n                id,\n                title as name,\n                created_at,\n                COALESCE(guest_seller_name, guest_seller_email, 'Guest Seller') as owner_name\n            FROM car_listings\n            WHERE COALESCE(is_guest, 0) = 1\n              AND status IN ('pending_approval', 'pending_email_verification')\n            ORDER BY created_at DESC\n            LIMIT 10\n        ");
        $pendingGuestCars = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $pending = array_merge($pending, $pendingGuestCars);
        
        // Pending users
        $stmt = $db->query("
            SELECT 'user' as type, id, full_name as name, created_at, 
                   full_name as owner_name
            FROM users 
            WHERE status = 'pending_approval'
            ORDER BY created_at DESC
            LIMIT 10
        ");
        $pendingUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $pending = array_merge($pending, $pendingUsers);

        // Pending garages
        $stmt = $db->query(" 
            SELECT 'garage' as type, g.id, g.name as name, g.created_at,
                   COALESCE(g.owner_name, u.full_name, 'N/A') as owner_name
            FROM garages g
            LEFT JOIN users u ON g.user_id = u.id
            WHERE g.status = 'pending_approval'
            ORDER BY g.created_at DESC
            LIMIT 10
        ");
        $pendingGarages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $pending = array_merge($pending, $pendingGarages);

        // Pending dealers
        $stmt = $db->query(" 
            SELECT 'dealer' as type, d.id, d.business_name as name, d.created_at,
                   COALESCE(d.owner_name, u.full_name, 'N/A') as owner_name
            FROM car_dealers d
            LEFT JOIN users u ON d.user_id = u.id
            WHERE d.status = 'pending_approval'
            ORDER BY d.created_at DESC
            LIMIT 10
        ");
        $pendingDealers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $pending = array_merge($pending, $pendingDealers);

        // Pending car hire companies
        $stmt = $db->query(" 
            SELECT 'car_hire' as type, c.id, c.business_name as name, c.created_at,
                   COALESCE(c.owner_name, u.full_name, 'N/A') as owner_name
            FROM car_hire_companies c
            LEFT JOIN users u ON c.user_id = u.id
            WHERE c.status = 'pending_approval'
            ORDER BY c.created_at DESC
            LIMIT 10
        ");
        $pendingCarHire = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $pending = array_merge($pending, $pendingCarHire);
        
        // Sort by created_at desc
        usort($pending, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });

        echo json_encode(['success' => true, 'pending_items' => array_slice($pending, 0, 15)]);
        exit();
    } catch (Exception $e) {
        error_log("getPendingApprovals error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to load pending approvals']);
        exit();
    }
}

function getCar($db) {
    requireAdmin();
    
    $id = $_GET['id'] ?? '';
    if (empty($id)) {
        echo json_encode(['success' => false, 'message' => 'Car ID is required']);
        return;
    }
    
    try {
        $stmt = $db->prepare("
            SELECT l.*, m.name as make_name, mo.name as model_name, 
                   loc.name as location_name, u.full_name as owner_name, u.email as owner_email, u.phone as owner_phone
            FROM car_listings l
            LEFT JOIN car_makes m ON l.make_id = m.id
            LEFT JOIN car_models mo ON l.model_id = mo.id
            LEFT JOIN locations loc ON l.location_id = loc.id
            LEFT JOIN users u ON l.user_id = u.id
            WHERE l.id = ?
        ");
        
        $stmt->execute([$id]);
        $car = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$car) {
            echo json_encode(['success' => false, 'message' => 'Car not found']);
            return;
        }

        echo json_encode(['success' => true, 'car' => $car]);
        exit();
    } catch (Exception $e) {
        error_log("getCar error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to load car']);
        exit();
    }
}

function getUsers($db) {
    requireAdmin();
    
    try {
        $where = ["1=1"];
        $params = [];

        // Apply filters - ID takes precedence for exact lookup
        if (!empty($_GET['id'])) {
            // Exact ID match - allow any user_type when fetching by ID
            $where[] = "id = ?";
            $params[] = $_GET['id'];
        } else {
            // Only exclude admins when not fetching by specific ID
            $where[] = "user_type != 'admin'";

            if (!empty($_GET['status'])) {
                $where[] = "status = ?";
                $params[] = $_GET['status'];
            }

            if (!empty($_GET['user_type'])) {
                $where[] = "user_type = ?";
                $params[] = $_GET['user_type'];
            }

            if (!empty($_GET['search'])) {
                $where[] = "(full_name LIKE ? OR email LIKE ? OR phone LIKE ?)";
                $searchTerm = "%" . $_GET['search'] . "%";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }
        }

        $whereClause = implode(' AND ', $where);

        $sql = "
            SELECT u.id, u.full_name, u.email, u.phone, u.user_type, u.status, u.city, u.address, u.bio, 
                   u.created_at, u.last_login, u.username, u.profile_image, u.business_name,
                   COALESCE(r.disabled, 0) as ai_chat_disabled,
                   r.reason as ai_chat_disabled_reason
            FROM users u
            LEFT JOIN ai_chat_user_restrictions r ON u.id = r.user_id AND r.disabled = 1
            WHERE {$whereClause}
            ORDER BY u.id DESC
            LIMIT 500
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'users' => $users]);
        exit();
    } catch (Exception $e) {
        error_log("getUsers error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to load users']);
        exit();
    }
}

function getAdmins($db) {
    requireAdmin();

    try {
        $sql = "
            SELECT id, username, full_name, email, role, status, created_at, last_login
            FROM admin_users
            ORDER BY created_at DESC
            LIMIT 100
        ";

        $stmt = $db->query($sql);
        $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'admins' => $admins]);
        exit();
    } catch (Exception $e) {
        error_log("getAdmins error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to load admins']);
        exit();
    }
}

function approveUser($db) {
    requireAdmin();
    
    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? '';
    $action = $input['action'] ?? 'approve';
    
    if (empty($id)) {
        echo json_encode(['success' => false, 'message' => 'User ID is required']);
        return;
    }
    
    try {
        $db->beginTransaction();
        
        $newStatus = ($action === 'approve') ? 'active' : 'rejected';
        $adminId = $_SESSION['admin_id'] ?? null;
        
        // Get user email, name, and business info BEFORE updating
        $userStmt = $db->prepare("SELECT email, full_name, user_type, business_id FROM users WHERE id = ?");
        $userStmt->execute([$id]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => 'User not found']);
            exit();
        }
        
        // Update user status (check for both 'pending' and 'pending_approval' to handle both cases)
        $stmt = $db->prepare("
            UPDATE users 
            SET status = ?,
                email_verified = CASE WHEN ? = 'active' THEN 1 ELSE email_verified END,
                verification_token = CASE WHEN ? = 'active' THEN NULL ELSE verification_token END,
                updated_at = NOW()
            WHERE id = ? AND (status = 'pending' OR status = 'pending_approval')
        ");
        
        $result = $stmt->execute([$newStatus, $newStatus, $newStatus, $id]);
        
        if ($result && $stmt->rowCount() > 0) {
            // If approving, also activate the associated business
            if ($action === 'approve') {
                
                if ($user && $user['business_id']) {
                    // Activate the associated business based on user type
                    switch ($user['user_type']) {
                        case 'garage':
                            $businessStmt = $db->prepare("
                                UPDATE garages 
                                SET status = 'active', approved_by = ?, approved_at = NOW(), updated_at = NOW()
                                WHERE id = ? AND status = 'pending_approval'
                            ");
                            $businessStmt->execute([$adminId, $user['business_id']]);
                            break;
                            
                        case 'dealer':
                            $businessStmt = $db->prepare("
                                UPDATE car_dealers 
                                SET status = 'active', approved_by = ?, approved_at = NOW(), updated_at = NOW()
                                WHERE id = ? AND status = 'pending_approval'
                            ");
                            $businessStmt->execute([$adminId, $user['business_id']]);
                            break;
                            
                        case 'car_hire':
                            $businessStmt = $db->prepare("
                                UPDATE car_hire_companies 
                                SET status = 'active', approved_by = ?, approved_at = NOW(), updated_at = NOW()
                                WHERE id = ? AND status = 'pending_approval'
                            ");
                            $businessStmt->execute([$adminId, $user['business_id']]);
                            break;
                    }
                }
            }
            
            $db->commit();
            
            // Send approval email if helper is available without importing api.php.
            $approvalEmailSent = false;
            if ($action === 'approve' && isset($user['email']) && isset($user['full_name']) && function_exists('sendApprovalEmail')) {
                sendApprovalEmail($db, $user['email'], $user['full_name']);
                $approvalEmailSent = true;
            }
            
            $actionText = ($action === 'approve') ? 'approved' : 'rejected';
            logActivity($db, 'user_approved', "User $actionText", "User ID: $id", $adminId);
            echo json_encode([
                'success' => true, 
                'message' => "User has been {$actionText} successfully" . 
                            ($action === 'approve' && isset($user['business_id']) ? ' and associated business activated' : '') .
                            ($approvalEmailSent ? '. Approval email sent.' : '')
            ]);
            exit();
        } else {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => 'User not found or already processed']);
            exit();
        }

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("approveUser error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to approve user: ' . $e->getMessage()]);
        exit();
    }
}

/**
 * Approve or reject a garage
 */
function approveGarage($db) {
    requireAdmin();
    
    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? '';
    $action = $input['action'] ?? 'approve';
    
    if (empty($id)) {
        echo json_encode(['success' => false, 'message' => 'Garage ID is required']);
        return;
    }
    
    try {
        $db->beginTransaction();
        
        $newStatus = ($action === 'approve') ? 'active' : 'suspended';
        $adminId = $_SESSION['admin_id'] ?? null;
        
        // Update garage status
        $stmt = $db->prepare("
            UPDATE garages 
            SET status = ?, approved_by = ?, approved_at = NOW(), updated_at = NOW()
            WHERE id = ? AND status = 'pending_approval'
        ");
        
        $result = $stmt->execute([$newStatus, $adminId, $id]);
        
        if ($result && $stmt->rowCount() > 0) {
            // Get the user_id from the garage
            $stmt = $db->prepare("SELECT user_id FROM garages WHERE id = ?");
            $stmt->execute([$id]);
            $garage = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($garage && $garage['user_id']) {
                // Update user status to active if business is approved
                if ($action === 'approve') {
                    $userStmt = $db->prepare("
                        UPDATE users 
                        SET status = 'active',
                            email_verified = CASE WHEN email_verified = 0 THEN 1 ELSE email_verified END,
                            verification_token = CASE WHEN email_verified = 0 THEN NULL ELSE verification_token END,
                            updated_at = NOW()
                        WHERE id = ? AND (status = 'pending' OR status = 'pending_approval')
                    ");
                    $userStmt->execute([$garage['user_id']]);
                }
            }
            
            $db->commit();
            
            $actionText = ($action === 'approve') ? 'approved' : 'rejected';
            logActivity($db, 'garage_approved', "Garage $actionText", "Garage ID: $id", $adminId);
            echo json_encode(['success' => true, 'message' => "Garage has been {$actionText} successfully"]);
            exit();
        } else {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => 'Garage not found or already processed']);
            exit();
        }
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("approveGarage error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to approve garage: ' . $e->getMessage()]);
        exit();
    }
}

/**
 * Approve or reject a car dealer
 */
function approveDealer($db) {
    requireAdmin();
    
    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? '';
    $action = $input['action'] ?? 'approve';
    
    if (empty($id)) {
        echo json_encode(['success' => false, 'message' => 'Dealer ID is required']);
        return;
    }
    
    try {
        $db->beginTransaction();
        
        $newStatus = ($action === 'approve') ? 'active' : 'suspended';
        $adminId = $_SESSION['admin_id'] ?? null;
        
        // Update dealer status
        $stmt = $db->prepare("
            UPDATE car_dealers 
            SET status = ?, approved_by = ?, approved_at = NOW(), updated_at = NOW()
            WHERE id = ? AND status = 'pending_approval'
        ");
        
        $result = $stmt->execute([$newStatus, $adminId, $id]);
        
        if ($result && $stmt->rowCount() > 0) {
            // Get the user_id from the dealer
            $stmt = $db->prepare("SELECT user_id FROM car_dealers WHERE id = ?");
            $stmt->execute([$id]);
            $dealer = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($dealer && $dealer['user_id']) {
                // Update user status to active if business is approved
                if ($action === 'approve') {
                    $userStmt = $db->prepare("
                        UPDATE users 
                        SET status = 'active',
                            email_verified = CASE WHEN email_verified = 0 THEN 1 ELSE email_verified END,
                            verification_token = CASE WHEN email_verified = 0 THEN NULL ELSE verification_token END,
                            updated_at = NOW()
                        WHERE id = ? AND (status = 'pending' OR status = 'pending_approval')
                    ");
                    $userStmt->execute([$dealer['user_id']]);
                }
            }
            
            $db->commit();
            
            $actionText = ($action === 'approve') ? 'approved' : 'rejected';
            logActivity($db, 'dealer_approved', "Dealer $actionText", "Dealer ID: $id", $adminId);
            echo json_encode(['success' => true, 'message' => "Dealer has been {$actionText} successfully"]);
            exit();
        } else {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => 'Dealer not found or already processed']);
            exit();
        }
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("approveDealer error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to approve dealer: ' . $e->getMessage()]);
        exit();
    }
}

function handleBulkApproveGarages($db) {
    requireAdmin();
    $input   = json_decode(file_get_contents('php://input'), true);
    $ids     = array_filter(array_map('intval', (array)($input['garage_ids'] ?? [])));
    $action  = $input['action'] ?? '';
    if (empty($ids) || !in_array($action, ['approve', 'reject'], true)) {
        echo json_encode(['success' => false, 'message' => 'garage_ids and action (approve|reject) required']);
        return;
    }
    $newStatus      = $action === 'approve' ? 'active'    : 'suspended';
    $approvalStatus = $action === 'approve' ? 'approved'  : 'denied';
    $adminId        = $_SESSION['admin_id'] ?? null;
    try {
        $ph   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("UPDATE garages SET status = ?, approved_by = ?, approved_at = NOW(), updated_at = NOW() WHERE id IN ($ph)");
        $stmt->execute(array_merge([$newStatus, $adminId], $ids));
        $count = $stmt->rowCount();
        echo json_encode(['success' => true, 'message' => ucfirst($action) . "d $count garage(s)", 'count' => $count]);
    } catch (Exception $e) {
        error_log("handleBulkApproveGarages: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Bulk action failed: ' . $e->getMessage()]);
    }
}

function handleBulkUpdateGarages($db) {
    requireAdmin();
    $input   = json_decode(file_get_contents('php://input'), true);
    $ids     = array_filter(array_map('intval', (array)($input['garage_ids'] ?? [])));
    $status  = $input['status'] ?? '';
    $allowed = ['active', 'suspended', 'pending_approval'];
    if (empty($ids) || !in_array($status, $allowed, true)) {
        echo json_encode(['success' => false, 'message' => 'garage_ids and a valid status required']);
        return;
    }
    try {
        $ph   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("UPDATE garages SET status = ?, updated_at = NOW() WHERE id IN ($ph)");
        $stmt->execute(array_merge([$status], $ids));
        $count = $stmt->rowCount();
        echo json_encode(['success' => true, 'message' => "Updated $count garage(s) to '$status'", 'count' => $count]);
    } catch (Exception $e) {
        error_log("handleBulkUpdateGarages: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Bulk update failed: ' . $e->getMessage()]);
    }
}

function handleBulkDeleteGarages($db) {
    requireAdmin();
    $input = json_decode(file_get_contents('php://input'), true);
    $ids   = array_filter(array_map('intval', (array)($input['garage_ids'] ?? [])));
    if (empty($ids)) {
        echo json_encode(['success' => false, 'message' => 'garage_ids required']);
        return;
    }
    try {
        $ph   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("UPDATE garages SET status = 'deleted', updated_at = NOW() WHERE id IN ($ph)");
        $stmt->execute($ids);
        $count = $stmt->rowCount();
        echo json_encode(['success' => true, 'message' => "Deleted $count garage(s)", 'count' => $count]);
    } catch (Exception $e) {
        error_log("handleBulkDeleteGarages: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Bulk delete failed: ' . $e->getMessage()]);
    }
}

function handleBulkApproveDealers($db) {
    requireAdmin();
    $input   = json_decode(file_get_contents('php://input'), true);
    $ids     = array_filter(array_map('intval', (array)($input['dealer_ids'] ?? [])));
    $action  = $input['action'] ?? '';
    if (empty($ids) || !in_array($action, ['approve', 'reject'], true)) {
        echo json_encode(['success' => false, 'message' => 'dealer_ids and action (approve|reject) required']);
        return;
    }
    $newStatus = $action === 'approve' ? 'active' : 'suspended';
    $adminId   = $_SESSION['admin_id'] ?? null;
    try {
        $ph   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("UPDATE car_dealers SET status = ?, approved_by = ?, approved_at = NOW(), updated_at = NOW() WHERE id IN ($ph)");
        $stmt->execute(array_merge([$newStatus, $adminId], $ids));
        $count = $stmt->rowCount();
        echo json_encode(['success' => true, 'message' => ucfirst($action) . "d $count dealer(s)", 'count' => $count]);
    } catch (Exception $e) {
        error_log("handleBulkApproveDealers: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Bulk action failed: ' . $e->getMessage()]);
    }
}

function handleBulkUpdateDealers($db) {
    requireAdmin();
    $input   = json_decode(file_get_contents('php://input'), true);
    $ids     = array_filter(array_map('intval', (array)($input['dealer_ids'] ?? [])));
    $status  = $input['status'] ?? '';
    $allowed = ['active', 'suspended', 'pending_approval'];
    if (empty($ids) || !in_array($status, $allowed, true)) {
        echo json_encode(['success' => false, 'message' => 'dealer_ids and a valid status required']);
        return;
    }
    try {
        $ph   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("UPDATE car_dealers SET status = ?, updated_at = NOW() WHERE id IN ($ph)");
        $stmt->execute(array_merge([$status], $ids));
        $count = $stmt->rowCount();
        echo json_encode(['success' => true, 'message' => "Updated $count dealer(s) to '$status'", 'count' => $count]);
    } catch (Exception $e) {
        error_log("handleBulkUpdateDealers: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Bulk update failed: ' . $e->getMessage()]);
    }
}

function handleBulkDeleteDealers($db) {
    requireAdmin();
    $input = json_decode(file_get_contents('php://input'), true);
    $ids   = array_filter(array_map('intval', (array)($input['dealer_ids'] ?? [])));
    if (empty($ids)) {
        echo json_encode(['success' => false, 'message' => 'dealer_ids required']);
        return;
    }
    try {
        $ph   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("UPDATE car_dealers SET status = 'deleted', updated_at = NOW() WHERE id IN ($ph)");
        $stmt->execute($ids);
        $count = $stmt->rowCount();
        echo json_encode(['success' => true, 'message' => "Deleted $count dealer(s)", 'count' => $count]);
    } catch (Exception $e) {
        error_log("handleBulkDeleteDealers: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Bulk delete failed: ' . $e->getMessage()]);
    }
}

/**
 * Approve or reject a car hire company
 */
function approveCarHire($db) {
    requireAdmin();
    
    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? '';
    $action = $input['action'] ?? 'approve';
    
    if (empty($id)) {
        echo json_encode(['success' => false, 'message' => 'Car Hire ID is required']);
        return;
    }
    
    try {
        $db->beginTransaction();
        
        $newStatus = ($action === 'approve') ? 'active' : 'suspended';
        $adminId = $_SESSION['admin_id'] ?? null;
        
        // Update car hire company status
        $stmt = $db->prepare("
            UPDATE car_hire_companies 
            SET status = ?, approved_by = ?, approved_at = NOW(), updated_at = NOW()
            WHERE id = ? AND status = 'pending_approval'
        ");
        
        $result = $stmt->execute([$newStatus, $adminId, $id]);
        
        if ($result && $stmt->rowCount() > 0) {
            // Get the user_id from the car hire company
            $stmt = $db->prepare("SELECT user_id FROM car_hire_companies WHERE id = ?");
            $stmt->execute([$id]);
            $carHire = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($carHire && $carHire['user_id']) {
                // Update user status to active if business is approved
                if ($action === 'approve') {
                    $userStmt = $db->prepare("
                        UPDATE users 
                        SET status = 'active',
                            email_verified = CASE WHEN email_verified = 0 THEN 1 ELSE email_verified END,
                            verification_token = CASE WHEN email_verified = 0 THEN NULL ELSE verification_token END,
                            updated_at = NOW()
                        WHERE id = ? AND (status = 'pending' OR status = 'pending_approval')
                    ");
                    $userStmt->execute([$carHire['user_id']]);
                }
            }
            
            $db->commit();
            
            $actionText = ($action === 'approve') ? 'approved' : 'rejected';
            logActivity($db, 'car_hire_approved', "Car hire company $actionText", "Car Hire ID: $id", $adminId);
            echo json_encode(['success' => true, 'message' => "Car hire company has been {$actionText} successfully"]);
            exit();
        } else {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => 'Car hire company not found or already processed']);
            exit();
        }
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("approveCarHire error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to approve car hire company: ' . $e->getMessage()]);
        exit();
    }
}

// ===== ENHANCED FUNCTIONS FOR COMPLETE MANAGEMENT =====

function getGarages($db) {
    requireAdmin();
    
    try {
        $where = ["1=1"];
        $params = [];
        
        // Apply filters
        if (!empty($_GET['status'])) {
            $where[] = "g.status = ?";
            $params[] = $_GET['status'];
        }
        
        if (!empty($_GET['location_id'])) {
            $where[] = "g.location_id = ?";
            $params[] = $_GET['location_id'];
        }
        
        if (!empty($_GET['search'])) {
            $where[] = "(g.name LIKE ? OR g.owner_name LIKE ? OR g.email LIKE ?)";
            $searchTerm = "%" . $_GET['search'] . "%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        $whereClause = implode(' AND ', $where);
        
        $sql = "
            SELECT 
                g.*,
                l.name as location_name,
                l.region as location_region
            FROM garages g
            LEFT JOIN locations l ON g.location_id = l.id
            WHERE {$whereClause}
            ORDER BY g.created_at DESC
            LIMIT 100
        ";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $garages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'garages' => $garages]);
        exit();
    } catch (Exception $e) {
        error_log("getGarages error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to load garages']);
        exit();
    }
}

function getDealers($db) {
    requireAdmin();
    
    try {
        $where = ["1=1"];
        $params = [];
        
        // Apply filters
        if (!empty($_GET['status'])) {
            $where[] = "d.status = ?";
            $params[] = $_GET['status'];
        }
        
        if (!empty($_GET['location_id'])) {
            $where[] = "d.location_id = ?";
            $params[] = $_GET['location_id'];
        }
        
        if (!empty($_GET['search'])) {
            $where[] = "(d.business_name LIKE ? OR d.owner_name LIKE ? OR d.email LIKE ?)";
            $searchTerm = "%" . $_GET['search'] . "%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        $whereClause = implode(' AND ', $where);
        
        $sql = "
            SELECT 
                d.*,
                l.name as location_name,
                l.region as location_region
            FROM car_dealers d
            LEFT JOIN locations l ON d.location_id = l.id
            WHERE {$whereClause}
            ORDER BY d.created_at DESC
            LIMIT 100
        ";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $dealers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'dealers' => $dealers]);
        exit();
    } catch (Exception $e) {
        error_log("getDealers error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to load dealers: ' . $e->getMessage()]);
        exit();
    }
}

function getCarHireCompanies($db) {
    requireAdmin();
    
    try {
        $where = ["1=1"];
        $params = [];
        
        // Apply filters
        if (!empty($_GET['status'])) {
            $where[] = "c.status = ?";
            $params[] = $_GET['status'];
        }
        
        if (!empty($_GET['location_id'])) {
            $where[] = "c.location_id = ?";
            $params[] = $_GET['location_id'];
        }
        
        if (!empty($_GET['search'])) {
            $where[] = "(c.business_name LIKE ? OR c.owner_name LIKE ? OR c.email LIKE ?)";
            $searchTerm = "%" . $_GET['search'] . "%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        $whereClause = implode(' AND ', $where);
        
        $sql = "
            SELECT 
                c.*,
                l.name as location_name,
                l.region as location_region
            FROM car_hire_companies c
            LEFT JOIN locations l ON c.location_id = l.id
            WHERE {$whereClause}
            ORDER BY c.created_at DESC
            LIMIT 100
        ";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $carHire = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'car_hire' => $carHire]);
        exit();
    } catch (Exception $e) {
        error_log("getCarHireCompanies error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to load car hire companies: ' . $e->getMessage()]);
        exit();
    }
}

// Enhanced makes and models functions
function getMakesModels($db) {
    requireAdmin();
    
    try {
        // Get all makes
        $stmt = $db->query("SELECT * FROM car_makes WHERE is_active = 1 ORDER BY sort_order, name");
        $makes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get all models with make names
        $stmt = $db->query("
            SELECT m.*, mk.name as make_name 
            FROM car_models m 
            LEFT JOIN car_makes mk ON m.make_id = mk.id 
            WHERE m.is_active = 1 
            ORDER BY mk.name, m.name
        ");
        $models = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'makes' => $makes, 'models' => $models]);
        exit();
    } catch (Exception $e) {
        error_log("getMakesModels error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to load makes and models']);
        exit();
    }
}

// Enhanced locations function
function getLocations($db) {
    requireAdmin();
    
    try {
        $where = ["is_active = 1"];
        $params = [];
        
        if (!empty($_GET['region'])) {
            $where[] = "region = ?";
            $params[] = $_GET['region'];
        }
        
        if (!empty($_GET['search'])) {
            $where[] = "(name LIKE ? OR region LIKE ? OR district LIKE ?)";
            $searchTerm = "%" . $_GET['search'] . "%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        $whereClause = implode(' AND ', $where);
        
        $sql = "SELECT * FROM locations WHERE {$whereClause} ORDER BY region, name";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'locations' => $locations]);
        exit();
    } catch (Exception $e) {
        error_log("getLocations error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to load locations']);
        exit();
    }
}

// Function to get car makes for filters
function getCarMakes($db) {
    requireAdmin();
    
    try {
        $stmt = $db->query("SELECT id, name FROM car_makes WHERE is_active = 1 ORDER BY name");
        $makes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'makes' => $makes]);
        exit();
    } catch (Exception $e) {
        error_log("getCarMakes error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to load car makes']);
        exit();
    }
}

// function for payment filtering
function getPayments($db) {
    requireAdmin();
    
    try {
        ensurePaymentsTableAdmin($db);

        $where = ["1=1"];
        $params = [];
        $includeFreeListings = (int)($_GET['include_free_listings'] ?? 0) === 1;
        
        // Apply filters
        if (!empty($_GET['status'])) {
            $where[] = "status = ?";
            $params[] = $_GET['status'];
        }
        
        if (!empty($_GET['service_type'])) {
            $where[] = "service_type = ?";
            $params[] = $_GET['service_type'];
        }
        
        if (!empty($_GET['date_from'])) {
            $where[] = "DATE(created_at) >= ?";
            $params[] = $_GET['date_from'];
        }
        
        if (!empty($_GET['date_to'])) {
            $where[] = "DATE(created_at) <= ?";
            $params[] = $_GET['date_to'];
        }
        
        $whereClause = implode(' AND ', $where);

        $paymentSql = "
            SELECT
                p.id,
                p.listing_id,
                p.user_id,
                COALESCE(u.full_name, p.user_name, 'Guest Seller') as user_name,
                COALESCE(u.email, p.user_email, '') as user_email,
                COALESCE(cl.title, '') as listing_title,
                COALESCE(cl.status, '') as listing_status,
                p.service_type,
                p.amount,
                p.payment_method,
                p.reference,
                p.proof_path,
                p.status,
                p.notes,
                p.verified_at,
                p.verified_by,
                p.created_at,
                p.updated_at,
                0 as is_free_listing
            FROM payments p
            LEFT JOIN users u ON p.user_id = u.id
            LEFT JOIN car_listings cl ON p.listing_id = cl.id
            WHERE {$whereClause}
        ";

        $sql = $paymentSql;
        $allParams = $params;

        if ($includeFreeListings) {
            $statusFilter = strtolower(trim((string)($_GET['status'] ?? '')));
            $serviceFilter = strtolower(trim((string)($_GET['service_type'] ?? '')));

            $canIncludeByStatus = ($statusFilter === '' || $statusFilter === 'free');
            $canIncludeByService = ($serviceFilter === '' || $serviceFilter === 'free_listing');

            if ($canIncludeByStatus && $canIncludeByService) {
                $freeWhere = ["cl.status != 'deleted'", "(COALESCE(cl.payment_required, 0) = 0 OR COALESCE(cl.payment_amount, 0) <= 0)"];
                $freeParams = [];

                if (!empty($_GET['date_from'])) {
                    $freeWhere[] = "DATE(cl.created_at) >= ?";
                    $freeParams[] = $_GET['date_from'];
                }

                if (!empty($_GET['date_to'])) {
                    $freeWhere[] = "DATE(cl.created_at) <= ?";
                    $freeParams[] = $_GET['date_to'];
                }

                $freeWhereClause = implode(' AND ', $freeWhere);
                $freeSql = "
                    SELECT
                        CONCAT('FREE-', cl.id) as id,
                        cl.id as listing_id,
                        cl.user_id,
                        COALESCE(u.full_name, cl.guest_seller_name, cl.guest_seller_email, 'Guest Seller') as user_name,
                        COALESCE(u.email, cl.guest_seller_email, '') as user_email,
                        COALESCE(cl.title, '') as listing_title,
                        COALESCE(cl.status, '') as listing_status,
                        'free_listing' as service_type,
                        0 as amount,
                        'free' as payment_method,
                        cl.reference_number as reference,
                        NULL as proof_path,
                        'free' as status,
                        'Free listing (no payment required)' as notes,
                        NULL as verified_at,
                        NULL as verified_by,
                        cl.created_at,
                        COALESCE(cl.updated_at, cl.created_at) as updated_at,
                        1 as is_free_listing
                    FROM car_listings cl
                    LEFT JOIN users u ON cl.user_id = u.id
                    WHERE {$freeWhereClause}
                ";

                $sql = "SELECT * FROM (({$paymentSql}) UNION ALL ({$freeSql})) as all_payments ORDER BY created_at DESC LIMIT 100";
                $allParams = array_merge($params, $freeParams);
            } else {
                $sql = "SELECT * FROM ({$paymentSql}) as all_payments ORDER BY created_at DESC LIMIT 100";
            }
        } else {
            $sql = "SELECT * FROM ({$paymentSql}) as all_payments ORDER BY created_at DESC LIMIT 100";
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($allParams);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'payments' => $payments]);
        exit();
    } catch (Exception $e) {
        error_log("getPayments error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to load payments']);
        exit();
    }
}

function ensurePaymentsTableAdmin($db) {
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

function getPaymentStats($db) {
    requireAdmin();

    try {
        ensurePaymentsTableAdmin($db);

        $today = date('Y-m-d');
        $monthStart = date('Y-m-01');

        $todayStmt = $db->prepare("SELECT COUNT(*) as count, COALESCE(SUM(amount),0) as amount FROM payments WHERE DATE(created_at) = ?");
        $todayStmt->execute([$today]);
        $todayRow = $todayStmt->fetch(PDO::FETCH_ASSOC);

        $monthStmt = $db->prepare("SELECT COUNT(*) as count, COALESCE(SUM(amount),0) as amount FROM payments WHERE DATE(created_at) >= ?");
        $monthStmt->execute([$monthStart]);
        $monthRow = $monthStmt->fetch(PDO::FETCH_ASSOC);

        $pendingStmt = $db->query("SELECT COUNT(*) as count, COALESCE(SUM(amount),0) as amount FROM payments WHERE status = 'pending'");
        $pendingRow = $pendingStmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'stats' => [
                'today' => ['count' => (int)$todayRow['count'], 'amount' => (float)$todayRow['amount']],
                'month' => ['count' => (int)$monthRow['count'], 'amount' => (float)$monthRow['amount']],
                'pending' => ['count' => (int)$pendingRow['count'], 'amount' => (float)$pendingRow['amount']]
            ]
        ]);
        exit();
    } catch (Exception $e) {
        error_log('getPaymentStats error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to load payment stats']);
        exit();
    }
}

function handleVerifyPayment($db) {
    requireAdmin();
    $input = json_decode(file_get_contents('php://input'), true);
    $id = (int)($input['id'] ?? 0);

    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Payment id is required']);
        exit();
    }

    try {
        ensurePaymentsTableAdmin($db);
        $db->beginTransaction();

        $stmt = $db->prepare("SELECT id, listing_id FROM payments WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$payment) {
            throw new Exception('Payment not found');
        }

        $upd = $db->prepare("UPDATE payments SET status = 'verified', verified_at = NOW(), verified_by = ? WHERE id = ?");
        $upd->execute([$_SESSION['admin_id'] ?? null, $id]);

        if (!empty($payment['listing_id'])) {
            $listingUpd = $db->prepare("UPDATE car_listings SET payment_status = 'verified' WHERE id = ?");
            $listingUpd->execute([(int)$payment['listing_id']]);
        }

        $db->commit();
        echo json_encode(['success' => true, 'message' => 'Payment verified successfully']);
        exit();
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('handleVerifyPayment error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to verify payment']);
        exit();
    }
}

function handleRejectPayment($db) {
    requireAdmin();
    $input = json_decode(file_get_contents('php://input'), true);
    $id = (int)($input['id'] ?? 0);

    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Payment id is required']);
        exit();
    }

    try {
        ensurePaymentsTableAdmin($db);
        $db->beginTransaction();

        $stmt = $db->prepare("SELECT id, listing_id FROM payments WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$payment) {
            throw new Exception('Payment not found');
        }

        $upd = $db->prepare("UPDATE payments SET status = 'rejected', verified_at = NOW(), verified_by = ? WHERE id = ?");
        $upd->execute([$_SESSION['admin_id'] ?? null, $id]);

        if (!empty($payment['listing_id'])) {
            $listingUpd = $db->prepare("UPDATE car_listings SET payment_status = 'rejected' WHERE id = ?");
            $listingUpd->execute([(int)$payment['listing_id']]);
        }

        $db->commit();
        echo json_encode(['success' => true, 'message' => 'Payment rejected']);
        exit();
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('handleRejectPayment error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to reject payment']);
        exit();
    }
}

function handleRecordManualPayment($db) {
    requireAdmin();

    $input = json_decode(file_get_contents('php://input'), true);
    $listingId = (int)($input['listing_id'] ?? 0);
    $amount = (float)($input['amount'] ?? 0);
    $paymentMethod = trim((string)($input['payment_method'] ?? 'manual'));
    $reference = trim((string)($input['reference'] ?? ''));
    $status = strtolower(trim((string)($input['status'] ?? 'verified')));
    $serviceType = trim((string)($input['service_type'] ?? 'listing_submission'));
    $notes = trim((string)($input['notes'] ?? ''));

    if ($listingId <= 0) {
        sendAdminError('Listing ID is required', 'LISTING_ID_REQUIRED', 400);
    }

    if ($amount < 0) {
        sendAdminError('Amount cannot be negative', 'INVALID_AMOUNT', 400);
    }

    if (!in_array($status, ['pending', 'verified', 'rejected'], true)) {
        sendAdminError('Invalid payment status', 'INVALID_PAYMENT_STATUS', 400);
    }

    try {
        ensurePaymentsTableAdmin($db);

        $listingStmt = $db->prepare("SELECT id, user_id, title, reference_number, COALESCE(guest_seller_name, '') AS guest_seller_name, COALESCE(guest_seller_email, '') AS guest_seller_email FROM car_listings WHERE id = ? LIMIT 1");
        $listingStmt->execute([$listingId]);
        $listing = $listingStmt->fetch(PDO::FETCH_ASSOC);

        if (!$listing) {
            sendAdminError('Listing not found', 'LISTING_NOT_FOUND', 404);
        }

        $userId = !empty($listing['user_id']) ? (int)$listing['user_id'] : null;
        $userName = $listing['guest_seller_name'] !== '' ? $listing['guest_seller_name'] : 'Guest Seller';
        $userEmail = $listing['guest_seller_email'] !== '' ? $listing['guest_seller_email'] : '';

        if ($userId) {
            $userStmt = $db->prepare("SELECT full_name, email FROM users WHERE id = ? LIMIT 1");
            $userStmt->execute([$userId]);
            $userRow = $userStmt->fetch(PDO::FETCH_ASSOC);
            if ($userRow) {
                $userName = $userRow['full_name'] ?: $userName;
                $userEmail = $userRow['email'] ?: $userEmail;
            }
        }

        $adminId = $_SESSION['admin_id'] ?? null;
        $verifiedAt = ($status === 'verified' || $status === 'rejected') ? date('Y-m-d H:i:s') : null;

        $db->beginTransaction();

        $insertStmt = $db->prepare("INSERT INTO payments (listing_id, user_id, user_name, user_email, service_type, amount, payment_method, reference, status, notes, verified_at, verified_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
        $insertStmt->execute([
            $listingId,
            $userId,
            $userName,
            $userEmail,
            $serviceType !== '' ? $serviceType : 'listing_submission',
            $amount,
            $paymentMethod !== '' ? $paymentMethod : 'manual',
            $reference !== '' ? $reference : null,
            $status,
            $notes !== '' ? $notes : null,
            $verifiedAt,
            ($status === 'verified' || $status === 'rejected') ? $adminId : null
        ]);

        $paymentStatusForListing = $status === 'verified' ? 'verified' : ($status === 'rejected' ? 'rejected' : 'pending');
        $hasPaymentRequired = adminTableColumnExists($db, 'car_listings', 'payment_required');
        $hasPaymentAmount = adminTableColumnExists($db, 'car_listings', 'payment_amount');
        $hasPaymentMethod = adminTableColumnExists($db, 'car_listings', 'payment_method');
        $hasPaymentReference = adminTableColumnExists($db, 'car_listings', 'payment_reference');
        $hasPaymentStatus = adminTableColumnExists($db, 'car_listings', 'payment_status');

        $setParts = [];
        $setParams = [];
        if ($hasPaymentRequired) {
            $setParts[] = 'payment_required = 1';
        }
        if ($hasPaymentAmount) {
            $setParts[] = 'payment_amount = ?';
            $setParams[] = $amount;
        }
        if ($hasPaymentMethod) {
            $setParts[] = 'payment_method = ?';
            $setParams[] = ($paymentMethod !== '' ? $paymentMethod : 'manual');
        }
        if ($hasPaymentReference) {
            $setParts[] = 'payment_reference = ?';
            $setParams[] = ($reference !== '' ? $reference : null);
        }
        if ($hasPaymentStatus) {
            $setParts[] = 'payment_status = ?';
            $setParams[] = $paymentStatusForListing;
        }

        if (!empty($setParts)) {
            $setParams[] = $listingId;
            $listingUpdateStmt = $db->prepare('UPDATE car_listings SET ' . implode(', ', $setParts) . ' WHERE id = ? LIMIT 1');
            $listingUpdateStmt->execute($setParams);
        }

        logActivity(
            $db,
            'manual_payment_recorded',
            'Manual payment recorded for listing',
            'Listing ID: ' . $listingId . ', Amount: ' . number_format($amount, 2) . ', Status: ' . $status,
            $adminId
        );

        $db->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Manual payment recorded successfully'
        ]);
        exit();
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('handleRecordManualPayment error: ' . $e->getMessage());
        sendAdminError('Failed to record manual payment', 'MANUAL_PAYMENT_RECORD_FAILED', 500);
    }
}

// Load Functions

function loadGarages($db) {
    requireAdmin();
    
    try {
        $where = [];
        $params = [];
        
        // Apply filters
        if (!empty($_GET['status'])) {
            $where[] = "g.status = ?";
            $params[] = $_GET['status'];
        }
        
        if (!empty($_GET['location_id'])) {
            $where[] = "g.location_id = ?";
            $params[] = $_GET['location_id'];
        }
        
        if (!empty($_GET['search'])) {
            $where[] = "(g.name LIKE ? OR g.owner_name LIKE ? OR g.email LIKE ?)";
            $searchTerm = "%" . $_GET['search'] . "%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        $whereClause = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);
        
        $sql = "
            SELECT 
                g.*,
                g.featured as is_featured,
                g.verified as is_verified,
                g.certified as is_certified,
                l.name as location_name,
                l.region as location_region
            FROM garages g
            LEFT JOIN locations l ON g.location_id = l.id
            {$whereClause}
            ORDER BY g.created_at DESC
            LIMIT 100
        ";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $garages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'garages' => $garages]);
        exit();
    } catch (Exception $e) {
        error_log("loadGarages error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to load garages']);
        exit();
    }
}

function loadDealers($db) {
    requireAdmin();
    
    try {
        $where = [];
        $params = [];
        
        // Apply filters
        if (!empty($_GET['status'])) {
            $where[] = "d.status = ?";
            $params[] = $_GET['status'];
        }
        
        if (!empty($_GET['location_id'])) {
            $where[] = "d.location_id = ?";
            $params[] = $_GET['location_id'];
        }
        
        if (!empty($_GET['search'])) {
            $where[] = "(d.business_name LIKE ? OR d.owner_name LIKE ? OR d.email LIKE ?)";
            $searchTerm = "%" . $_GET['search'] . "%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        $whereClause = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);
        
        $sql = "
            SELECT 
                d.*,
                d.featured as is_featured,
                d.verified as is_verified,
                COALESCE(d.certified, 0) as is_certified,
                l.name as location_name,
                l.region as location_region
            FROM car_dealers d
            LEFT JOIN locations l ON d.location_id = l.id
            {$whereClause}
            ORDER BY d.created_at DESC
            LIMIT 100
        ";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $dealers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'dealers' => $dealers]);
        exit();
    } catch (Exception $e) {
        error_log("loadDealers error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to load dealers: ' . $e->getMessage()]);
        exit();
    }
}

function loadCarHire($db) {
    requireAdmin();
    
    try {
        $where = [];
        $params = [];
        
        // Apply filters
        if (!empty($_GET['status'])) {
            $where[] = "c.status = ?";
            $params[] = $_GET['status'];
        }
        
        if (!empty($_GET['location_id'])) {
            $where[] = "c.location_id = ?";
            $params[] = $_GET['location_id'];
        }
        
        if (!empty($_GET['search'])) {
            $where[] = "(c.business_name LIKE ? OR c.owner_name LIKE ? OR c.email LIKE ?)";
            $searchTerm = "%" . $_GET['search'] . "%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        $whereClause = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);
        
        $sql = "
            SELECT
                c.*,
                c.featured as is_featured,
                c.verified as is_verified,
                COALESCE(c.certified, 0) as is_certified,
                l.name as location_name,
                l.region as location_region,
                (SELECT COUNT(*) FROM car_listings WHERE user_id = c.user_id AND status != 'deleted') as active_listings,
                (SELECT COUNT(*) FROM car_hire_fleet WHERE company_id = c.id) as fleet_count
            FROM car_hire_companies c
            LEFT JOIN locations l ON c.location_id = l.id
            {$whereClause}
            ORDER BY c.created_at DESC
            LIMIT 100
        ";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $carHire = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'car_hire' => $carHire]);
        exit();
    } catch (Exception $e) {
        error_log("loadCarHire error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to load car hire companies: ' . $e->getMessage()]);
        exit();
    }
}

function loadMakesModels($db) {
    requireAdmin();
    
    try {
        // Get all makes with model counts
        $stmt = $db->query("
            SELECT 
                m.*,
                COUNT(DISTINCT CONCAT(cm.name, '-', cm.body_type)) as models_count
            FROM car_makes m
            LEFT JOIN car_models cm ON m.id = cm.make_id AND cm.is_active = 1
            WHERE m.is_active = 1
            GROUP BY m.id
            ORDER BY m.sort_order, m.name
        ");
        $makes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get all models with make names - show ALL variations (all rows)
        // This allows editing each individual variation
        try {
            $stmt = $db->query("
                SELECT 
                    m.id,
                    m.name,
                    m.body_type,
                    m.make_id,
                    m.is_active,
                    m.year_start,
                    m.year_end,
                    m.engine_size_liters,
                    m.fuel_tank_capacity_liters,
                    m.fuel_type,
                    m.transmission_type,
                    m.drive_type,
                    COALESCE(m.horsepower_hp, NULL) as horsepower_hp,
                    COALESCE(m.torque_nm, NULL) as torque_nm,
                    COALESCE(m.fuel_consumption_liters_per_100km, NULL) as fuel_consumption_liters_per_100km,
                    mk.name as make_name 
                FROM car_models m 
                LEFT JOIN car_makes mk ON m.make_id = mk.id 
                WHERE m.is_active = 1 
                ORDER BY mk.name, m.name, m.engine_size_liters, m.id
            ");
            $models = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $queryError) {
            // If query fails, try with only essential columns
            error_log("Full query failed, trying simplified: " . $queryError->getMessage());
            $stmt = $db->query("
                SELECT 
                    m.id,
                    m.name,
                    m.body_type,
                    m.make_id,
                    m.is_active,
                    m.year_start,
                    m.year_end,
                    m.engine_size_liters,
                    m.fuel_tank_capacity_liters,
                    m.fuel_type,
                    m.transmission_type,
                    m.drive_type,
                    mk.name as make_name 
                FROM car_models m 
                LEFT JOIN car_makes mk ON m.make_id = mk.id 
                WHERE m.is_active = 1 
                ORDER BY mk.name, m.name, m.engine_size_liters, m.id
            ");
            $models = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // Add null values for missing columns
            foreach ($models as &$model) {
                $model['horsepower_hp'] = null;
                $model['torque_nm'] = null;
                $model['fuel_consumption_liters_per_100km'] = null;
            }
            unset($model);
        }

        echo json_encode(['success' => true, 'makes' => $makes, 'models' => $models]);
        exit();
    } catch (Exception $e) {
        error_log("loadMakesModels error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to load makes and models']);
        exit();
    }
}

function loadLocations($db) {
    requireAdmin();
    
    try {
        $where = [];
        $params = [];
        
        if (!empty($_GET['region'])) {
            $where[] = "region = ?";
            $params[] = $_GET['region'];
        }
        
        if (!empty($_GET['search'])) {
            $where[] = "(name LIKE ? OR region LIKE ? OR district LIKE ?)";
            $searchTerm = "%" . $_GET['search'] . "%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        $where[] = "is_active = 1";
        $whereClause = 'WHERE ' . implode(' AND ', $where);
        
        $sql = "SELECT * FROM locations {$whereClause} ORDER BY region, name";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'locations' => $locations]);
        exit();
    } catch (Exception $e) {
        error_log("loadLocations error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to load locations']);
        exit();
    }
}

// ===== CAR IMAGE MANAGEMENT FUNCTIONS =====

function handleGetCarImages($db) {
    requireAdmin();
    
    $carId = $_GET['car_id'] ?? '';
    if (empty($carId)) {
        echo json_encode(['success' => false, 'message' => 'Car ID is required']);
        return;
    }
    
    try {
        $stmt = $db->prepare("
            SELECT 
                id,
                listing_id,
                filename,
                original_filename,
                file_path,
                thumbnail_path,
                file_size,
                mime_type,
                is_primary,
                sort_order,
                uploaded_at
            FROM car_listing_images 
            WHERE listing_id = ? 
            ORDER BY is_primary DESC, sort_order ASC, id ASC
        ");
        $stmt->execute([$carId]);
        $images = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'images' => $images,
            'count' => count($images)
        ]);
        exit();
    } catch (Exception $e) {
        error_log("Error fetching car images: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
            'images' => []
        ]);
        exit();
    }
}

function handleUploadCarImages($db) {
    requireAdmin();
    
    $carId = $_POST['car_id'] ?? '';
    if (empty($carId)) {
        echo json_encode(['success' => false, 'message' => 'Car ID is required']);
        return;
    }
    
    try {
        $uploadedImages = [];
        
        if (!empty($_FILES['images'])) {
            // Ensure uploads directory exists
            $uploadDir = '../uploads/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            foreach ($_FILES['images']['tmp_name'] as $key => $tmpName) {
                if ($_FILES['images']['error'][$key] === UPLOAD_ERR_OK) {
                    $originalName = $_FILES['images']['name'][$key];
                    $fileExtension = pathinfo($originalName, PATHINFO_EXTENSION);
                    $fileName = uniqid() . '_' . time() . '.' . $fileExtension;
                    $uploadPath = $uploadDir . $fileName;
                    
                    if (move_uploaded_file($tmpName, $uploadPath)) {
                        // Check if this should be primary (first image or if no images exist)
                        $isPrimary = 0;
                        if ($key === 0) {
                            $checkStmt = $db->prepare("SELECT COUNT(*) FROM car_listing_images WHERE listing_id = ? AND is_primary = 1");
                            $checkStmt->execute([$carId]);
                            $primaryCount = $checkStmt->fetchColumn();
                            $isPrimary = ($primaryCount == 0) ? 1 : 0;
                        }
                        
                        $stmt = $db->prepare("
                            INSERT INTO car_listing_images 
                            (listing_id, filename, original_filename, file_path, file_size, mime_type, is_primary, uploaded_at) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                        ");
                        $stmt->execute([
                            $carId, 
                            $fileName, 
                            $originalName, 
                            $uploadPath, 
                            $_FILES['images']['size'][$key],
                            $_FILES['images']['type'][$key],
                            $isPrimary
                        ]);
                        
                        $uploadedImages[] = [
                            'id' => $db->lastInsertId(),
                            'filename' => $fileName,
                            'original_filename' => $originalName,
                            'file_path' => $uploadPath,
                            'is_primary' => $isPrimary
                        ];
                    }
                }
            }
        }

        echo json_encode([
            'success' => true,
            'message' => 'Images uploaded successfully',
            'images' => $uploadedImages
        ]);
        exit();
    } catch (Exception $e) {
        error_log("handleUploadCarImages error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to upload images: ' . $e->getMessage()]);
        exit();
    }
}

function handleDeleteCarImage($db) {
    requireAdmin();
    
    $input = json_decode(file_get_contents('php://input'), true);
    $imageId = $input['image_id'] ?? '';
    
    if (empty($imageId)) {
        echo json_encode(['success' => false, 'message' => 'Image ID is required']);
        return;
    }
    
    try {
        // Get image info to delete file
        $stmt = $db->prepare("SELECT filename, file_path, is_primary, listing_id FROM car_listing_images WHERE id = ?");
        $stmt->execute([$imageId]);
        $image = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($image) {
            // Delete from database
            $stmt = $db->prepare("DELETE FROM car_listing_images WHERE id = ?");
            $result = $stmt->execute([$imageId]);
            
            if ($result) {
                // Delete physical file
                $filePath = $image['file_path'];
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
                
                // If we deleted a primary image, set another one as primary
                if ($image['is_primary']) {
                    $setPrimaryStmt = $db->prepare("
                        UPDATE car_listing_images 
                        SET is_primary = 1 
                        WHERE listing_id = ? 
                        ORDER BY id ASC 
                        LIMIT 1
                    ");
                    $setPrimaryStmt->execute([$image['listing_id']]);
                }

                echo json_encode(['success' => true, 'message' => 'Image deleted successfully']);
                exit();
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to delete image from database']);
                exit();
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Image not found']);
            exit();
        }

    } catch (Exception $e) {
        error_log("handleDeleteCarImage error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to delete image: ' . $e->getMessage()]);
        exit();
    }
}

function handleSetPrimaryImage($db) {
    requireAdmin();
    
    $input = json_decode(file_get_contents('php://input'), true);
    $imageId = $input['image_id'] ?? '';
    $carId = $input['car_id'] ?? '';
    
    if (empty($imageId) || empty($carId)) {
        echo json_encode(['success' => false, 'message' => 'Image ID and Car ID are required']);
        return;
    }
    
    try {
        // First, set all images for this car as not primary
        $stmt = $db->prepare("UPDATE car_listing_images SET is_primary = 0 WHERE listing_id = ?");
        $stmt->execute([$carId]);
        
        // Then set the selected image as primary
        $stmt = $db->prepare("UPDATE car_listing_images SET is_primary = 1 WHERE id = ? AND listing_id = ?");
        $result = $stmt->execute([$imageId, $carId]);

        if ($result && $stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => 'Primary image updated successfully']);
            exit();
        } else {
            throw new Exception('Failed to set primary image');
        }

    } catch (Exception $e) {
        error_log("handleSetPrimaryImage error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to set primary image: ' . $e->getMessage()]);
        exit();
    }
}

function handleUpdateCar($db) {
    requireAdmin();
    
    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? '';
    
    if (empty($id)) {
        echo json_encode(['success' => false, 'message' => 'Car ID is required']);
        return;
    }
    
    try {
        // List of allowed fields to update
        $allowedFields = [
            'title', 'description', 'make_id', 'model_id', 'year', 'price', 
            'negotiable', 'location_id', 'fuel_type', 'transmission', 
            'mileage', 'condition_type', 'color', 'body_type', 'engine_size', 'features',
            'status'
        ];

        // Validate status if provided
        if (isset($input['status'])) {
            $allowedStatuses = ['active', 'suspended', 'pending_approval', 'rejected'];
            if (!in_array($input['status'], $allowedStatuses, true)) {
                echo json_encode(['success' => false, 'message' => 'Invalid status value']);
                return;
            }
        }
        
        $updateData = [];
        foreach ($allowedFields as $field) {
            if (isset($input[$field])) {
                $updateData[$field] = $input[$field];
            }
        }
        
        if (empty($updateData)) {
            echo json_encode(['success' => false, 'message' => 'No data to update']);
            return;
        }
        
        // Add updated_at timestamp
        $updateData['updated_at'] = date('Y-m-d H:i:s');
        
        $setClause = [];
        $params = [];
        foreach ($updateData as $field => $value) {
            $setClause[] = "$field = ?";
            $params[] = $value;
        }
        $params[] = $id;
        
        $sql = "UPDATE car_listings SET " . implode(', ', $setClause) . " WHERE id = ?";
        $stmt = $db->prepare($sql);
        $result = $stmt->execute($params);

        if ($result) {
            echo json_encode(['success' => true, 'message' => 'Car updated successfully']);
            exit();
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update car']);
            exit();
        }

    } catch (Exception $e) {
        error_log("handleUpdateCar error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to update car']);
        exit();
    }
}

function handleDeleteCar($db) {
    requireAdmin();

    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? '';

    if (empty($id)) {
        echo json_encode(['success' => false, 'message' => 'Car ID is required']);
        return;
    }

    try {
        // Soft delete - set status to 'deleted' instead of removing from database
        $stmt = $db->prepare("
            UPDATE car_listings
            SET status = 'deleted'
            WHERE id = ?
        ");
        $result = $stmt->execute([$id]);

        if ($result) {
            echo json_encode(['success' => true, 'message' => 'Car deleted successfully']);
            exit();
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to delete car']);
            exit();
        }

    } catch (Exception $e) {
        error_log("handleDeleteCar error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to delete car']);
        exit();
    }
}

function handleRestoreCar($db) {
    requireAdmin();
    $input = json_decode(file_get_contents('php://input'), true);
    $id = intval($input['id'] ?? 0);

    if (empty($id)) {
        echo json_encode(['success' => false, 'message' => 'Car ID is required']);
        return;
    }

    try {
        $stmt = $db->prepare(
            "UPDATE car_listings SET status = 'active', updated_at = NOW() WHERE id = ? AND status = 'deleted'"
        );
        $stmt->execute([$id]);

        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => 'Car listing restored successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Car not found or not in deleted state']);
        }
        exit();
    } catch (Exception $e) {
        error_log("handleRestoreCar error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to restore car']);
        exit();
    }
}

function handleBulkApproveCars($db) {
    requireAdmin();
    $input = json_decode(file_get_contents('php://input'), true);
    $carIds = array_filter(array_map('intval', (array)($input['car_ids'] ?? [])));
    $action = $input['action'] ?? '';

    if (empty($carIds) || !in_array($action, ['approve', 'reject'], true)) {
        echo json_encode(['success' => false, 'message' => 'car_ids and action (approve|reject) required']);
        return;
    }

    $newStatus     = $action === 'approve' ? 'active'    : 'rejected';
    $approvalStatus = $action === 'approve' ? 'approved'  : 'denied';
    $adminId       = $_SESSION['admin_id'] ?? null;

    try {
        $placeholders = implode(',', array_fill(0, count($carIds), '?'));
        $params = array_merge([$newStatus, $approvalStatus, $adminId], $carIds);
        $stmt = $db->prepare(
            "UPDATE car_listings
             SET status = ?, approval_status = ?, approved_by = ?, updated_at = NOW()
             WHERE id IN ($placeholders)"
        );
        $stmt->execute($params);
        $count = $stmt->rowCount();
        echo json_encode(['success' => true, 'message' => ucfirst($action) . "d $count listing(s) successfully", 'count' => $count]);
    } catch (Exception $e) {
        error_log("handleBulkApproveCars error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Bulk action failed: ' . $e->getMessage()]);
    }
}

function handleBulkUpdateCars($db) {
    requireAdmin();
    $input  = json_decode(file_get_contents('php://input'), true);
    $carIds = array_filter(array_map('intval', (array)($input['car_ids'] ?? [])));
    $status = $input['status'] ?? '';
    $allowed = ['active', 'suspended', 'pending_approval'];

    if (empty($carIds) || !in_array($status, $allowed, true)) {
        echo json_encode(['success' => false, 'message' => 'car_ids and a valid status required']);
        return;
    }

    try {
        $placeholders = implode(',', array_fill(0, count($carIds), '?'));
        $params = array_merge([$status], $carIds);
        $stmt = $db->prepare(
            "UPDATE car_listings SET status = ?, updated_at = NOW() WHERE id IN ($placeholders)"
        );
        $stmt->execute($params);
        $count = $stmt->rowCount();
        echo json_encode(['success' => true, 'message' => "Updated $count listing(s) to '$status'", 'count' => $count]);
    } catch (Exception $e) {
        error_log("handleBulkUpdateCars error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Bulk update failed: ' . $e->getMessage()]);
    }
}

function handleBulkDeleteCars($db) {
    requireAdmin();
    $input  = json_decode(file_get_contents('php://input'), true);
    $carIds = array_filter(array_map('intval', (array)($input['car_ids'] ?? [])));

    if (empty($carIds)) {
        echo json_encode(['success' => false, 'message' => 'car_ids required']);
        return;
    }

    try {
        $placeholders = implode(',', array_fill(0, count($carIds), '?'));
        $params = $carIds;
        $stmt = $db->prepare(
            "UPDATE car_listings SET status = 'deleted', updated_at = NOW() WHERE id IN ($placeholders)"
        );
        $stmt->execute($params);
        $count = $stmt->rowCount();
        echo json_encode(['success' => true, 'message' => "Deleted $count listing(s) successfully", 'count' => $count]);
    } catch (Exception $e) {
        error_log("handleBulkDeleteCars error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Bulk delete failed: ' . $e->getMessage()]);
    }
}

// ===== USER MANAGEMENT FUNCTIONS =====

function handleUpdateUser($db) {
    requireAdmin();

    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? '';

    if (empty($id)) {
        echo json_encode(['success' => false, 'message' => 'User ID is required']);
        return;
    }

    try {
        $allowedFields = ['full_name', 'email', 'phone', 'user_type', 'status'];

        $updateData = [];
        foreach ($allowedFields as $field) {
            if (isset($input[$field])) {
                $updateData[$field] = $input[$field];
            }
        }

        // Handle password update if provided
        if (!empty($input['password'])) {
            if (strlen($input['password']) < 6) {
                echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters']);
                return;
            }
            $updateData['password_hash'] = password_hash($input['password'], PASSWORD_DEFAULT);
        }

        if (empty($updateData)) {
            echo json_encode(['success' => false, 'message' => 'No data to update']);
            return;
        }

        $updateData['updated_at'] = date('Y-m-d H:i:s');

        $setClause = [];
        $params = [];
        foreach ($updateData as $field => $value) {
            $setClause[] = "$field = ?";
            $params[] = $value;
        }
        $params[] = $id;

        $sql = "UPDATE users SET " . implode(', ', $setClause) . " WHERE id = ?";
        $stmt = $db->prepare($sql);
        $result = $stmt->execute($params);

        if ($result) {
            // If status is being set to 'suspended', also suspend the associated business
            if (isset($updateData['status']) && $updateData['status'] === 'suspended') {
                // Get user info to find associated business
                $userStmt = $db->prepare("SELECT user_type, business_id FROM users WHERE id = ?");
                $userStmt->execute([$id]);
                $user = $userStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user && $user['business_id']) {
                    // Suspend the associated business based on user type
                    switch ($user['user_type']) {
                        case 'garage':
                            $businessStmt = $db->prepare("
                                UPDATE garages 
                                SET status = 'suspended', updated_at = NOW()
                                WHERE id = ? AND status != 'suspended'
                            ");
                            $businessStmt->execute([$user['business_id']]);
                            break;
                            
                        case 'dealer':
                            $businessStmt = $db->prepare("
                                UPDATE car_dealers 
                                SET status = 'suspended', updated_at = NOW()
                                WHERE id = ? AND status != 'suspended'
                            ");
                            $businessStmt->execute([$user['business_id']]);
                            break;
                            
                        case 'car_hire':
                            $businessStmt = $db->prepare("
                                UPDATE car_hire_companies 
                                SET status = 'suspended', updated_at = NOW()
                                WHERE id = ? AND status != 'suspended'
                            ");
                            $businessStmt->execute([$user['business_id']]);
                            break;
                    }
                }
            }
            
            // Log the activity
            logActivity($db, 'user_updated', 'User updated', "User ID: $id", $_SESSION['admin_id'] ?? null);

            echo json_encode(['success' => true, 'message' => 'User updated successfully' . (isset($updateData['status']) && $updateData['status'] === 'suspended' && isset($user['business_id']) ? ' and associated business suspended' : '')]);
            exit();
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update user']);
            exit();
        }

    } catch (Exception $e) {
        error_log("handleUpdateUser error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to update user: ' . $e->getMessage()]);
        exit();
    }
}

function handleDeleteUser($db) {
    requireAdmin();

    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? '';

    if (empty($id)) {
        echo json_encode(['success' => false, 'message' => 'User ID is required']);
        return;
    }

    try {
        // Start transaction to ensure all deletes happen together
        $db->beginTransaction();

        // Get user info to determine what business entities to delete
        $stmt = $db->prepare("SELECT user_type, business_id FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => 'User not found']);
            return;
        }

        // CASCADE DELETE ALL RELATED RECORDS
        
        // 1. Get all car listing IDs for this user to delete images
        $stmt = $db->prepare("SELECT id FROM car_listings WHERE user_id = ?");
        $stmt->execute([$id]);
        $listingIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($listingIds)) {
            $placeholders = str_repeat('?,', count($listingIds) - 1) . '?';
            
            // Delete all car listing images (both from database and filesystem)
            $imgStmt = $db->prepare("SELECT filename FROM car_listing_images WHERE listing_id IN ($placeholders)");
            $imgStmt->execute($listingIds);
            $images = $imgStmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Delete image files from filesystem
            foreach ($images as $image) {
                if (!empty($image)) {
                    $imagePath = __DIR__ . '/../uploads/' . $image;
                    if (file_exists($imagePath)) {
                        @unlink($imagePath);
                    }
                }
            }
            
            // Delete car listing images from database
            $stmt = $db->prepare("DELETE FROM car_listing_images WHERE listing_id IN ($placeholders)");
            $stmt->execute($listingIds);
        }

        // 2. Delete all car listings owned by this user (hard delete - user is being removed)
        $stmt = $db->prepare("DELETE FROM car_listings WHERE user_id = ?");
        $stmt->execute([$id]);
        
        // 3. Delete/cascade business entities based on user_type
        if ($user['user_type'] === 'garage') {
            // NOTE: garage_services, garage_specializations, and garage_reviews tables
            // don't exist in current schema - CASCADE will handle related data when implemented

            // Delete garages themselves
            $stmt = $db->prepare("DELETE FROM garages WHERE user_id = ?");
            $stmt->execute([$id]);

        } elseif ($user['user_type'] === 'dealer') {
            // NOTE: dealer_inventory and dealer_reviews tables don't exist in current schema
            // CASCADE will handle related data when implemented

            // Delete dealers themselves
            $stmt = $db->prepare("DELETE FROM car_dealers WHERE user_id = ?");
            $stmt->execute([$id]);
            
        } elseif ($user['user_type'] === 'car_hire') {
            // Get car hire company IDs owned by this user
            $stmt = $db->prepare("SELECT id FROM car_hire_companies WHERE user_id = ?");
            $stmt->execute([$id]);
            $carHireIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($carHireIds)) {
                $placeholders = str_repeat('?,', count($carHireIds) - 1) . '?';

                // Get fleet vehicles to delete their images
                $fleetStmt = $db->prepare("SELECT image FROM car_hire_fleet WHERE company_id IN ($placeholders)");
                $fleetStmt->execute($carHireIds);
                $fleetImages = $fleetStmt->fetchAll(PDO::FETCH_COLUMN);
                
                // Delete fleet vehicle images from filesystem
                foreach ($fleetImages as $image) {
                    if (!empty($image)) {
                        $imagePath = __DIR__ . '/../uploads/' . $image;
                        if (file_exists($imagePath)) {
                            @unlink($imagePath);
                        }
                    }
                }

                // DELETE ALL FLEET VEHICLES for these companies
                $stmt = $db->prepare("DELETE FROM car_hire_fleet WHERE company_id IN ($placeholders)");
                $stmt->execute($carHireIds);

                // NOTE: car_hire_bookings and car_hire_reviews tables don't exist in current schema
                // CASCADE will handle related data when implemented
            }

            // Delete car hire companies themselves
            $stmt = $db->prepare("DELETE FROM car_hire_companies WHERE user_id = ?");
            $stmt->execute([$id]);
        }
        
        // 4. Delete user's favorites (saved_listings)
        try {
            $stmt = $db->prepare("DELETE FROM saved_listings WHERE user_id = ?");
            $stmt->execute([$id]);
        } catch (Exception $e) {
            error_log("Note: saved_listings table may not exist or already deleted: " . $e->getMessage());
        }
        
        // 5. Delete user's messages (both sent and received)
        try {
            $stmt = $db->prepare("DELETE FROM messages WHERE sender_id = ? OR recipient_id = ?");
            $stmt->execute([$id, $id]);
        } catch (Exception $e) {
            error_log("Note: messages table may not exist or already deleted: " . $e->getMessage());
        }
        
        // 6. Delete user's notifications
        try {
            $stmt = $db->prepare("DELETE FROM notifications WHERE user_id = ?");
            $stmt->execute([$id]);
        } catch (Exception $e) {
            error_log("Note: notifications table may not exist or already deleted: " . $e->getMessage());
        }
        
        // 7. Delete user's sessions
        try {
            $stmt = $db->prepare("DELETE FROM user_sessions WHERE user_id = ?");
            $stmt->execute([$id]);
        } catch (Exception $e) {
            error_log("Note: user_sessions table may not exist or already deleted: " . $e->getMessage());
        }
        
        // 8. Delete user's search history
        try {
            $stmt = $db->prepare("DELETE FROM search_history WHERE user_id = ?");
            $stmt->execute([$id]);
        } catch (Exception $e) {
            error_log("Note: search_history table may not exist or already deleted: " . $e->getMessage());
        }
        
        // 9. Delete user's saved searches
        try {
            $stmt = $db->prepare("DELETE FROM saved_searches WHERE user_id = ?");
            $stmt->execute([$id]);
        } catch (Exception $e) {
            error_log("Note: saved_searches table may not exist or already deleted: " . $e->getMessage());
        }
        
        // 10. FINALLY, delete the user account itself
        $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$id]);

        // Commit transaction
        $db->commit();

        // Log the activity
        logActivity($db, 'user_deleted', 'User permanently deleted', "User ID: $id, Type: {$user['user_type']}, CASCADE DELETE all related records", $_SESSION['admin_id'] ?? null);

        echo json_encode(['success' => true, 'message' => 'User and ALL associated records deleted permanently']);
        exit();
    } catch (Exception $e) {
        $db->rollBack();
        error_log("handleDeleteUser error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to delete user: ' . $e->getMessage()]);
        exit();
    }
}

// ===== GARAGE MANAGEMENT =====

function handleDeleteGarage($db) {
    requireAdmin();

    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? '';

    if (empty($id)) {
        echo json_encode(['success' => false, 'message' => 'Garage ID is required']);
        return;
    }

    try {
        // Start transaction
        $db->beginTransaction();

        // Get full garage details including user info and car listing count
        $stmt = $db->prepare("
            SELECT g.*, u.username, u.email, u.full_name,
                   (SELECT COUNT(*) FROM car_listings WHERE user_id = g.user_id AND status != 'deleted') as active_listings
            FROM garages g
            LEFT JOIN users u ON g.user_id = u.id
            WHERE g.id = ?
        ");
        $stmt->execute([$id]);
        $garage = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$garage) {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => 'Garage not found']);
            return;
        }

        // CASCADE DELETE: Delete all garage-related data
        // NOTE: garage_services, garage_specializations, and garage_reviews tables
        // don't exist in current schema - they will be handled by CASCADE when implemented

        // 1. Delete the garage itself
        $stmt = $db->prepare("DELETE FROM garages WHERE id = ?");
        $stmt->execute([$id]);

        $deletedListingsCount = 0;
        // 2. CASCADE DELETE the associated user account and ALL their data
        if ($garage['user_id']) {
            // Get all car listing IDs to delete images
            $stmt = $db->prepare("SELECT id FROM car_listings WHERE user_id = ?");
            $stmt->execute([$garage['user_id']]);
            $listingIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (!empty($listingIds)) {
                $placeholders = str_repeat('?,', count($listingIds) - 1) . '?';
                
                // Delete all car listing images (both from database and filesystem)
                $imgStmt = $db->prepare("SELECT filename FROM car_listing_images WHERE listing_id IN ($placeholders)");
                $imgStmt->execute($listingIds);
                $images = $imgStmt->fetchAll(PDO::FETCH_COLUMN);
                
                // Delete image files from filesystem
                foreach ($images as $image) {
                    if (!empty($image)) {
                        $imagePath = __DIR__ . '/../uploads/' . $image;
                        if (file_exists($imagePath)) {
                            @unlink($imagePath);
                        }
                    }
                }
                
                // Delete car listing images from database
                $stmt = $db->prepare("DELETE FROM car_listing_images WHERE listing_id IN ($placeholders)");
                $stmt->execute($listingIds);
            }
            
            // HARD DELETE car listings (complete removal)
            $stmt = $db->prepare("DELETE FROM car_listings WHERE user_id = ?");
            $stmt->execute([$garage['user_id']]);
            $deletedListingsCount = $stmt->rowCount();

            // Delete user's favorites (saved_listings)
            try {
                $stmt = $db->prepare("DELETE FROM saved_listings WHERE user_id = ?");
                $stmt->execute([$garage['user_id']]);
            } catch (Exception $e) {
                error_log("Note: saved_listings table may not exist or already deleted: " . $e->getMessage());
            }

            // Delete user's messages
            try {
                $stmt = $db->prepare("DELETE FROM messages WHERE sender_id = ? OR recipient_id = ?");
                $stmt->execute([$garage['user_id'], $garage['user_id']]);
            } catch (Exception $e) {
                error_log("Note: messages table may not exist or already deleted: " . $e->getMessage());
            }

            // Delete user's notifications
            try {
                $stmt = $db->prepare("DELETE FROM notifications WHERE user_id = ?");
                $stmt->execute([$garage['user_id']]);
            } catch (Exception $e) {
                error_log("Note: notifications table may not exist or already deleted: " . $e->getMessage());
            }

            // Delete user's sessions
            try {
                $stmt = $db->prepare("DELETE FROM user_sessions WHERE user_id = ?");
                $stmt->execute([$garage['user_id']]);
            } catch (Exception $e) {
                error_log("Note: user_sessions table may not exist or already deleted: " . $e->getMessage());
            }

            // Delete user's search history
            try {
                $stmt = $db->prepare("DELETE FROM search_history WHERE user_id = ?");
                $stmt->execute([$garage['user_id']]);
            } catch (Exception $e) {
                error_log("Note: search_history table may not exist or already deleted: " . $e->getMessage());
            }

            // Delete user's saved searches
            try {
                $stmt = $db->prepare("DELETE FROM saved_searches WHERE user_id = ?");
                $stmt->execute([$garage['user_id']]);
            } catch (Exception $e) {
                error_log("Note: saved_searches table may not exist or already deleted: " . $e->getMessage());
            }

            // Finally, delete the user account
            $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$garage['user_id']]);
        }

        $db->commit();

        // Comprehensive activity logging
        $activityDetails = sprintf(
            "Garage '%s' (ID: %d) permanently deleted\nUser: %s (ID: %d, Email: %s)\nCar Listings permanently removed: %d\nAll user data removed",
            $garage['name'],
            $id,
            $garage['full_name'] ?? 'N/A',
            $garage['user_id'] ?? 0,
            $garage['email'] ?? 'N/A',
            $deletedListingsCount
        );

        logActivity($db, 'garage_deleted', 'Garage permanently deleted with CASCADE', $activityDetails, $_SESSION['admin_id'] ?? null);

        echo json_encode([
            'success' => true,
            'message' => sprintf(
                'Garage "%s" and user account deleted. %d car listing(s) permanently removed.',
                $garage['name'],
                $deletedListingsCount
            ),
            'deleted_listings' => $deletedListingsCount
        ]);
        exit();
    } catch (Exception $e) {
        $db->rollBack();
        error_log("handleDeleteGarage error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to delete garage: ' . $e->getMessage()]);
        exit();
    }
}

// ===== DEALER MANAGEMENT =====

function handleDeleteDealer($db) {
    requireAdmin();

    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? '';

    if (empty($id)) {
        echo json_encode(['success' => false, 'message' => 'Dealer ID is required']);
        return;
    }

    try {
        // Start transaction
        $db->beginTransaction();

        // Get full dealer details including user info and car listing count
        $stmt = $db->prepare("
            SELECT d.*, u.username, u.email, u.full_name,
                   (SELECT COUNT(*) FROM car_listings WHERE user_id = d.user_id AND status != 'deleted') as active_listings
            FROM car_dealers d
            LEFT JOIN users u ON d.user_id = u.id
            WHERE d.id = ?
        ");
        $stmt->execute([$id]);
        $dealer = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$dealer) {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => 'Dealer not found']);
            return;
        }

        // CASCADE DELETE: Delete all dealer-related data
        // NOTE: dealer_inventory and dealer_reviews tables don't exist in current schema
        // They will be handled by CASCADE when implemented

        // 1. Delete the dealer itself
        $stmt = $db->prepare("DELETE FROM car_dealers WHERE id = ?");
        $stmt->execute([$id]);

        $deletedListingsCount = 0;
        // 2. CASCADE DELETE the associated user account and ALL their data
        if ($dealer['user_id']) {
            // Get all car listing IDs to delete images
            $stmt = $db->prepare("SELECT id FROM car_listings WHERE user_id = ?");
            $stmt->execute([$dealer['user_id']]);
            $listingIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (!empty($listingIds)) {
                $placeholders = str_repeat('?,', count($listingIds) - 1) . '?';
                
                // Delete all car listing images (both from database and filesystem)
                $imgStmt = $db->prepare("SELECT filename FROM car_listing_images WHERE listing_id IN ($placeholders)");
                $imgStmt->execute($listingIds);
                $images = $imgStmt->fetchAll(PDO::FETCH_COLUMN);
                
                // Delete image files from filesystem
                foreach ($images as $image) {
                    if (!empty($image)) {
                        $imagePath = __DIR__ . '/../uploads/' . $image;
                        if (file_exists($imagePath)) {
                            @unlink($imagePath);
                        }
                    }
                }
                
                // Delete car listing images from database
                $stmt = $db->prepare("DELETE FROM car_listing_images WHERE listing_id IN ($placeholders)");
                $stmt->execute($listingIds);
            }
            
            // HARD DELETE car listings (complete removal)
            $stmt = $db->prepare("DELETE FROM car_listings WHERE user_id = ?");
            $stmt->execute([$dealer['user_id']]);
            $deletedListingsCount = $stmt->rowCount();

            // Delete user's favorites (saved_listings)
            try {
                $stmt = $db->prepare("DELETE FROM saved_listings WHERE user_id = ?");
                $stmt->execute([$dealer['user_id']]);
            } catch (Exception $e) {
                error_log("Note: saved_listings table may not exist or already deleted: " . $e->getMessage());
            }

            // Delete user's messages
            try {
                $stmt = $db->prepare("DELETE FROM messages WHERE sender_id = ? OR recipient_id = ?");
                $stmt->execute([$dealer['user_id'], $dealer['user_id']]);
            } catch (Exception $e) {
                error_log("Note: messages table may not exist or already deleted: " . $e->getMessage());
            }

            // Delete user's notifications
            try {
                $stmt = $db->prepare("DELETE FROM notifications WHERE user_id = ?");
                $stmt->execute([$dealer['user_id']]);
            } catch (Exception $e) {
                error_log("Note: notifications table may not exist or already deleted: " . $e->getMessage());
            }

            // Delete user's sessions
            try {
                $stmt = $db->prepare("DELETE FROM user_sessions WHERE user_id = ?");
                $stmt->execute([$dealer['user_id']]);
            } catch (Exception $e) {
                error_log("Note: user_sessions table may not exist or already deleted: " . $e->getMessage());
            }

            // Delete user's search history
            try {
                $stmt = $db->prepare("DELETE FROM search_history WHERE user_id = ?");
                $stmt->execute([$dealer['user_id']]);
            } catch (Exception $e) {
                error_log("Note: search_history table may not exist or already deleted: " . $e->getMessage());
            }

            // Delete user's saved searches
            try {
                $stmt = $db->prepare("DELETE FROM saved_searches WHERE user_id = ?");
                $stmt->execute([$dealer['user_id']]);
            } catch (Exception $e) {
                error_log("Note: saved_searches table may not exist or already deleted: " . $e->getMessage());
            }

            // Finally, delete the user account
            $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$dealer['user_id']]);
        }

        $db->commit();

        // Comprehensive activity logging
        $activityDetails = sprintf(
            "Dealer '%s' (ID: %d) permanently deleted\nUser: %s (ID: %d, Email: %s)\nCar Listings permanently removed: %d\nAll user data removed",
            $dealer['business_name'],
            $id,
            $dealer['full_name'] ?? 'N/A',
            $dealer['user_id'] ?? 0,
            $dealer['email'] ?? 'N/A',
            $deletedListingsCount
        );

        logActivity($db, 'dealer_deleted', 'Dealer permanently deleted with CASCADE', $activityDetails, $_SESSION['admin_id'] ?? null);

        echo json_encode([
            'success' => true,
            'message' => sprintf(
                'Dealer "%s" and user account deleted. %d car listing(s) permanently removed.',
                $dealer['business_name'],
                $deletedListingsCount
            ),
            'deleted_listings' => $deletedListingsCount
        ]);
        exit();
    } catch (Exception $e) {
        $db->rollBack();
        error_log("handleDeleteDealer error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to delete dealer: ' . $e->getMessage()]);
        exit();
    }
}

// ===== CAR HIRE MANAGEMENT =====

function handleDeleteCarHire($db) {
    error_log("=== DELETE CAR HIRE CALLED ===");
    requireAdmin();
    error_log("Admin check passed");

    $input = json_decode(file_get_contents('php://input'), true);
    error_log("Input received: " . json_encode($input));
    $id = $input['id'] ?? '';

    if (empty($id)) {
        error_log("ERROR: No ID provided");
        echo json_encode(['success' => false, 'message' => 'Car Hire ID is required']);
        return;
    }

    error_log("Attempting to delete car hire ID: " . $id);

    try {
        // Start transaction
        $db->beginTransaction();
        error_log("Transaction started");

        // Get full car hire company details including user info and car listing count
        $stmt = $db->prepare("
            SELECT c.*, u.username, u.email, u.full_name,
                   (SELECT COUNT(*) FROM car_listings WHERE user_id = c.user_id AND status != 'deleted') as active_listings,
                   (SELECT COUNT(*) FROM car_hire_fleet WHERE company_id = c.id) as fleet_count
            FROM car_hire_companies c
            LEFT JOIN users u ON c.user_id = u.id
            WHERE c.id = ?
        ");
        $stmt->execute([$id]);
        $carHire = $stmt->fetch(PDO::FETCH_ASSOC);
        error_log("Car hire found: " . json_encode($carHire));

        if (!$carHire) {
            error_log("ERROR: Car hire not found with ID: " . $id);
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => 'Car hire company not found']);
            return;
        }

        // CASCADE DELETE: Delete all car hire-related data

        // 1. DELETE ALL FLEET VEHICLES and their images for this company
        // First get all fleet vehicles to delete their images
        error_log("Step 1: Getting fleet vehicles");
        $stmt = $db->prepare("SELECT id, image FROM car_hire_fleet WHERE company_id = ?");
        $stmt->execute([$id]);
        $fleetVehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("Found " . count($fleetVehicles) . " fleet vehicles");

        // Delete image files for each fleet vehicle
        foreach ($fleetVehicles as $vehicle) {
            if (!empty($vehicle['image'])) {
                $imagePath = '../uploads/' . $vehicle['image'];
                if (file_exists($imagePath)) {
                    @unlink($imagePath);
                    error_log("Deleted image: " . $imagePath);
                }
            }
        }

        // Delete fleet vehicles from database
        error_log("Step 2: Deleting fleet vehicles from database");
        $stmt = $db->prepare("DELETE FROM car_hire_fleet WHERE company_id = ?");
        $stmt->execute([$id]);
        error_log("Deleted " . $stmt->rowCount() . " fleet vehicles");

        // NOTE: car_hire_bookings and car_hire_reviews tables don't exist in current schema
        // They will be handled by CASCADE when implemented

        // 3. Delete the car hire company itself
        error_log("Step 3: Deleting car hire company");
        $stmt = $db->prepare("DELETE FROM car_hire_companies WHERE id = ?");
        $stmt->execute([$id]);
        error_log("Deleted car hire company, rows affected: " . $stmt->rowCount());

        $deletedListingsCount = 0;
        // 4. CASCADE DELETE the associated user account and ALL their data
        if ($carHire['user_id']) {
            error_log("Step 4: Deleting user data for user_id: " . $carHire['user_id']);

            // Get all car listing IDs to delete images
            $stmt = $db->prepare("SELECT id FROM car_listings WHERE user_id = ?");
            $stmt->execute([$carHire['user_id']]);
            $listingIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (!empty($listingIds)) {
                $placeholders = str_repeat('?,', count($listingIds) - 1) . '?';
                
                // Delete all car listing images (both from database and filesystem)
                $imgStmt = $db->prepare("SELECT filename FROM car_listing_images WHERE listing_id IN ($placeholders)");
                $imgStmt->execute($listingIds);
                $images = $imgStmt->fetchAll(PDO::FETCH_COLUMN);
                
                // Delete image files from filesystem
                foreach ($images as $image) {
                    if (!empty($image)) {
                        $imagePath = __DIR__ . '/../uploads/' . $image;
                        if (file_exists($imagePath)) {
                            @unlink($imagePath);
                            error_log("Deleted car listing image: " . $imagePath);
                        }
                    }
                }
                
                // Delete car listing images from database
                $stmt = $db->prepare("DELETE FROM car_listing_images WHERE listing_id IN ($placeholders)");
                $stmt->execute($listingIds);
                error_log("Deleted " . $stmt->rowCount() . " car listing images from database");
            }
            
            // HARD DELETE car listings (complete removal)
            $stmt = $db->prepare("DELETE FROM car_listings WHERE user_id = ?");
            $stmt->execute([$carHire['user_id']]);
            $deletedListingsCount = $stmt->rowCount();
            error_log("Hard-deleted " . $deletedListingsCount . " car listings");

            // Delete user's favorites (saved_listings)
            try {
                $stmt = $db->prepare("DELETE FROM saved_listings WHERE user_id = ?");
                $stmt->execute([$carHire['user_id']]);
                error_log("Deleted " . $stmt->rowCount() . " saved listings");
            } catch (Exception $e) {
                error_log("Note: saved_listings table may not exist or already deleted: " . $e->getMessage());
            }

            // Delete user's messages
            try {
                $stmt = $db->prepare("DELETE FROM messages WHERE sender_id = ? OR recipient_id = ?");
                $stmt->execute([$carHire['user_id'], $carHire['user_id']]);
                error_log("Deleted " . $stmt->rowCount() . " messages");
            } catch (Exception $e) {
                error_log("Note: messages table may not exist or already deleted: " . $e->getMessage());
            }

            // Delete user's notifications
            try {
                $stmt = $db->prepare("DELETE FROM notifications WHERE user_id = ?");
                $stmt->execute([$carHire['user_id']]);
                error_log("Deleted " . $stmt->rowCount() . " notifications");
            } catch (Exception $e) {
                error_log("Note: notifications table may not exist or already deleted: " . $e->getMessage());
            }

            // Delete user's sessions
            try {
                $stmt = $db->prepare("DELETE FROM user_sessions WHERE user_id = ?");
                $stmt->execute([$carHire['user_id']]);
                error_log("Deleted " . $stmt->rowCount() . " sessions");
            } catch (Exception $e) {
                error_log("Note: user_sessions table may not exist or already deleted: " . $e->getMessage());
            }

            // Delete user's search history
            try {
                $stmt = $db->prepare("DELETE FROM search_history WHERE user_id = ?");
                $stmt->execute([$carHire['user_id']]);
                error_log("Deleted " . $stmt->rowCount() . " search history items");
            } catch (Exception $e) {
                error_log("Note: search_history table may not exist or already deleted: " . $e->getMessage());
            }

            // Delete user's saved searches
            try {
                $stmt = $db->prepare("DELETE FROM saved_searches WHERE user_id = ?");
                $stmt->execute([$carHire['user_id']]);
                error_log("Deleted " . $stmt->rowCount() . " saved searches");
            } catch (Exception $e) {
                error_log("Note: saved_searches table may not exist or already deleted: " . $e->getMessage());
            }

            // Finally, delete the user account
            error_log("Step 5: Deleting user account");
            $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$carHire['user_id']]);
            error_log("Deleted user account, rows affected: " . $stmt->rowCount());
        } else {
            error_log("No user_id associated with car hire");
        }

        error_log("Step 6: Committing transaction");
        $db->commit();
        error_log("Transaction committed successfully");

        // Comprehensive activity logging
        $activityDetails = sprintf(
            "Car Hire '%s' (ID: %d) permanently deleted\nUser: %s (ID: %d, Email: %s)\nCar Listings permanently removed: %d\nFleet vehicles removed: %d\nAll user data removed",
            $carHire['business_name'],
            $id,
            $carHire['full_name'] ?? 'N/A',
            $carHire['user_id'] ?? 0,
            $carHire['email'] ?? 'N/A',
            $deletedListingsCount,
            $carHire['fleet_count'] ?? 0
        );

        logActivity($db, 'car_hire_deleted', 'Car hire company permanently deleted with CASCADE', $activityDetails, $_SESSION['admin_id'] ?? null);
        error_log("Activity logged successfully");

        $successResponse = [
            'success' => true,
            'message' => sprintf(
                'Car hire "%s" and user account deleted. %d car listing(s) permanently removed, %d fleet vehicle(s) removed.',
                $carHire['business_name'],
                $deletedListingsCount,
                $carHire['fleet_count'] ?? 0
            ),
            'deleted_listings' => $deletedListingsCount,
            'deleted_fleet' => $carHire['fleet_count'] ?? 0
        ];
        error_log("Success response: " . json_encode($successResponse));
        echo json_encode($successResponse);
        error_log("=== DELETE CAR HIRE COMPLETED SUCCESSFULLY ===");
        exit();
    } catch (Exception $e) {
        $db->rollBack();
        error_log("=== DELETE CAR HIRE ERROR ===");
        error_log("Error message: " . $e->getMessage());
        error_log("Error trace: " . $e->getTraceAsString());
        echo json_encode(['success' => false, 'message' => 'Failed to delete car hire company: ' . $e->getMessage()]);
        exit();
    }
}

// ===== DELETED LISTINGS =====

function getDeletedCars($db) {
    requireAdmin();

    try {
        $sql = "SELECT
            cl.*,
            cm.name as make_name,
            cmo.name as model_name,
            loc.name as location_name,
            u.full_name as owner_name,
            u.email as owner_email,
            u.phone as owner_phone,
            u.user_type,
            (SELECT filename FROM car_listing_images WHERE listing_id = cl.id AND is_primary = 1 LIMIT 1) as primary_image
        FROM car_listings cl
        LEFT JOIN car_makes cm ON cl.make_id = cm.id
        LEFT JOIN car_models cmo ON cl.model_id = cmo.id
        LEFT JOIN locations loc ON cl.location_id = loc.id
        LEFT JOIN users u ON cl.user_id = u.id
        WHERE cl.status = 'deleted'
        ORDER BY cl.updated_at DESC
        LIMIT 100";

        $stmt = $db->query($sql);
        $deletedCars = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'cars' => $deletedCars]);
        exit();
    } catch (Exception $e) {
        error_log("getDeletedCars error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to load deleted cars']);
        exit();
    }
}

// ===== ADD/CREATE FUNCTIONS =====

function handleAddCar($db) {
    requireAdmin();

    $input = json_decode(file_get_contents('php://input'), true);

    try {
        // Generate a unique reference number
        $year = date('Y');
        $stmt = $db->query("SELECT COUNT(*) as count FROM car_listings WHERE reference_number LIKE 'ML{$year}%'");
        $result = $stmt->fetch();
        $count = $result['count'] + 1;
        $referenceNumber = 'ML' . $year . str_pad($count, 3, '0', STR_PAD_LEFT);

        $sql = "INSERT INTO car_listings (
            user_id, reference_number, title, make_id, model_id, year, price, negotiable,
            mileage, location_id, fuel_type, transmission, condition_type, exterior_color,
            interior_color, engine_size, doors, seats, description, listing_type,
            status, approval_status, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            $input['user_id'] ?? null,
            $referenceNumber,
            $input['title'],
            $input['make_id'],
            $input['model_id'],
            $input['year'],
            $input['price'],
            $input['negotiable'] ?? 0,
            $input['mileage'] ?? null,
            $input['location_id'],
            $input['fuel_type'],
            $input['transmission'],
            $input['condition_type'],
            $input['exterior_color'] ?? null,
            $input['interior_color'] ?? null,
            $input['engine_size'] ?? null,
            $input['doors'] ?? 4,
            $input['seats'] ?? 5,
            $input['description'] ?? null,
            $input['listing_type'] ?? 'free',
            $input['status'] ?? 'active',
            $input['approval_status'] ?? 'approved'
        ]);

        $carId = $db->lastInsertId();
        echo json_encode(['success' => true, 'message' => 'Car listing added successfully', 'car_id' => $carId]);
        exit();
    } catch (Exception $e) {
        error_log("handleAddCar error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to add car: ' . $e->getMessage()]);
        exit();
    }
}

function handleAddGarage($db) {
    requireAdmin();

    $input = json_decode(file_get_contents('php://input'), true);

    try {
        $sql = "INSERT INTO garages (
            name, owner_name, email, phone, recovery_number, whatsapp, address, location_id,
            services, emergency_services, specialization, years_experience,
            business_hours, website, description, status, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            $input['name'],
            $input['owner_name'],
            $input['email'],
            $input['phone'],
            $input['recovery_number'] ?? null,
            $input['whatsapp'] ?? null,
            $input['address'] ?? null,
            $input['location_id'],
            isset($input['services']) ? json_encode($input['services']) : null,
            isset($input['emergency_services']) ? json_encode($input['emergency_services']) : null,
            isset($input['specialization']) ? json_encode($input['specialization']) : null,
            $input['years_experience'] ?? 0,
            $input['business_hours'] ?? null,
            $input['website'] ?? null,
            $input['description'] ?? null,
            $input['status'] ?? 'active'
        ]);

        $garageId = $db->lastInsertId();
        echo json_encode(['success' => true, 'message' => 'Garage added successfully', 'garage_id' => $garageId]);
        exit();
    } catch (Exception $e) {
        error_log("handleAddGarage error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to add garage: ' . $e->getMessage()]);
        exit();
    }
}

function handleAddDealer($db) {
    requireAdmin();

    $input = json_decode(file_get_contents('php://input'), true);

    try {
        $sql = "INSERT INTO car_dealers (
            business_name, owner_name, email, phone, whatsapp, address, location_id,
            specialization, years_established, business_hours, website,
            description, verified, status, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            $input['business_name'],
            $input['owner_name'],
            $input['email'],
            $input['phone'],
            $input['whatsapp'] ?? null,
            $input['address'] ?? null,
            $input['location_id'],
            isset($input['specialization']) ? json_encode($input['specialization']) : null,
            $input['years_established'] ?? null,
            $input['business_hours'] ?? null,
            $input['website'] ?? null,
            $input['description'] ?? null,
            $input['verified'] ?? 0,
            $input['status'] ?? 'active'
        ]);

        $dealerId = $db->lastInsertId();
        echo json_encode(['success' => true, 'message' => 'Dealer added successfully', 'dealer_id' => $dealerId]);
        exit();
    } catch (Exception $e) {
        error_log("handleAddDealer error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to add dealer: ' . $e->getMessage()]);
        exit();
    }
}

function handleAddCarHire($db) {
    requireAdmin();

    $input = json_decode(file_get_contents('php://input'), true);

    try {
        $sql = "INSERT INTO car_hire_companies (
            business_name, owner_name, email, phone, whatsapp, address, location_id,
            vehicle_types, services, daily_rate_from, weekly_rate_from, monthly_rate_from,
            currency, years_established, business_hours, website, description,
            hire_category, event_types,
            verified, status, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

        $hireCategory = $input['hire_category'] ?? 'standard';
        if (!in_array($hireCategory, ['standard', 'events', 'vans_trucks', 'all'])) {
            $hireCategory = 'standard';
        }
        $eventTypes = null;
        if (isset($input['event_types']) && is_array($input['event_types'])) {
            $eventTypes = json_encode($input['event_types']);
        }

        $stmt = $db->prepare($sql);
        $stmt->execute([
            $input['business_name'],
            $input['owner_name'],
            $input['email'],
            $input['phone'],
            $input['whatsapp'] ?? null,
            $input['address'] ?? null,
            $input['location_id'],
            isset($input['vehicle_types']) ? json_encode($input['vehicle_types']) : null,
            isset($input['services']) ? json_encode($input['services']) : null,
            $input['daily_rate_from'] ?? null,
            $input['weekly_rate_from'] ?? null,
            $input['monthly_rate_from'] ?? null,
            $input['currency'] ?? (motorlink_get_site_runtime_config($db)['currency_code'] ?? 'MWK'),
            $input['years_established'] ?? null,
            $input['business_hours'] ?? null,
            $input['website'] ?? null,
            $input['description'] ?? null,
            $hireCategory,
            $eventTypes,
            $input['verified'] ?? 0,
            $input['status'] ?? 'active'
        ]);

        $carHireId = $db->lastInsertId();
        echo json_encode(['success' => true, 'message' => 'Car hire company added successfully', 'car_hire_id' => $carHireId]);
        exit();
    } catch (Exception $e) {
        error_log("handleAddCarHire error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to add car hire: ' . $e->getMessage()]);
        exit();
    }
}

function handleAddUser($db) {
    requireAdmin();

    $input = json_decode(file_get_contents('php://input'), true);

    try {
        // Check if email already exists
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$input['email']]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Email already exists']);
            return;
        }

        // Hash password
        $passwordHash = password_hash($input['password'], PASSWORD_DEFAULT);

        $sql = "INSERT INTO users (
            full_name, email, password_hash, phone, whatsapp, city, address,
            user_type, status, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            $input['full_name'],
            $input['email'],
            $passwordHash,
            $input['phone'] ?? null,
            $input['whatsapp'] ?? null,
            $input['city'] ?? null,
            $input['address'] ?? null,
            $input['user_type'] ?? 'buyer',
            $input['status'] ?? 'active'
        ]);

        $userId = $db->lastInsertId();
        echo json_encode(['success' => true, 'message' => 'User added successfully', 'user_id' => $userId]);
        exit();
    } catch (Exception $e) {
        error_log("handleAddUser error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to add user: ' . $e->getMessage()]);
        exit();
    }
}

function handleAddAdmin($db) {
    requireSuperAdmin($db);

    $input = json_decode(file_get_contents('php://input'), true);
    $allowedRoles = ['moderator', 'onboarding_manager', 'admin', 'super_admin'];
    $requestedRole = trim((string)($input['role'] ?? 'moderator'));

    if (!in_array($requestedRole, $allowedRoles, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid admin role']);
        return;
    }

    try {
        // Check if email already exists
        $stmt = $db->prepare("SELECT id FROM admin_users WHERE email = ?");
        $stmt->execute([$input['email']]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Email already exists']);
            return;
        }

        // Hash password
        $passwordHash = password_hash($input['password'], PASSWORD_DEFAULT);

        $sql = "INSERT INTO admin_users (
            username, email, password_hash, full_name, role, status, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            $input['username'],
            $input['email'],
            $passwordHash,
            $input['full_name'],
            $requestedRole,
            $input['status'] ?? 'active'
        ]);

        $adminId = $db->lastInsertId();
        echo json_encode(['success' => true, 'message' => 'Admin added successfully', 'admin_id' => $adminId]);
        exit();
    } catch (Exception $e) {
        error_log("handleAddAdmin error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to add admin: ' . $e->getMessage()]);
        exit();
    }
}

function handleAddMake($db) {
    requireAdmin();

    $input = json_decode(file_get_contents('php://input'), true);

    try {
        $sql = "INSERT INTO car_makes (name, country, is_active, sort_order, created_at, updated_at)
                VALUES (?, ?, ?, ?, NOW(), NOW())";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            $input['name'],
            $input['country'] ?? null,
            $input['is_active'] ?? 1,
            $input['sort_order'] ?? 0
        ]);

        $makeId = $db->lastInsertId();
        echo json_encode(['success' => true, 'message' => 'Make added successfully', 'make_id' => $makeId]);
        exit();
    } catch (Exception $e) {
        error_log("handleAddMake error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to add make: ' . $e->getMessage()]);
        exit();
    }
}

function handleAddModel($db) {
    requireAdmin();

    $input = json_decode(file_get_contents('php://input'), true);

    try {
        $sql = "INSERT INTO car_models (
            make_id, name, body_type, is_active,
            year_start, year_end, fuel_tank_capacity_liters, engine_size_liters, engine_cylinders,
            fuel_consumption_urban_l100km, fuel_consumption_highway_l100km, fuel_consumption_combined_l100km,
            fuel_type, transmission_type, horsepower_hp, torque_nm, seating_capacity, doors,
            weight_kg, drive_type, co2_emissions_gkm, length_mm, width_mm, height_mm, wheelbase_mm,
            created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            $input['make_id'],
            $input['name'],
            $input['body_type'] ?? null,
            $input['is_active'] ?? 1,
            !empty($input['year_start']) ? (int)$input['year_start'] : null,
            !empty($input['year_end']) ? (int)$input['year_end'] : null,
            !empty($input['fuel_tank_capacity_liters']) ? (float)$input['fuel_tank_capacity_liters'] : null,
            !empty($input['engine_size_liters']) ? (float)$input['engine_size_liters'] : null,
            !empty($input['engine_cylinders']) ? (int)$input['engine_cylinders'] : null,
            !empty($input['fuel_consumption_urban_l100km']) ? (float)$input['fuel_consumption_urban_l100km'] : null,
            !empty($input['fuel_consumption_highway_l100km']) ? (float)$input['fuel_consumption_highway_l100km'] : null,
            !empty($input['fuel_consumption_combined_l100km']) ? (float)$input['fuel_consumption_combined_l100km'] : null,
            $input['fuel_type'] ?? null,
            $input['transmission_type'] ?? null,
            !empty($input['horsepower_hp']) ? (int)$input['horsepower_hp'] : null,
            !empty($input['torque_nm']) ? (int)$input['torque_nm'] : null,
            !empty($input['seating_capacity']) ? (int)$input['seating_capacity'] : null,
            !empty($input['doors']) ? (int)$input['doors'] : null,
            !empty($input['weight_kg']) ? (int)$input['weight_kg'] : null,
            $input['drive_type'] ?? null,
            !empty($input['co2_emissions_gkm']) ? (int)$input['co2_emissions_gkm'] : null,
            !empty($input['length_mm']) ? (int)$input['length_mm'] : null,
            !empty($input['width_mm']) ? (int)$input['width_mm'] : null,
            !empty($input['height_mm']) ? (int)$input['height_mm'] : null,
            !empty($input['wheelbase_mm']) ? (int)$input['wheelbase_mm'] : null
        ]);

        $modelId = $db->lastInsertId();
        echo json_encode(['success' => true, 'message' => 'Model added successfully', 'model_id' => $modelId]);
        exit();
    } catch (Exception $e) {
        error_log("handleAddModel error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to add model: ' . $e->getMessage()]);
        exit();
    }
}

function handleAddLocation($db) {
    requireAdmin();

    $input = json_decode(file_get_contents('php://input'), true);

    try {
        $sql = "INSERT INTO locations (name, region, district, type, created_at)
                VALUES (?, ?, ?, ?, NOW())";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            $input['name'],
            $input['region'] ?? null,
            $input['district'] ?? null,
            $input['type'] ?? 'city'
        ]);

        $locationId = $db->lastInsertId();
        echo json_encode(['success' => true, 'message' => 'Location added successfully', 'location_id' => $locationId]);
        exit();
    } catch (Exception $e) {
        error_log("handleAddLocation error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to add location: ' . $e->getMessage()]);
        exit();
    }
}

// ===== CAR FEATURE/PREMIUM MANAGEMENT =====

/**
 * Toggle featured status for a car listing
 */
function handleFeatureCar($db) {
    requireAdmin();

    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? '';
    $isFeatured = $input['is_featured'] ?? 1;

    if (empty($id)) {
        echo json_encode(['success' => false, 'message' => 'Car ID is required']);
        return;
    }

    try {
        // Ensure the column exists (add if it doesn't)
        try {
            $db->exec("ALTER TABLE car_listings ADD COLUMN is_featured TINYINT(1) DEFAULT 0");
        } catch (Exception $e) {
            // Column already exists, ignore
        }

        $stmt = $db->prepare("UPDATE car_listings SET is_featured = ?, updated_at = NOW() WHERE id = ?");
        $result = $stmt->execute([$isFeatured, $id]);

        if ($result) {
            $status = $isFeatured ? 'featured' : 'unfeatured';
            // Log the activity
            logActivity($db, 'car_featured', "Car $status", "Car ID: $id", $_SESSION['admin_id'] ?? null);

            echo json_encode(['success' => true, 'message' => "Car has been $status successfully"]);
            exit();
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update car feature status']);
            exit();
        }

    } catch (Exception $e) {
        error_log("handleFeatureCar error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to feature car: ' . $e->getMessage()]);
        exit();
    }
}

/**
 * Toggle premium status for a car listing
 */
function handlePremiumCar($db) {
    requireAdmin();

    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? '';
    $isPremium = $input['is_premium'] ?? 1;

    if (empty($id)) {
        echo json_encode(['success' => false, 'message' => 'Car ID is required']);
        return;
    }

    try {
        // Ensure the column exists (add if it doesn't)
        try {
            $db->exec("ALTER TABLE car_listings ADD COLUMN is_premium TINYINT(1) DEFAULT 0");
        } catch (Exception $e) {
            // Column already exists, ignore
        }

        try {
            $db->exec("ALTER TABLE car_listings ADD COLUMN premium_until DATETIME DEFAULT NULL");
        } catch (Exception $e) {
            // Column already exists, ignore
        }

        // If setting to premium, set expiry to 30 days from now
        $premiumUntil = $isPremium ? date('Y-m-d H:i:s', strtotime('+30 days')) : null;

        $stmt = $db->prepare("UPDATE car_listings SET is_premium = ?, premium_until = ?, updated_at = NOW() WHERE id = ?");
        $result = $stmt->execute([$isPremium, $premiumUntil, $id]);

        if ($result) {
            $status = $isPremium ? 'set to premium' : 'removed from premium';
            // Log the activity
            logActivity($db, 'car_premium', "Car $status", "Car ID: $id, Until: $premiumUntil", $_SESSION['admin_id'] ?? null);

            echo json_encode(['success' => true, 'message' => "Car has been $status successfully"]);
            exit();
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update car premium status']);
            exit();
        }

    } catch (Exception $e) {
        error_log("handlePremiumCar error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to set car as premium: ' . $e->getMessage()]);
        exit();
    }
}

/**
 * Update existing car make
 */
function handleUpdateMake($db) {
    requireAdmin();

    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['id'])) {
        echo json_encode(['success' => false, 'message' => 'Make ID is required']);
        return;
    }

    try {
        $sql = "UPDATE car_makes
                SET name = ?, country = ?, is_active = ?, updated_at = NOW()
                WHERE id = ?";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            $input['name'],
            $input['country'] ?? null,
            $input['is_active'] ?? 1,
            $input['id']
        ]);

        echo json_encode(['success' => true, 'message' => 'Make updated successfully']);
        exit();
    } catch (Exception $e) {
        error_log("handleUpdateMake error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to update make: ' . $e->getMessage()]);
        exit();
    }
}

/**
 * Update existing car model
 */
function handleUpdateModel($db) {
    requireAdmin();

    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['id'])) {
        echo json_encode(['success' => false, 'message' => 'Model ID is required']);
        return;
    }

    try {
        $sql = "UPDATE car_models SET
                make_id = ?, name = ?, body_type = ?, is_active = ?,
                year_start = ?, year_end = ?, fuel_tank_capacity_liters = ?, engine_size_liters = ?, engine_cylinders = ?,
                fuel_consumption_urban_l100km = ?, fuel_consumption_highway_l100km = ?, fuel_consumption_combined_l100km = ?,
                fuel_type = ?, transmission_type = ?, horsepower_hp = ?, torque_nm = ?, seating_capacity = ?, doors = ?,
                weight_kg = ?, drive_type = ?, co2_emissions_gkm = ?, length_mm = ?, width_mm = ?, height_mm = ?, wheelbase_mm = ?,
                updated_at = NOW()
                WHERE id = ?";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            $input['make_id'],
            $input['name'],
            $input['body_type'] ?? null,
            $input['is_active'] ?? 1,
            !empty($input['year_start']) ? (int)$input['year_start'] : null,
            !empty($input['year_end']) ? (int)$input['year_end'] : null,
            !empty($input['fuel_tank_capacity_liters']) ? (float)$input['fuel_tank_capacity_liters'] : null,
            !empty($input['engine_size_liters']) ? (float)$input['engine_size_liters'] : null,
            !empty($input['engine_cylinders']) ? (int)$input['engine_cylinders'] : null,
            !empty($input['fuel_consumption_urban_l100km']) ? (float)$input['fuel_consumption_urban_l100km'] : null,
            !empty($input['fuel_consumption_highway_l100km']) ? (float)$input['fuel_consumption_highway_l100km'] : null,
            !empty($input['fuel_consumption_combined_l100km']) ? (float)$input['fuel_consumption_combined_l100km'] : null,
            $input['fuel_type'] ?? null,
            $input['transmission_type'] ?? null,
            !empty($input['horsepower_hp']) ? (int)$input['horsepower_hp'] : null,
            !empty($input['torque_nm']) ? (int)$input['torque_nm'] : null,
            !empty($input['seating_capacity']) ? (int)$input['seating_capacity'] : null,
            !empty($input['doors']) ? (int)$input['doors'] : null,
            !empty($input['weight_kg']) ? (int)$input['weight_kg'] : null,
            $input['drive_type'] ?? null,
            !empty($input['co2_emissions_gkm']) ? (int)$input['co2_emissions_gkm'] : null,
            !empty($input['length_mm']) ? (int)$input['length_mm'] : null,
            !empty($input['width_mm']) ? (int)$input['width_mm'] : null,
            !empty($input['height_mm']) ? (int)$input['height_mm'] : null,
            !empty($input['wheelbase_mm']) ? (int)$input['wheelbase_mm'] : null,
            $input['id']
        ]);

        echo json_encode(['success' => true, 'message' => 'Model updated successfully']);
        exit();
    } catch (Exception $e) {
        error_log("handleUpdateModel error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to update model: ' . $e->getMessage()]);
        exit();
    }
}

// ===== SETTINGS MANAGEMENT =====

/**
 * Ensure settings table exists
 */
function ensureSettingsTable($db) {
    try {
        $sql = "CREATE TABLE IF NOT EXISTS settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) UNIQUE NOT NULL,
            setting_value TEXT,
            setting_type VARCHAR(50) DEFAULT 'string',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            updated_by INT,
            INDEX idx_key (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $db->exec($sql);
    } catch (Exception $e) {
        error_log("Error creating settings table: " . $e->getMessage());
    }
}

function getGeneralRuntimeSettingsForAdmin($db) {
    $config = motorlink_get_site_runtime_config($db, [
        'include_private' => true,
        'runtime_base_url' => motorlink_get_runtime_origin_fallback()
    ]);

    return [
        'general_siteName' => $config['site_name'] ?? '',
        'general_siteShortName' => $config['site_short_name'] ?? '',
        'general_siteTagline' => $config['site_tagline'] ?? '',
        'general_siteDescription' => $config['site_description'] ?? '',
        'general_siteUrl' => $config['site_url'] ?? '',
        'general_countryName' => $config['country_name'] ?? '',
        'general_countryCode' => $config['country_code'] ?? '',
        'general_countryDemonym' => $config['country_demonym'] ?? '',
        'general_locale' => $config['locale'] ?? '',
        'general_currencyCode' => $config['currency_code'] ?? '',
        'general_currencySymbol' => $config['currency_symbol'] ?? '',
        'general_marketScopeLabel' => $config['market_scope_label'] ?? '',
        'general_adminEmail' => $config['admin_contact_email'] ?? '',
        'general_supportEmail' => $config['contact_support_email'] ?? ''
    ];
}

function buildGeneralRuntimeSiteSettingsPayload(array $settings) {
    $defaults = motorlink_get_default_site_runtime_config(motorlink_get_runtime_origin_fallback());
    $siteName = trim((string)($settings['siteName'] ?? $defaults['site_name']));
    $supportEmail = trim((string)($settings['supportEmail'] ?? $defaults['contact_support_email']));

    return [
        'site_name' => [
            'value' => $siteName !== '' ? $siteName : $defaults['site_name'],
            'group' => 'general',
            'type' => 'string',
            'description' => 'Public site name',
            'is_public' => 1
        ],
        'site_short_name' => [
            'value' => trim((string)($settings['siteShortName'] ?? $defaults['site_short_name'])) ?: $defaults['site_short_name'],
            'group' => 'general',
            'type' => 'string',
            'description' => 'Short brand name used in compact UI',
            'is_public' => 1
        ],
        'site_tagline' => [
            'value' => trim((string)($settings['siteTagline'] ?? $defaults['site_tagline'])) ?: $defaults['site_tagline'],
            'group' => 'general',
            'type' => 'string',
            'description' => 'Public site tagline',
            'is_public' => 1
        ],
        'site_description' => [
            'value' => trim((string)($settings['siteDescription'] ?? $defaults['site_description'])) ?: $defaults['site_description'],
            'group' => 'general',
            'type' => 'string',
            'description' => 'Public site description',
            'is_public' => 1
        ],
        'site_url' => [
            'value' => trim((string)($settings['siteUrl'] ?? $defaults['site_url'])) ?: $defaults['site_url'],
            'group' => 'general',
            'type' => 'url',
            'description' => 'Primary public site URL',
            'is_public' => 1
        ],
        'country_name' => [
            'value' => trim((string)($settings['countryName'] ?? $defaults['country_name'])) ?: $defaults['country_name'],
            'group' => 'general',
            'type' => 'string',
            'description' => 'Country or market name',
            'is_public' => 1
        ],
        'country_code' => [
            'value' => strtoupper(trim((string)($settings['countryCode'] ?? $defaults['country_code']))) ?: $defaults['country_code'],
            'group' => 'general',
            'type' => 'string',
            'description' => 'ISO country code',
            'is_public' => 1
        ],
        'country_demonym' => [
            'value' => trim((string)($settings['countryDemonym'] ?? $defaults['country_demonym'])) ?: $defaults['country_demonym'],
            'group' => 'general',
            'type' => 'string',
            'description' => 'Country demonym for marketing copy',
            'is_public' => 1
        ],
        'locale' => [
            'value' => trim((string)($settings['locale'] ?? $defaults['locale'])) ?: $defaults['locale'],
            'group' => 'general',
            'type' => 'string',
            'description' => 'Primary locale',
            'is_public' => 1
        ],
        'currency_code' => [
            'value' => strtoupper(trim((string)($settings['currencyCode'] ?? $defaults['currency_code']))) ?: $defaults['currency_code'],
            'group' => 'general',
            'type' => 'string',
            'description' => 'Primary currency code',
            'is_public' => 1
        ],
        'currency_symbol' => [
            'value' => trim((string)($settings['currencySymbol'] ?? $defaults['currency_symbol'])) ?: $defaults['currency_symbol'],
            'group' => 'general',
            'type' => 'string',
            'description' => 'Primary currency label or symbol',
            'is_public' => 1
        ],
        'market_scope_label' => [
            'value' => trim((string)($settings['marketScopeLabel'] ?? $defaults['market_scope_label'])) ?: $defaults['market_scope_label'],
            'group' => 'general',
            'type' => 'string',
            'description' => 'Coverage label used in marketing copy',
            'is_public' => 1
        ],
        'contact_support_email' => [
            'value' => $supportEmail !== '' ? $supportEmail : $defaults['contact_support_email'],
            'group' => 'contact',
            'type' => 'string',
            'description' => 'Public support email',
            'is_public' => 1
        ],
        'contact_email' => [
            'value' => $supportEmail !== '' ? $supportEmail : $defaults['contact_email'],
            'group' => 'contact',
            'type' => 'string',
            'description' => 'Primary public contact email',
            'is_public' => 1
        ],
        'admin_contact_email' => [
            'value' => trim((string)($settings['adminEmail'] ?? $defaults['admin_contact_email'])) ?: $defaults['admin_contact_email'],
            'group' => 'general',
            'type' => 'string',
            'description' => 'Private admin contact email',
            'is_public' => 0
        ]
    ];
}

/**
 * Save settings to database
 */
function handleSaveSettings($db) {
    requireAdmin();
    ensureSettingsTable($db);

    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['category']) || !isset($input['settings'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid settings data']);
        return;
    }

    $category = $input['category'];
    $settings = $input['settings'];
    $adminId = $_SESSION['admin_id'] ?? null;

    try {
        $db->beginTransaction();

        foreach ($settings as $key => $value) {
            $settingKey = $category . '_' . $key;
            if (is_bool($value)) {
                $settingValue = $value ? '1' : '0';
            } elseif (is_array($value)) {
                $settingValue = json_encode($value);
            } else {
                $settingValue = $value;
            }
            $settingType = is_bool($value) ? 'boolean' : (is_numeric($value) ? 'number' : 'string');

            $sql = "INSERT INTO settings (setting_key, setting_value, setting_type, updated_by, updated_at)
                    VALUES (?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                    setting_value = VALUES(setting_value),
                    setting_type = VALUES(setting_type),
                    updated_by = VALUES(updated_by),
                    updated_at = NOW()";

            $stmt = $db->prepare($sql);
            $stmt->execute([$settingKey, $settingValue, $settingType, $adminId]);
        }

        if ($category === 'general') {
            motorlink_upsert_site_settings($db, buildGeneralRuntimeSiteSettingsPayload($settings));
        }

        $db->commit();
        echo json_encode(['success' => true, 'message' => ucfirst($category) . ' settings saved successfully']);
        exit();
    } catch (Exception $e) {
        $db->rollBack();
        error_log("handleSaveSettings error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to save settings: ' . $e->getMessage()]);
        exit();
    }
}

/**
 * Get settings from database
 */
function handleGetSettings($db) {
    requireAdmin();
    ensureSettingsTable($db);

    try {
        $stmt = $db->query("SELECT setting_key, setting_value, setting_type FROM settings ORDER BY setting_key");
        $rows = $stmt->fetchAll();

        $settings = [];
        foreach ($rows as $row) {
            $key = $row['setting_key'];
            $value = $row['setting_value'];

            // Convert value based on type
            if ($row['setting_type'] === 'boolean') {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            } elseif ($row['setting_type'] === 'number') {
                $value = is_numeric($value) ? (strpos($value, '.') !== false ? floatval($value) : intval($value)) : $value;
            } elseif ($value && (substr($value, 0, 1) === '{' || substr($value, 0, 1) === '[')) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $value = $decoded;
                }
            }

            $settings[$key] = $value;
        }

        foreach (getGeneralRuntimeSettingsForAdmin($db) as $key => $value) {
            if ($value !== null && $value !== '') {
                $settings[$key] = $value;
            }
        }

        echo json_encode(['success' => true, 'settings' => $settings]);
        exit();
    } catch (Exception $e) {
        error_log("handleGetSettings error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to load settings: ' . $e->getMessage()]);
        exit();
    }
}

function ensureFuelPricesTable($db) {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS fuel_prices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            fuel_type VARCHAR(50) NOT NULL,
            price_per_liter_mwk DECIMAL(10,2) NOT NULL,
            price_per_liter_usd DECIMAL(10,4) DEFAULT NULL,
            currency VARCHAR(10) DEFAULT 'MWK',
            date DATE NOT NULL,
            is_active TINYINT(1) DEFAULT 1,
            source VARCHAR(255) DEFAULT NULL,
            source_url VARCHAR(500) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            updated_by INT DEFAULT NULL,
            UNIQUE KEY uniq_fuel_type_date (fuel_type, date),
            INDEX idx_fuel_prices_active_date (is_active, date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Exception $e) {
        error_log('ensureFuelPricesTable create error: ' . $e->getMessage());
    }

    $alterStatements = [
        "ALTER TABLE fuel_prices ADD COLUMN price_per_liter_usd DECIMAL(10,4) DEFAULT NULL",
        "ALTER TABLE fuel_prices ADD COLUMN currency VARCHAR(10) DEFAULT 'MWK'",
        "ALTER TABLE fuel_prices ADD COLUMN is_active TINYINT(1) DEFAULT 1",
        "ALTER TABLE fuel_prices ADD COLUMN source VARCHAR(255) DEFAULT NULL",
        "ALTER TABLE fuel_prices ADD COLUMN source_url VARCHAR(500) DEFAULT NULL",
        "ALTER TABLE fuel_prices ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
        "ALTER TABLE fuel_prices ADD COLUMN last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
        "ALTER TABLE fuel_prices ADD COLUMN updated_by INT DEFAULT NULL",
        "ALTER TABLE fuel_prices ADD UNIQUE KEY uniq_fuel_type_date (fuel_type, date)",
        "ALTER TABLE fuel_prices ADD INDEX idx_fuel_prices_active_date (is_active, date)"
    ];

    foreach ($alterStatements as $sql) {
        try {
            $db->exec($sql);
        } catch (Exception $e) {
            // Ignore existing columns/indexes or duplicate-key migration issues.
        }
    }
}

function isValidFuelPriceDate($value) {
    if (!is_string($value) || $value === '') {
        return false;
    }

    $date = DateTime::createFromFormat('Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value;
}

function fetchAdminFuelPriceSnapshot($db, $requestedDate) {
    $stmt = $db->prepare("SELECT fuel_type, price_per_liter_mwk, price_per_liter_usd, currency, source, source_url, last_updated, date
        FROM fuel_prices
        WHERE is_active = 1 AND date = ?
        ORDER BY fuel_type");
    $stmt->execute([$requestedDate]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $resolvedDate = $requestedDate;
    $hasExactDate = !empty($rows);

    if (empty($rows)) {
        $latestDateStmt = $db->query("SELECT date FROM fuel_prices WHERE is_active = 1 ORDER BY date DESC LIMIT 1");
        $latestDate = $latestDateStmt ? $latestDateStmt->fetchColumn() : false;

        if ($latestDate) {
            $resolvedDate = $latestDate;
            $fallbackStmt = $db->prepare("SELECT fuel_type, price_per_liter_mwk, price_per_liter_usd, currency, source, source_url, last_updated, date
                FROM fuel_prices
                WHERE is_active = 1 AND date = ?
                ORDER BY fuel_type");
            $fallbackStmt->execute([$resolvedDate]);
            $rows = $fallbackStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    $priceMap = [
        'petrol' => [
            'fuel_type' => 'petrol',
            'price_per_liter_mwk' => '',
            'price_per_liter_usd' => '',
            'currency' => 'MWK',
            'source' => ''
        ],
        'diesel' => [
            'fuel_type' => 'diesel',
            'price_per_liter_mwk' => '',
            'price_per_liter_usd' => '',
            'currency' => 'MWK',
            'source' => ''
        ]
    ];

    $sources = [];
    $mostRecentUpdated = null;

    foreach ($rows as $row) {
        $fuelType = strtolower((string)($row['fuel_type'] ?? ''));
        if (!isset($priceMap[$fuelType])) {
            $priceMap[$fuelType] = [
                'fuel_type' => $fuelType,
                'price_per_liter_mwk' => '',
                'price_per_liter_usd' => '',
                'currency' => 'MWK',
                'source' => ''
            ];
        }

        $priceMap[$fuelType]['price_per_liter_mwk'] = $row['price_per_liter_mwk'] !== null ? (float)$row['price_per_liter_mwk'] : '';
        $priceMap[$fuelType]['price_per_liter_usd'] = $row['price_per_liter_usd'] !== null ? (float)$row['price_per_liter_usd'] : '';
        $priceMap[$fuelType]['currency'] = (string)($row['currency'] ?? 'MWK');
        $priceMap[$fuelType]['source'] = (string)($row['source'] ?? '');

        if (!empty($row['source'])) {
            $sources[] = (string)$row['source'];
        }

        if (!empty($row['last_updated'])) {
            $updatedAt = strtotime((string)$row['last_updated']);
            if ($updatedAt && ($mostRecentUpdated === null || $updatedAt > $mostRecentUpdated)) {
                $mostRecentUpdated = $updatedAt;
            }
        }
    }

    return [
        'requested_date' => $requestedDate,
        'resolved_date' => $resolvedDate,
        'has_exact_date' => $hasExactDate,
        'prices' => $priceMap,
        'source' => !empty($sources) ? implode(', ', array_values(array_unique($sources))) : '',
        'last_updated' => $mostRecentUpdated ? date('Y-m-d H:i:s', $mostRecentUpdated) : null
    ];
}

function handleFetchLiveFuelPrices($db) {
    requireAdmin();

    require_once __DIR__ . '/../includes/fuel-price-runtime.php';

    try {
        $snapshot = motorlink_resolve_fuel_price_snapshot($db, ['force_refresh' => true]);

        if (($snapshot['meta']['source_key'] ?? 'none') === 'none') {
            echo json_encode([
                'success' => false,
                'message' => 'Could not retrieve live fuel prices. Check that the country slug is configured in Site Settings.'
            ]);
            return;
        }

        $prices = [];
        foreach (($snapshot['prices'] ?? []) as $row) {
            $fuelType = strtolower((string)($row['fuel_type'] ?? ''));
            if ($fuelType === '') continue;
            $prices[$fuelType] = [
                'mwk' => isset($row['price_per_liter_mwk']) && $row['price_per_liter_mwk'] !== '' ? (float)$row['price_per_liter_mwk'] : null,
                'usd' => isset($row['price_per_liter_usd']) && $row['price_per_liter_usd'] !== '' && $row['price_per_liter_usd'] !== null ? (float)$row['price_per_liter_usd'] : null
            ];
        }

        echo json_encode([
            'success'      => true,
            'prices'       => $prices,
            'source_label' => $snapshot['meta']['source_label'] ?? 'Live feed',
            'source_key'   => $snapshot['meta']['source_key'] ?? 'live',
            'is_live'      => (bool)($snapshot['is_live'] ?? false),
            'last_updated' => $snapshot['meta']['last_updated'] ?? null
        ]);
    } catch (Exception $e) {
        error_log('handleFetchLiveFuelPrices error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to fetch live fuel prices: ' . $e->getMessage()]);
    }
}

function handleGetFuelPriceSettings($db) {
    requireAdmin();
    ensureFuelPricesTable($db);

    $requestedDate = trim((string)($_GET['date'] ?? date('Y-m-d')));
    if (!isValidFuelPriceDate($requestedDate)) {
        echo json_encode(['success' => false, 'message' => 'Invalid effective date']);
        return;
    }

    try {
        $snapshot = fetchAdminFuelPriceSnapshot($db, $requestedDate);
        echo json_encode(['success' => true] + $snapshot);
    } catch (Exception $e) {
        error_log('handleGetFuelPriceSettings error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to load fuel prices']);
    }
}

function handleSaveFuelPriceSettings($db) {
    requireAdmin();
    ensureFuelPricesTable($db);

    try {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            throw new Exception('Invalid request payload');
        }

        $effectiveDate = trim((string)($data['effective_date'] ?? date('Y-m-d')));
        $source = trim((string)($data['source'] ?? 'admin_portal'));
        if ($source === '') {
            $source = 'admin_portal';
        }

        if (!isValidFuelPriceDate($effectiveDate)) {
            throw new Exception('Effective date must be in YYYY-MM-DD format');
        }

        if (strlen($source) > 255) {
            throw new Exception('Source label must be 255 characters or fewer');
        }

        $fuelInputs = [
            'petrol' => [
                'mwk' => $data['petrol_mwk'] ?? null,
                'usd' => $data['petrol_usd'] ?? null
            ],
            'diesel' => [
                'mwk' => $data['diesel_mwk'] ?? null,
                'usd' => $data['diesel_usd'] ?? null
            ]
        ];

        $normalizedPrices = [];
        foreach ($fuelInputs as $fuelType => $values) {
            if ($values['mwk'] === null || $values['mwk'] === '' || !is_numeric((string)$values['mwk'])) {
                throw new Exception(ucfirst($fuelType) . ' price is required');
            }

            $mwkValue = round((float)$values['mwk'], 2);
            if ($mwkValue <= 0 || $mwkValue > 100000) {
                throw new Exception(ucfirst($fuelType) . ' price must be greater than 0 and less than 100000');
            }

            $usdValue = null;
            if ($values['usd'] !== null && $values['usd'] !== '') {
                if (!is_numeric((string)$values['usd'])) {
                    throw new Exception(ucfirst($fuelType) . ' price (USD) must be a valid number');
                }

                $usdValue = round((float)$values['usd'], 4);
                if ($usdValue < 0 || $usdValue > 1000) {
                    throw new Exception(ucfirst($fuelType) . ' price (USD) must be between 0 and 1000');
                }
            }

            $normalizedPrices[$fuelType] = [
                'mwk' => $mwkValue,
                'usd' => $usdValue
            ];
        }

        $adminId = $_SESSION['admin_id'] ?? null;

        $selectStmt = $db->prepare("SELECT id FROM fuel_prices WHERE fuel_type = ? AND date = ? ORDER BY is_active DESC, last_updated DESC, id DESC LIMIT 1");
        $deactivateStmt = $db->prepare("UPDATE fuel_prices SET is_active = 0 WHERE fuel_type = ? AND date = ? AND id <> ?");
        $updateStmt = $db->prepare("UPDATE fuel_prices
            SET price_per_liter_mwk = ?,
                price_per_liter_usd = ?,
                currency = 'MWK',
                is_active = 1,
                source = ?,
                source_url = NULL,
                last_updated = NOW()
            WHERE id = ?");
        $insertStmt = $db->prepare("INSERT INTO fuel_prices
            (fuel_type, price_per_liter_mwk, price_per_liter_usd, currency, date, is_active, source, source_url, last_updated)
            VALUES (?, ?, ?, 'MWK', ?, 1, ?, NULL, NOW())");

        $db->beginTransaction();

        foreach ($normalizedPrices as $fuelType => $values) {
            $selectStmt->execute([$fuelType, $effectiveDate]);
            $existingId = $selectStmt->fetchColumn();

            if ($existingId) {
                $updateStmt->execute([$values['mwk'], $values['usd'], $source, $existingId]);
                $deactivateStmt->execute([$fuelType, $effectiveDate, $existingId]);
            } else {
                $insertStmt->execute([$fuelType, $values['mwk'], $values['usd'], $effectiveDate, $source]);
            }
        }

        $db->commit();

        logActivity(
            $db,
            'fuel_prices_updated',
            'Fuel prices updated',
            sprintf(
                'Date: %s | Petrol: %.2f | Diesel: %.2f | Source: %s',
                $effectiveDate,
                $normalizedPrices['petrol']['mwk'],
                $normalizedPrices['diesel']['mwk'],
                $source
            ),
            $adminId
        );

        $snapshot = fetchAdminFuelPriceSnapshot($db, $effectiveDate);
        echo json_encode([
            'success' => true,
            'message' => 'Fuel prices saved successfully'
        ] + $snapshot);
    } catch (Exception $e) {
        if ($db instanceof PDO && $db->inTransaction()) {
            $db->rollBack();
        }

        error_log('handleSaveFuelPriceSettings error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to save fuel prices: ' . $e->getMessage()]);
    }
}

/**
 * Get system information
 */
function handleGetSystemInfo($db) {
    requireAdmin();

    try {
        // Get PHP version
        $phpVersion = phpversion();

        // Get database version
        $stmt = $db->query("SELECT VERSION() as version");
        $dbVersion = $stmt->fetch()['version'];

        // Get server time
        $stmt = $db->query("SELECT NOW() as server_time");
        $serverTime = $stmt->fetch()['server_time'];

        // Get total users count
        $stmt = $db->query("SELECT COUNT(*) as count FROM users WHERE status != 'suspended'");
        $totalUsers = $stmt->fetch()['count'];

        // Get total listings count
        $stmt = $db->query("SELECT COUNT(*) as count FROM car_listings WHERE status != 'deleted'");
        $totalListings = $stmt->fetch()['count'];

        // Get total garages count
        $stmt = $db->query("SELECT COUNT(*) as count FROM garages WHERE status = 'active'");
        $totalGarages = $stmt->fetch()['count'];

        // Get total dealers count
        $stmt = $db->query("SELECT COUNT(*) as count FROM car_dealers WHERE status = 'active'");
        $totalDealers = $stmt->fetch()['count'];

        // Get disk space (if available)
        $diskSpace = [
            'total' => disk_total_space('.'),
            'free' => disk_free_space('.')
        ];

        $systemInfo = [
            'phpVersion' => $phpVersion,
            'dbVersion' => $dbVersion,
            'serverTime' => $serverTime,
            'totalUsers' => $totalUsers,
            'totalListings' => $totalListings,
            'totalGarages' => $totalGarages,
            'totalDealers' => $totalDealers,
            'diskSpace' => $diskSpace
        ];

        echo json_encode(['success' => true, 'systemInfo' => $systemInfo]);
        exit();
    } catch (Exception $e) {
        error_log("handleGetSystemInfo error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to get system info: ' . $e->getMessage()]);
        exit();
    }
}

/**
 * Export database backup
 */
function handleExportDatabase($db) {
    requireAdmin();

    try {
        // Get all table data
        $tables = ['users', 'car_listings', 'garages', 'car_dealers', 'car_hire_companies', 'car_makes', 'car_models', 'locations'];
        $backup = [
            'export_date' => date('Y-m-d H:i:s'),
            'version' => '4.1.0',
            'tables' => []
        ];

        foreach ($tables as $table) {
            try {
                $stmt = $db->query("SELECT * FROM {$table}");
                $backup['tables'][$table] = $stmt->fetchAll();
            } catch (Exception $e) {
                // Table might not exist, skip it
                error_log("Could not export table {$table}: " . $e->getMessage());
            }
        }

        echo json_encode(['success' => true, 'backup' => $backup, 'filename' => 'motorlink_backup_' . date('Y-m-d_His') . '.json']);
        exit();
    } catch (Exception $e) {
        error_log("handleExportDatabase error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to export database: ' . $e->getMessage()]);
        exit();
    }
}

// ===== ACTIVITY LOGS MANAGEMENT =====

/**
 * Get activity logs with filtering and pagination
 */
function handleGetActivityLogs($db) {
    requireAdmin();

    try {
        // Ensure activity_logs table exists
        $db->exec("CREATE TABLE IF NOT EXISTS activity_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            admin_id INT,
            action_type VARCHAR(100),
            action_description VARCHAR(255),
            details TEXT,
            ip_address VARCHAR(45),
            user_agent TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_admin_id (admin_id),
            INDEX idx_action_type (action_type),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $where = ["1=1"];
        $params = [];

        // Apply filters
        if (!empty($_GET['action_type'])) {
            $where[] = "action_type = ?";
            $params[] = $_GET['action_type'];
        }

        if (!empty($_GET['admin_id'])) {
            $where[] = "admin_id = ?";
            $params[] = $_GET['admin_id'];
        }

        if (!empty($_GET['date_from'])) {
            $where[] = "DATE(created_at) >= ?";
            $params[] = $_GET['date_from'];
        }

        if (!empty($_GET['date_to'])) {
            $where[] = "DATE(created_at) <= ?";
            $params[] = $_GET['date_to'];
        }

        if (!empty($_GET['search'])) {
            $where[] = "(action_description LIKE ? OR details LIKE ?)";
            $searchTerm = "%" . $_GET['search'] . "%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        $whereClause = implode(' AND ', $where);
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;

        $sql = "SELECT
            al.*,
            au.full_name as admin_name,
            au.email as admin_email
        FROM activity_logs al
        LEFT JOIN admin_users au ON al.admin_id = au.id
        WHERE {$whereClause}
        ORDER BY al.created_at DESC
        LIMIT ?";

        $params[] = $limit;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'logs' => $logs, 'count' => count($logs)]);
        exit();
    } catch (Exception $e) {
        error_log("handleGetActivityLogs error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to load activity logs']);
        exit();
    }
}

/**
 * Get listing reports for admin review.
 */
function handleGetListingReports($db) {
    requireAdmin();

    try {
        $schemaWarnings = ensureListingReportsSchema($db);

        $where = ["1=1"];
        $params = [];

        if (!empty($_GET['status'])) {
            $where[] = "lr.status = ?";
            $params[] = $_GET['status'];
        }

        if (!empty($_GET['reason'])) {
            $where[] = "lr.reason = ?";
            $params[] = $_GET['reason'];
        }

        if (!empty($_GET['search'])) {
            $where[] = "(l.title LIKE ? OR lr.details LIKE ? OR lr.reporter_email LIKE ? OR ru.email LIKE ?)";
            $term = '%' . $_GET['search'] . '%';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        $limit = isset($_GET['limit']) ? max(1, min(500, (int)$_GET['limit'])) : 200;
        $whereClause = implode(' AND ', $where);

        $sql = "SELECT lr.id, lr.listing_id, lr.user_id, lr.reason, lr.details, lr.reporter_email, lr.reporter_ip,
                       lr.status, lr.admin_notes, lr.reviewed_by, lr.reviewed_at, lr.created_at,
                       l.title AS listing_title, l.status AS listing_status, l.approval_status,
                       ru.email AS reporter_user_email, ru.full_name AS reporter_user_name,
                       au.full_name AS reviewed_by_name
                FROM listing_reports lr
                LEFT JOIN car_listings l ON lr.listing_id = l.id
                LEFT JOIN users ru ON lr.user_id = ru.id
                LEFT JOIN admin_users au ON lr.reviewed_by = au.id
                WHERE {$whereClause}
                ORDER BY CASE WHEN lr.status = 'pending' THEN 0 ELSE 1 END, lr.created_at DESC
                LIMIT ?";

        $params[] = $limit;
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $counts = ['pending' => 0, 'reviewed' => 0, 'dismissed' => 0];
        foreach ($reports as $r) {
            $key = strtolower((string)($r['status'] ?? 'pending'));
            if (isset($counts[$key])) {
                $counts[$key]++;
            }
        }

        echo json_encode([
            'success' => true,
            'reports' => $reports,
            'stats' => $counts,
            'warnings' => $schemaWarnings
        ]);
        exit();
    } catch (Exception $e) {
        error_log('handleGetListingReports error: ' . $e->getMessage());
        sendAdminError('Failed to load listing reports', 'REPORTS_LOAD_FAILED', 500);
    }
}

/**
 * Update listing report review status.
 */
function handleUpdateListingReportStatus($db) {
    requireAdmin();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendAdminError('POST method required', 'INVALID_METHOD', 405);
    }

    try {
        $schemaWarnings = ensureListingReportsSchema($db);
        $input = json_decode(file_get_contents('php://input'), true);
        $reportId = isset($input['report_id']) ? (int)$input['report_id'] : 0;
        $status = trim((string)($input['status'] ?? ''));
        $notes = trim((string)($input['admin_notes'] ?? ''));

        if ($reportId <= 0) {
            sendAdminError('Report ID is required', 'REPORT_ID_REQUIRED', 400);
        }

        $allowed = ['pending', 'reviewed', 'dismissed'];
        if (!in_array($status, $allowed, true)) {
            sendAdminError('Invalid report status', 'INVALID_REPORT_STATUS', 400);
        }

        $adminId = $_SESSION['admin_id'] ?? null;
        $stmt = $db->prepare("UPDATE listing_reports
                              SET status = ?, admin_notes = ?, reviewed_by = ?, reviewed_at = NOW()
                              WHERE id = ?");
        $stmt->execute([$status, $notes !== '' ? $notes : null, $adminId, $reportId]);

        if ($stmt->rowCount() === 0) {
            sendAdminError('Report not found or unchanged', 'REPORT_NOT_FOUND_OR_UNCHANGED', 404);
        }

        logActivity(
            $db,
            'listing_report_updated',
            'Listing report status updated',
            'Report ID: ' . $reportId . ', Status: ' . $status,
            $adminId
        );

        echo json_encode([
            'success' => true,
            'message' => 'Report status updated successfully',
            'warnings' => $schemaWarnings
        ]);
        exit();
    } catch (Exception $e) {
        error_log('handleUpdateListingReportStatus error: ' . $e->getMessage());
        sendAdminError('Failed to update report status', 'REPORT_STATUS_UPDATE_FAILED', 500);
    }
}

/**
 * Ensure listing reports table and review columns exist.
 */
function ensureListingReportsSchema($db) {
    $warnings = [];

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

    $schemaUpgrades = [
        "ALTER TABLE listing_reports ADD COLUMN admin_notes TEXT DEFAULT NULL",
        "ALTER TABLE listing_reports ADD COLUMN reviewed_by INT DEFAULT NULL",
        "ALTER TABLE listing_reports ADD COLUMN reviewed_at DATETIME DEFAULT NULL"
    ];

    foreach ($schemaUpgrades as $sql) {
        try {
            $db->exec($sql);
        } catch (Exception $e) {
            // Ignore if column already exists.
        }
    }

    try {
        $db->exec("ALTER TABLE listing_reports ADD UNIQUE KEY uniq_listing_user_report (listing_id, user_id)");
    } catch (Exception $e) {
        $message = strtolower($e->getMessage());

        if (strpos($message, 'duplicate key name') !== false) {
            return $warnings;
        }

        if (strpos($message, 'duplicate entry') !== false || strpos($message, '1062') !== false) {
            $warnings[] = [
                'code' => 'SCHEMA_DUPLICATE_REPORTS_PRESENT',
                'message' => 'Duplicate listing reports already exist. Resolve duplicates before uniqueness can be fully enforced in admin schema backfill.'
            ];
        } else {
            $warnings[] = [
                'code' => 'SCHEMA_BACKFILL_WARNING',
                'message' => 'Listing report uniqueness backfill could not be applied automatically.'
            ];
        }
    }

    return $warnings;
}

// ===== ACTIVITY LOGGING FUNCTION =====

/**
 * Log admin activities to database
 */
function logActivity($db, $action_type, $action_description, $details = null, $admin_id = null) {
    try {
        // Ensure activity_logs table exists
        $db->exec("CREATE TABLE IF NOT EXISTS activity_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            admin_id INT,
            action_type VARCHAR(100),
            action_description VARCHAR(255),
            details TEXT,
            ip_address VARCHAR(45),
            user_agent TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_admin_id (admin_id),
            INDEX idx_action_type (action_type),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $stmt = $db->prepare("
            INSERT INTO activity_logs (admin_id, action_type, action_description, details, ip_address, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");

        $stmt->execute([
            $admin_id,
            $action_type,
            $action_description,
            $details,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);

        return true;
    } catch (Exception $e) {
        error_log("logActivity error: " . $e->getMessage());
        return false;
    }
}

// ===== GARAGE FEATURE/VERIFY/CERTIFY =====

function handleFeatureGarage($db) {
    requireAdmin();
    
    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? '';
    $isFeatured = $input['is_featured'] ?? 1;
    
    if (empty($id)) {
        echo json_encode(['success' => false, 'message' => 'Garage ID is required']);
        return;
    }
    
    try {
        $stmt = $db->prepare("UPDATE garages SET featured = ?, updated_at = NOW() WHERE id = ?");
        $result = $stmt->execute([$isFeatured, $id]);
        
        if ($result) {
            $status = $isFeatured ? 'featured' : 'unfeatured';
            logActivity($db, 'garage_featured', "Garage $status", "Garage ID: $id", $_SESSION['admin_id'] ?? null);
            echo json_encode(['success' => true, 'message' => "Garage has been $status successfully"]);
            exit();
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update garage feature status']);
            exit();
        }
    } catch (Exception $e) {
        error_log("handleFeatureGarage error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to feature garage: ' . $e->getMessage()]);
        exit();
    }
}

function handleVerifyGarage($db) {
    requireAdmin();
    
    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? '';
    $isVerified = $input['is_verified'] ?? 1;
    
    if (empty($id)) {
        echo json_encode(['success' => false, 'message' => 'Garage ID is required']);
        return;
    }
    
    try {
        $stmt = $db->prepare("UPDATE garages SET verified = ?, updated_at = NOW() WHERE id = ?");
        $result = $stmt->execute([$isVerified, $id]);
        
        if ($result) {
            $status = $isVerified ? 'verified' : 'unverified';
            logActivity($db, 'garage_verified', "Garage $status", "Garage ID: $id", $_SESSION['admin_id'] ?? null);
            echo json_encode(['success' => true, 'message' => "Garage has been $status successfully"]);
            exit();
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update garage verification status']);
            exit();
        }
    } catch (Exception $e) {
        error_log("handleVerifyGarage error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to verify garage: ' . $e->getMessage()]);
        exit();
    }
}

function handleCertifyGarage($db) {
    requireAdmin();
    
    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? '';
    $isCertified = $input['is_certified'] ?? 1;
    
    if (empty($id)) {
        echo json_encode(['success' => false, 'message' => 'Garage ID is required']);
        return;
    }
    
    try {
        $stmt = $db->prepare("UPDATE garages SET certified = ?, updated_at = NOW() WHERE id = ?");
        $result = $stmt->execute([$isCertified, $id]);
        
        if ($result) {
            $status = $isCertified ? 'certified' : 'uncertified';
            logActivity($db, 'garage_certified', "Garage $status", "Garage ID: $id", $_SESSION['admin_id'] ?? null);
            echo json_encode(['success' => true, 'message' => "Garage has been $status successfully"]);
            exit();
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update garage certification status']);
            exit();
        }
    } catch (Exception $e) {
        error_log("handleCertifyGarage error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to certify garage: ' . $e->getMessage()]);
        exit();
    }
}

// ===== DEALER FEATURE/VERIFY/CERTIFY =====

function handleFeatureDealer($db) {
    requireAdmin();
    
    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? '';
    $isFeatured = $input['is_featured'] ?? 1;
    
    if (empty($id)) {
        echo json_encode(['success' => false, 'message' => 'Dealer ID is required']);
        return;
    }
    
    try {
        $stmt = $db->prepare("UPDATE car_dealers SET featured = ?, updated_at = NOW() WHERE id = ?");
        $result = $stmt->execute([$isFeatured, $id]);
        
        if ($result) {
            $status = $isFeatured ? 'featured' : 'unfeatured';
            logActivity($db, 'dealer_featured', "Dealer $status", "Dealer ID: $id", $_SESSION['admin_id'] ?? null);
            echo json_encode(['success' => true, 'message' => "Dealer has been $status successfully"]);
            exit();
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update dealer feature status']);
            exit();
        }
    } catch (Exception $e) {
        error_log("handleFeatureDealer error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to feature dealer: ' . $e->getMessage()]);
        exit();
    }
}

function handleVerifyDealer($db) {
    requireAdmin();
    
    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? '';
    $isVerified = $input['is_verified'] ?? 1;
    
    if (empty($id)) {
        echo json_encode(['success' => false, 'message' => 'Dealer ID is required']);
        return;
    }
    
    try {
        $stmt = $db->prepare("UPDATE car_dealers SET verified = ?, updated_at = NOW() WHERE id = ?");
        $result = $stmt->execute([$isVerified, $id]);
        
        if ($result) {
            $status = $isVerified ? 'verified' : 'unverified';
            logActivity($db, 'dealer_verified', "Dealer $status", "Dealer ID: $id", $_SESSION['admin_id'] ?? null);
            echo json_encode(['success' => true, 'message' => "Dealer has been $status successfully"]);
            exit();
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update dealer verification status']);
            exit();
        }
    } catch (Exception $e) {
        error_log("handleVerifyDealer error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to verify dealer: ' . $e->getMessage()]);
        exit();
    }
}

function handleCertifyDealer($db) {
    requireAdmin();
    
    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? '';
    $isCertified = $input['is_certified'] ?? 1;
    
    if (empty($id)) {
        echo json_encode(['success' => false, 'message' => 'Dealer ID is required']);
        return;
    }
    
    try {
        // Ensure the column exists (add if it doesn't)
        try {
            $db->exec("ALTER TABLE car_dealers ADD COLUMN certified TINYINT(1) DEFAULT 0");
        } catch (Exception $e) {
            // Column already exists, ignore
        }
        
        $stmt = $db->prepare("UPDATE car_dealers SET certified = ?, updated_at = NOW() WHERE id = ?");
        $result = $stmt->execute([$isCertified, $id]);
        
        if ($result) {
            $status = $isCertified ? 'certified' : 'uncertified';
            logActivity($db, 'dealer_certified', "Dealer $status", "Dealer ID: $id", $_SESSION['admin_id'] ?? null);
            echo json_encode(['success' => true, 'message' => "Dealer has been $status successfully"]);
            exit();
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update dealer certification status']);
            exit();
        }
    } catch (Exception $e) {
        error_log("handleCertifyDealer error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to certify dealer: ' . $e->getMessage()]);
        exit();
    }
}

// ===== CAR HIRE FEATURE/VERIFY/CERTIFY =====

function handleFeatureCarHire($db) {
    requireAdmin();
    
    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? '';
    $isFeatured = $input['is_featured'] ?? 1;
    
    if (empty($id)) {
        echo json_encode(['success' => false, 'message' => 'Car Hire ID is required']);
        return;
    }
    
    try {
        $stmt = $db->prepare("UPDATE car_hire_companies SET featured = ?, updated_at = NOW() WHERE id = ?");
        $result = $stmt->execute([$isFeatured, $id]);
        
        if ($result) {
            $status = $isFeatured ? 'featured' : 'unfeatured';
            logActivity($db, 'car_hire_featured', "Car hire company $status", "Car Hire ID: $id", $_SESSION['admin_id'] ?? null);
            echo json_encode(['success' => true, 'message' => "Car hire company has been $status successfully"]);
            exit();
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update car hire feature status']);
            exit();
        }
    } catch (Exception $e) {
        error_log("handleFeatureCarHire error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to feature car hire: ' . $e->getMessage()]);
        exit();
    }
}

function handleVerifyCarHire($db) {
    requireAdmin();
    
    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? '';
    $isVerified = $input['is_verified'] ?? 1;
    
    if (empty($id)) {
        echo json_encode(['success' => false, 'message' => 'Car Hire ID is required']);
        return;
    }
    
    try {
        $stmt = $db->prepare("UPDATE car_hire_companies SET verified = ?, updated_at = NOW() WHERE id = ?");
        $result = $stmt->execute([$isVerified, $id]);
        
        if ($result) {
            $status = $isVerified ? 'verified' : 'unverified';
            logActivity($db, 'car_hire_verified', "Car hire company $status", "Car Hire ID: $id", $_SESSION['admin_id'] ?? null);
            echo json_encode(['success' => true, 'message' => "Car hire company has been $status successfully"]);
            exit();
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update car hire verification status']);
            exit();
        }
    } catch (Exception $e) {
        error_log("handleVerifyCarHire error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to verify car hire: ' . $e->getMessage()]);
        exit();
    }
}

function handleCertifyCarHire($db) {
    requireAdmin();
    
    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? '';
    $isCertified = $input['is_certified'] ?? 1;
    
    if (empty($id)) {
        echo json_encode(['success' => false, 'message' => 'Car Hire ID is required']);
        return;
    }
    
    try {
        // Ensure the column exists (add if it doesn't)
        try {
            $db->exec("ALTER TABLE car_hire_companies ADD COLUMN certified TINYINT(1) DEFAULT 0");
        } catch (Exception $e) {
            // Column already exists, ignore
        }
        
        $stmt = $db->prepare("UPDATE car_hire_companies SET certified = ?, updated_at = NOW() WHERE id = ?");
        $result = $stmt->execute([$isCertified, $id]);
        
        if ($result) {
            $status = $isCertified ? 'certified' : 'uncertified';
            logActivity($db, 'car_hire_certified', "Car hire company $status", "Car Hire ID: $id", $_SESSION['admin_id'] ?? null);
            echo json_encode(['success' => true, 'message' => "Car hire company has been $status successfully"]);
            exit();
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update car hire certification status']);
            exit();
        }
    } catch (Exception $e) {
        error_log("handleCertifyCarHire error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to certify car hire: ' . $e->getMessage()]);
        exit();
    }
}

function getAIProviderSiteSettingDefinitions() {
    return [
        'openai' => [
            'label' => 'OpenAI',
            'setting_key' => 'openai_api_key',
            'description' => 'OpenAI API key used by MotorLink AI chat and AI learning'
        ],
        'deepseek' => [
            'label' => 'DeepSeek',
            'setting_key' => 'deepseek_api_key',
            'description' => 'DeepSeek API key used by MotorLink AI chat and AI learning'
        ],
        'qwen' => [
            'label' => 'Qwen',
            'setting_key' => 'qwen_api_key',
            'description' => 'Qwen API key used by MotorLink AI chat and AI learning'
        ],
        'glm' => [
            'label' => 'GLM',
            'setting_key' => 'glm_api_key',
            'description' => 'GLM API key used by MotorLink AI chat and AI learning'
        ]
    ];
}

function adminCanManageAIProviderSecrets($db) {
    if (($_SESSION['admin_role'] ?? '') === 'super_admin') {
        return true;
    }

    try {
        $adminId = $_SESSION['admin_id'] ?? null;
        if (!$adminId) {
            return false;
        }

        $stmt = $db->prepare("SELECT role FROM admin_users WHERE id = ? LIMIT 1");
        $stmt->execute([$adminId]);
        $role = $stmt->fetchColumn();
        return $role === 'super_admin';
    } catch (Exception $e) {
        error_log('adminCanManageAIProviderSecrets error: ' . $e->getMessage());
        return false;
    }
}

function maskAdminSecretValue($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    if (strlen($value) <= 4) {
        return '********';
    }

    $suffix = substr($value, -4);
    return '********' . $suffix;
}

function ensureAIProviderSiteSettingsRows($db) {
    $definitions = getAIProviderSiteSettingDefinitions();
    $settingKeys = array_map(function ($meta) {
        return $meta['setting_key'];
    }, $definitions);

    if (empty($settingKeys)) {
        return;
    }

    $settingKeys = array_values($settingKeys);
    $settingKeys = array_values($settingKeys);
    $placeholders = implode(',', array_fill(0, count($settingKeys), '?'));
    $stmt = $db->prepare("SELECT setting_key FROM site_settings WHERE setting_key IN ($placeholders)");
    $stmt->execute($settingKeys);
    $existing = array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), true);

    $insert = $db->prepare("INSERT INTO site_settings (setting_key, setting_value, setting_group, setting_type, description, is_public)
                            VALUES (?, '', 'ai', 'string', ?, 0)");

    foreach ($definitions as $meta) {
        if (isset($existing[$meta['setting_key']])) {
            continue;
        }

        $insert->execute([
            $meta['setting_key'],
            $meta['description']
        ]);
    }
}

function getAIProviderKeyMetadata($db) {
    ensureAIProviderSiteSettingsRows($db);

    $definitions = getAIProviderSiteSettingDefinitions();
    $settingKeys = array_values(array_map(function ($meta) {
        return $meta['setting_key'];
    }, $definitions));
    $placeholders = implode(',', array_fill(0, count($settingKeys), '?'));
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ($placeholders)");
    $stmt->execute($settingKeys);

    $values = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $values[$row['setting_key']] = (string)($row['setting_value'] ?? '');
    }

    $metadata = [];
    foreach ($definitions as $provider => $meta) {
        $value = trim((string)($values[$meta['setting_key']] ?? ''));
        $metadata[$provider] = [
            'configured' => $value !== '',
            'masked' => $value !== '' ? maskAdminSecretValue($value) : ''
        ];
    }

    return $metadata;
}

function buildAIProviderKeySettingsPayload(array $inputKeys) {
    $definitions = getAIProviderSiteSettingDefinitions();
    $payload = [];
    $updatedProviders = [];

    foreach ($inputKeys as $provider => $rawValue) {
        if (!isset($definitions[$provider])) {
            throw new Exception('Unknown AI provider key payload: ' . $provider);
        }

        $value = trim((string)$rawValue);
        if ($value === '') {
            continue;
        }

        if (strlen($value) > 512) {
            throw new Exception($definitions[$provider]['label'] . ' API key is too long.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new Exception($definitions[$provider]['label'] . ' API key contains invalid characters.');
        }

        $meta = $definitions[$provider];
        $payload[$meta['setting_key']] = [
            'value' => $value,
            'group' => 'ai',
            'type' => 'string',
            'description' => $meta['description'],
            'is_public' => 0
        ];
        $updatedProviders[] = $provider;
    }

    return [
        'payload' => $payload,
        'providers' => $updatedProviders
    ];
}

function getAIProviderRuntimeDefinitions() {
    $definitions = getAIProviderSiteSettingDefinitions();

    $definitions['openai']['url'] = 'https://api.openai.com/v1/chat/completions';
    $definitions['openai']['default_model'] = 'gpt-4o-mini';

    $definitions['deepseek']['url'] = 'https://api.deepseek.com/v1/chat/completions';
    $definitions['deepseek']['default_model'] = 'deepseek-chat';

    $definitions['qwen']['url'] = 'https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions';
    $definitions['qwen']['default_model'] = 'qwen-plus';

    $definitions['glm']['url'] = 'https://open.bigmodel.cn/api/paas/v4/chat/completions';
    $definitions['glm']['default_model'] = 'glm-4.7-flash';

    return $definitions;
}

function getAIProviderSecretValues($db) {
    ensureAIProviderSiteSettingsRows($db);

    $definitions = getAIProviderSiteSettingDefinitions();
    $settingKeys = array_values(array_map(function ($meta) {
        return $meta['setting_key'];
    }, $definitions));
    $placeholders = implode(',', array_fill(0, count($settingKeys), '?'));
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ($placeholders)");
    $stmt->execute($settingKeys);

    $values = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $values[$row['setting_key']] = (string)($row['setting_value'] ?? '');
    }

    $secrets = [];
    foreach ($definitions as $provider => $meta) {
        $secrets[$provider] = trim((string)($values[$meta['setting_key']] ?? ''));
    }

    return $secrets;
}

function getAIProviderRetryOrderSettingValue($db) {
    try {
        $stmt = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'ai_provider_retry_order' LIMIT 1");
        $stmt->execute();
        return trim((string)$stmt->fetchColumn());
    } catch (Exception $e) {
        error_log('getAIProviderRetryOrderSettingValue error: ' . $e->getMessage());
        return '';
    }
}

function parseAIProviderRetryOrderValue($rawValue, $preferredProvider) {
    $preferredProvider = strtolower(trim((string)$preferredProvider));
    $supportedProviders = array_keys(getAIProviderSiteSettingDefinitions());
    $fallbackOrder = ['deepseek', 'glm', 'openai', 'qwen'];

    if ($rawValue !== '') {
        $parsedValues = preg_split('/[\s,|>]+/', strtolower($rawValue));
        $parsedOrder = [];

        foreach ((array)$parsedValues as $provider) {
            $provider = trim((string)$provider);
            if ($provider === '' || !in_array($provider, $supportedProviders, true)) {
                continue;
            }
            $parsedOrder[] = $provider;
        }

        if (!empty($parsedOrder)) {
            $fallbackOrder = $parsedOrder;
        }
    }

    $ordered = [];
    foreach (array_values(array_unique(array_merge([$preferredProvider], $fallbackOrder))) as $provider) {
        if (in_array($provider, $supportedProviders, true)) {
            $ordered[] = $provider;
        }
    }

    return $ordered;
}

function getAdminApiLogTailLines($filePath, $maxBytes = 262144, $maxLines = 400) {
    if (!is_file($filePath) || !is_readable($filePath)) {
        return [];
    }

    $size = filesize($filePath);
    if ($size === false) {
        return [];
    }

    $handle = fopen($filePath, 'rb');
    if (!$handle) {
        return [];
    }

    $start = max(0, $size - $maxBytes);
    if (fseek($handle, $start) !== 0) {
        fclose($handle);
        return [];
    }

    if ($start > 0) {
        fgets($handle);
    }

    $lines = [];
    while (($line = fgets($handle)) !== false) {
        $line = trim($line);
        if ($line !== '') {
            $lines[] = $line;
        }
    }

    fclose($handle);

    if (count($lines) > $maxLines) {
        $lines = array_slice($lines, -$maxLines);
    }

    return $lines;
}

function getAIProviderLogMatchPattern($provider) {
    switch ($provider) {
        case 'openai':
            return '/(ChatGPT \(OpenAI\)|OpenAI)/i';
        case 'deepseek':
            return '/DeepSeek/i';
        case 'qwen':
            return '/Qwen/i';
        case 'glm':
            return '/GLM/i';
        default:
            return null;
    }
}

function classifyAIProviderHealthState($httpCode = null, $errorMessage = '', $errorType = '', $errorCode = '', $fallbackText = '') {
    $haystack = strtolower(trim((string)$errorMessage . ' ' . (string)$errorType . ' ' . (string)$errorCode . ' ' . (string)$fallbackText));
    $httpCode = $httpCode !== null ? (int)$httpCode : null;

    if (strpos($haystack, 'not configured') !== false) {
        return ['status' => 'not_configured', 'message' => 'API key is not configured for this provider.'];
    }

    if ($httpCode === 402
        || strpos($haystack, 'insufficient balance') !== false
        || strpos($haystack, 'insufficient_quota') !== false
        || strpos($haystack, 'insufficient credit') !== false
        || strpos($haystack, 'insufficient funds') !== false
        || strpos($haystack, 'exceeded your current quota') !== false
        || strpos($haystack, 'payment required') !== false) {
        return ['status' => 'out_of_credit', 'message' => 'Provider credits are exhausted or billing is insufficient.'];
    }

    if ($httpCode === 429
        || strpos($haystack, 'rate limit') !== false
        || strpos($haystack, 'too many requests') !== false
        || strpos($haystack, 'requests per minute') !== false
        || strpos($haystack, 'requests per day') !== false
        || strpos($haystack, '速率限制') !== false) {
        return ['status' => 'rate_limited', 'message' => 'Provider is rate-limiting requests right now.'];
    }

    if ($httpCode === 401
        || strpos($haystack, 'authentication failed') !== false
        || strpos($haystack, 'invalid api key') !== false
        || strpos($haystack, 'incorrect api key') !== false
        || strpos($haystack, 'unauthorized') !== false) {
        return ['status' => 'auth_error', 'message' => 'Provider authentication failed. Check the stored API key.'];
    }

    if (($httpCode === 400 && strpos($haystack, 'model') !== false)
        || strpos($haystack, 'not exist') !== false
        || strpos($haystack, 'does not exist') !== false
        || strpos($haystack, 'unknown model') !== false
        || strpos($haystack, 'invalid model') !== false
        || strpos($haystack, '1211') !== false) {
        return ['status' => 'model_error', 'message' => 'The configured model is invalid or unavailable for this provider.'];
    }

    if (strpos($haystack, 'empty response content') !== false) {
        return ['status' => 'degraded', 'message' => 'Provider responded, but returned empty content.'];
    }

    if (strpos($haystack, 'curl error') !== false
        || strpos($haystack, 'connection error') !== false
        || strpos($haystack, 'timed out') !== false
        || strpos($haystack, 'timeout') !== false
        || strpos($haystack, 'could not resolve host') !== false) {
        return ['status' => 'connection_error', 'message' => 'Connection to the provider failed or timed out.'];
    }

    if ($httpCode !== null && $httpCode >= 500) {
        return ['status' => 'provider_error', 'message' => 'Provider is returning server-side errors.'];
    }

    if ($httpCode !== null && $httpCode >= 400) {
        return ['status' => 'provider_error', 'message' => 'Provider returned an API error.'];
    }

    return ['status' => 'ready', 'message' => 'Configured and enabled. No recent provider errors were detected.'];
}

function getRecentAIProviderLogHealth($provider, array $lines) {
    $pattern = getAIProviderLogMatchPattern($provider);
    if (!$pattern) {
        return null;
    }

    for ($index = count($lines) - 1; $index >= 0; $index--) {
        $line = (string)$lines[$index];
        if (!preg_match($pattern, $line)) {
            continue;
        }

        if (stripos($line, 'AI Chat Settings loaded') !== false) {
            continue;
        }

        $timestamp = null;
        $body = $line;
        if (preg_match('/^\[([^\]]+)\]\s*(.*)$/', $line, $matches)) {
            $timestamp = trim((string)$matches[1]);
            $body = trim((string)$matches[2]);
        }

        $httpCode = null;
        if (preg_match('/API HTTP\s+(\d+)/i', $body, $httpMatch)) {
            $httpCode = (int)$httpMatch[1];
        }

        $errorType = '';
        if (preg_match('/Type:\s*([^,\)]*)/i', $body, $typeMatch)) {
            $errorType = trim((string)$typeMatch[1]);
        }

        $errorCode = '';
        if (preg_match('/Code:\s*([^\)]*)/i', $body, $codeMatch)) {
            $errorCode = trim((string)$codeMatch[1]);
        }

        $errorMessage = $body;
        if (preg_match('/\):\s*(.+)$/', $body, $messageMatch)) {
            $errorMessage = trim((string)$messageMatch[1]);
        }

        $classified = classifyAIProviderHealthState($httpCode, $errorMessage, $errorType, $errorCode, $body);

        return [
            'status' => $classified['status'],
            'message' => $classified['message'],
            'timestamp' => $timestamp,
            'raw_line' => $line,
            'http_code' => $httpCode,
            'error_type' => $errorType,
            'error_code' => $errorCode
        ];
    }

    return null;
}

function isAdminOpenAIReasoningCapableModel($modelName) {
    $model = strtolower(trim((string)$modelName));
    return $model !== '' && preg_match('/^(o\d|gpt-5(?:$|[-._:]))/', $model) === 1;
}

function isAdminOpenAIGPT5ReasoningModel($modelName) {
    $model = strtolower(trim((string)$modelName));
    return $model !== '' && preg_match('/^gpt-5(?:$|[-._:])/', $model) === 1;
}

function isAdminDeepSeekReasonerModel($modelName) {
    $model = strtolower(trim((string)$modelName));
    return $model !== '' && preg_match('/^deepseek-reasoner(?:$|[-._:])/', $model) === 1;
}

function isAdminGLMFlashFamilyModel($modelName) {
    $model = strtolower(trim((string)$modelName));
    return $model !== '' && preg_match('/^glm-(?:4(?:\.7)?-)?flash(?:x)?(?:$|[-._:])/', $model) === 1;
}

function isAdminGLMThinkingCapableModel($modelName) {
    $model = strtolower(trim((string)$modelName));
    if ($model === '' || isAdminGLMFlashFamilyModel($model)) {
        return false;
    }

    return preg_match('/^glm-(4\.5|4\.6|4\.7|5\.1|5)(?:$|[-._:])/', $model) === 1;
}

function buildAIProviderHealthProbePayload($provider, $modelName) {
    $payload = [
        'model' => $modelName,
        'messages' => [
            ['role' => 'system', 'content' => 'Reply with OK only.'],
            ['role' => 'user', 'content' => 'OK?']
        ],
        'max_tokens' => 8,
        'temperature' => 0
    ];

    if ($provider === 'openai' && isAdminOpenAIReasoningCapableModel($modelName)) {
        $payload['max_completion_tokens'] = 8;
        $payload['reasoning_effort'] = isAdminOpenAIGPT5ReasoningModel($modelName) ? 'minimal' : 'low';
        unset($payload['max_tokens'], $payload['temperature']);
    }

    if ($provider === 'deepseek' && isAdminDeepSeekReasonerModel($modelName)) {
        unset($payload['temperature']);
    }

    if ($provider === 'glm') {
        // Always disable thinking for health probes — flash models based on thinking-capable
        // base models (e.g. glm-4.7-flash → GLM-4.7) can return empty content if thinking
        // is active and consumes the token budget before the answer is produced.
        $payload['thinking'] = ['type' => 'disabled'];
    }

    return $payload;
}

function runAIProviderLiveHealthCheck($provider, $apiKey, $modelName, array $providerMeta) {
    if (!function_exists('curl_init')) {
        return [
            'status' => 'connection_error',
            'message' => 'cURL extension is required for live provider health checks.',
            'http_code' => null,
            'latency_ms' => null,
            'checked_at' => date('c')
        ];
    }

    $requestBody = buildAIProviderHealthProbePayload($provider, $modelName);
    $serverHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
    $serverAddr = $_SERVER['SERVER_ADDR'] ?? '';
    $isLocalDev = (
        strpos($serverHost, 'localhost') !== false
        || strpos($serverHost, '127.0.0.1') !== false
        || strpos($serverHost, '192.168.') !== false
        || strpos($serverHost, '10.') !== false
        || strpos($serverAddr, '127.0.0.1') !== false
        || strpos($serverAddr, '::1') !== false
        || (isset($_SERVER['REMOTE_ADDR']) && in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1'], true))
    );
    $disableSSL = (PHP_OS_FAMILY === 'Windows') || $isLocalDev;

    $start = microtime(true);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $providerMeta['url']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody));
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 1);
    curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, 8);
    curl_setopt($ch, CURLOPT_ENCODING, '');
    curl_setopt($ch, CURLOPT_TCP_NODELAY, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !$disableSSL);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $disableSSL ? 0 : 2);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    $curlErrno = (int)curl_errno($ch);
    curl_close($ch);

    $latencyMs = (int)round((microtime(true) - $start) * 1000);
    $checkedAt = date('c');

    if ($error || $curlErrno) {
        $classified = classifyAIProviderHealthState(null, $error ?: curl_strerror($curlErrno), '', '', 'curl error');
        return [
            'status' => $classified['status'],
            'message' => $classified['message'],
            'http_code' => null,
            'latency_ms' => $latencyMs,
            'checked_at' => $checkedAt
        ];
    }

    if ($httpCode !== 200) {
        $errorMessage = substr((string)$response, 0, 300);
        $errorType = '';
        $errorCode = '';
        $decoded = json_decode((string)$response, true);
        if (isset($decoded['error'])) {
            $errorMessage = trim((string)($decoded['error']['message'] ?? $errorMessage));
            $errorType = trim((string)($decoded['error']['type'] ?? ''));
            $errorCode = trim((string)($decoded['error']['code'] ?? ''));
        }

        $classified = classifyAIProviderHealthState($httpCode, $errorMessage, $errorType, $errorCode, (string)$response);
        return [
            'status' => $classified['status'],
            'message' => $classified['message'],
            'http_code' => $httpCode,
            'latency_ms' => $latencyMs,
            'checked_at' => $checkedAt
        ];
    }

    $decoded = json_decode((string)$response, true);
    $content = trim((string)($decoded['choices'][0]['message']['content'] ?? ''));

    if ($content === '') {
        return [
            'status' => 'degraded',
            'message' => 'Live check reached the provider, but the response content was empty.',
            'http_code' => $httpCode,
            'latency_ms' => $latencyMs,
            'checked_at' => $checkedAt
        ];
    }

    return [
        'status' => 'healthy',
        'message' => 'Live provider check succeeded.',
        'http_code' => $httpCode,
        'latency_ms' => $latencyMs,
        'checked_at' => $checkedAt
    ];
}

/**
 * Get AI Chat Settings
 */
function handleGetAIChatSettings($db) {
    requireAdmin();
    
    try {
        // Ensure settings table exists with proper structure
        $db->exec("CREATE TABLE IF NOT EXISTS ai_chat_settings (
            id INT PRIMARY KEY DEFAULT 1,
            ai_provider VARCHAR(20) DEFAULT 'glm',
            model_name VARCHAR(120) DEFAULT 'glm-4.7-flash',
            openai_enabled TINYINT(1) DEFAULT 1,
            openai_reasoning_enabled TINYINT(1) DEFAULT 1,
            openai_reasoning_effort VARCHAR(20) DEFAULT 'medium',
            deepseek_enabled TINYINT(1) DEFAULT 1,
            deepseek_auto_profile_enabled TINYINT(1) DEFAULT 1,
            qwen_enabled TINYINT(1) DEFAULT 1,
            glm_enabled TINYINT(1) DEFAULT 1,
            glm_auto_profile_enabled TINYINT(1) DEFAULT 1,
            max_tokens_per_request INT DEFAULT 600,
            temperature DECIMAL(3,2) DEFAULT 0.8,
            requests_per_day INT DEFAULT 50,
            requests_per_hour INT DEFAULT 10,
            enabled TINYINT(1) DEFAULT 1,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            updated_by INT DEFAULT NULL COMMENT 'Admin user ID who last updated'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        $stmt = $db->prepare("SELECT * FROM ai_chat_settings WHERE id = 1");
        $stmt->execute();
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$settings) {
            // Insert defaults with all values
            $db->exec("INSERT INTO ai_chat_settings (id, ai_provider, model_name, openai_enabled, openai_reasoning_enabled, openai_reasoning_effort, deepseek_enabled, deepseek_auto_profile_enabled, qwen_enabled, glm_enabled, glm_auto_profile_enabled, max_tokens_per_request, temperature, requests_per_day, requests_per_hour, enabled) VALUES (1, 'glm', 'glm-4.7-flash', 1, 1, 'medium', 1, 1, 1, 1, 1, 600, 0.8, 50, 10, 1)");
            $stmt->execute();
            $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // Backfill newer columns for older schemas
        try {
            $db->exec("ALTER TABLE ai_chat_settings ADD COLUMN ai_provider VARCHAR(20) DEFAULT 'glm'");
        } catch (Exception $e) {
            // ignore
        }
        try {
            $db->exec("ALTER TABLE ai_chat_settings ADD COLUMN openai_enabled TINYINT(1) DEFAULT 1");
        } catch (Exception $e) {
            // ignore
        }
        try {
            $db->exec("ALTER TABLE ai_chat_settings ADD COLUMN openai_reasoning_enabled TINYINT(1) DEFAULT 1");
        } catch (Exception $e) {
            // ignore
        }
        try {
            $db->exec("ALTER TABLE ai_chat_settings ADD COLUMN openai_reasoning_effort VARCHAR(20) DEFAULT 'medium'");
        } catch (Exception $e) {
            // ignore
        }
        try {
            $db->exec("ALTER TABLE ai_chat_settings ADD COLUMN deepseek_enabled TINYINT(1) DEFAULT 1");
        } catch (Exception $e) {
            // ignore
        }
        try {
            $db->exec("ALTER TABLE ai_chat_settings ADD COLUMN deepseek_auto_profile_enabled TINYINT(1) DEFAULT 1");
        } catch (Exception $e) {
            // ignore
        }
        try {
            $db->exec("ALTER TABLE ai_chat_settings ADD COLUMN qwen_enabled TINYINT(1) DEFAULT 1");
        } catch (Exception $e) {
            // ignore
        }
        try {
            $db->exec("ALTER TABLE ai_chat_settings ADD COLUMN glm_enabled TINYINT(1) DEFAULT 1");
        } catch (Exception $e) {
            // ignore
        }
        try {
            $db->exec("ALTER TABLE ai_chat_settings ADD COLUMN glm_auto_profile_enabled TINYINT(1) DEFAULT 1");
        } catch (Exception $e) {
            // ignore
        }
        try {
            $db->exec("ALTER TABLE ai_chat_settings MODIFY COLUMN model_name VARCHAR(120) DEFAULT 'glm-4.7-flash'");
        } catch (Exception $e) {
            // ignore
        }

        $stmt = $db->prepare("SELECT * FROM ai_chat_settings WHERE id = 1");
        $stmt->execute();
        $settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        
        // Ensure all fields are properly typed and present
        $settings['ai_provider'] = $settings['ai_provider'] ?? 'glm';
        $settings['model_name'] = $settings['model_name'] ?? 'glm-4.7-flash';
        $settings['openai_enabled'] = (int)($settings['openai_enabled'] ?? 1);
        $settings['openai_reasoning_enabled'] = (int)($settings['openai_reasoning_enabled'] ?? 1);
        $settings['openai_reasoning_effort'] = $settings['openai_reasoning_effort'] ?? 'medium';
        $settings['deepseek_enabled'] = (int)($settings['deepseek_enabled'] ?? 1);
        $settings['deepseek_auto_profile_enabled'] = (int)($settings['deepseek_auto_profile_enabled'] ?? 1);
        $settings['qwen_enabled'] = (int)($settings['qwen_enabled'] ?? 1);
        $settings['glm_enabled'] = (int)($settings['glm_enabled'] ?? 1);
        $settings['glm_auto_profile_enabled'] = (int)($settings['glm_auto_profile_enabled'] ?? 1);
        $settings['max_tokens_per_request'] = (int)($settings['max_tokens_per_request'] ?? 600);
        $settings['temperature'] = (float)($settings['temperature'] ?? 0.8);
        $settings['requests_per_day'] = (int)($settings['requests_per_day'] ?? 50);
        $settings['requests_per_hour'] = (int)($settings['requests_per_hour'] ?? 10);
        $settings['enabled'] = (int)($settings['enabled'] ?? 1);
        
        echo json_encode([
            'success' => true,
            'settings' => $settings,
            'api_keys' => getAIProviderKeyMetadata($db),
            'can_manage_api_keys' => adminCanManageAIProviderSecrets($db)
        ]);
    } catch (Exception $e) {
        error_log("handleGetAIChatSettings error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to get AI chat settings']);
    }
}

function handleGetAIProviderHealth($db) {
    requireAdmin();

    try {
        $liveCheckRequested = isset($_GET['live_check']) && (string)$_GET['live_check'] === '1';
        $providerMeta = getAIProviderRuntimeDefinitions();
        $providerSecrets = getAIProviderSecretValues($db);
        $providerKeyMetadata = getAIProviderKeyMetadata($db);
        $logLines = getAdminApiLogTailLines(__DIR__ . '/../logs/api_errors.log');

        $settings = [];
        try {
            $stmt = $db->prepare("SELECT * FROM ai_chat_settings WHERE id = 1 LIMIT 1");
            $stmt->execute();
            $settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            $settings = [];
        }

        $preferredProvider = trim((string)($settings['ai_provider'] ?? 'glm'));
        if (!isset($providerMeta[$preferredProvider])) {
            $preferredProvider = 'glm';
        }

        $retryOrderRaw = getAIProviderRetryOrderSettingValue($db);
        $effectiveRetryOrder = parseAIProviderRetryOrderValue($retryOrderRaw, $preferredProvider);
        $providers = [];

        foreach ($providerMeta as $provider => $meta) {
            $enabled = (int)($settings[$provider . '_enabled'] ?? 1) === 1;
            $configured = trim((string)($providerSecrets[$provider] ?? '')) !== '';
            $modelName = $provider === $preferredProvider
                ? trim((string)($settings['model_name'] ?? $meta['default_model']))
                : $meta['default_model'];
            if ($modelName === '') {
                $modelName = $meta['default_model'];
            }

            $status = 'ready';
            $statusSource = 'configuration';
            $statusMessage = 'Configured and enabled. No recent provider errors were found in the latest API log window.';
            $lastEvent = null;
            $lastEventTime = null;
            $httpCode = null;
            $latencyMs = null;
            $checkedAt = null;

            $recentLogHealth = getRecentAIProviderLogHealth($provider, $logLines);
            if (!$enabled) {
                $status = 'disabled';
                $statusMessage = 'This provider is disabled in AI chat settings.';
            } elseif (!$configured) {
                $status = 'not_configured';
                $statusMessage = 'No API key is stored for this provider.';
            } elseif ($liveCheckRequested) {
                $liveResult = runAIProviderLiveHealthCheck($provider, $providerSecrets[$provider], $modelName, $meta);
                $status = $liveResult['status'];
                $statusSource = 'live_check';
                $statusMessage = $liveResult['message'];
                $httpCode = $liveResult['http_code'];
                $latencyMs = $liveResult['latency_ms'];
                $checkedAt = $liveResult['checked_at'];
            } elseif (is_array($recentLogHealth)) {
                $status = $recentLogHealth['status'];
                $statusSource = 'recent_log';
                $statusMessage = $recentLogHealth['message'];
                $lastEvent = $recentLogHealth['raw_line'];
                $lastEventTime = $recentLogHealth['timestamp'];
                $httpCode = $recentLogHealth['http_code'];
            }

            if ($lastEvent === null && is_array($recentLogHealth)) {
                $lastEvent = $recentLogHealth['raw_line'];
                $lastEventTime = $recentLogHealth['timestamp'];
            }

            $providers[$provider] = [
                'label' => $meta['label'],
                'enabled' => $enabled,
                'configured' => $configured,
                'active_provider' => $provider === $preferredProvider,
                'model_name' => $modelName,
                'default_model' => $meta['default_model'],
                'masked_key' => (string)($providerKeyMetadata[$provider]['masked'] ?? ''),
                'status' => $status,
                'status_source' => $statusSource,
                'status_message' => $statusMessage,
                'last_event' => $lastEvent,
                'last_event_time' => $lastEventTime,
                'http_code' => $httpCode,
                'latency_ms' => $latencyMs,
                'checked_at' => $checkedAt
            ];
        }

        $usableRetryOrder = [];
        foreach ($effectiveRetryOrder as $provider) {
            if (!empty($providers[$provider]['enabled']) && !empty($providers[$provider]['configured'])) {
                $usableRetryOrder[] = $provider;
            }
        }

        echo json_encode([
            'success' => true,
            'checked_live' => $liveCheckRequested,
            'current_provider' => $preferredProvider,
            'retry_order_raw' => $retryOrderRaw,
            'effective_retry_order' => $effectiveRetryOrder,
            'usable_retry_order' => $usableRetryOrder,
            'providers' => $providers
        ]);
    } catch (Exception $e) {
        error_log('handleGetAIProviderHealth error: ' . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Failed to load AI provider health: ' . $e->getMessage()
        ]);
    }
}

/**
 * Save AI Chat Settings
 */
function handleSaveAIChatSettings($db) {
    requireAdmin();
    
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $aiProvider = trim($data['ai_provider'] ?? 'openai');
        $modelName = trim($data['model_name'] ?? 'gpt-4o');
        $apiKeysInput = isset($data['api_keys']) && is_array($data['api_keys']) ? $data['api_keys'] : [];
        $openaiEnabled = isset($data['openai_enabled']) ? (int)$data['openai_enabled'] : 1;
        $openAIReasoningEnabled = isset($data['openai_reasoning_enabled']) ? (int)$data['openai_reasoning_enabled'] : 1;
        $openAIReasoningEffort = strtolower(trim((string)($data['openai_reasoning_effort'] ?? 'medium')));
        $deepseekEnabled = isset($data['deepseek_enabled']) ? (int)$data['deepseek_enabled'] : 1;
        $deepseekAutoProfileEnabled = isset($data['deepseek_auto_profile_enabled']) ? (int)$data['deepseek_auto_profile_enabled'] : 1;
        $qwenEnabled = isset($data['qwen_enabled']) ? (int)$data['qwen_enabled'] : 1;
        $glmEnabled = isset($data['glm_enabled']) ? (int)$data['glm_enabled'] : 1;
        $glmAutoProfileEnabled = isset($data['glm_auto_profile_enabled']) ? (int)$data['glm_auto_profile_enabled'] : 1;
        $maxTokens = (int)($data['max_tokens_per_request'] ?? 600);
        $temperature = (float)($data['temperature'] ?? 0.8);
        $requestsPerDay = (int)($data['requests_per_day'] ?? 50);
        $requestsPerHour = (int)($data['requests_per_hour'] ?? 10);
        $enabled = isset($data['enabled']) ? (int)$data['enabled'] : 1;

        $deepseekAutoProfileEnabled = $deepseekAutoProfileEnabled === 0 ? 0 : 1;
        $glmAutoProfileEnabled = $glmAutoProfileEnabled === 0 ? 0 : 1;

        // Validate provider/model
        $allowedProviders = ['openai', 'deepseek', 'qwen', 'glm'];
        if (!in_array($aiProvider, $allowedProviders, true)) {
            throw new Exception('Invalid AI provider. Allowed providers: ' . implode(', ', $allowedProviders));
        }
        if ($modelName === '' || strlen($modelName) > 120 || !preg_match('/^[a-zA-Z0-9._:-]+$/', $modelName)) {
            throw new Exception('Invalid model name format. Use letters, numbers, dot, underscore, dash, and colon only (max 120 chars).');
        }
        $allowedReasoningEfforts = ['minimal', 'low', 'medium', 'high'];
        if (!in_array($openAIReasoningEffort, $allowedReasoningEfforts, true)) {
            throw new Exception('OpenAI reasoning effort must be one of: ' . implode(', ', $allowedReasoningEfforts));
        }
        
        // Validate
        if ($maxTokens < 1 || $maxTokens > 4000) {
            throw new Exception('Max tokens must be between 1 and 4000');
        }
        if ($temperature < 0 || $temperature > 2) {
            throw new Exception('Temperature must be between 0 and 2');
        }
        if ($requestsPerDay < 1 || $requestsPerDay > 1000) {
            throw new Exception('Requests per day must be between 1 and 1000');
        }
        if ($requestsPerHour < 1 || $requestsPerHour > 100) {
            throw new Exception('Requests per hour must be between 1 and 100');
        }

        $apiKeyUpdate = buildAIProviderKeySettingsPayload($apiKeysInput);
        $siteSettingsPayload = $apiKeyUpdate['payload'];
        $updatedKeyProviders = $apiKeyUpdate['providers'];
        $canManageApiKeys = adminCanManageAIProviderSecrets($db);

        if (!empty($updatedKeyProviders) && !$canManageApiKeys) {
            sendAdminError('Only super admins can update AI provider API keys.', 'SUPER_ADMIN_REQUIRED', 403);
        }
        
        try {
            $db->exec("ALTER TABLE ai_chat_settings ADD COLUMN ai_provider VARCHAR(20) DEFAULT 'glm'");
        } catch (Exception $e) {
            // ignore
        }
        try {
            $db->exec("ALTER TABLE ai_chat_settings ADD COLUMN openai_enabled TINYINT(1) DEFAULT 1");
        } catch (Exception $e) {
            // ignore
        }
        try {
            $db->exec("ALTER TABLE ai_chat_settings ADD COLUMN openai_reasoning_enabled TINYINT(1) DEFAULT 1");
        } catch (Exception $e) {
            // ignore
        }
        try {
            $db->exec("ALTER TABLE ai_chat_settings ADD COLUMN openai_reasoning_effort VARCHAR(20) DEFAULT 'medium'");
        } catch (Exception $e) {
            // ignore
        }
        try {
            $db->exec("ALTER TABLE ai_chat_settings ADD COLUMN deepseek_enabled TINYINT(1) DEFAULT 1");
        } catch (Exception $e) {
            // ignore
        }
        try {
            $db->exec("ALTER TABLE ai_chat_settings ADD COLUMN deepseek_auto_profile_enabled TINYINT(1) DEFAULT 1");
        } catch (Exception $e) {
            // ignore
        }
        try {
            $db->exec("ALTER TABLE ai_chat_settings ADD COLUMN qwen_enabled TINYINT(1) DEFAULT 1");
        } catch (Exception $e) {
            // ignore
        }
        try {
            $db->exec("ALTER TABLE ai_chat_settings ADD COLUMN glm_enabled TINYINT(1) DEFAULT 1");
        } catch (Exception $e) {
            // ignore
        }
        try {
            $db->exec("ALTER TABLE ai_chat_settings ADD COLUMN glm_auto_profile_enabled TINYINT(1) DEFAULT 1");
        } catch (Exception $e) {
            // ignore
        }
        try {
            $db->exec("ALTER TABLE ai_chat_settings MODIFY COLUMN model_name VARCHAR(120) DEFAULT 'glm-4.7-flash'");
        } catch (Exception $e) {
            // ignore
        }

        // Get admin ID for logging (optional field)
        $adminId = $_SESSION['admin_id'] ?? null;
        
        // Try to add updated_by column if it doesn't exist (migration)
        try {
            $db->exec("ALTER TABLE ai_chat_settings ADD COLUMN updated_by INT DEFAULT NULL COMMENT 'Admin user ID who last updated'");
        } catch (Exception $e) {
            // Column already exists or table doesn't exist yet - ignore
            if (strpos($e->getMessage(), 'Duplicate column name') === false && strpos($e->getMessage(), "doesn't exist") === false) {
                error_log("Warning: Could not add updated_by column: " . $e->getMessage());
            }
        }
        
        // Check if updated_by column exists now
        try {
            $checkColumn = $db->query("SHOW COLUMNS FROM ai_chat_settings LIKE 'updated_by'");
            $hasUpdatedBy = $checkColumn && $checkColumn->rowCount() > 0;
        } catch (Exception $e) {
            $hasUpdatedBy = false;
        }

        ensureAIProviderSiteSettingsRows($db);
        $db->beginTransaction();
        
        if ($hasUpdatedBy) {
            // Table has updated_by column
            $stmt = $db->prepare("
                INSERT INTO ai_chat_settings 
                (id, ai_provider, model_name, openai_enabled, openai_reasoning_enabled, openai_reasoning_effort, deepseek_enabled, deepseek_auto_profile_enabled, qwen_enabled, glm_enabled, glm_auto_profile_enabled, max_tokens_per_request, temperature, requests_per_day, requests_per_hour, enabled, updated_by)
                VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                ai_provider = VALUES(ai_provider),
                model_name = VALUES(model_name),
                openai_enabled = VALUES(openai_enabled),
                openai_reasoning_enabled = VALUES(openai_reasoning_enabled),
                openai_reasoning_effort = VALUES(openai_reasoning_effort),
                deepseek_enabled = VALUES(deepseek_enabled),
                deepseek_auto_profile_enabled = VALUES(deepseek_auto_profile_enabled),
                qwen_enabled = VALUES(qwen_enabled),
                glm_enabled = VALUES(glm_enabled),
                glm_auto_profile_enabled = VALUES(glm_auto_profile_enabled),
                max_tokens_per_request = VALUES(max_tokens_per_request),
                temperature = VALUES(temperature),
                requests_per_day = VALUES(requests_per_day),
                requests_per_hour = VALUES(requests_per_hour),
                enabled = VALUES(enabled),
                updated_by = VALUES(updated_by),
                updated_at = NOW()
            ");
            $result = $stmt->execute([$aiProvider, $modelName, $openaiEnabled, $openAIReasoningEnabled, $openAIReasoningEffort, $deepseekEnabled, $deepseekAutoProfileEnabled, $qwenEnabled, $glmEnabled, $glmAutoProfileEnabled, $maxTokens, $temperature, $requestsPerDay, $requestsPerHour, $enabled, $adminId]);
        } else {
            // Table doesn't have updated_by column (older schema)
            $stmt = $db->prepare("
                INSERT INTO ai_chat_settings 
                (id, ai_provider, model_name, openai_enabled, openai_reasoning_enabled, openai_reasoning_effort, deepseek_enabled, deepseek_auto_profile_enabled, qwen_enabled, glm_enabled, glm_auto_profile_enabled, max_tokens_per_request, temperature, requests_per_day, requests_per_hour, enabled)
                VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                ai_provider = VALUES(ai_provider),
                model_name = VALUES(model_name),
                openai_enabled = VALUES(openai_enabled),
                openai_reasoning_enabled = VALUES(openai_reasoning_enabled),
                openai_reasoning_effort = VALUES(openai_reasoning_effort),
                deepseek_enabled = VALUES(deepseek_enabled),
                deepseek_auto_profile_enabled = VALUES(deepseek_auto_profile_enabled),
                qwen_enabled = VALUES(qwen_enabled),
                glm_enabled = VALUES(glm_enabled),
                glm_auto_profile_enabled = VALUES(glm_auto_profile_enabled),
                max_tokens_per_request = VALUES(max_tokens_per_request),
                temperature = VALUES(temperature),
                requests_per_day = VALUES(requests_per_day),
                requests_per_hour = VALUES(requests_per_hour),
                enabled = VALUES(enabled),
                updated_at = NOW()
            ");
            $result = $stmt->execute([$aiProvider, $modelName, $openaiEnabled, $openAIReasoningEnabled, $openAIReasoningEffort, $deepseekEnabled, $deepseekAutoProfileEnabled, $qwenEnabled, $glmEnabled, $glmAutoProfileEnabled, $maxTokens, $temperature, $requestsPerDay, $requestsPerHour, $enabled]);
        }
        
        if (!$result) {
            throw new Exception('Failed to execute database update');
        }

        if (!empty($siteSettingsPayload)) {
            motorlink_upsert_site_settings($db, $siteSettingsPayload);
        }
        
        // Verify the save by reading back from database
        $verifyStmt = $db->prepare("SELECT * FROM ai_chat_settings WHERE id = 1");
        $verifyStmt->execute();
        $savedSettings = $verifyStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$savedSettings) {
            throw new Exception('Settings were not saved correctly');
        }

        $db->commit();
        
        // Log the change
        error_log("AI Chat Settings updated by admin ID {$adminId}: provider={$aiProvider}, model={$modelName}, reasoning_enabled={$openAIReasoningEnabled}, reasoning_effort={$openAIReasoningEffort}, deepseek_auto_profile_enabled={$deepseekAutoProfileEnabled}, glm_auto_profile_enabled={$glmAutoProfileEnabled}, max_tokens={$maxTokens}, temp={$temperature}, daily_limit={$requestsPerDay}, hourly_limit={$requestsPerHour}, enabled={$enabled}, updated_api_keys=" . implode(',', $updatedKeyProviders));
        
        echo json_encode([
            'success' => true, 
            'message' => 'AI chat settings saved successfully. Changes take effect immediately for new requests.',
            'settings' => [
                'ai_provider' => $savedSettings['ai_provider'],
                'model_name' => $savedSettings['model_name'],
                'openai_enabled' => (int)($savedSettings['openai_enabled'] ?? 1),
                'openai_reasoning_enabled' => (int)($savedSettings['openai_reasoning_enabled'] ?? 1),
                'openai_reasoning_effort' => (string)($savedSettings['openai_reasoning_effort'] ?? 'medium'),
                'deepseek_enabled' => (int)($savedSettings['deepseek_enabled'] ?? 1),
                'deepseek_auto_profile_enabled' => (int)($savedSettings['deepseek_auto_profile_enabled'] ?? 1),
                'qwen_enabled' => (int)($savedSettings['qwen_enabled'] ?? 1),
                'glm_enabled' => (int)($savedSettings['glm_enabled'] ?? 1),
                'glm_auto_profile_enabled' => (int)($savedSettings['glm_auto_profile_enabled'] ?? 1),
                'max_tokens_per_request' => (int)$savedSettings['max_tokens_per_request'],
                'temperature' => (float)$savedSettings['temperature'],
                'requests_per_day' => (int)$savedSettings['requests_per_day'],
                'requests_per_hour' => (int)$savedSettings['requests_per_hour'],
                'enabled' => (int)$savedSettings['enabled']
            ],
            'api_keys' => getAIProviderKeyMetadata($db),
            'can_manage_api_keys' => $canManageApiKeys,
            'api_keys_updated' => $updatedKeyProviders
        ]);
    } catch (Exception $e) {
        if ($db instanceof PDO && $db->inTransaction()) {
            $db->rollBack();
        }
        error_log("handleSaveAIChatSettings error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to save settings: ' . $e->getMessage()]);
    }
}

/**
 * Get AI Chat Usage Logs
 */
function handleGetAIChatUsage($db) {
    requireAdmin();
    
    try {
        try {
            $db->exec("ALTER TABLE ai_chat_usage ADD COLUMN tuning_profile VARCHAR(80) DEFAULT 'generic_payload' AFTER model_used");
        } catch (Exception $e) {
            // ignore
        }

        $page = (int)($_GET['page'] ?? 1);
        $limit = (int)($_GET['limit'] ?? 50);
        $userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;
        $startDate = $_GET['start_date'] ?? null;
        $endDate = $_GET['end_date'] ?? null;
        
        $offset = ($page - 1) * $limit;
        
        $where = [];
        $params = [];
        
        if ($userId) {
            $where[] = "u.user_id = ?";
            $params[] = $userId;
        }
        
        if ($startDate) {
            $where[] = "DATE(u.created_at) >= ?";
            $params[] = $startDate;
        }
        
        if ($endDate) {
            $where[] = "DATE(u.created_at) <= ?";
            $params[] = $endDate;
        }
        
        $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
        
        // Get total count
        $countStmt = $db->prepare("
            SELECT COUNT(*) as total 
            FROM ai_chat_usage u
            $whereClause
        ");
        $countStmt->execute($params);
        $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Get usage logs with user info
        $stmt = $db->prepare("
            SELECT 
                u.*,
                us.username,
                us.email,
                us.user_type
            FROM ai_chat_usage u
            LEFT JOIN users us ON u.user_id = us.id
            $whereClause
            ORDER BY u.created_at DESC
            LIMIT ? OFFSET ?
        ");
        
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get summary stats
        $statsStmt = $db->prepare("
            SELECT 
                COUNT(*) as total_requests,
                SUM(tokens_used) as total_tokens,
                SUM(cost_estimate) as total_cost,
                COUNT(DISTINCT user_id) as unique_users
            FROM ai_chat_usage u
            $whereClause
        ");
        $statsStmt->execute(array_slice($params, 0, -2)); // Remove limit and offset
        $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'logs' => $logs,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => (int)$total,
                'pages' => ceil($total / $limit)
            ],
            'stats' => $stats
        ]);
    } catch (Exception $e) {
        error_log("handleGetAIChatUsage error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to get usage logs']);
    }
}

/**
 * Get list of users who have used AI chat
 */
function handleGetAIChatUsers($db) {
    requireAdmin();
    
    try {
        $stmt = $db->prepare("
            SELECT 
                u.id,
                u.username,
                u.email,
                u.user_type,
                COUNT(acu.id) as usage_count,
                SUM(acu.tokens_used) as total_tokens,
                SUM(acu.cost_estimate) as total_cost
            FROM users u
            INNER JOIN ai_chat_usage acu ON u.id = acu.user_id
            GROUP BY u.id, u.username, u.email, u.user_type
            ORDER BY usage_count DESC, u.username ASC
        ");
        
        $stmt->execute();
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'users' => $users
        ]);
    } catch (Exception $e) {
        error_log("handleGetAIChatUsers error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to get users']);
    }
}

/**
 * Get AI chat restriction for a specific user
 */
function handleGetUserAIRestriction($db) {
    requireAdmin();
    
    try {
        $userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
        
        if ($userId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Valid user ID required']);
            exit();
        }
        
        $stmt = $db->prepare("
            SELECT r.*, 
                   u.username, u.email, u.full_name,
                   a.full_name as disabled_by_name
            FROM ai_chat_user_restrictions r
            INNER JOIN users u ON r.user_id = u.id
            LEFT JOIN admin_users a ON r.disabled_by = a.id
            WHERE r.user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $restriction = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'restriction' => $restriction ?: null
        ]);
    } catch (Exception $e) {
        error_log("handleGetUserAIRestriction error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to get restriction']);
    }
}

/**
 * Set AI chat restriction for a user (enable/disable)
 */
function handleSetUserAIRestriction($db) {
    requireAdmin();
    
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $userId = isset($input['user_id']) ? (int)$input['user_id'] : 0;
        $disabled = isset($input['disabled']) ? (int)$input['disabled'] : 0;
        $reason = isset($input['reason']) ? trim($input['reason']) : '';
        $adminId = $_SESSION['admin_id'] ?? null;
        
        if ($userId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Valid user ID required']);
            exit();
        }
        
        // Verify user exists
        $userStmt = $db->prepare("SELECT id, username, email FROM users WHERE id = ?");
        $userStmt->execute([$userId]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'User not found']);
            exit();
        }
        
        // Check if restriction already exists
        $checkStmt = $db->prepare("SELECT id FROM ai_chat_user_restrictions WHERE user_id = ?");
        $checkStmt->execute([$userId]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            // Update existing restriction
            $updateStmt = $db->prepare("
                UPDATE ai_chat_user_restrictions
                SET disabled = ?,
                    reason = ?,
                    disabled_by = ?,
                    disabled_at = CASE WHEN ? = 1 THEN COALESCE(disabled_at, NOW()) ELSE NULL END,
                    enabled_at = CASE WHEN ? = 0 THEN NOW() ELSE NULL END,
                    updated_at = NOW()
                WHERE user_id = ?
            ");
            $updateStmt->execute([$disabled, $reason, $adminId, $disabled, $disabled, $userId]);
        } else {
            // Insert new restriction
            $insertStmt = $db->prepare("
                INSERT INTO ai_chat_user_restrictions
                (user_id, disabled, reason, disabled_by, disabled_at, enabled_at)
                VALUES (?, ?, ?, ?, 
                    CASE WHEN ? = 1 THEN NOW() ELSE NULL END,
                    CASE WHEN ? = 0 THEN NOW() ELSE NULL END
                )
            ");
            $insertStmt->execute([$userId, $disabled, $reason, $adminId, $disabled, $disabled]);
        }
        
        // Log activity
        $action = $disabled ? 'disabled' : 'enabled';
        $activityDetails = sprintf(
            'User: %s (%s) - %s',
            $user['username'] ?? 'N/A',
            $user['email'] ?? 'N/A',
            $action
        );
        if ($reason) {
            $activityDetails .= " - Reason: {$reason}";
        }
        
        logActivity($db, 'ai_chat_restriction', "AI chat {$action} for user", $activityDetails, $adminId);
        
        echo json_encode([
            'success' => true,
            'message' => "AI chat access " . ($disabled ? 'disabled' : 'enabled') . " for user successfully"
        ]);
    } catch (Exception $e) {
        error_log("handleSetUserAIRestriction error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to update restriction: ' . $e->getMessage()]);
    }
}



/**
 * Trigger AI Web Cache Learning
 */
function handleTriggerAIWebLearning($db) {
    requireAdmin();
    
    // Increase execution time limit for learning operations
    set_time_limit(300); // 5 minutes
    
    try {
        $filePath = __DIR__ . '/../ai-learning-api.php';
        if (!file_exists($filePath)) {
            throw new Exception("AI learning API file not found: " . $filePath);
        }
        
        require_once $filePath;
        
        if (!function_exists('learnWebCacheTopics')) {
            throw new Exception("Function learnWebCacheTopics not found after including ai-learning-api.php");
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $count = isset($input['count']) ? (int)$input['count'] : 20;
        $provider = isset($input['provider']) ? trim($input['provider']) : 'auto';
        $model = isset($input['model']) ? trim((string)$input['model']) : null;
        
        $result = learnWebCacheTopics($db, $count, $provider, $model);
        
        if ($result['success']) {
            logActivity($db, 'ai_learning', 'AI Web Cache Learning', "Learned {$result['learned']} topics", $_SESSION['admin_id'] ?? null);
        }
        
        echo json_encode($result);
    } catch (Exception $e) {
        error_log("handleTriggerAIWebLearning error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to trigger learning: ' . $e->getMessage()]);
    } catch (Error $e) {
        error_log("handleTriggerAIWebLearning fatal error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Fatal error: ' . $e->getMessage()]);
    }
}

/**
 * Trigger AI Parts Cache Learning
 */
function handleTriggerAIPartsLearning($db) {
    requireAdmin();
    
    // Increase execution time limit for learning operations
    set_time_limit(600); // 10 minutes for parts learning (can be many items)
    
    try {
        $filePath = __DIR__ . '/../ai-learning-api.php';
        if (!file_exists($filePath)) {
            throw new Exception("AI learning API file not found: " . $filePath);
        }
        
        require_once $filePath;
        
        if (!function_exists('learnPartsCacheTopics')) {
            throw new Exception("Function learnPartsCacheTopics not found after including ai-learning-api.php");
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $count = isset($input['count']) ? (int)$input['count'] : 500;
        $provider = isset($input['provider']) ? trim($input['provider']) : 'auto';
        $model = isset($input['model']) ? trim((string)$input['model']) : null;
        
        $result = learnPartsCacheTopics($db, $count, $provider, $model);
        
        if ($result['success']) {
            logActivity($db, 'ai_learning', 'AI Parts Cache Learning', "Learned {$result['learned']} parts", $_SESSION['admin_id'] ?? null);
        }
        
        echo json_encode($result);
    } catch (Exception $e) {
        error_log("handleTriggerAIPartsLearning error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to trigger learning: ' . $e->getMessage()]);
    } catch (Error $e) {
        error_log("handleTriggerAIPartsLearning fatal error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Fatal error: ' . $e->getMessage()]);
    }
}

/**
 * Get AI Learning Settings
 */
function handleGetAILearningSettings($db) {
    requireAdmin();
    
    try {
        require_once __DIR__ . '/../ai-learning-api.php';
        
        $settings = getAILearningSettings($db);
        $telemetry = function_exists('getLearningTelemetryCounters')
            ? getLearningTelemetryCounters($db)
            : [
                'date' => date('Y-m-d'),
                'total' => ['attempts' => 0, 'success' => 0, 'skipped_limit' => 0, 'errors' => 0],
                'today' => ['attempts' => 0, 'success' => 0, 'skipped_limit' => 0, 'errors' => 0]
            ];
        
        // Also get current stats
        $stmt = $db->query("SELECT COUNT(*) as total, 
            (SELECT COUNT(*) FROM ai_web_cache WHERE DATE(created_at) = CURDATE()) as web_today,
            (SELECT COUNT(*) FROM ai_parts_cache WHERE DATE(created_at) = CURDATE()) as parts_today
            FROM ai_web_cache");
        $webStats = $stmt->fetch();
        
        $stmt = $db->query("SELECT COUNT(*) as total FROM ai_parts_cache");
        $partsStats = $stmt->fetch();
        
        echo json_encode([
            'success' => true,
            'settings' => $settings,
            'stats' => [
                'web_cache' => [
                    'total' => (int)$webStats['total'],
                    'today' => (int)$webStats['web_today'],
                    'limit' => $settings['web_cache_limit']
                ],
                'parts_cache' => [
                    'total' => (int)$partsStats['total'],
                    'today' => (int)$webStats['parts_today'],
                    'limit' => $settings['parts_cache_limit']
                ],
                'telemetry' => $telemetry
            ]
        ]);
    } catch (Exception $e) {
        error_log("handleGetAILearningSettings error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to get settings: ' . $e->getMessage()]);
    }
}

/**
 * Save AI Learning Settings
 */
function handleSaveAILearningSettings($db) {
    requireAdmin();
    
    try {
        require_once __DIR__ . '/../ai-learning-api.php';
        if (function_exists('ensureAILearningSchema')) {
            ensureAILearningSchema($db);
        }

        $input = json_decode(file_get_contents('php://input'), true);
        
        $aiProvider = isset($input['ai_provider']) ? trim($input['ai_provider']) : 'glm';
        $learningModelName = trim((string)($input['learning_model_name'] ?? ''));
        $webCacheLimit = isset($input['web_cache_limit']) ? (int)$input['web_cache_limit'] : 20;
        $partsCacheLimit = isset($input['parts_cache_limit']) ? (int)$input['parts_cache_limit'] : 500;
        
        // Validate
        if (!in_array($aiProvider, ['openai', 'deepseek', 'qwen', 'glm', 'auto'], true)) {
            throw new Exception('Invalid AI provider. Must be openai, deepseek, qwen, glm, or auto');
        }
        if ($learningModelName !== '' && (strlen($learningModelName) > 120 || !preg_match('/^[a-zA-Z0-9._:-]+$/', $learningModelName))) {
            throw new Exception('Invalid learning model name format. Use letters, numbers, dot, underscore, dash, and colon only (max 120 chars).');
        }
        if ($webCacheLimit < 1 || $webCacheLimit > 1000) {
            throw new Exception('Web cache limit must be between 1 and 1000');
        }
        if ($partsCacheLimit < 1 || $partsCacheLimit > 5000) {
            throw new Exception('Parts cache limit must be between 1 and 5000');
        }
        
        // Try to add columns if they don't exist
        try {
            $db->exec("ALTER TABLE ai_chat_settings ADD COLUMN ai_provider VARCHAR(20) DEFAULT 'glm'");
        } catch (Exception $e) {
            // Column exists, ignore
        }
        try {
            $db->exec("ALTER TABLE ai_chat_settings ADD COLUMN learning_ai_provider VARCHAR(20) DEFAULT 'auto'");
        } catch (Exception $e) {
            // Column exists, ignore
        }
        try {
            $db->exec("ALTER TABLE ai_chat_settings ADD COLUMN learning_model_name VARCHAR(120) DEFAULT 'glm-4.7-flash'");
        } catch (Exception $e) {
            // Column exists, ignore
        }
        try {
            $db->exec("ALTER TABLE ai_chat_settings ADD COLUMN web_cache_daily_limit INT DEFAULT 20");
        } catch (Exception $e) {
            // Column exists, ignore
        }
        try {
            $db->exec("ALTER TABLE ai_chat_settings ADD COLUMN parts_cache_daily_limit INT DEFAULT 500");
        } catch (Exception $e) {
            // Column exists, ignore
        }

        $db->prepare("
            INSERT INTO ai_chat_settings (
                id, ai_provider, model_name, openai_enabled, deepseek_enabled, qwen_enabled, glm_enabled,
                learning_ai_provider, learning_model_name, web_cache_daily_limit, parts_cache_daily_limit, enabled
            )
            VALUES (1, 'glm', 'glm-4.7-flash', 1, 1, 1, 1, 'glm', 'glm-4.7-flash', 20, 500, 1)
            ON DUPLICATE KEY UPDATE id = id
        ")->execute();
        
        // Update settings
        $stmt = $db->prepare("
            UPDATE ai_chat_settings 
            SET learning_ai_provider = ?,
                learning_model_name = ?,
                web_cache_daily_limit = ?,
                parts_cache_daily_limit = ?,
                updated_at = NOW(),
                updated_by = ?
            WHERE id = 1
        ");
        $stmt->execute([
            $aiProvider,
            $learningModelName !== '' ? $learningModelName : null,
            $webCacheLimit,
            $partsCacheLimit,
            $_SESSION['admin_id'] ?? null
        ]);
        
        logActivity($db, 'settings_update', 'AI Learning Settings Updated', 
            "Provider: {$aiProvider}, Model: " . ($learningModelName !== '' ? $learningModelName : 'provider-default') . ", Web Limit: {$webCacheLimit}, Parts Limit: {$partsCacheLimit}", 
            $_SESSION['admin_id'] ?? null);
        
        echo json_encode(['success' => true, 'message' => 'Settings saved successfully']);
    } catch (Exception $e) {
        error_log("handleSaveAILearningSettings error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to save settings: ' . $e->getMessage()]);
    }
}

/**
 * Get AI Learning Stats
 */
function handleGetAILearningStats($db) {
    requireAdmin();
    
    try {
        require_once __DIR__ . '/../ai-learning-api.php';

        $stmt = $db->query("
            SELECT 
                COUNT(*) as total,
                COUNT(DISTINCT DATE(created_at)) as days_active,
                MAX(created_at) as last_learned
            FROM ai_web_cache
        ");
        $webStats = $stmt->fetch();
        
        $stmt = $db->query("
            SELECT 
                COUNT(*) as total,
                COUNT(DISTINCT DATE(created_at)) as days_active,
                MAX(created_at) as last_learned
            FROM ai_parts_cache
        ");
        $partsStats = $stmt->fetch();
        
        $stmt = $db->query("
            SELECT COUNT(*) as today 
            FROM ai_web_cache 
            WHERE DATE(created_at) = CURDATE()
        ");
        $webToday = $stmt->fetch();
        
        $stmt = $db->query("
            SELECT COUNT(*) as today 
            FROM ai_parts_cache 
            WHERE DATE(created_at) = CURDATE()
        ");
        $partsToday = $stmt->fetch();

        $telemetry = function_exists('getLearningTelemetryCounters')
            ? getLearningTelemetryCounters($db)
            : [
                'date' => date('Y-m-d'),
                'total' => ['attempts' => 0, 'success' => 0, 'skipped_limit' => 0, 'errors' => 0],
                'today' => ['attempts' => 0, 'success' => 0, 'skipped_limit' => 0, 'errors' => 0]
            ];
        
        echo json_encode([
            'success' => true,
            'stats' => [
                'web_cache' => [
                    'total' => (int)$webStats['total'],
                    'today' => (int)$webToday['today'],
                    'days_active' => (int)$webStats['days_active'],
                    'last_learned' => $webStats['last_learned']
                ],
                'parts_cache' => [
                    'total' => (int)$partsStats['total'],
                    'today' => (int)$partsToday['today'],
                    'days_active' => (int)$partsStats['days_active'],
                    'last_learned' => $partsStats['last_learned']
                ],
                'telemetry' => $telemetry
            ]
        ]);
    } catch (Exception $e) {
        error_log("handleGetAILearningStats error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to get stats: ' . $e->getMessage()]);
    }
}

/**
 * One-click global AI chat enable/disable
 */
function handleSetAIChatEnabled($db) {
    requireAdmin();

    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $enabled = isset($input['enabled']) && (int)$input['enabled'] === 1 ? 1 : 0;

        // Ensure settings table exists (same schema as other AI settings handlers).
        $db->exec("CREATE TABLE IF NOT EXISTS ai_chat_settings (
            id INT PRIMARY KEY DEFAULT 1,
            ai_provider VARCHAR(20) DEFAULT 'glm',
            model_name VARCHAR(120) DEFAULT 'glm-4.7-flash',
            openai_enabled TINYINT(1) DEFAULT 1,
            openai_reasoning_enabled TINYINT(1) DEFAULT 1,
            openai_reasoning_effort VARCHAR(20) DEFAULT 'medium',
            deepseek_enabled TINYINT(1) DEFAULT 1,
            deepseek_auto_profile_enabled TINYINT(1) DEFAULT 1,
            qwen_enabled TINYINT(1) DEFAULT 1,
            glm_enabled TINYINT(1) DEFAULT 1,
            glm_auto_profile_enabled TINYINT(1) DEFAULT 1,
            max_tokens_per_request INT DEFAULT 600,
            temperature DECIMAL(3,2) DEFAULT 0.8,
            requests_per_day INT DEFAULT 50,
            requests_per_hour INT DEFAULT 10,
            enabled TINYINT(1) DEFAULT 1,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            updated_by INT DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $adminId = $_SESSION['admin_id'] ?? null;

        $stmt = $db->prepare("\n            INSERT INTO ai_chat_settings (id, enabled, updated_by)\n            VALUES (1, ?, ?)\n            ON DUPLICATE KEY UPDATE\n                enabled = VALUES(enabled),\n                updated_by = VALUES(updated_by),\n                updated_at = NOW()\n        ");
        $stmt->execute([$enabled, $adminId]);

        echo json_encode([
            'success' => true,
            'enabled' => $enabled,
            'message' => $enabled
                ? 'AI chat has been enabled globally for all users.'
                : 'AI chat has been disabled globally for all users.'
        ]);
    } catch (Exception $e) {
        error_log("handleSetAIChatEnabled error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to update global AI chat status']);
    }
}

/**
 * Fetch admin DB credentials from site_settings for admin settings UI.
 * Password value is not returned; only whether it is configured.
 */
function handleGetAdminDbCredentials($db) {
    requireSuperAdmin($db);

    try {
        $keys = ['admin_db_host', 'admin_db_user', 'admin_db_pass', 'admin_db_name'];
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $db->prepare("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ($placeholders)");
        $stmt->execute($keys);

        $values = [
            'admin_db_host' => '',
            'admin_db_user' => '',
            'admin_db_pass' => '',
            'admin_db_name' => ''
        ];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $values[$row['setting_key']] = (string)($row['setting_value'] ?? '');
        }

        echo json_encode([
            'success' => true,
            'credentials' => [
                'host' => $values['admin_db_host'],
                'user' => $values['admin_db_user'],
                'name' => $values['admin_db_name'],
                'has_password' => $values['admin_db_pass'] !== ''
            ]
        ]);
        exit();
    } catch (Exception $e) {
        error_log('handleGetAdminDbCredentials error: ' . $e->getMessage());
        sendAdminError('Failed to load admin DB credentials', 'ADMIN_DB_CREDENTIALS_LOAD_FAILED', 500);
    }
}

/**
 * Test admin DB credentials without persisting.
 */
function handleTestAdminDbCredentials($db) {
    requireSuperAdmin($db);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendAdminError('POST method required', 'INVALID_METHOD', 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $host = trim((string)($input['host'] ?? ''));
    $user = trim((string)($input['user'] ?? ''));
    $name = trim((string)($input['name'] ?? ''));
    $password = (string)($input['password'] ?? '');

    if ($host === '' || $user === '' || $name === '') {
        sendAdminError('Host, username, and database name are required', 'ADMIN_DB_TEST_INPUT_REQUIRED', 400);
    }

    if ($password === '') {
        try {
            $stmt = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'admin_db_pass' LIMIT 1");
            $stmt->execute();
            $password = (string)($stmt->fetchColumn() ?: '');
        } catch (Exception $e) {
            // Keep empty and fail with clear error below.
        }
    }

    if ($password === '') {
        sendAdminError('Password is required for test connection', 'ADMIN_DB_TEST_PASSWORD_REQUIRED', 400);
    }

    try {
        $testDb = new PDO(
            "mysql:host={$host};dbname={$name};charset=utf8mb4",
            $user,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 8
            ]
        );

        $version = $testDb->query('SELECT VERSION() as v')->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'message' => 'Connection successful',
            'meta' => [
                'server_version' => $version['v'] ?? 'unknown'
            ]
        ]);
        exit();
    } catch (Exception $e) {
        error_log('handleTestAdminDbCredentials error: ' . $e->getMessage());
        sendAdminError('Connection failed. Please verify host, database, username, and password.', 'ADMIN_DB_TEST_FAILED', 400);
    }
}

/**
 * Save admin DB credentials in site_settings.
 */
function handleSaveAdminDbCredentials($db) {
    requireSuperAdmin($db);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendAdminError('POST method required', 'INVALID_METHOD', 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $host = trim((string)($input['host'] ?? ''));
    $user = trim((string)($input['user'] ?? ''));
    $name = trim((string)($input['name'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $skipTest = !empty($input['skip_test']);

    if ($host === '' || $user === '' || $name === '') {
        sendAdminError('Host, username, and database name are required', 'ADMIN_DB_SAVE_INPUT_REQUIRED', 400);
    }

    if (!$skipTest) {
        $testPassword = $password;
        if ($testPassword === '') {
            $stmt = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'admin_db_pass' LIMIT 1");
            $stmt->execute();
            $testPassword = (string)($stmt->fetchColumn() ?: '');
        }

        if ($testPassword === '') {
            sendAdminError('Password is required to verify credentials before save', 'ADMIN_DB_SAVE_PASSWORD_REQUIRED', 400);
        }

        try {
            $verify = new PDO(
                "mysql:host={$host};dbname={$name};charset=utf8mb4",
                $user,
                $testPassword,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_TIMEOUT => 8
                ]
            );
            $verify->query('SELECT 1');
        } catch (Exception $e) {
            error_log('handleSaveAdminDbCredentials test failed: ' . $e->getMessage());
            sendAdminError('Connection test failed. Credentials were not saved.', 'ADMIN_DB_SAVE_TEST_FAILED', 400);
        }
    }

    try {
        $values = [
            'admin_db_host' => $host,
            'admin_db_user' => $user,
            'admin_db_name' => $name
        ];

        if ($password !== '') {
            $values['admin_db_pass'] = $password;
        }

        $db->beginTransaction();

        foreach ($values as $key => $value) {
            $stmt = $db->prepare("INSERT INTO site_settings (setting_key, setting_value, setting_group, setting_type, description, is_public)
                                  VALUES (?, ?, 'security', 'string', ?, 0)
                                  ON DUPLICATE KEY UPDATE
                                  setting_value = VALUES(setting_value),
                                  setting_group = VALUES(setting_group),
                                  setting_type = VALUES(setting_type),
                                  description = VALUES(description),
                                  is_public = VALUES(is_public)");
            $stmt->execute([$key, $value, 'Admin DB credential setting: ' . $key]);
        }

        $db->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Admin DB credentials saved successfully. New connections will use these settings.',
            'password_updated' => $password !== ''
        ]);
        exit();
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('handleSaveAdminDbCredentials error: ' . $e->getMessage());
        sendAdminError('Failed to save admin DB credentials', 'ADMIN_DB_SAVE_FAILED', 500);
    }
}

/**
 * Footer support setting definitions used for admin load/save validation.
 */
function getFooterSupportSettingDefinitions() {
    return [
        'footer_support_help_label' => [
            'default' => 'Help Center',
            'group' => 'footer',
            'type' => 'string',
            'description' => 'Footer support link label: Help Center',
            'is_public' => 1
        ],
        'footer_support_help_href' => [
            'default' => 'help.html#top',
            'group' => 'footer',
            'type' => 'url',
            'description' => 'Footer support link target: Help Center',
            'is_public' => 1
        ],
        'footer_support_help_type' => [
            'default' => 'page',
            'group' => 'footer',
            'type' => 'string',
            'description' => 'Footer support link type: page|modal (Help Center)',
            'is_public' => 1
        ],
        'footer_support_safety_label' => [
            'default' => 'Safety Tips',
            'group' => 'footer',
            'type' => 'string',
            'description' => 'Footer support link label: Safety Tips',
            'is_public' => 1
        ],
        'footer_support_safety_href' => [
            'default' => 'safety.html#top',
            'group' => 'footer',
            'type' => 'url',
            'description' => 'Footer support link target: Safety Tips',
            'is_public' => 1
        ],
        'footer_support_safety_type' => [
            'default' => 'page',
            'group' => 'footer',
            'type' => 'string',
            'description' => 'Footer support link type: page|modal (Safety Tips)',
            'is_public' => 1
        ],
        'footer_support_contact_label' => [
            'default' => 'Contact Us',
            'group' => 'footer',
            'type' => 'string',
            'description' => 'Footer support link label: Contact Us',
            'is_public' => 1
        ],
        'footer_support_contact_href' => [
            'default' => 'contact.html#channels',
            'group' => 'footer',
            'type' => 'url',
            'description' => 'Footer support link target: Contact Us',
            'is_public' => 1
        ],
        'footer_support_contact_type' => [
            'default' => 'page',
            'group' => 'footer',
            'type' => 'string',
            'description' => 'Footer support link type: page|modal (Contact Us)',
            'is_public' => 1
        ],
        'footer_support_terms_label' => [
            'default' => 'Terms of Service',
            'group' => 'footer',
            'type' => 'string',
            'description' => 'Footer support link label: Terms of Service',
            'is_public' => 1
        ],
        'footer_support_terms_href' => [
            'default' => 'terms.html',
            'group' => 'footer',
            'type' => 'url',
            'description' => 'Footer support link target: Terms of Service',
            'is_public' => 1
        ],
        'footer_support_terms_type' => [
            'default' => 'page',
            'group' => 'footer',
            'type' => 'string',
            'description' => 'Footer support link type: page|modal (Terms of Service)',
            'is_public' => 1
        ],
        'footer_support_cookie_label' => [
            'default' => 'Cookie Policy',
            'group' => 'footer',
            'type' => 'string',
            'description' => 'Footer support link label: Cookie Policy',
            'is_public' => 1
        ],
        'footer_support_cookie_href' => [
            'default' => 'cookie-policy.html',
            'group' => 'footer',
            'type' => 'url',
            'description' => 'Footer support link target: Cookie Policy',
            'is_public' => 1
        ],
        'footer_support_cookie_type' => [
            'default' => 'page',
            'group' => 'footer',
            'type' => 'string',
            'description' => 'Footer support link type: page|modal (Cookie Policy)',
            'is_public' => 1
        ]
    ];
}

/**
 * Return footer support settings from site_settings for admin UI.
 */
function handleGetFooterSupportSettings($db) {
    requireAdmin();

    try {
        $definitions = getFooterSupportSettingDefinitions();
        $keys = array_keys($definitions);
        $placeholders = implode(',', array_fill(0, count($keys), '?'));

        $stmt = $db->prepare("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ($placeholders)");
        $stmt->execute($keys);

        $settings = [];
        foreach ($definitions as $key => $meta) {
            $settings[$key] = $meta['default'];
        }

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $settings[$row['setting_key']] = (string)($row['setting_value'] ?? '');
        }

        echo json_encode([
            'success' => true,
            'settings' => $settings
        ]);
        exit();
    } catch (Exception $e) {
        error_log('handleGetFooterSupportSettings error: ' . $e->getMessage());
        sendAdminError('Failed to load footer support settings', 'FOOTER_SUPPORT_LOAD_FAILED', 500);
    }
}

/**
 * Save footer support settings to site_settings for public footer rendering.
 */
function handleSaveFooterSupportSettings($db) {
    requireAdmin();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendAdminError('POST method required', 'INVALID_METHOD', 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $incomingSettings = $input['settings'] ?? null;

    if (!is_array($incomingSettings)) {
        sendAdminError('Invalid settings payload', 'FOOTER_SUPPORT_INVALID_PAYLOAD', 400);
    }

    $definitions = getFooterSupportSettingDefinitions();

    try {
        $db->beginTransaction();

        $stmt = $db->prepare("INSERT INTO site_settings (setting_key, setting_value, setting_group, setting_type, description, is_public)
                              VALUES (?, ?, ?, ?, ?, ?)
                              ON DUPLICATE KEY UPDATE
                              setting_value = VALUES(setting_value),
                              setting_group = VALUES(setting_group),
                              setting_type = VALUES(setting_type),
                              description = VALUES(description),
                              is_public = VALUES(is_public)");

        $updated = 0;

        foreach ($definitions as $key => $meta) {
            if (!array_key_exists($key, $incomingSettings)) {
                continue;
            }

            $value = trim((string)$incomingSettings[$key]);

            if (str_ends_with($key, '_type')) {
                $value = in_array($value, ['page', 'modal'], true) ? $value : 'page';
            }

            if (str_ends_with($key, '_href')) {
                if (preg_match('/^\s*(javascript:|data:)/i', $value)) {
                    sendAdminError('Unsafe URL provided for ' . $key, 'FOOTER_SUPPORT_UNSAFE_URL', 400);
                }
            }

            if ($value === '') {
                $value = $meta['default'];
            }

            $stmt->execute([
                $key,
                $value,
                $meta['group'],
                $meta['type'],
                $meta['description'],
                $meta['is_public']
            ]);
            $updated++;
        }

        $db->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Footer support settings saved successfully',
            'updated' => $updated
        ]);
        exit();
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('handleSaveFooterSupportSettings error: ' . $e->getMessage());
        sendAdminError('Failed to save footer support settings', 'FOOTER_SUPPORT_SAVE_FAILED', 500);
    }
}

// ============================================================================
// WHATSAPP INTEGRATION SETTINGS
// ============================================================================

/**
 * Return WhatsApp Cloud API settings for admin UI.
 * The token is masked on read for display security.
 */
function handleGetWhatsAppSettings($db) {
    requireSuperAdmin($db);
    try {
        $keys = ['wa_enabled', 'wa_api_token', 'wa_phone_number_id', 'wa_business_account_id', 'wa_api_version', 'wa_lead_notifications'];
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $db->prepare("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ($placeholders)");
        $stmt->execute($keys);
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $token = $rows['wa_api_token'] ?? '';
        echo json_encode([
            'success' => true,
            'settings' => [
                'wa_enabled'              => $rows['wa_enabled']              ?? '0',
                'wa_api_token'            => $token !== '' ? '••••••••' . substr($token, -4) : '',
                'wa_phone_number_id'      => $rows['wa_phone_number_id']      ?? '',
                'wa_business_account_id'  => $rows['wa_business_account_id']  ?? '',
                'wa_api_version'          => $rows['wa_api_version']          ?? 'v19.0',
                'wa_lead_notifications'   => $rows['wa_lead_notifications']   ?? '0',
                'token_configured'        => $token !== '',
            ],
        ]);
        exit();
    } catch (Exception $e) {
        error_log('handleGetWhatsAppSettings error: ' . $e->getMessage());
        sendAdminError('Failed to load WhatsApp settings', 'WA_SETTINGS_LOAD_FAILED', 500);
    }
}

/**
 * Save WhatsApp Cloud API settings.
 * If wa_api_token is blank or placeholder (all '•'), the existing token is preserved.
 */
function handleSaveWhatsAppSettings($db) {
    requireSuperAdmin($db);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendAdminError('POST method required', 'INVALID_METHOD', 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        sendAdminError('Invalid payload', 'WA_SETTINGS_INVALID_PAYLOAD', 400);
    }

    $enabled      = isset($input['wa_enabled']) && $input['wa_enabled'] ? '1' : '0';
    $leadNotifs   = isset($input['wa_lead_notifications']) && $input['wa_lead_notifications'] ? '1' : '0';
    $token        = trim((string)($input['wa_api_token'] ?? ''));
    $phoneNumId   = trim(preg_replace('/[^0-9]/', '', (string)($input['wa_phone_number_id'] ?? '')));
    $wabaId       = trim(preg_replace('/[^0-9]/', '', (string)($input['wa_business_account_id'] ?? '')));
    $apiVersion   = trim((string)($input['wa_api_version'] ?? 'v19.0'));

    // Reject placeholder value — don't overwrite existing token with mask
    $tokenIsPlaceholder = ($token === '' || preg_match('/^[•\*]+/', $token));

    // Validate api version format
    if (!preg_match('/^v\d+\.\d+$/', $apiVersion)) {
        $apiVersion = 'v19.0';
    }

    try {
        $db->beginTransaction();

        $upsert = $db->prepare(
            "INSERT INTO site_settings (setting_key, setting_value, setting_group, setting_type, description, is_public)
             VALUES (?, ?, 'whatsapp', 'string', ?, 0)
             ON DUPLICATE KEY UPDATE
             setting_value = VALUES(setting_value)"
        );

        $upsert->execute(['wa_enabled', $enabled, 'Enable WhatsApp Cloud API integration']);
        $upsert->execute(['wa_lead_notifications', $leadNotifs, 'Send WhatsApp notification to dealer on new lead/message']);
        if (!$tokenIsPlaceholder) {
            $upsert->execute(['wa_api_token', $token, 'Meta WhatsApp Cloud API bearer token']);
        }
        $upsert->execute(['wa_phone_number_id', $phoneNumId, 'Meta WhatsApp Phone Number ID']);
        $upsert->execute(['wa_business_account_id', $wabaId, 'Meta WhatsApp Business Account ID (WABA ID)']);
        $upsert->execute(['wa_api_version', $apiVersion, 'Meta Graph API version']);

        $db->commit();

        echo json_encode([
            'success'         => true,
            'message'         => 'WhatsApp settings saved.',
            'token_updated'   => !$tokenIsPlaceholder,
        ]);
        exit();
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('handleSaveWhatsAppSettings error: ' . $e->getMessage());
        sendAdminError('Failed to save WhatsApp settings', 'WA_SETTINGS_SAVE_FAILED', 500);
    }
}

/**
 * Send a test WhatsApp text message to a supplied number using the stored API credentials.
 * POST body: { test_phone: "+265888000000" }
 */
function handleTestWhatsAppMessage($db) {
    requireSuperAdmin($db);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendAdminError('POST method required', 'INVALID_METHOD', 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $testPhone = trim(preg_replace('/[^0-9+]/', '', (string)($input['test_phone'] ?? '')));

    if (empty($testPhone)) {
        sendAdminError('A valid test phone number is required (digits only, including country code)', 'WA_TEST_PHONE_REQUIRED', 400);
    }
    // Strip leading + for Graph API
    $toNumber = preg_replace('/[^0-9]/', '', $testPhone);

    // Load credentials
    $keys = ['wa_api_token', 'wa_phone_number_id', 'wa_api_version'];
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ($placeholders)");
    $stmt->execute($keys);
    $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $token       = $rows['wa_api_token']       ?? '';
    $phoneNumId  = $rows['wa_phone_number_id'] ?? '';
    $apiVersion  = !empty($rows['wa_api_version']) ? $rows['wa_api_version'] : 'v19.0';

    if (empty($token)) {
        sendAdminError('WhatsApp API token is not configured. Save your settings first.', 'WA_TEST_NO_TOKEN', 400);
    }
    if (empty($phoneNumId)) {
        sendAdminError('WhatsApp Phone Number ID is not configured. Save your settings first.', 'WA_TEST_NO_PHONE_ID', 400);
    }

    $url = "https://graph.facebook.com/{$apiVersion}/{$phoneNumId}/messages";
    $body = json_encode([
        'messaging_product' => 'whatsapp',
        'recipient_type'    => 'individual',
        'to'                => $toNumber,
        'type'              => 'text',
        'text'              => [
            'preview_url' => false,
            'body'        => "✅ MotorLink WhatsApp API test message.\n\nYour WhatsApp integration is working correctly. Booking notifications will be sent via this number.\n\n_Sent from MotorLink Admin Panel_",
        ],
    ]);

    $_waHost      = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
    $_waLocalhost = in_array($_waHost, ['localhost', '127.0.0.1', '::1'], true)
                 || strpos($_waHost, 'localhost:') === 0
                 || strpos($_waHost, '127.0.0.1:') === 0
                 || preg_match('/^(192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $_waHost);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_POST            => true,
        CURLOPT_POSTFIELDS      => $body,
        CURLOPT_TIMEOUT         => 15,
        CURLOPT_SSL_VERIFYPEER  => !$_waLocalhost,
        CURLOPT_SSL_VERIFYHOST  => $_waLocalhost ? 0 : 2,
        CURLOPT_HTTPHEADER      => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
    ]);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        error_log("WA test cURL error: $curlError");
        echo json_encode(['success' => false, 'error' => "Network error: $curlError", 'http_code' => 0]);
        exit();
    }

    $decoded = json_decode($response, true);
    $wamid   = $decoded['messages'][0]['id'] ?? null;

    // Detect if using Meta's free test number (not a real business number)
    // Test numbers are typically in the format 1065XXXXXXXXX or similar short IDs
    $usingTestNumber = (strlen($phoneNumId) < 16);

    if ($httpCode === 200 && $wamid) {
        logActivity($db, 'whatsapp_test_sent',
            'WhatsApp test message sent',
            "To: {$toNumber}, wamid: {$wamid}", $_SESSION['admin_id'] ?? null);

        $note = $usingTestNumber
            ? "API accepted the message (wamid issued). If you did NOT receive it: the Meta test number can only deliver to verified recipients. Go to Meta → WhatsApp → API Setup → \"To\" dropdown → Add phone number → verify +{$toNumber} with OTP."
            : "Message delivered to +{$toNumber}.";

        echo json_encode([
            'success'    => true,
            'message'    => $note,
            'wamid'      => $wamid,
            'http_code'  => $httpCode,
        ]);
    } else {
        $errMsg  = $decoded['error']['message'] ?? "HTTP {$httpCode}";
        $errCode = $decoded['error']['code']    ?? null;
        error_log("WA test failed: $errMsg — response: $response");
        echo json_encode([
            'success'    => false,
            'error'      => $errMsg,
            'api_code'   => $errCode,
            'http_code'  => $httpCode,
        ]);
    }
    exit();
}

/**
 * List WhatsApp booking requests from car_hire_bookings.
 * GET ?action=get_whatsapp_bookings&status=&limit=&company_id=
 */
function handleGetWhatsAppBookings($db) {
    requireAdmin();

    try {
        $where  = ['1=1'];
        $params = [];

        if (!empty($_GET['status'])) {
            $where[]  = 'b.status = ?';
            $params[] = $_GET['status'];
        }
        if (!empty($_GET['company_id']) && is_numeric($_GET['company_id'])) {
            $where[]  = 'b.company_id = ?';
            $params[] = (int)$_GET['company_id'];
        }
        if (!empty($_GET['search'])) {
            $where[]  = '(b.renter_name LIKE ? OR b.renter_phone LIKE ? OR b.vehicle_name LIKE ?)';
            $term     = '%' . $_GET['search'] . '%';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        $limit    = max(1, min(500, (int)($_GET['limit'] ?? 100)));
        $whereSql = implode(' AND ', $where);

        $sql = "SELECT b.id, b.company_id, b.fleet_id, b.vehicle_name, b.daily_rate,
                       b.renter_name, b.renter_phone, b.renter_whatsapp,
                       b.start_date, b.end_date, b.duration_days, b.total_estimate,
                       b.special_requests, b.status, b.wa_sent, b.wa_message_id,
                       b.created_at,
                       c.business_name AS company_name, c.whatsapp AS company_whatsapp
                FROM car_hire_bookings b
                LEFT JOIN car_hire_companies c ON c.id = b.company_id
                WHERE {$whereSql}
                ORDER BY b.created_at DESC
                LIMIT ?";
        $params[] = $limit;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Stats
        $stmtStats = $db->query("SELECT status, COUNT(*) as cnt, SUM(wa_sent) as wa_count FROM car_hire_bookings GROUP BY status");
        $stats = ['pending' => 0, 'confirmed' => 0, 'declined' => 0, 'cancelled' => 0, 'total' => 0, 'wa_sent' => 0];
        foreach ($stmtStats->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $stats[$row['status']] = (int)$row['cnt'];
            $stats['total']       += (int)$row['cnt'];
            $stats['wa_sent']     += (int)$row['wa_count'];
        }

        echo json_encode(['success' => true, 'bookings' => $bookings, 'stats' => $stats]);
        exit();
    } catch (Exception $e) {
        error_log('handleGetWhatsAppBookings error: ' . $e->getMessage());
        sendAdminError('Failed to load bookings', 'WA_BOOKINGS_LOAD_FAILED', 500);
    }
}

/**
 * Update a booking status (confirm / decline / cancel).
 * POST body: { booking_id, status }
 */
function handleUpdateWhatsAppBookingStatus($db) {
    requireAdmin();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendAdminError('POST method required', 'INVALID_METHOD', 405);
    }

    $input     = json_decode(file_get_contents('php://input'), true) ?? [];
    $bookingId = (int)($input['booking_id'] ?? 0);
    $status    = trim((string)($input['status'] ?? ''));
    $allowed   = ['pending', 'confirmed', 'declined', 'cancelled'];

    if ($bookingId <= 0) sendAdminError('booking_id required', 'BOOKING_ID_REQUIRED', 400);
    if (!in_array($status, $allowed, true)) sendAdminError('Invalid status', 'INVALID_STATUS', 400);

    try {
        $stmt = $db->prepare("UPDATE car_hire_bookings SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$status, $bookingId]);

        if ($stmt->rowCount() === 0) {
            sendAdminError('Booking not found', 'BOOKING_NOT_FOUND', 404);
        }

        logActivity($db, 'wa_booking_status_updated',
            'Car hire booking status updated',
            "Booking ID: {$bookingId}, Status: {$status}", $_SESSION['admin_id'] ?? null);

        echo json_encode(['success' => true, 'message' => 'Booking updated to ' . $status]);
        exit();
    } catch (Exception $e) {
        error_log('handleUpdateWhatsAppBookingStatus error: ' . $e->getMessage());
        sendAdminError('Failed to update booking', 'BOOKING_UPDATE_FAILED', 500);
    }
}

function handleManageGuestListingAuth($db) {
    requireAdmin();

    $input = json_decode(file_get_contents('php://input'), true);
    $listingId = (int)($input['id'] ?? 0);
    $operation = trim((string)($input['operation'] ?? ''));

    if ($listingId <= 0 || $operation === '') {
        sendAdminError('Listing ID and operation are required', 'INVALID_INPUT', 400);
    }

    try {
        ensureGuestListingAuthColumns($db);

        $listingStmt = $db->prepare("SELECT id, COALESCE(is_guest, 0) AS is_guest, status, approval_status FROM car_listings WHERE id = ? LIMIT 1");
        $listingStmt->execute([$listingId]);
        $listing = $listingStmt->fetch(PDO::FETCH_ASSOC);

        if (!$listing) {
            sendAdminError('Guest listing not found', 'LISTING_NOT_FOUND', 404);
        }

        if ((int)$listing['is_guest'] !== 1) {
            sendAdminError('This action is only available for guest listings', 'NOT_GUEST_LISTING', 400);
        }

        if ($operation === 'override_email_verification') {
            $hasVerifiedAt = adminTableColumnExists($db, 'car_listings', 'listing_email_verified_at');

            $setParts = [
                "listing_email_verified = 1",
                "listing_email_verification_token = NULL",
                "listing_email_verification_expires = NULL",
                "status = CASE WHEN status = 'pending_email_verification' THEN 'pending_approval' ELSE status END",
                "approval_status = CASE WHEN approval_status = 'pending_email_verification' THEN 'pending' ELSE approval_status END"
            ];

            if ($hasVerifiedAt) {
                $setParts[] = "listing_email_verified_at = COALESCE(listing_email_verified_at, NOW())";
            }

            $sql = "UPDATE car_listings SET " . implode(', ', $setParts) . " WHERE id = ? LIMIT 1";
            $stmt = $db->prepare($sql);
            $stmt->execute([$listingId]);

            echo json_encode([
                'success' => true,
                'message' => 'Guest listing email verification overridden successfully'
            ]);
            exit();
        }

        if ($operation === 'set_guest_manage_code') {
            $providedCode = trim((string)($input['code'] ?? ''));
            $expiresHours = (int)($input['expires_hours'] ?? 24);
            if ($expiresHours < 1) $expiresHours = 1;
            if ($expiresHours > 168) $expiresHours = 168;

            $code = $providedCode !== '' ? $providedCode : str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            if (!preg_match('/^\d{4,8}$/', $code)) {
                sendAdminError('Code must be 4 to 8 digits', 'INVALID_CODE_FORMAT', 400);
            }

            $codeHash = password_hash($code, PASSWORD_DEFAULT);
            $expiresAt = date('Y-m-d H:i:s', strtotime('+' . $expiresHours . ' hours'));

            $stmt = $db->prepare("UPDATE car_listings SET guest_manage_code_hash = ?, guest_manage_code_expires = ?, guest_manage_code_last_sent = NOW() WHERE id = ? LIMIT 1");
            $stmt->execute([$codeHash, $expiresAt, $listingId]);

            echo json_encode([
                'success' => true,
                'message' => 'Guest manage code has been set successfully',
                'code' => $code,
                'expires_at' => $expiresAt
            ]);
            exit();
        }

        sendAdminError('Unsupported guest listing auth operation', 'UNSUPPORTED_OPERATION', 400);
    } catch (Exception $e) {
        error_log('handleManageGuestListingAuth error: ' . $e->getMessage());
        sendAdminError('Failed to process guest listing auth action', 'GUEST_AUTH_UPDATE_FAILED', 500);
    }
}

// ===== CACHE MANAGEMENT =====

/**
 * Clear all server-side caches: OPcache, AI web/learning caches, PHP session files, temp files.
 * Also returns cache-busting headers and a version token the frontend can append to asset URLs.
 */
function handleClearAllCaches($db) {
    requireAdmin();

    $results = [];

    // 1. OPcache
    if (function_exists('opcache_reset')) {
        $results['opcache'] = opcache_reset() ? 'cleared' : 'failed';
    } else {
        $results['opcache'] = 'not_available';
    }

    // 2. AI web cache table (ai_web_cache)
    try {
        $stmt = $db->prepare("DELETE FROM ai_web_cache WHERE 1=1");
        $stmt->execute();
        $results['ai_web_cache'] = $stmt->rowCount() . ' rows deleted';
    } catch (Exception $e) {
        $results['ai_web_cache'] = 'skipped (table may not exist)';
    }

    // 3. AI learning cache table (ai_learning_cache)
    try {
        $stmt = $db->prepare("DELETE FROM ai_learning_cache WHERE 1=1");
        $stmt->execute();
        $results['ai_learning_cache'] = $stmt->rowCount() . ' rows deleted';
    } catch (Exception $e) {
        $results['ai_learning_cache'] = 'skipped (table may not exist)';
    }

    // 4. PHP session files
    $sessionPath = session_save_path();
    if (empty($sessionPath)) {
        $sessionPath = sys_get_temp_dir();
    }
    $sessionCount = 0;
    if (is_dir($sessionPath)) {
        $files = glob($sessionPath . '/sess_*');
        if ($files) {
            foreach ($files as $file) {
                if (is_file($file) && @unlink($file)) {
                    $sessionCount++;
                }
            }
        }
    }
    $results['php_sessions'] = $sessionCount . ' session files cleared';

    // 5. Temp/log cleanup in logs/ directory (files older than 7 days)
    $logsDir = __DIR__ . '/../logs';
    $tempCount = 0;
    if (is_dir($logsDir)) {
        $cutoff = time() - (7 * 86400);
        $logFiles = glob($logsDir . '/*.log');
        if ($logFiles) {
            foreach ($logFiles as $file) {
                if (is_file($file) && filemtime($file) < $cutoff && @unlink($file)) {
                    $tempCount++;
                }
            }
        }
    }
    $results['old_logs'] = $tempCount . ' old log files removed';

    // 6. Generate a cache-bust version token the frontend can use
    $cacheBustToken = 'v=' . time();
    $results['cache_bust_token'] = $cacheBustToken;

    // Send no-cache headers so the response itself is not cached
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    echo json_encode([
        'success' => true,
        'message' => 'All caches cleared successfully',
        'results' => $results,
        'cache_bust_token' => $cacheBustToken
    ]);
    exit();
}

// ============================================================================
// BUSINESS REVIEWS ADMIN
// ============================================================================

/**
 * List reviews with filters, joins to users + business names.
 * GET ?action=get_business_reviews&business_type=&status=&search=&limit=
 */
function handleGetBusinessReviews($db) {
    requireAdmin();

    try {
        $where = ["1=1"];
        $params = [];

        if (!empty($_GET['business_type'])) {
            $where[] = "br.business_type = ?";
            $params[] = $_GET['business_type'];
        }

        if (!empty($_GET['status'])) {
            $where[] = "br.status = ?";
            $params[] = $_GET['status'];
        }

        if (!empty($_GET['search'])) {
            $where[] = "(u.full_name LIKE ? OR br.review_text LIKE ?)";
            $term = '%' . $_GET['search'] . '%';
            $params[] = $term;
            $params[] = $term;
        }

        $limit = isset($_GET['limit']) ? max(1, min(500, (int)$_GET['limit'])) : 200;
        $whereClause = implode(' AND ', $where);

        $sql = "SELECT br.id, br.business_type, br.business_id, br.rating,
                       br.review_text, br.status, br.created_at, br.updated_at,
                       u.full_name AS reviewer_name, u.email AS reviewer_email,
                       COALESCE(
                           (SELECT cd.business_name FROM car_dealers cd WHERE cd.id = br.business_id AND br.business_type = 'dealer' LIMIT 1),
                           (SELECT g.name FROM garages g WHERE g.id = br.business_id AND br.business_type = 'garage' LIMIT 1),
                           (SELECT ch.business_name FROM car_hire_companies ch WHERE ch.id = br.business_id AND br.business_type = 'car_hire' LIMIT 1)
                       ) AS business_name
                FROM business_reviews br
                INNER JOIN users u ON br.user_id = u.id
                WHERE {$whereClause}
                ORDER BY CASE WHEN br.status = 'active' THEN 0 ELSE 1 END, br.created_at DESC
                LIMIT ?";

        $params[] = $limit;
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Aggregate counts
        $stmtCounts = $db->query("SELECT status, COUNT(*) as cnt FROM business_reviews GROUP BY status");
        $counts = ['active' => 0, 'hidden' => 0];
        foreach ($stmtCounts->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = $row['status'];
            if (isset($counts[$key])) $counts[$key] = (int)$row['cnt'];
        }

        echo json_encode(['success' => true, 'reviews' => $reviews, 'stats' => $counts]);
        exit();
    } catch (Exception $e) {
        error_log('handleGetBusinessReviews error: ' . $e->getMessage());
        sendAdminError('Failed to load reviews', 'REVIEWS_LOAD_FAILED', 500);
    }
}

/**
 * Update review status: active or hidden.
 * POST ?action=update_review_status  body: { review_id, status }
 */
function handleUpdateBusinessReviewStatus($db) {
    requireAdmin();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendAdminError('POST method required', 'INVALID_METHOD', 405);
    }

    try {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $reviewId = (int)($input['review_id'] ?? 0);
        $status   = trim((string)($input['status'] ?? ''));

        if ($reviewId <= 0) sendAdminError('review_id required', 'REVIEW_ID_REQUIRED', 400);
        if (!in_array($status, ['active', 'hidden'], true)) sendAdminError('Invalid status', 'INVALID_STATUS', 400);

        $adminId = $_SESSION['admin_id'] ?? null;
        $stmt = $db->prepare("UPDATE business_reviews SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$status, $reviewId]);

        if ($stmt->rowCount() === 0) {
            sendAdminError('Review not found or unchanged', 'REVIEW_NOT_FOUND', 404);
        }

        logActivity($db, 'review_status_updated',
            'Business review status updated',
            "Review ID: {$reviewId}, Status: {$status}", $adminId);

        echo json_encode(['success' => true, 'message' => 'Review status updated']);
        exit();
    } catch (Exception $e) {
        error_log('handleUpdateBusinessReviewStatus error: ' . $e->getMessage());
        sendAdminError('Failed to update review status', 'REVIEW_UPDATE_FAILED', 500);
    }
}

/**
 * Hard-delete a review (permanent).
 * POST ?action=delete_review  body: { review_id }
 */
function handleDeleteBusinessReview($db) {
    requireAdmin();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendAdminError('POST method required', 'INVALID_METHOD', 405);
    }

    try {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $reviewId = (int)($input['review_id'] ?? 0);

        if ($reviewId <= 0) sendAdminError('review_id required', 'REVIEW_ID_REQUIRED', 400);

        $adminId = $_SESSION['admin_id'] ?? null;
        $stmt = $db->prepare("DELETE FROM business_reviews WHERE id = ?");
        $stmt->execute([$reviewId]);

        if ($stmt->rowCount() === 0) {
            sendAdminError('Review not found', 'REVIEW_NOT_FOUND', 404);
        }

        logActivity($db, 'review_deleted',
            'Business review deleted',
            "Review ID: {$reviewId}", $adminId);

        echo json_encode(['success' => true, 'message' => 'Review deleted']);
        exit();
    } catch (Exception $e) {
        error_log('handleDeleteBusinessReview error: ' . $e->getMessage());
        sendAdminError('Failed to delete review', 'REVIEW_DELETE_FAILED', 500);
    }
}
