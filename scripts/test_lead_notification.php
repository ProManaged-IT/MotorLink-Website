<?php
/**
 * CLI script: test dealer lead WhatsApp notification end-to-end.
 * 1. Finds a seller user, sets their whatsapp number to the test recipient.
 * 2. Fires the notification directly.
 * 3. Reports result.
 * Usage: php scripts/test_lead_notification.php [recipient_number]
 */

$testRecipient = isset($argv[1]) ? preg_replace('/[^0-9]/', '', $argv[1]) : '353860081635';

$pdo = new PDO(
    'mysql:host=promanaged-it.com;dbname=p601229_motorlinkmalawi_db;charset=utf8mb4',
    'p601229',
    '2:p2WpmX[0YTs7',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// 1. Load WA settings
$stmt = $pdo->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('wa_api_token','wa_phone_number_id','wa_api_version','wa_enabled','wa_lead_notifications')");
$rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

echo "=== Settings ===" . PHP_EOL;
echo "wa_enabled:            " . ($rows['wa_enabled']           ?? 'NOT SET') . PHP_EOL;
echo "wa_lead_notifications: " . ($rows['wa_lead_notifications'] ?? 'NOT SET') . PHP_EOL;
echo "wa_phone_id:           " . ($rows['wa_phone_number_id']   ?? 'NOT SET') . PHP_EOL;
$token      = $rows['wa_api_token']       ?? '';
$phoneNumId = $rows['wa_phone_number_id'] ?? '';
$apiVersion = !empty($rows['wa_api_version']) ? $rows['wa_api_version'] : 'v19.0';
echo "wa_token:              " . ($token ? '••••' . substr($token, -6) : 'NOT SET') . PHP_EOL . PHP_EOL;

// 2. Find a seller/dealer user — update their whatsapp field temporarily
$stmt = $pdo->query("SELECT id, full_name, whatsapp, phone FROM users LIMIT 1");
$seller = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$seller) { echo "No users found in DB.\n"; exit(1); }

$originalWa = $seller['whatsapp'];
echo "=== Test Seller ===" . PHP_EOL;
echo "ID:       " . $seller['id'] . PHP_EOL;
echo "Name:     " . $seller['full_name'] . PHP_EOL;
echo "Original whatsapp: " . ($originalWa ?: '(none)') . PHP_EOL;

// Temporarily set whatsapp to test recipient
$pdo->prepare("UPDATE users SET whatsapp = ? WHERE id = ?")->execute([$testRecipient, $seller['id']]);
echo "Updated whatsapp → " . $testRecipient . PHP_EOL . PHP_EOL;

// 3. Build notification message (mirrors sendDealerLeadNotification)
$listingTitle = '2023 Toyota Hilux Double Cab (TEST)';
$buyerName    = 'Test Buyer';
$firstMessage = 'Hi, is this car still available? I would like to arrange a viewing this weekend.';
$preview      = mb_substr($firstMessage, 0, 120);

$msgBody = "🔔 *New Lead — MotorLink*\n\n"
         . "*Listing:* {$listingTitle}\n"
         . "*From:* {$buyerName}\n\n"
         . "*Message:*\n{$preview}\n\n"
         . "_Reply via MotorLink chat or WhatsApp the buyer directly._";

// 4. Send via Meta API
$url = "https://graph.facebook.com/{$apiVersion}/{$phoneNumId}/messages";
$payload = json_encode([
    'messaging_product' => 'whatsapp',
    'recipient_type'    => 'individual',
    'to'                => $testRecipient,
    'type'              => 'text',
    'text'              => ['preview_url' => false, 'body' => $msgBody],
]);

echo "=== Sending Lead Notification ===" . PHP_EOL;
echo "To:  +" . $testRecipient . PHP_EOL;
echo "URL: " . $url . PHP_EOL . PHP_EOL;

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
    ],
]);

$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// 5. Restore original whatsapp value
$pdo->prepare("UPDATE users SET whatsapp = ? WHERE id = ?")->execute([$originalWa, $seller['id']]);
echo "Restored original whatsapp value." . PHP_EOL . PHP_EOL;

if ($curlError) {
    echo "cURL ERROR: " . $curlError . PHP_EOL;
    exit(1);
}

$decoded = json_decode($response, true);
echo "HTTP: " . $httpCode . PHP_EOL;
echo "Response: " . json_encode($decoded, JSON_PRETTY_PRINT) . PHP_EOL . PHP_EOL;

$wamid = $decoded['messages'][0]['id'] ?? null;
if ($httpCode === 200 && $wamid) {
    echo "✅ SUCCESS — Lead notification sent!" . PHP_EOL;
    echo "wamid: {$wamid}" . PHP_EOL;
    echo PHP_EOL . "Check WhatsApp on +" . $testRecipient . " — message should arrive from +1 555 176 0384" . PHP_EOL;
} else {
    $errMsg  = $decoded['error']['message'] ?? 'Unknown';
    $errCode = $decoded['error']['code']    ?? 'N/A';
    echo "❌ FAILED: {$errMsg} (code: {$errCode})" . PHP_EOL;
    if ($errCode == 131030) {
        echo PHP_EOL . "FIX: Go to developers.facebook.com → your app → WhatsApp → API Setup" . PHP_EOL;
        echo "     → 'To' dropdown → Add phone number → add +" . $testRecipient . " → verify OTP on your phone" . PHP_EOL;
    }
}
