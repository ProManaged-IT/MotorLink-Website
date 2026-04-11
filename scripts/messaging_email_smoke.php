<?php
// Messaging email smoke test.
// Validates that both listing owner and buyer receive email notifications when they receive messages.

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$_SERVER['PHP_SELF'] = $_SERVER['PHP_SELF'] ?? 'scripts/messaging_email_smoke.php';

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
$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'motorlink-msg-mail-tests-' . uniqid();
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
    $stmt = $db->prepare("SELECT id, email, reset_token FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function getFirstId(PDO $db, $table) {
    $stmt = $db->query("SELECT id FROM {$table} ORDER BY id ASC LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['id'] : null;
}

function countRecipientSends($logPath, $recipientEmail) {
    if (!is_file($logPath)) {
        return 0;
    }

    $lines = @file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return 0;
    }

    $needle = 'Email sent successfully to: ' . $recipientEmail;
    $count = 0;
    foreach ($lines as $line) {
        if (strpos($line, $needle) !== false) {
            $count++;
        }
    }

    return $count;
}

$stamp = date('YmdHis');
$sellerEmail = 'johnpaulchirwa+msg_seller_' . $stamp . '@gmail.com';
$buyerEmail = 'johnpaulchirwa+msg_buyer_' . $stamp . '@gmail.com';
$pwd = 'Start#12345';

$adminCookie = $tmpDir . DIRECTORY_SEPARATOR . 'admin.cookie';
$sellerCookie = $tmpDir . DIRECTORY_SEPARATOR . 'seller.cookie';
$buyerCookie = $tmpDir . DIRECTORY_SEPARATOR . 'buyer.cookie';

$logPath = __DIR__ . '/../logs/smtp_emails.log';
$sellerBefore = countRecipientSends($logPath, $sellerEmail);
$buyerBefore = countRecipientSends($logPath, $buyerEmail);

$adminLogin = apiCall($baseUrl . '/admin/admin-api.php?action=admin_login', 'POST', [
    'email' => $adminEmail,
    'password' => $adminPassword,
], $adminCookie);
if (!requireSuccess('Admin login', $adminLogin, $failures, $passes)) {
    echo "Cannot continue without admin login.\n";
    exit(1);
}

$users = [
    'seller' => [
        'email' => $sellerEmail,
        'username' => 'msg_seller_' . $stamp,
        'full_name' => 'Message Seller ' . $stamp,
        'cookie' => $sellerCookie,
        'id' => null,
    ],
    'buyer' => [
        'email' => $buyerEmail,
        'username' => 'msg_buyer_' . $stamp,
        'full_name' => 'Message Buyer ' . $stamp,
        'cookie' => $buyerCookie,
        'id' => null,
    ],
];

foreach ($users as $key => &$u) {
    $register = apiCall($baseUrl . '/api.php?action=register', 'POST', [
        'full_name' => $u['full_name'],
        'username' => $u['username'],
        'email' => $u['email'],
        'password' => $pwd,
        'phone' => '+265991700' . ($key === 'seller' ? '001' : '002'),
        'city' => 'Lilongwe',
        'address' => 'Area 25',
        'user_type' => 'individual',
    ]);
    requireSuccess(ucfirst($key) . ' register', $register, $failures, $passes);

    $dbUser = findUserByEmail($db, $u['email']);
    if (!$dbUser) {
        fail(ucfirst($key) . ' db user created', 'User missing after register', $failures);
        continue;
    }
    $u['id'] = (int)$dbUser['id'];
    ok(ucfirst($key) . ' db user created', $passes);

    $approve = apiCall($baseUrl . '/admin/admin-api.php?action=approve_user', 'POST', [
        'id' => $u['id'],
        'action' => 'approve',
    ], $adminCookie);
    requireSuccess(ucfirst($key) . ' approved', $approve, $failures, $passes);

    $login = apiCall($baseUrl . '/api.php?action=login', 'POST', [
        'email' => $u['email'],
        'password' => $pwd,
    ], $u['cookie']);
    requireSuccess(ucfirst($key) . ' login', $login, $failures, $passes);
}
unset($u);

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

    $submit = apiCall($baseUrl . '/api.php?action=submit_listing', 'POST', [
        'title' => 'Message Listing ' . $stamp,
        'make_id' => $makeId,
        'model_id' => $modelId,
        'year' => 2020,
        'price' => 9500000,
        'location_id' => $locationId,
        'fuel_type' => 'petrol',
        'transmission' => 'manual',
        'condition_type' => 'good',
        'description' => 'Messaging email smoke listing',
        'listing_type' => 'free',
    ], $users['seller']['cookie']);

    if (requireSuccess('Seller submit listing', $submit, $failures, $passes)) {
        $listingId = (int)($submit['json']['listing_id'] ?? 0);

        $stmt = $db->prepare('SELECT listing_email_verification_token FROM car_listings WHERE id = ? LIMIT 1');
        $stmt->execute([$listingId]);
        $listing = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!empty($listing['listing_email_verification_token'])) {
            $verify = apiCall($baseUrl . '/api.php?action=verify_listing_email&token=' . urlencode($listing['listing_email_verification_token']), 'GET');
            requireSuccess('Seller verify listing email', $verify, $failures, $passes);
        }

        $start = apiCall($baseUrl . '/api.php?action=start_conversation', 'POST', [
            'listing_id' => $listingId,
            'seller_id' => $users['seller']['id'],
            'message' => 'Hello seller, is this still available?',
        ], $users['buyer']['cookie']);

        if (requireSuccess('Buyer starts conversation', $start, $failures, $passes)) {
            $conversationId = (int)($start['json']['conversation_id'] ?? 0);

            $reply = apiCall($baseUrl . '/api.php?action=send_message', 'POST', [
                'conversation_id' => $conversationId,
                'message' => 'Yes, it is available. You can view it this weekend.',
            ], $users['seller']['cookie']);
            requireSuccess('Seller replies in conversation', $reply, $failures, $passes);
        }
    }
}

$adminLogout = apiCall($baseUrl . '/admin/admin-api.php?action=admin_logout', 'POST', [], $adminCookie);
requireSuccess('Admin logout', $adminLogout, $failures, $passes);

sleep(1);
$sellerAfter = countRecipientSends($logPath, $sellerEmail);
$buyerAfter = countRecipientSends($logPath, $buyerEmail);

if ($sellerAfter > $sellerBefore) {
    ok('Seller received message notification email', $passes);
} else {
    fail('Seller received message notification email', 'No new SMTP success log entry found for seller recipient', $failures);
}

if ($buyerAfter > $buyerBefore) {
    ok('Buyer received message notification email', $passes);
} else {
    fail('Buyer received message notification email', 'No new SMTP success log entry found for buyer recipient', $failures);
}

foreach (glob($tmpDir . DIRECTORY_SEPARATOR . '*.cookie') as $cookieFile) {
    @unlink($cookieFile);
}
@rmdir($tmpDir);

echo "\n=== Messaging Email Smoke Summary ===\n";
echo 'Seller recipient: ' . $sellerEmail . "\n";
echo 'Buyer recipient: ' . $buyerEmail . "\n";
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
