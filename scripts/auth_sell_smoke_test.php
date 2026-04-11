<?php
// Auth + sell flow smoke test for MotorLink.
// Covers: register, admin approve, login, password reset, login again, submit listing,
// listing email verification (if enabled), and my_listings ownership check.

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$_SERVER['PHP_SELF'] = $_SERVER['PHP_SELF'] ?? 'scripts/auth_sell_smoke_test.php';

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
$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'motorlink-auth-sell-tests-' . uniqid();
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

function findUserByEmail(PDO $db, $email) {
    $stmt = $db->prepare("SELECT id, email, status, email_verified, reset_token FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function getFirstId(PDO $db, $table) {
    $stmt = $db->query("SELECT id FROM {$table} ORDER BY id ASC LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['id'] : null;
}

$stamp = date('YmdHis');
$email = strtolower('autotest_authsell_' . $stamp . '@example.com');
$username = 'autotest_authsell_' . $stamp;
$password = 'Start#12345';
$newPassword = 'Reset#12345';
$fullName = 'Auth Sell Tester ' . $stamp;
$userCookie = $tmpDir . DIRECTORY_SEPARATOR . 'user.cookie';
$adminCookie = $tmpDir . DIRECTORY_SEPARATOR . 'admin.cookie';

$adminLogin = apiCall($baseUrl . '/admin/admin-api.php?action=admin_login', 'POST', [
    'email' => $adminEmail,
    'password' => $adminPassword,
], $adminCookie);
if (!requireSuccess('Admin login', $adminLogin, $failures, $passes)) {
    echo "Cannot continue without admin login.\n";
    exit(1);
}

$register = apiCall($baseUrl . '/api.php?action=register', 'POST', [
    'full_name' => $fullName,
    'username' => $username,
    'email' => $email,
    'password' => $password,
    'phone' => '+265991123123',
    'city' => 'Lilongwe',
    'address' => 'Area 10',
    'user_type' => 'individual',
]);
requireSuccess('Register', $register, $failures, $passes);

$user = findUserByEmail($db, $email);
if (!$user) {
    fail('User exists in DB', 'User missing after register', $failures);
} else {
    ok('User exists in DB', $passes);

    $approve = apiCall($baseUrl . '/admin/admin-api.php?action=approve_user', 'POST', [
        'id' => (int)$user['id'],
        'action' => 'approve',
    ], $adminCookie);
    requireSuccess('Admin approve user', $approve, $failures, $passes);
}

$login = apiCall($baseUrl . '/api.php?action=login', 'POST', [
    'email' => $email,
    'password' => $password,
], $userCookie);
requireSuccess('Login', $login, $failures, $passes);

$reqReset = apiCall($baseUrl . '/api.php?action=request_password_reset', 'POST', [
    'email' => $email,
]);
requireSuccess('Request password reset', $reqReset, $failures, $passes);

$user = findUserByEmail($db, $email);
if (!$user || empty($user['reset_token'])) {
    fail('Reset token generated', 'No reset token found in DB', $failures);
} else {
    ok('Reset token generated', $passes);

    $reset = apiCall($baseUrl . '/api.php?action=reset_password', 'POST', [
        'token' => $user['reset_token'],
        'user_id' => (int)$user['id'],
        'password' => $newPassword,
        'confirm_password' => $newPassword,
    ]);
    requireSuccess('Reset password', $reset, $failures, $passes);
}

$relogin = apiCall($baseUrl . '/api.php?action=login', 'POST', [
    'email' => $email,
    'password' => $newPassword,
], $userCookie);
requireSuccess('Login with new password', $relogin, $failures, $passes);

$makeId = getFirstId($db, 'car_makes');
$modelId = $makeId ? null : null;
if ($makeId) {
    $stmt = $db->prepare('SELECT id FROM car_models WHERE make_id = ? ORDER BY id ASC LIMIT 1');
    $stmt->execute([$makeId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $modelId = $row ? (int)$row['id'] : null;
}
$locationId = getFirstId($db, 'locations');

if (!$makeId || !$modelId || !$locationId) {
    fail('Listing prerequisites', 'Missing make/model/location seed data', $failures);
} else {
    ok('Listing prerequisites', $passes);

    $submit = apiCall($baseUrl . '/api.php?action=submit_listing', 'POST', [
        'title' => 'Smoke Test Listing ' . $stamp,
        'make_id' => $makeId,
        'model_id' => $modelId,
        'year' => 2018,
        'price' => 8500000,
        'location_id' => $locationId,
        'fuel_type' => 'petrol',
        'transmission' => 'automatic',
        'condition_type' => 'good',
        'description' => 'Automated smoke test listing',
        'listing_type' => 'free',
    ], $userCookie);

    if (requireSuccess('Submit listing', $submit, $failures, $passes)) {
        $listingId = (int)($submit['json']['listing_id'] ?? 0);

        $stmt = $db->prepare('SELECT id, user_id, listing_email_verification_token, listing_email_verified FROM car_listings WHERE id = ? LIMIT 1');
        $stmt->execute([$listingId]);
        $listing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$listing || (int)$listing['user_id'] !== (int)$user['id']) {
            fail('Listing ownership', 'Listing not linked to logged-in user', $failures);
        } else {
            ok('Listing ownership', $passes);

            if (!empty($listing['listing_email_verification_token'])) {
                $verify = apiCall($baseUrl . '/api.php?action=verify_listing_email&token=' . urlencode($listing['listing_email_verification_token']), 'GET');
                requireSuccess('Verify listing email', $verify, $failures, $passes);
            }
        }

        $myListings = apiCall($baseUrl . '/api.php?action=my_listings', 'GET', null, $userCookie);
        if (requireSuccess('My listings', $myListings, $failures, $passes)) {
            $found = false;
            foreach (($myListings['json']['listings'] ?? []) as $row) {
                if ((int)($row['id'] ?? 0) === $listingId) {
                    $found = true;
                    break;
                }
            }
            if ($found) {
                ok('Listing visible in my_listings', $passes);
            } else {
                fail('Listing visible in my_listings', 'Submitted listing not returned', $failures);
            }
        }
    }
}

$adminLogout = apiCall($baseUrl . '/admin/admin-api.php?action=admin_logout', 'POST', [], $adminCookie);
requireSuccess('Admin logout', $adminLogout, $failures, $passes);

foreach (glob($tmpDir . DIRECTORY_SEPARATOR . '*.cookie') as $cookieFile) {
    @unlink($cookieFile);
}
@rmdir($tmpDir);

echo "\n=== Auth + Sell Smoke Summary ===\n";
echo 'Passed: ' . count($passes) . "\n";
echo 'Failed: ' . count($failures) . "\n";

if (!empty($failures)) {
    foreach ($failures as $f) {
        echo '- ' . $f['label'] . ' => ' . $f['details'] . "\n";
    }
    exit(2);
}

echo "Status: PASS\n";
exit(0);
