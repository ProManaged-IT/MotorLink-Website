<?php
// Onboarding notification smoke test.
// Verifies that onboarding sends credentials email and attempts optional WhatsApp notification.

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$_SERVER['PHP_SELF'] = $_SERVER['PHP_SELF'] ?? 'scripts/onboarding_notification_smoke.php';

require_once __DIR__ . '/../api-common.php';

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(1);
}

$baseUrl = getenv('MOTORLINK_TEST_BASE_URL') ?: 'https://promanaged-it.com/motorlink';
$requestedEmail = getenv('MOTORLINK_ONBOARD_TEST_EMAIL') ?: 'johnpaulchirwa@gmail.com';
$requestedWhatsapp = getenv('MOTORLINK_ONBOARD_TEST_WHATSAPP') ?: '+353860081635';
$baseHost = parse_url($baseUrl, PHP_URL_HOST) ?: '';
$defaultInsecureTls = in_array($baseHost, ['localhost', '127.0.0.1'], true) ? '1' : '0';
$allowInsecureTls = filter_var(getenv('MOTORLINK_TEST_INSECURE_TLS') ?: $defaultInsecureTls, FILTER_VALIDATE_BOOLEAN);

$db = getDB();
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

function apiCall($url, $method = 'GET', $payload = null) {
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

function findUserByEmail(PDO $db, $email) {
    $stmt = $db->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1');
    $stmt->execute([$email]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
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

$activeEmail = $requestedEmail;
if (findUserByEmail($db, $requestedEmail)) {
    $stamp = date('YmdHis');
    $activeEmail = 'johnpaulchirwa+onboard_' . $stamp . '@gmail.com';
    echo "[INFO] Requested email already exists in users. Using alias for onboarding creation: {$activeEmail}\n";
}

$locations = apiCall($baseUrl . '/onboarding/api-onboarding.php?action=locations', 'GET');
if (!$locations['ok'] || $locations['http'] >= 400 || !is_array($locations['json']) || !($locations['json']['success'] ?? false)) {
    fail('Load locations', 'Unable to fetch onboarding locations', $failures);
    exit(1);
}

$locationId = null;
if (!empty($locations['json']['locations']) && is_array($locations['json']['locations'])) {
    $first = $locations['json']['locations'][0];
    $locationId = $first['id'] ?? null;
}

if (!$locationId) {
    fail('Resolve location id', 'No active location found', $failures);
    exit(1);
}
ok('Resolve location id', $passes);

$logPath = __DIR__ . '/../logs/smtp_emails.log';
$scenarios = [
    ['label' => 'Opt-out', 'opt_in' => 0, 'expect_wa_status' => 'skipped'],
    ['label' => 'Opt-in', 'opt_in' => 1, 'expect_wa_status' => null],
];

foreach ($scenarios as $idx => $scenario) {
    $stamp = date('YmdHis') . $idx;
    $scenarioTag = preg_replace('/[^a-z0-9_]/', '_', strtolower($scenario['label']));
    $scenarioEmail = preg_replace('/@/', '+' . strtolower($scenario['label']) . '_' . $stamp . '@', $activeEmail, 1);
    $emailBefore = countRecipientSends($logPath, $scenarioEmail);

    $payload = [
        'business_name' => 'Onboard Notify Dealer ' . $stamp,
        'owner_name' => 'Onboarding Test Owner ' . $stamp,
        'email' => $scenarioEmail,
        'phone' => '+2659918' . substr($stamp, -4),
        'whatsapp' => $requestedWhatsapp,
        'whatsapp_updates_opt_in' => $scenario['opt_in'],
        'address' => 'Area 47, Lilongwe',
        'location_id' => (int)$locationId,
        'username' => 'onboard_notify_' . $scenarioTag . '_' . $stamp,
        'password' => 'Start#' . substr($stamp, -6) . 'A!',
        'specialization' => ['SUV', 'Sedan'],
        'description' => 'Onboarding notification smoke test account (' . $scenario['label'] . ')',
    ];

    $result = apiCall($baseUrl . '/onboarding/api-onboarding.php?action=add_dealer', 'POST', $payload);
    $labelPrefix = 'Dealer onboarding call [' . $scenario['label'] . ']';
    if (!$result['ok']) {
        fail($labelPrefix, 'Transport error: ' . $result['error'], $failures);
        continue;
    }
    if ($result['http'] >= 400) {
        fail($labelPrefix, 'HTTP ' . $result['http'] . ' body=' . substr($result['raw'], 0, 280), $failures);
        continue;
    }
    if (!is_array($result['json']) || !($result['json']['success'] ?? false)) {
        fail($labelPrefix, 'API did not return success', $failures);
        continue;
    }
    ok($labelPrefix, $passes);

    $notifications = $result['json']['notifications'] ?? null;
    if (is_array($notifications) && (($notifications['email']['sent'] ?? false) === true)) {
        ok('Credentials email flagged as sent by API [' . $scenario['label'] . ']', $passes);
    } else {
        fail('Credentials email flagged as sent by API [' . $scenario['label'] . ']', 'Missing notifications.email.sent=true', $failures);
    }

    if (is_array($notifications) && isset($notifications['whatsapp']['status'])) {
        $waStatus = (string)$notifications['whatsapp']['status'];
        if ($scenario['expect_wa_status'] !== null && $waStatus !== $scenario['expect_wa_status']) {
            fail('WhatsApp status [' . $scenario['label'] . ']', 'Expected ' . $scenario['expect_wa_status'] . ', got ' . $waStatus, $failures);
        } elseif (in_array($waStatus, ['sent', 'skipped', 'failed'], true)) {
            ok('WhatsApp notification processed [' . $scenario['label'] . ']: ' . $waStatus, $passes);
        } else {
            fail('WhatsApp notification processed [' . $scenario['label'] . ']', 'Unexpected status: ' . $waStatus, $failures);
        }
    } else {
        fail('WhatsApp notification processed [' . $scenario['label'] . ']', 'Missing notifications.whatsapp status', $failures);
    }

    $emailAfter = countRecipientSends($logPath, $scenarioEmail);
    if ($emailAfter > $emailBefore) {
        ok('SMTP log confirms onboarding email delivery [' . $scenario['label'] . ']', $passes);
    } else {
        fail('SMTP log confirms onboarding email delivery [' . $scenario['label'] . ']', 'No new SMTP success entry for ' . $scenarioEmail, $failures);
    }
}

echo "\n=== ONBOARDING NOTIFICATION SMOKE SUMMARY ===\n";
echo 'Requested Email: ' . $requestedEmail . "\n";
echo 'Test Recipient Email: ' . $activeEmail . "\n";
echo 'Requested WhatsApp: ' . $requestedWhatsapp . "\n";
echo 'Passes: ' . count($passes) . "\n";
echo 'Failures: ' . count($failures) . "\n";

if (!empty($failures)) {
    foreach ($failures as $f) {
        echo '- ' . $f['label'] . ': ' . $f['details'] . "\n";
    }
    exit(1);
}

exit(0);
