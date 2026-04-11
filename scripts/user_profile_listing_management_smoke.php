<?php
// User profile + listing management smoke test.
// Covers: register/approve/login, profile update (including password change),
// submit listing, update listing, mark sold, and delete listing.

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$_SERVER['PHP_SELF'] = $_SERVER['PHP_SELF'] ?? 'scripts/user_profile_listing_management_smoke.php';

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
$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'motorlink-user-mgmt-tests-' . uniqid();
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
    $stmt = $db->prepare("SELECT id, email, status, email_verified, password_hash FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function getFirstId(PDO $db, $table) {
    $stmt = $db->query("SELECT id FROM {$table} ORDER BY id ASC LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['id'] : null;
}

$stamp = date('YmdHis');
$email = strtolower('autotest_usermgmt_' . $stamp . '@example.com');
$username = 'autotest_usermgmt_' . $stamp;
$password = 'Start#12345';
$newPassword = 'Changed#12345';
$fullName = 'User Mgmt Tester ' . $stamp;
$updatedFullName = 'User Mgmt Updated ' . $stamp;
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
    'phone' => '+265991654321',
    'city' => 'Lilongwe',
    'address' => 'Area 47',
    'user_type' => 'individual',
]);
requireSuccess('Register user', $register, $failures, $passes);

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
requireSuccess('Login (initial password)', $login, $failures, $passes);

$profileUpdate = apiCall($baseUrl . '/api.php?action=update_profile', 'POST', [
    'full_name' => $updatedFullName,
    'phone' => '+265991000111',
    'whatsapp' => '+265881000111',
    'city' => 'Blantyre',
    'address' => 'Nyambadwe',
    'bio' => 'Smoke test profile update',
    'current_password' => $password,
    'new_password' => $newPassword,
], $userCookie);
requireSuccess('Update profile + change password', $profileUpdate, $failures, $passes);

$relogin = apiCall($baseUrl . '/api.php?action=login', 'POST', [
    'email' => $email,
    'password' => $newPassword,
], $userCookie);
requireSuccess('Login (new password)', $relogin, $failures, $passes);

$getProfile = apiCall($baseUrl . '/api.php?action=get_profile', 'GET', null, $userCookie);
if (requireSuccess('Get profile after update', $getProfile, $failures, $passes)) {
    $profile = $getProfile['json']['profile'] ?? [];
    $nameOk = (($profile['full_name'] ?? '') === $updatedFullName);
    $cityOk = (($profile['city'] ?? '') === 'Blantyre');
    $bioOk = (($profile['bio'] ?? '') === 'Smoke test profile update');

    if ($nameOk && $cityOk && $bioOk) {
        ok('Profile fields persisted', $passes);
    } else {
        fail('Profile fields persisted', 'Mismatch in persisted profile values', $failures);
    }
}

$makeId = getFirstId($db, 'car_makes');
$modelId = null;
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
        'title' => 'User Mgmt Smoke Listing ' . $stamp,
        'make_id' => $makeId,
        'model_id' => $modelId,
        'year' => 2019,
        'price' => 9000000,
        'location_id' => $locationId,
        'fuel_type' => 'petrol',
        'transmission' => 'automatic',
        'condition_type' => 'good',
        'description' => 'Listing management smoke test listing',
        'listing_type' => 'free',
        'mileage' => 65000,
    ], $userCookie);

    $listingId = 0;
    if (requireSuccess('Submit listing', $submit, $failures, $passes)) {
        $listingId = (int)($submit['json']['listing_id'] ?? 0);
    }

    if ($listingId > 0) {
        $updateListing = apiCall($baseUrl . '/api.php?action=update_listing', 'POST', [
            'listing_id' => $listingId,
            'title' => 'User Mgmt Smoke Listing Updated ' . $stamp,
            'price' => 9450000,
            'mileage' => 65150,
            'description' => 'Updated by user management smoke test',
        ], $userCookie);
        requireSuccess('Update listing', $updateListing, $failures, $passes);

        $markSold = apiCall($baseUrl . '/api.php?action=update_listing_status', 'POST', [
            'listing_id' => $listingId,
            'status' => 'sold',
        ], $userCookie);
        requireSuccess('Mark listing sold', $markSold, $failures, $passes);

        $myListings = apiCall($baseUrl . '/api.php?action=my_listings', 'GET', null, $userCookie);
        if (requireSuccess('My listings after update', $myListings, $failures, $passes)) {
            $matched = null;
            foreach (($myListings['json']['listings'] ?? []) as $row) {
                if ((int)($row['id'] ?? 0) === $listingId) {
                    $matched = $row;
                    break;
                }
            }

            if (!$matched) {
                fail('Updated listing visible in my_listings', 'Updated listing missing from response', $failures);
            } else {
                $titleOk = (($matched['title'] ?? '') === 'User Mgmt Smoke Listing Updated ' . $stamp);
                $statusOk = (($matched['status'] ?? '') === 'sold');
                if ($titleOk && $statusOk) {
                    ok('Updated listing persisted', $passes);
                } else {
                    fail('Updated listing persisted', 'Listing update/status mismatch', $failures);
                }
            }
        }

        $deleteListing = apiCall($baseUrl . '/api.php?action=delete_listing', 'POST', [
            'listing_id' => $listingId,
        ], $userCookie);
        requireSuccess('Delete listing', $deleteListing, $failures, $passes);

        $myListingsAfterDelete = apiCall($baseUrl . '/api.php?action=my_listings', 'GET', null, $userCookie);
        if (requireSuccess('My listings after delete', $myListingsAfterDelete, $failures, $passes)) {
            $foundDeleted = false;
            foreach (($myListingsAfterDelete['json']['listings'] ?? []) as $row) {
                if ((int)($row['id'] ?? 0) === $listingId) {
                    $foundDeleted = true;
                    break;
                }
            }

            if ($foundDeleted) {
                fail('Deleted listing removed from my_listings', 'Deleted listing is still returned', $failures);
            } else {
                ok('Deleted listing removed from my_listings', $passes);
            }
        }
    }
}

echo "\n========== SUMMARY ==========\n";
echo "Passes: " . count($passes) . "\n";
echo "Failures: " . count($failures) . "\n";

if (!empty($failures)) {
    foreach ($failures as $f) {
        echo " - {$f['label']}: {$f['details']}\n";
    }
    exit(1);
}

echo "All user management checks passed.\n";
exit(0);
