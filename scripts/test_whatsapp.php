<?php
/**
 * One-off CLI script: test WhatsApp message using live DB credentials.
 * Usage: php scripts/test_whatsapp.php [phone_number]
 * Example: php scripts/test_whatsapp.php 353860081635
 */

$toNumber = isset($argv[1]) ? preg_replace('/[^0-9]/', '', $argv[1]) : '353860081635';

// DB connect
$pdo = new PDO(
    'mysql:host=promanaged-it.com;dbname=p601229_motorlinkmalawi_db;charset=utf8mb4',
    'p601229',
    '2:p2WpmX[0YTs7',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$stmt = $pdo->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('wa_api_token','wa_phone_number_id','wa_api_version','wa_enabled')");
$rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$token      = $rows['wa_api_token']       ?? '';
$phoneNumId = $rows['wa_phone_number_id'] ?? '';
$apiVersion = !empty($rows['wa_api_version']) ? $rows['wa_api_version'] : 'v19.0';
$enabled    = $rows['wa_enabled']         ?? '0';

echo "=== WhatsApp Credential Check ===" . PHP_EOL;
echo "wa_enabled:       " . $enabled . PHP_EOL;
echo "wa_phone_id:      " . ($phoneNumId ?: 'NOT SET') . PHP_EOL;
echo "wa_api_version:   " . $apiVersion . PHP_EOL;
echo "wa_token:         " . ($token ? '••••' . substr($token, -6) : 'NOT SET') . PHP_EOL;
echo "To number:        +" . $toNumber . PHP_EOL;
echo PHP_EOL;

if (!$token)      { echo "ERROR: No API token in DB.\n"; exit(1); }
if (!$phoneNumId) { echo "ERROR: No Phone Number ID in DB.\n"; exit(1); }

$url = "https://graph.facebook.com/{$apiVersion}/{$phoneNumId}/messages";
$payload = json_encode([
    'messaging_product' => 'whatsapp',
    'recipient_type'    => 'individual',
    'to'                => $toNumber,
    'type'              => 'text',
    'text'              => [
        'preview_url' => false,
        'body'        => "✅ MotorLink WhatsApp test (CLI)\n\nAPI is connected and working.\n\n_Sent from MotorLink test script_",
    ],
]);

echo "POST {$url}" . PHP_EOL;

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_SSL_VERIFYPEER => false, // CLI has no CA bundle; fine for a test script
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

if ($curlError) {
    echo "cURL ERROR: " . $curlError . PHP_EOL;
    exit(1);
}

$decoded = json_decode($response, true);
echo "HTTP: " . $httpCode . PHP_EOL;
echo "Response: " . json_encode($decoded, JSON_PRETTY_PRINT) . PHP_EOL;

$wamid = $decoded['messages'][0]['id'] ?? null;
if ($httpCode === 200 && $wamid) {
    echo PHP_EOL . "SUCCESS — wamid: {$wamid}" . PHP_EOL;
    echo "NOTE: If you do not receive the message, add +{$toNumber} as a verified recipient:" . PHP_EOL;
    echo "  developers.facebook.com → your app → WhatsApp → API Setup → To dropdown → Add phone number" . PHP_EOL;
} else {
    $errMsg  = $decoded['error']['message'] ?? 'Unknown error';
    $errCode = $decoded['error']['code']    ?? 'N/A';
    echo PHP_EOL . "FAILED — {$errMsg} (code: {$errCode})" . PHP_EOL;
}
