<?php
// Full role upload smoke test for seller, dealer, garage, and car-hire flows.
// Includes account lifecycle + upload actions.

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$_SERVER['PHP_SELF'] = $_SERVER['PHP_SELF'] ?? 'scripts/full_role_upload_smoke.php';

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
$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'motorlink-role-upload-tests-' . uniqid();
if (!is_dir($tmpDir) && !mkdir($tmpDir, 0700, true)) {
    fwrite(STDERR, "Failed to create temp dir\n");
    exit(1);
}

$imgPath = $tmpDir . DIRECTORY_SEPARATOR . 'tiny.png';
$pngData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO7ZfWQAAAAASUVORK5CYII=');
file_put_contents($imgPath, $pngData);

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
    curl_setopt($ch, CURLOPT_TIMEOUT, 90);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    if ($allowInsecureTls) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    }

    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($payload !== null) {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }
    } else {
        curl_setopt($ch, CURLOPT_HTTPGET, true);
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

function multipartCall($url, array $fields, $cookieFile = null) {
    global $allowInsecureTls;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);

    if ($allowInsecureTls) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    }

    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
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
    $stmt = $db->prepare("SELECT id, email, user_type, status, email_verified, verification_token FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function getFirstId(PDO $db, $table) {
    $stmt = $db->query("SELECT id FROM {$table} ORDER BY id ASC LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['id'] : null;
}

$stamp = date('YmdHis');
$roles = [
    ['key' => 'individual', 'label' => 'Seller'],
    ['key' => 'dealer', 'label' => 'Dealer'],
    ['key' => 'garage', 'label' => 'Garage'],
    ['key' => 'car_hire', 'label' => 'Car Hire'],
];

$users = [];
foreach ($roles as $idx => $role) {
    $local = 'johnpaulchirwa+' . $role['key'] . '_' . $stamp . '_' . $idx;
    $users[$role['key']] = [
        'email' => $local . '@gmail.com',
        'username' => 'smoke_' . $role['key'] . '_' . $stamp . '_' . $idx,
        'password' => 'Start#12345',
        'full_name' => $role['label'] . ' Upload Tester ' . $stamp,
        'cookie' => $tmpDir . DIRECTORY_SEPARATOR . $role['key'] . '.cookie',
        'id' => null,
    ];
}

$adminCookie = $tmpDir . DIRECTORY_SEPARATOR . 'admin.cookie';
$adminLogin = apiCall($baseUrl . '/admin/admin-api.php?action=admin_login', 'POST', [
    'email' => $adminEmail,
    'password' => $adminPassword,
], $adminCookie);
if (!requireSuccess('Admin login', $adminLogin, $failures, $passes)) {
    echo "Cannot continue without admin login.\n";
    exit(1);
}

foreach ($roles as $i => $role) {
    $u = &$users[$role['key']];

    $register = apiCall($baseUrl . '/api.php?action=register', 'POST', [
        'full_name' => $u['full_name'],
        'username' => $u['username'],
        'email' => $u['email'],
        'password' => $u['password'],
        'phone' => '+265991300' . sprintf('%03d', $i),
        'city' => 'Lilongwe',
        'address' => 'Area 47',
        'user_type' => $role['key'],
        'business_name' => $role['label'] . ' Biz ' . $stamp,
    ]);
    requireSuccess($role['label'] . ' register', $register, $failures, $passes);

    $dbUser = findUserByEmail($db, $u['email']);
    if (!$dbUser) {
        fail($role['label'] . ' db user created', 'User missing after register', $failures);
        continue;
    }

    $u['id'] = (int)$dbUser['id'];
    ok($role['label'] . ' db user created', $passes);

    $approve = apiCall($baseUrl . '/admin/admin-api.php?action=approve_user', 'POST', [
        'id' => $u['id'],
        'action' => 'approve',
    ], $adminCookie);
    requireSuccess($role['label'] . ' approved', $approve, $failures, $passes);

    $login = apiCall($baseUrl . '/api.php?action=login', 'POST', [
        'email' => $u['email'],
        'password' => $u['password'],
    ], $u['cookie']);
    requireSuccess($role['label'] . ' login', $login, $failures, $passes);
}

// Prerequisites for listing/fleet operations
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
    fail('Seed data check', 'Missing make/model/location records', $failures);
} else {
    ok('Seed data check', $passes);
}

// Seller: submit listing + upload listing image
if ($makeId && $modelId && $locationId) {
    $seller = $users['individual'];
    $submit = apiCall($baseUrl . '/api.php?action=submit_listing', 'POST', [
        'title' => 'Seller Upload Test ' . $stamp,
        'make_id' => $makeId,
        'model_id' => $modelId,
        'year' => 2019,
        'price' => 7600000,
        'location_id' => $locationId,
        'fuel_type' => 'petrol',
        'transmission' => 'manual',
        'condition_type' => 'good',
        'description' => 'Seller upload smoke test',
        'listing_type' => 'free',
    ], $seller['cookie']);

    if (requireSuccess('Seller submit listing', $submit, $failures, $passes)) {
        $listingId = (int)($submit['json']['listing_id'] ?? 0);
        $stmt = $db->prepare('SELECT listing_email_verification_token FROM car_listings WHERE id = ? LIMIT 1');
        $stmt->execute([$listingId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!empty($row['listing_email_verification_token'])) {
            $verify = apiCall($baseUrl . '/api.php?action=verify_listing_email&token=' . urlencode($row['listing_email_verification_token']), 'GET');
            requireSuccess('Seller verify listing email', $verify, $failures, $passes);
        }

        $upload = multipartCall($baseUrl . '/api.php?action=upload_images', [
            'listing_id' => (string)$listingId,
            'images[0]' => new CURLFile($imgPath, 'image/png', 'seller.png'),
        ], $seller['cookie']);
        requireSuccess('Seller upload listing image', $upload, $failures, $passes);
    }
}

// Dealer: ensure profile exists, add car, upload logo
if ($makeId && $modelId) {
    $dealer = $users['dealer'];
    $info = apiCall($baseUrl . '/api.php?action=get_dealer_info', 'GET', null, $dealer['cookie']);
    requireSuccess('Dealer get info', $info, $failures, $passes);

    $addCar = multipartCall($baseUrl . '/api.php?action=dealer_add_car', [
        'make' => 'Toyota',
        'model' => 'Corolla',
        'year' => '2020',
        'price' => '9800000',
        'description' => 'Dealer car upload smoke test',
        'mileage' => '54000',
        'fuel_type' => 'petrol',
        'transmission' => 'automatic',
        'color' => 'White',
        'images[0]' => new CURLFile($imgPath, 'image/png', 'dealer-car.png'),
    ], $dealer['cookie']);
    requireSuccess('Dealer add car with image', $addCar, $failures, $passes);

    $dealerLogo = multipartCall($baseUrl . '/api.php?action=upload_dealer_logo', [
        'logo' => new CURLFile($imgPath, 'image/png', 'dealer-logo.png'),
    ], $dealer['cookie']);
    requireSuccess('Dealer upload logo', $dealerLogo, $failures, $passes);
}

// Garage: ensure profile exists, update services, upload logo
{
    $garage = $users['garage'];
    $info = apiCall($baseUrl . '/api.php?action=get_garage_info', 'GET', null, $garage['cookie']);
    requireSuccess('Garage get info', $info, $failures, $passes);

    $updateServices = apiCall($baseUrl . '/api.php?action=update_garage_services', 'POST', [
        'services' => 'Diagnostics,Engine,Brakes',
    ], $garage['cookie']);
    requireSuccess('Garage update services', $updateServices, $failures, $passes);

    $garageLogo = multipartCall($baseUrl . '/api.php?action=upload_garage_logo', [
        'logo' => new CURLFile($imgPath, 'image/png', 'garage-logo.png'),
    ], $garage['cookie']);
    requireSuccess('Garage upload logo', $garageLogo, $failures, $passes);
}

// Car hire: ensure profile exists, add fleet vehicle with image, upload logo
if ($makeId && $modelId) {
    $carHire = $users['car_hire'];
    $info = apiCall($baseUrl . '/api.php?action=get_car_hire_company_info', 'GET', null, $carHire['cookie']);
    requireSuccess('Car hire get company info', $info, $failures, $passes);

    $addFleet = multipartCall($baseUrl . '/api.php?action=add_car_hire_vehicle', [
        'make_id' => (string)$makeId,
        'model_id' => (string)$modelId,
        'year' => '2021',
        'daily_rate' => '90000',
        'description' => 'Car hire fleet upload smoke test',
        'license_plate' => 'SMK-' . substr($stamp, -4),
        'seats' => '5',
        'fuel_type' => 'petrol',
        'transmission' => 'manual',
        'color' => 'Silver',
        'status' => 'available',
        'images[0]' => new CURLFile($imgPath, 'image/png', 'fleet-vehicle.png'),
    ], $carHire['cookie']);
    requireSuccess('Car hire add fleet vehicle with image', $addFleet, $failures, $passes);

    $carHireLogo = multipartCall($baseUrl . '/api.php?action=upload_car_hire_logo', [
        'logo' => new CURLFile($imgPath, 'image/png', 'carhire-logo.png'),
    ], $carHire['cookie']);
    requireSuccess('Car hire upload logo', $carHireLogo, $failures, $passes);
}

$adminLogout = apiCall($baseUrl . '/admin/admin-api.php?action=admin_logout', 'POST', [], $adminCookie);
requireSuccess('Admin logout', $adminLogout, $failures, $passes);

foreach (glob($tmpDir . DIRECTORY_SEPARATOR . '*.cookie') as $cookieFile) {
    @unlink($cookieFile);
}
@unlink($imgPath);
@rmdir($tmpDir);

echo "\n=== Full Role Upload Smoke Summary ===\n";
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
