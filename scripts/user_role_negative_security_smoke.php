<?php
// Negative/security smoke test for user role cycles.
// Covers: auth-required enforcement, ownership isolation, invalid profile/listing updates.

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$_SERVER['PHP_SELF'] = $_SERVER['PHP_SELF'] ?? 'scripts/user_role_negative_security_smoke.php';

require_once __DIR__ . '/../api-common.php';

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(1);
}

$baseUrl = getenv('MOTORLINK_TEST_BASE_URL') ?: 'https://promanaged-it.com/motorlink';
$adminEmail = getenv('MOTORLINK_ADMIN_EMAIL') ?: 'admin@motorlink.mw';
$adminPassword = getenv('MOTORLINK_ADMIN_PASSWORD') ?: 'password';
$baseHost = parse_url($baseUrl, PHP_URL_HOST) ?: '';
$defaultInsecureTls = in_array($baseHost, ['localhost', '127.0.0.1'], true) ? '1' : '0';
$allowInsecureTls = filter_var(getenv('MOTORLINK_TEST_INSECURE_TLS') ?: $defaultInsecureTls, FILTER_VALIDATE_BOOLEAN);

$db = getDB();
$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'motorlink-negative-security-tests-' . uniqid();
if (!is_dir($tmpDir) && !mkdir($tmpDir, 0700, true)) {
    fwrite(STDERR, "Failed to create temp dir\n");
    exit(1);
}

$failures = [];
$passes = [];

function ok($label, &$passes) {
    $passes[] = $label;
    echo "[PASS] {$label}\n";
}

function fail($label, $details, &$failures) {
    $failures[] = ['label' => $label, 'details' => $details];
    echo "[FAIL] {$label} :: {$details}\n";
}

function apiCall($url, $method = 'GET', $payload = null, $cookieFile = null) {
    global $allowInsecureTls;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    if ($allowInsecureTls) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    }

    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }

    $headers = [];
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($payload !== null) {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            $headers[] = 'Content-Type: application/json';
        }
    } else {
        curl_setopt($ch, CURLOPT_HTTPGET, true);
    }

    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false || !empty($err)) {
        return ['ok' => false, 'http' => $httpCode, 'error' => $err ?: 'Unknown cURL error', 'json' => null, 'raw' => ''];
    }

    $json = json_decode($body, true);
    return ['ok' => true, 'http' => $httpCode, 'error' => null, 'json' => $json, 'raw' => $body];
}

function requireSuccess($label, $result, &$failures, &$passes) {
    if (!$result['ok']) {
        fail($label, 'transport error: ' . $result['error'], $failures);
        return false;
    }

    if ($result['http'] >= 400) {
        fail($label, 'HTTP ' . $result['http'] . ' body=' . substr($result['raw'], 0, 260), $failures);
        return false;
    }

    if (!is_array($result['json']) || !($result['json']['success'] ?? false)) {
        fail($label, 'API not successful: ' . substr($result['raw'], 0, 260), $failures);
        return false;
    }

    ok($label, $passes);
    return true;
}

function requireFailure($label, $result, $expectedHttpCodes, &$failures, &$passes) {
    if (!$result['ok']) {
        fail($label, 'transport error: ' . $result['error'], $failures);
        return false;
    }

    $codeOk = in_array((int)$result['http'], $expectedHttpCodes, true);
    $json = is_array($result['json']) ? $result['json'] : [];
    $apiFailed = isset($json['success']) ? !$json['success'] : ((int)$result['http'] >= 400);

    if (!$codeOk || !$apiFailed) {
        fail(
            $label,
            'Expected failure HTTP ' . implode('/', $expectedHttpCodes) . ' but got HTTP ' . $result['http'] . ' body=' . substr($result['raw'], 0, 260),
            $failures
        );
        return false;
    }

    ok($label, $passes);
    return true;
}

function findUserByEmail(PDO $db, $email) {
    $stmt = $db->prepare("SELECT id, email, status FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function getFirstId(PDO $db, $table) {
    $stmt = $db->query("SELECT id FROM {$table} ORDER BY id ASC LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['id'] : null;
}

$stamp = date('YmdHis');

$userAEmail = strtolower('autotest_neg_a_' . $stamp . '@example.com');
$userAUsername = 'autotest_nega_' . $stamp;
$userAPassword = 'Start#12345';
$userACookie = $tmpDir . DIRECTORY_SEPARATOR . 'userA.cookie';

$userBEmail = strtolower('autotest_neg_b_' . $stamp . '@example.com');
$userBUsername = 'autotest_negb_' . $stamp;
$userBPassword = 'Start#12345';
$userBCookie = $tmpDir . DIRECTORY_SEPARATOR . 'userB.cookie';

$adminCookie = $tmpDir . DIRECTORY_SEPARATOR . 'admin.cookie';

$adminLogin = apiCall($baseUrl . '/admin/admin-api.php?action=admin_login', 'POST', [
    'email' => $adminEmail,
    'password' => $adminPassword,
], $adminCookie);
if (!requireSuccess('Admin login', $adminLogin, $failures, $passes)) {
    echo "Cannot continue without admin login.\n";
    exit(1);
}

$registerA = apiCall($baseUrl . '/api.php?action=register', 'POST', [
    'full_name' => 'Negative Test User A',
    'username' => $userAUsername,
    'email' => $userAEmail,
    'password' => $userAPassword,
    'phone' => '+265991777111',
    'city' => 'Lilongwe',
    'address' => 'Area 4',
    'user_type' => 'individual',
]);
requireSuccess('Register user A', $registerA, $failures, $passes);

$registerB = apiCall($baseUrl . '/api.php?action=register', 'POST', [
    'full_name' => 'Negative Test User B',
    'username' => $userBUsername,
    'email' => $userBEmail,
    'password' => $userBPassword,
    'phone' => '+265991777222',
    'city' => 'Blantyre',
    'address' => 'Chirimba',
    'user_type' => 'individual',
]);
requireSuccess('Register user B', $registerB, $failures, $passes);

$userA = findUserByEmail($db, $userAEmail);
$userB = findUserByEmail($db, $userBEmail);

if (!$userA || !$userB) {
    fail('Users created in DB', 'User A or User B missing after registration', $failures);
} else {
    ok('Users created in DB', $passes);

    $approveA = apiCall($baseUrl . '/admin/admin-api.php?action=approve_user', 'POST', [
        'id' => (int)$userA['id'],
        'action' => 'approve',
    ], $adminCookie);
    requireSuccess('Approve user A', $approveA, $failures, $passes);

    $approveB = apiCall($baseUrl . '/admin/admin-api.php?action=approve_user', 'POST', [
        'id' => (int)$userB['id'],
        'action' => 'approve',
    ], $adminCookie);
    requireSuccess('Approve user B', $approveB, $failures, $passes);
}

$loginA = apiCall($baseUrl . '/api.php?action=login', 'POST', [
    'email' => $userAEmail,
    'password' => $userAPassword,
], $userACookie);
requireSuccess('Login user A', $loginA, $failures, $passes);

$loginB = apiCall($baseUrl . '/api.php?action=login', 'POST', [
    'email' => $userBEmail,
    'password' => $userBPassword,
], $userBCookie);
requireSuccess('Login user B', $loginB, $failures, $passes);

$unauthUpdateProfile = apiCall($baseUrl . '/api.php?action=update_profile', 'POST', [
    'full_name' => 'Should Fail'
]);
requireFailure('Unauthenticated update_profile blocked', $unauthUpdateProfile, [401], $failures, $passes);

$unauthUpdateListing = apiCall($baseUrl . '/api.php?action=update_listing', 'POST', [
    'listing_id' => 1,
    'title' => 'Should Fail'
]);
requireFailure('Unauthenticated update_listing blocked', $unauthUpdateListing, [401], $failures, $passes);

$unauthDeleteListing = apiCall($baseUrl . '/api.php?action=delete_listing', 'POST', [
    'listing_id' => 1
]);
requireFailure('Unauthenticated delete_listing blocked', $unauthDeleteListing, [401], $failures, $passes);

$invalidProfileUpdate = apiCall($baseUrl . '/api.php?action=update_profile', 'POST', [
    'full_name' => ''
], $userACookie);
requireFailure('Profile update requires full_name', $invalidProfileUpdate, [400], $failures, $passes);

$wrongPasswordChange = apiCall($baseUrl . '/api.php?action=update_profile', 'POST', [
    'full_name' => 'Negative Test User A',
    'current_password' => 'Wrong#12345',
    'new_password' => 'Changed#12345'
], $userACookie);
requireFailure('Password change rejects wrong current password', $wrongPasswordChange, [400], $failures, $passes);

$makeId = getFirstId($db, 'car_makes');
$modelId = null;
if ($makeId) {
    $stmt = $db->prepare('SELECT id FROM car_models WHERE make_id = ? ORDER BY id ASC LIMIT 1');
    $stmt->execute([$makeId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $modelId = $row ? (int)$row['id'] : null;
}
$locationId = getFirstId($db, 'locations');

$listingId = 0;
if (!$makeId || !$modelId || !$locationId) {
    fail('Listing prerequisites', 'Missing make/model/location seed data', $failures);
} else {
    ok('Listing prerequisites', $passes);

    $submit = apiCall($baseUrl . '/api.php?action=submit_listing', 'POST', [
        'title' => 'Negative Security Listing ' . $stamp,
        'make_id' => $makeId,
        'model_id' => $modelId,
        'year' => 2018,
        'price' => 8000000,
        'location_id' => $locationId,
        'fuel_type' => 'petrol',
        'transmission' => 'automatic',
        'condition_type' => 'good',
        'description' => 'Negative security smoke listing',
        'listing_type' => 'free',
        'mileage' => 75000,
    ], $userACookie);

    if (requireSuccess('User A submit listing', $submit, $failures, $passes)) {
        $listingId = (int)($submit['json']['listing_id'] ?? 0);
    }
}

if ($listingId > 0) {
    $crossUpdate = apiCall($baseUrl . '/api.php?action=update_listing', 'POST', [
        'listing_id' => $listingId,
        'title' => 'User B Unauthorized Edit'
    ], $userBCookie);
    requireFailure('User B cannot update user A listing', $crossUpdate, [403, 404], $failures, $passes);

    $crossDelete = apiCall($baseUrl . '/api.php?action=delete_listing', 'POST', [
        'listing_id' => $listingId,
    ], $userBCookie);
    requireFailure('User B cannot delete user A listing', $crossDelete, [403, 404], $failures, $passes);

    $crossStatus = apiCall($baseUrl . '/api.php?action=update_listing_status', 'POST', [
        'listing_id' => $listingId,
        'status' => 'sold',
    ], $userBCookie);
    requireFailure('User B cannot change user A listing status', $crossStatus, [404], $failures, $passes);

    $invalidStatus = apiCall($baseUrl . '/api.php?action=update_listing_status', 'POST', [
        'listing_id' => $listingId,
        'status' => 'archived',
    ], $userACookie);
    requireFailure('Invalid listing status rejected', $invalidStatus, [400], $failures, $passes);

    $fraudYearChange = apiCall($baseUrl . '/api.php?action=update_listing', 'POST', [
        'listing_id' => $listingId,
        'year' => 2015,
    ], $userACookie);
    requireFailure('Fraud guard blocks year change', $fraudYearChange, [400], $failures, $passes);

    $fraudPriceJump = apiCall($baseUrl . '/api.php?action=update_listing', 'POST', [
        'listing_id' => $listingId,
        'price' => 20000000,
    ], $userACookie);
    requireFailure('Fraud guard blocks excessive price jump', $fraudPriceJump, [400], $failures, $passes);

    $ownerDelete = apiCall($baseUrl . '/api.php?action=delete_listing', 'POST', [
        'listing_id' => $listingId,
    ], $userACookie);
    requireSuccess('Owner can delete own listing', $ownerDelete, $failures, $passes);
}

echo "\n========== NEGATIVE SECURITY SUMMARY ==========\n";
echo "Passes: " . count($passes) . "\n";
echo "Failures: " . count($failures) . "\n";

if (!empty($failures)) {
    foreach ($failures as $f) {
        echo " - {$f['label']}: {$f['details']}\n";
    }
    exit(1);
}

echo "All negative/security checks passed.\n";
exit(0);
