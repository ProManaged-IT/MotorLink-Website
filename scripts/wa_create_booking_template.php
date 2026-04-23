<?php
/**
 * 1. Creates the motorlink_booking template via Meta API
 * 2. Polls for approval (up to 30s)
 * 3. Sends a live test once approved
 * 4. Reports full result
 *
 * Usage: php scripts/wa_create_booking_template.php
 */

$pdo = new PDO(
    'mysql:host=promanaged-it.com;dbname=p601229_motorlinkmalawi_db;charset=utf8mb4',
    'p601229',
    '2:p2WpmX[0YTs7',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$rows = $pdo->query(
    "SELECT setting_key, setting_value FROM site_settings WHERE setting_group='whatsapp'"
)->fetchAll(PDO::FETCH_KEY_PAIR);

$token      = $rows['wa_api_token']          ?? '';
$phoneNumId = $rows['wa_phone_number_id']    ?? '';
$wabaId     = $rows['wa_business_account_id'] ?? '';
$apiVersion = !empty($rows['wa_api_version']) ? $rows['wa_api_version'] : 'v25.0';
$testTo     = '353860081635';

echo "=== WA Settings ===" . PHP_EOL;
echo "api_version:  {$apiVersion}" . PHP_EOL;
echo "phone_num_id: {$phoneNumId}" . PHP_EOL;
echo "waba_id:      " . ($wabaId ?: '(NOT SET — will try to look it up)') . PHP_EOL;
echo "token:        ••••" . substr($token, -6) . PHP_EOL . PHP_EOL;

if (!$token || !$phoneNumId) {
    echo "ERROR: Missing token or phone number ID in DB.\n"; exit(1);
}

// --- Helper: cURL wrapper ---
function waPost(string $url, string $token, array $body): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode($resp, true) ?? []];
}

function waGet(string $url, string $token): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode($resp, true) ?? []];
}

// --- If WABA ID not in DB, fetch it from the phone number ---
if (!$wabaId) {
    echo "=== Fetching WABA ID from phone number ID ===" . PHP_EOL;
    $r = waGet("https://graph.facebook.com/{$apiVersion}/{$phoneNumId}?fields=whatsapp_business_account", $token);
    echo "HTTP: {$r['code']}" . PHP_EOL;
    echo json_encode($r['body'], JSON_PRETTY_PRINT) . PHP_EOL . PHP_EOL;
    $wabaId = $r['body']['whatsapp_business_account']['id'] ?? '';
    if ($wabaId) {
        $pdo->prepare("INSERT INTO site_settings (setting_key,setting_value,setting_group,setting_type,description,is_public) VALUES('wa_business_account_id',?,'whatsapp','string','Meta WhatsApp Business Account ID',0) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$wabaId]);
        echo "Saved WABA ID to DB: {$wabaId}" . PHP_EOL . PHP_EOL;
    } else {
        echo "ERROR: Could not determine WABA ID. Please enter it manually in Admin → Settings → WhatsApp." . PHP_EOL;
        exit(1);
    }
}

// --- 1. Create template ---
$templateName = 'motorlink_booking';
echo "=== Step 1: Create template '{$templateName}' ===" . PHP_EOL;

$templatePayload = [
    'name'       => $templateName,
    'language'   => 'en_US',
    'category'   => 'UTILITY',
    'components' => [
        [
            'type' => 'HEADER',
            'format' => 'TEXT',
            'text' => 'New Booking - MotorLink',
        ],
        [
            'type' => 'BODY',
            'text' => "You have a new booking request!\n\n*Vehicle:* {{1}}\n*Customer:* {{2}}\n*Phone:* {{3}}\n*Dates:* {{4}} → {{5}}\n\nLog in to MotorLink to confirm or decline.",
            'example' => [
                'body_text' => [['Toyota Hilux 2023', 'John Banda', '+265888000000', '25 Apr 2026', '28 Apr 2026']],
            ],
        ],
        [
            'type' => 'FOOTER',
            'text' => 'MotorLink Car Hire Platform',
        ],
    ],
];

$r = waPost("https://graph.facebook.com/{$apiVersion}/{$wabaId}/message_templates", $token, $templatePayload);
echo "HTTP: {$r['code']}" . PHP_EOL;
echo json_encode($r['body'], JSON_PRETTY_PRINT) . PHP_EOL . PHP_EOL;

$templateId = $r['body']['id'] ?? null;
$status     = strtoupper($r['body']['status'] ?? '');

if (!$templateId && isset($r['body']['error'])) {
    $errCode = $r['body']['error']['code'] ?? '';
    // Error 100 subcode 2388085 = template name already exists
    if ($errCode == 100) {
        echo "Template may already exist. Searching for existing template..." . PHP_EOL;
        $list = waGet("https://graph.facebook.com/{$apiVersion}/{$wabaId}/message_templates?name={$templateName}", $token);
        $existing = $list['body']['data'][0] ?? null;
        if ($existing) {
            $templateId = $existing['id'];
            $status     = strtoupper($existing['status']);
            echo "Found existing template. ID: {$templateId}, Status: {$status}" . PHP_EOL . PHP_EOL;
        } else {
            echo "ERROR: Could not create or find template.\n"; exit(1);
        }
    } else {
        echo "ERROR creating template. Check WABA ID and token permissions.\n"; exit(1);
    }
}

// --- 2. Poll for approval (max 10 checks × 3s) ---
if ($status !== 'APPROVED') {
    echo "=== Step 2: Waiting for approval (status: {$status}) ===" . PHP_EOL;
    for ($i = 0; $i < 10; $i++) {
        sleep(3);
        $r2 = waGet("https://graph.facebook.com/{$apiVersion}/{$templateId}?fields=status,name", $token);
        $status = strtoupper($r2['body']['status'] ?? $status);
        echo "  [{$i}] status: {$status}" . PHP_EOL;
        if ($status === 'APPROVED') break;
    }
    echo PHP_EOL;
}

// --- 3. Send test using the template ---
echo "=== Step 3: Send template test to +{$testTo} ===" . PHP_EOL;

if ($status !== 'APPROVED') {
    echo "Template status is '{$status}' — cannot send yet. Templates sometimes take a few minutes." . PHP_EOL;
    echo "Run 'php scripts/wa_test_booking_template.php' once it's approved." . PHP_EOL;
    exit(0);
}

$sendPayload = [
    'messaging_product' => 'whatsapp',
    'to'                => $testTo,
    'type'              => 'template',
    'template'          => [
        'name'       => $templateName,
        'language'   => ['code' => 'en_US'],
        'components' => [
            [
                'type'       => 'body',
                'parameters' => [
                    ['type' => 'text', 'text' => 'Toyota Hilux Double Cab 2023'],
                    ['type' => 'text', 'text' => 'Test Customer'],
                    ['type' => 'text', 'text' => '+353860081635'],
                    ['type' => 'text', 'text' => '25 Apr 2026'],
                    ['type' => 'text', 'text' => '28 Apr 2026'],
                ],
            ],
        ],
    ],
];

$r3 = waPost("https://graph.facebook.com/{$apiVersion}/{$phoneNumId}/messages", $token, $sendPayload);
echo "HTTP: {$r3['code']}" . PHP_EOL;
echo json_encode($r3['body'], JSON_PRETTY_PRINT) . PHP_EOL . PHP_EOL;

$wamid = $r3['body']['messages'][0]['id'] ?? null;
if ($r3['code'] === 200 && $wamid) {
    echo "✅ SUCCESS — Booking template sent to +{$testTo}" . PHP_EOL;
    echo "wamid: {$wamid}" . PHP_EOL;
    echo PHP_EOL . "Check your WhatsApp — message should arrive from +1 555 176 0384" . PHP_EOL;
} else {
    $msg  = $r3['body']['error']['message'] ?? 'Unknown';
    $code = $r3['body']['error']['code']    ?? '';
    echo "❌ Send failed: {$msg} (code: {$code})" . PHP_EOL;
}
