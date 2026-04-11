<?php
// End-to-end role flow tests for MotorLink.
// Covers: register, admin approval, login, password reset, profile updates,
// and dealer/garage/car-hire management endpoints.

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$_SERVER['PHP_SELF'] = $_SERVER['PHP_SELF'] ?? 'scripts/role_flow_tests.php';

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
$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'motorlink-role-tests-' . uniqid();
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
    curl_setopt($ch, CURLOPT_TIMEOUT, 45);
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
        fail($label, 'HTTP ' . $result['http'] . ' body=' . substr($result['raw'], 0, 200), $failures);
        return false;
    }

    if (!is_array($result['json']) || !($result['json']['success'] ?? false)) {
        fail($label, 'API not successful: ' . substr($result['raw'], 0, 240), $failures);
        return false;
    }

    ok($label, $passes);
    return true;
}

function findUserByEmail(PDO $db, $email) {
    $stmt = $db->prepare("SELECT id, email, user_type, status, email_verified, reset_token, reset_token_expires FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function markUserVerified(PDO $db, $userId) {
    $stmt = $db->prepare("UPDATE users SET email_verified = 1, status = 'active', verification_token = NULL, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$userId]);
}

$roles = [
    ['key' => 'individual', 'label' => 'Guest user (individual)'],
    ['key' => 'dealer', 'label' => 'Dealer'],
    ['key' => 'garage', 'label' => 'Garage'],
    ['key' => 'car_hire', 'label' => 'Car hire'],
];

$stamp = date('YmdHis');
$users = [];

// Admin login for management/approval flow
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
    $email = strtolower('autotest_' . $role['key'] . '_' . $stamp . '_' . $i . '@example.com');
    $username = 'autotest_' . $role['key'] . '_' . $stamp . '_' . $i;
    $password = 'Start#12345';
    $newPassword = 'Reset#12345';

    $users[$role['key']] = [
        'email' => $email,
        'username' => $username,
        'password' => $password,
        'new_password' => $newPassword,
        'full_name' => ucfirst($role['key']) . ' Tester ' . $stamp,
        'cookie' => $tmpDir . DIRECTORY_SEPARATOR . $role['key'] . '.cookie',
        'id' => null,
    ];

    $register = apiCall($baseUrl . '/api.php?action=register', 'POST', [
        'full_name' => $users[$role['key']]['full_name'],
        'username' => $username,
        'email' => $email,
        'password' => $password,
        'phone' => '+265991000' . sprintf('%03d', $i),
        'city' => 'Lilongwe',
        'address' => 'Area 47',
        'user_type' => $role['key'],
        'business_name' => ucfirst($role['key']) . ' Business ' . $stamp,
    ]);

    requireSuccess($role['label'] . ' register', $register, $failures, $passes);

    $dbUser = findUserByEmail($db, $email);
    if (!$dbUser) {
        fail($role['label'] . ' DB user created', 'User missing after register', $failures);
        continue;
    }

    $users[$role['key']]['id'] = (int)$dbUser['id'];
    ok($role['label'] . ' DB user created', $passes);

    // Admin approval path
    $approve = apiCall($baseUrl . '/admin/admin-api.php?action=approve_user', 'POST', [
        'id' => (int)$dbUser['id'],
        'action' => 'approve',
    ], $adminCookie);

    if (!requireSuccess($role['label'] . ' admin approve user', $approve, $failures, $passes)) {
        // Fallback safety to keep test progression while still reporting failure.
        markUserVerified($db, (int)$dbUser['id']);
        fail($role['label'] . ' approval fallback', 'Applied DB fallback activation due approve_user failure', $failures);
    }

    // Ensure email verification gate is passed so login can be tested.
    markUserVerified($db, (int)$dbUser['id']);

    $login = apiCall($baseUrl . '/api.php?action=login', 'POST', [
        'email' => $email,
        'password' => $password,
    ], $users[$role['key']]['cookie']);

    requireSuccess($role['label'] . ' login', $login, $failures, $passes);

    $profile = apiCall($baseUrl . '/api.php?action=get_profile', 'GET', null, $users[$role['key']]['cookie']);
    requireSuccess($role['label'] . ' get profile', $profile, $failures, $passes);

    $profileUpdate = apiCall($baseUrl . '/api.php?action=update_profile', 'POST', [
        'full_name' => $users[$role['key']]['full_name'] . ' Updated',
        'phone' => '+265999111' . sprintf('%03d', $i),
        'whatsapp' => '+265999111' . sprintf('%03d', $i),
        'city' => 'Blantyre',
        'address' => 'Updated Address',
    ], $users[$role['key']]['cookie']);
    requireSuccess($role['label'] . ' update profile', $profileUpdate, $failures, $passes);

    // Password reset end-to-end
    $reqReset = apiCall($baseUrl . '/api.php?action=request_password_reset', 'POST', [
        'email' => $email,
    ]);
    requireSuccess($role['label'] . ' request password reset', $reqReset, $failures, $passes);

    $dbUser = findUserByEmail($db, $email);
    if (!$dbUser || empty($dbUser['reset_token'])) {
        fail($role['label'] . ' reset token generated', 'No reset token found', $failures);
    } else {
        ok($role['label'] . ' reset token generated', $passes);

        $reset = apiCall($baseUrl . '/api.php?action=reset_password', 'POST', [
            'token' => $dbUser['reset_token'],
            'user_id' => (int)$dbUser['id'],
            'password' => $newPassword,
            'confirm_password' => $newPassword,
        ]);
        requireSuccess($role['label'] . ' reset password', $reset, $failures, $passes);

        $relogin = apiCall($baseUrl . '/api.php?action=login', 'POST', [
            'email' => $email,
            'password' => $newPassword,
        ], $users[$role['key']]['cookie']);
        requireSuccess($role['label'] . ' login with reset password', $relogin, $failures, $passes);
    }

    // Role-specific management flows
    if ($role['key'] === 'dealer') {
        $r1 = apiCall($baseUrl . '/api.php?action=get_dealer_info', 'GET', null, $users[$role['key']]['cookie']);
        requireSuccess('Dealer get dealer info', $r1, $failures, $passes);

        $r2 = apiCall($baseUrl . '/api.php?action=update_dealer_business', 'POST', [
            'business_name' => 'Dealer Biz ' . $stamp,
            'registration_number' => 'REG-' . $stamp,
            'tax_id' => 'TAX-' . $stamp,
            'business_type' => 'showroom',
            'years_in_business' => 3,
        ], $users[$role['key']]['cookie']);
        requireSuccess('Dealer update business', $r2, $failures, $passes);

        $r3 = apiCall($baseUrl . '/api.php?action=dealer_inventory', 'GET', null, $users[$role['key']]['cookie']);
        requireSuccess('Dealer inventory', $r3, $failures, $passes);
    }

    if ($role['key'] === 'garage') {
        $r1 = apiCall($baseUrl . '/api.php?action=get_garage_info', 'GET', null, $users[$role['key']]['cookie']);
        requireSuccess('Garage get info', $r1, $failures, $passes);

        $r2 = apiCall($baseUrl . '/api.php?action=update_garage_info', 'POST', [
            'garage_name' => 'Garage ' . $stamp,
            'description' => 'Garage description',
            'phone' => '+265881000001',
            'email' => $email,
            'address' => 'Garage address',
            'district' => 'Lilongwe',
        ], $users[$role['key']]['cookie']);
        requireSuccess('Garage update info', $r2, $failures, $passes);

        $r3 = apiCall($baseUrl . '/api.php?action=update_garage_hours', 'POST', [
            'operating_hours' => 'Mon-Fri 08:00-17:00',
        ], $users[$role['key']]['cookie']);
        requireSuccess('Garage update hours', $r3, $failures, $passes);

        $r4 = apiCall($baseUrl . '/api.php?action=update_garage_services', 'POST', [
            'services' => 'Engine repair,Brakes,Diagnostics',
        ], $users[$role['key']]['cookie']);
        requireSuccess('Garage update services', $r4, $failures, $passes);
    }

    if ($role['key'] === 'car_hire') {
        $r1 = apiCall($baseUrl . '/api.php?action=get_car_hire_company_info', 'GET', null, $users[$role['key']]['cookie']);
        requireSuccess('Car hire get company info', $r1, $failures, $passes);

        $r2 = apiCall($baseUrl . '/api.php?action=update_car_hire_company', 'POST', [
            'company_name' => 'Car Hire ' . $stamp,
            'description' => 'Car hire description',
            'phone' => '+265881000002',
            'email' => $email,
            'address' => 'Car hire address',
            'district' => 'Lilongwe',
        ], $users[$role['key']]['cookie']);
        requireSuccess('Car hire update company', $r2, $failures, $passes);

        $r3 = apiCall($baseUrl . '/api.php?action=get_car_hire_fleet', 'GET', null, $users[$role['key']]['cookie']);
        requireSuccess('Car hire get fleet', $r3, $failures, $passes);
    }
}

// Admin management visibility checks
$adminChecks = [
    ['label' => 'Admin check auth', 'url' => '/admin/admin-api.php?action=check_admin_auth'],
    ['label' => 'Admin get users', 'url' => '/admin/admin-api.php?action=get_users'],
    ['label' => 'Admin get dealers', 'url' => '/admin/admin-api.php?action=get_dealers'],
    ['label' => 'Admin get garages', 'url' => '/admin/admin-api.php?action=get_garages'],
    ['label' => 'Admin get car hire', 'url' => '/admin/admin-api.php?action=get_car_hire'],
];

foreach ($adminChecks as $check) {
    $res = apiCall($baseUrl . $check['url'], 'GET', null, $adminCookie);
    requireSuccess($check['label'], $res, $failures, $passes);
}

$adminLogout = apiCall($baseUrl . '/admin/admin-api.php?action=admin_logout', 'POST', [], $adminCookie);
requireSuccess('Admin logout', $adminLogout, $failures, $passes);

// Cleanup temp cookies
foreach (glob($tmpDir . DIRECTORY_SEPARATOR . '*.cookie') as $cookieFile) {
    @unlink($cookieFile);
}
@rmdir($tmpDir);

echo "\n=== Role Flow Test Summary ===\n";
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
