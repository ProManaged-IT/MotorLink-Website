<?php
/**
 * Local CORS Proxy for MotorLink
 * Run with: php -S localhost:8000
 *
 * This proxy handles session cookies to maintain authentication state
 * between the local frontend and the remote API.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0); // Disable error display to prevent breaking JSON responses
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/proxy_errors.log');

// ============================================================================
// CONFIGURATION - Set to true to use local API for development
// ============================================================================
// Auto-detect: Use local API if running on localhost, otherwise use production
$serverHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
$isLocalhost = in_array($serverHost, ['localhost', '127.0.0.1']) || 
               strpos($serverHost, 'localhost:') === 0 || 
               strpos($serverHost, '127.0.0.1:') === 0 ||
               preg_match('/^(192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $serverHost);
define('USE_LOCAL_API', $isLocalhost); // Auto-detect: true for localhost, false for production

// Start local session to store remote session cookies
session_start();

// CORS Configuration - Must use specific origin when credentials are enabled
// Cannot use '*' when Access-Control-Allow-Credentials is true
$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? null;

// Determine the origin to use for CORS header
$originToUse = null;

// Priority 1: Use the Origin header from the request (most reliable)
if ($requestOrigin) {
    $parsedOrigin = parse_url($requestOrigin);
    if ($parsedOrigin) {
        $originHost = $parsedOrigin['host'] ?? '';
        // Allow localhost and 127.0.0.1 for local development
        if (in_array($originHost, ['localhost', '127.0.0.1'])) {
            $originToUse = $requestOrigin; // Use exact origin from request
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
        if (in_array($host, ['localhost', '127.0.0.1'])) {
            $originToUse = $scheme . '://' . $host . $port;
        }
    }
}

// Priority 3: Construct from current request (fallback for same-origin requests)
if (!$originToUse) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
    $hostParts = explode(':', $host);
    $hostOnly = $hostParts[0];
    if (in_array($hostOnly, ['localhost', '127.0.0.1'])) {
        $originToUse = $scheme . '://' . $host;
    }
}

// Set CORS headers - MUST be specific origin, never '*' when credentials are enabled
if ($originToUse) {
    header("Access-Control-Allow-Origin: $originToUse");
} else {
    // Last resort: log error and use a default (shouldn't happen in normal operation)
    error_log("CORS: Could not determine origin. Request origin: " . ($requestOrigin ?? 'none') . ", Referer: " . ($_SERVER['HTTP_REFERER'] ?? 'none'));
    // Default to localhost:8000 for local development
    header("Access-Control-Allow-Origin: http://127.0.0.1:8000");
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cookie');
header('Access-Control-Allow-Credentials: true');

// Only set JSON content-type if not a file upload
if (empty($_FILES)) {
    header('Content-Type: application/json');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Determine which API endpoint to use
$endpoint = $_GET['endpoint'] ?? 'main';

// Get action from request to route to appropriate API file
$action = $_GET['action'] ?? $_POST['action'] ?? '';
if (empty($action) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $jsonInput = json_decode(file_get_contents('php://input'), true);
    if (isset($jsonInput['action'])) {
        $action = $jsonInput['action'];
    }
}


// ============================================================================
// AI CAR CHAT ENDPOINTS - Route to separate API file
// ============================================================================
if ($action === 'get_ai_chat_usage_remaining') {
    try {
        // Change to root directory for proper includes
        chdir(__DIR__);
        
        // Include common functions and the AI car chat API file
        require_once __DIR__ . '/api-common.php';
        require_once __DIR__ . '/ai-car-chat-api.php';
        requireAuth();
        
        $user = getCurrentUser(true);
        if (!$user) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Authentication required']);
            exit;
        }
        
        $db = getDB();
        $usage = getUserAIChatUsageRemaining($db, $user['id']);
        echo json_encode(['success' => true, 'usage' => $usage]);
        exit;
    } catch (Exception $e) {
        error_log("Get AI Chat Usage Error: " . $e->getMessage());
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Failed to get usage']);
        exit;
    }
}

if ($action === 'get_ai_chat_session_history') {
    try {
        // Change to root directory for proper includes
        chdir(__DIR__);

        // Include common functions and the AI car chat API file
        require_once __DIR__ . '/api-common.php';
        require_once __DIR__ . '/ai-car-chat-api.php';
        requireAuth();
        handleGetAIChatSessionHistory(getDB());
        exit;
    } catch (Exception $e) {
        error_log("Get AI Chat Session History Error: " . $e->getMessage());
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Failed to get AI chat history']);
        exit;
    }
}

if ($action === 'ai_car_chat') {
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
        // Change to root directory for proper includes
        chdir(__DIR__);
        
        // Include common functions and the AI car chat API file
        require_once __DIR__ . '/api-common.php';
        require_once __DIR__ . '/ai-car-chat-api.php';
        requireAuth();
        handleAICarChat(getDB());
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
            'message' => 'A fatal error occurred. Please contact support.',
            'error' => $e->getMessage()
        ]);
        exit;
    }
}

// ============================================================================
// VIN DECODER ENDPOINTS - Route to separate API file
// ============================================================================
if (in_array($action, ['nhtsa', 'autodata'])) {
    // Change to root directory for proper includes
    chdir(__DIR__);
    
    // Include common functions and route to VIN decoder functions
    require_once __DIR__ . '/api-common.php';
    require_once __DIR__ . '/vin-decoder-api.php';
    
    $db = getDB();
        switch ($action) {
        case 'nhtsa':
            getNhtsaData($db);
            break;
        case 'autodata':
            sendError('AutoData endpoint not available', 501);
            break;
    }
    exit;
}

// ============================================================================
// ADMIN ENDPOINTS - Always handle these first, regardless of USE_LOCAL_API
// Admin files connect to production database remotely (admin-config.php)
// ============================================================================
if ($endpoint === 'admin-api' || $endpoint === 'admin-edit') {
    unset($_GET['endpoint']);

    // Change to admin directory for proper includes
    chdir(__DIR__ . '/admin');

    // Include the local admin file
    if ($endpoint === 'admin-api') {
        include __DIR__ . '/admin/admin-api.php';
    } else {
        include __DIR__ . '/admin/admin-edit.php';
    }
    exit;
}

// ============================================================================
// LOCAL API MODE - Include and execute api.php directly for main API
// ============================================================================
if (USE_LOCAL_API) {
    // Change to api.php directory for proper includes
    chdir(__DIR__);

    // Set up $_GET from query string
    // The api.php will handle the request directly
    include __DIR__ . '/api.php';
    exit;
}

// Check PHP requirements
if (!extension_loaded('openssl')) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'OpenSSL extension not loaded. Enable extension=openssl in php.ini',
        'fix' => 'Edit your php.ini file and uncomment: extension=openssl'
    ]);
    exit;
}

if (!ini_get('allow_url_fopen')) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Enable allow_url_fopen=On in php.ini']);
    exit;
}

// For remote API mode (USE_LOCAL_API = false), route to appropriate endpoint
// Use relative paths (same server) - works with any domain
switch ($endpoint) {
    case 'onboarding':
        $productionAPI = '/onboarding/api-onboarding.php';
        break;
    case 'admin-edit':
        $productionAPI = '/admin/admin-edit.php';
        break;
    case 'admin-api':
        $productionAPI = '/admin/admin-api.php';
        break;
    default:
        $productionAPI = '/api.php';
        break;
}

unset($_GET['endpoint']);

$queryString = http_build_query($_GET);
$url = $productionAPI . ($queryString ? '?' . $queryString : '');

// Try cURL first if available (more reliable on Windows)
if (function_exists('curl_init')) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HEADER, true); // Get response headers

    // Build headers including any stored cookies
    $headers = ['Content-Type: application/json', 'Accept: application/json'];

    // Forward stored session cookies to the remote API
    if (!empty($_SESSION['remote_cookies'])) {
        $cookieHeader = 'Cookie: ' . implode('; ', $_SESSION['remote_cookies']);
        $headers[] = $cookieHeader;
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents('php://input'));
    }

    $response = curl_exec($ch);
    $error = curl_error($ch);

    if ($response === false) {
        curl_close($ch);
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'cURL error: ' . $error]);
        exit;
    }

    // Separate headers and body
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headerStr = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    curl_close($ch);

    // Parse and store Set-Cookie headers from remote API
    preg_match_all('/Set-Cookie:\s*([^;\r\n]+)/i', $headerStr, $matches);
    if (!empty($matches[1])) {
        if (!isset($_SESSION['remote_cookies'])) {
            $_SESSION['remote_cookies'] = [];
        }
        foreach ($matches[1] as $cookie) {
            // Extract cookie name and value
            $parts = explode('=', $cookie, 2);
            if (count($parts) === 2) {
                $cookieName = trim($parts[0]);
                // Store as name=value for forwarding
                $_SESSION['remote_cookies'][$cookieName] = $cookie;
            }
        }
    }

    echo $body;
    exit;
}

// Fallback to file_get_contents
$headerStr = "Content-Type: application/json\r\nAccept: application/json\r\nUser-Agent: MotorLink-Proxy/1.0\r\n";

// Forward stored session cookies
if (!empty($_SESSION['remote_cookies'])) {
    $headerStr .= 'Cookie: ' . implode('; ', $_SESSION['remote_cookies']) . "\r\n";
}

$options = [
    'http' => [
        'method' => $_SERVER['REQUEST_METHOD'],
        'header' => $headerStr,
        'ignore_errors' => true,
        'timeout' => 30
    ],
    'ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false,
        'allow_self_signed' => true
    ]
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $options['http']['content'] = file_get_contents('php://input');
}

$context = stream_context_create($options);
$response = @file_get_contents($url, false, $context);

if ($response === false) {
    $error = error_get_last();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to connect to API',
        'url' => $url,
        'error' => $error ? $error['message'] : 'Unknown error',
        'hint' => 'Make sure OpenSSL extension is enabled in php.ini'
    ]);
    exit;
}

// Parse response headers for Set-Cookie (from $http_response_header)
if (isset($http_response_header)) {
    foreach ($http_response_header as $header) {
        if (stripos($header, 'Set-Cookie:') === 0) {
            $cookie = trim(substr($header, 11));
            $cookiePart = explode(';', $cookie)[0]; // Get name=value part
            $parts = explode('=', $cookiePart, 2);
            if (count($parts) === 2) {
                $cookieName = trim($parts[0]);
                if (!isset($_SESSION['remote_cookies'])) {
                    $_SESSION['remote_cookies'] = [];
                }
                $_SESSION['remote_cookies'][$cookieName] = $cookiePart;
            }
        }
    }
}

echo $response;
