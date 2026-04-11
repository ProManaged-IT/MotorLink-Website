<?php
// Auth rate-limit smoke test.
// Verifies login, password reset request, and password reset submit throttling.

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(1);
}

$baseUrl = getenv('MOTORLINK_TEST_BASE_URL') ?: 'https://promanaged-it.com/motorlink';
$baseHost = parse_url($baseUrl, PHP_URL_HOST) ?: '';
$defaultInsecureTls = in_array($baseHost, ['localhost', '127.0.0.1'], true) ? '1' : '0';
$allowInsecureTls = filter_var(getenv('MOTORLINK_TEST_INSECURE_TLS') ?: $defaultInsecureTls, FILTER_VALIDATE_BOOLEAN);

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
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    if ($allowInsecureTls) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
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

function expectHttp($label, $result, $expectedCodes, &$failures, &$passes) {
    if (!$result['ok']) {
        fail($label, 'transport error: ' . $result['error'], $failures);
        return false;
    }

    if (!in_array((int)$result['http'], $expectedCodes, true)) {
        fail(
            $label,
            'Expected HTTP ' . implode('/', $expectedCodes) . ' got ' . $result['http'] . ' body=' . substr($result['raw'], 0, 260),
            $failures
        );
        return false;
    }

    ok($label, $passes);
    return true;
}

$stamp = date('YmdHis');
$unknownEmail = 'autotest_rate_limit_' . $stamp . '@example.com';

// Login throttle: default max 8 attempts, block should trigger on next request.
for ($i = 1; $i <= 8; $i++) {
    $res = apiCall($baseUrl . '/api.php?action=login', 'POST', [
        'email' => $unknownEmail,
        'password' => 'Wrong#Password1',
    ]);
    if (!expectHttp('Login invalid attempt ' . $i . ' allowed as failure', $res, [401], $failures, $passes)) {
        break;
    }
}
$loginBlocked = apiCall($baseUrl . '/api.php?action=login', 'POST', [
    'email' => $unknownEmail,
    'password' => 'Wrong#Password1',
]);
expectHttp('Login throttled after repeated failures', $loginBlocked, [429], $failures, $passes);

// Password reset request throttle: default max 5 per window.
for ($i = 1; $i <= 5; $i++) {
    $res = apiCall($baseUrl . '/api.php?action=request_password_reset', 'POST', [
        'email' => $unknownEmail,
    ]);
    if (!expectHttp('Password reset request attempt ' . $i, $res, [200], $failures, $passes)) {
        break;
    }
}
$resetReqBlocked = apiCall($baseUrl . '/api.php?action=request_password_reset', 'POST', [
    'email' => $unknownEmail,
]);
expectHttp('Password reset request throttled', $resetReqBlocked, [429], $failures, $passes);

// Password reset submit throttle: use invalid token with fixed user_id.
for ($i = 1; $i <= 10; $i++) {
    $res = apiCall($baseUrl . '/api.php?action=reset_password', 'POST', [
        'token' => 'invalid-token-' . $stamp,
        'user_id' => 999999,
        'password' => 'Changed#12345',
        'confirm_password' => 'Changed#12345',
    ]);
    if (!expectHttp('Password reset submit invalid attempt ' . $i, $res, [400], $failures, $passes)) {
        break;
    }
}
$resetSubmitBlocked = apiCall($baseUrl . '/api.php?action=reset_password', 'POST', [
    'token' => 'invalid-token-' . $stamp,
    'user_id' => 999999,
    'password' => 'Changed#12345',
    'confirm_password' => 'Changed#12345',
]);
expectHttp('Password reset submit throttled', $resetSubmitBlocked, [429], $failures, $passes);

echo "\n========== AUTH RATE LIMIT SUMMARY ==========\n";
echo 'Passes: ' . count($passes) . "\n";
echo 'Failures: ' . count($failures) . "\n";

if (!empty($failures)) {
    foreach ($failures as $f) {
        echo ' - ' . $f['label'] . ': ' . $f['details'] . "\n";
    }
    exit(1);
}

echo "All auth rate-limit checks passed.\n";
exit(0);
