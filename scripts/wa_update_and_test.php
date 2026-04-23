<?php
/**
 * 1. Updates DB: new token + v25.0
 * 2. Sends hello_world template (should physically arrive on phone)
 * 3. Sends custom free-form text (will return 200 but sandbox will drop it)
 */

$token = 'EAANFYUsaQfYBRSf3bOSdSaFpvd52WA2oxM52MQJSlaTcwNPaqIyZA9okRAoi6H6NgADbFfndyfLnMPyO3ZBFsM0c1qg9q9oZAASZCGbAt5VHUyDKtdlDDbzBZCcXc0l87CoZAfwmuP2n0jNAjdAUSnuBrd3NIEZB8CvZAqm9XBtNOAQ1XImDHiVhmaI6NZAmyfBW4938K9MecO9ADvtPShSkq8wnAltg2I5kBymtAtC9CBn8ERcEItNZCfSd6NSPEMY1GOrrTptvi3SsK26PasCTNoYRlXHZBylmvRJRWcZD';
$phoneNumId = '1133418596516189';
$apiVersion = 'v25.0';
$toNumber   = '353860081635';

// --- 1. Update DB ---
$pdo = new PDO(
    'mysql:host=promanaged-it.com;dbname=p601229_motorlinkmalawi_db;charset=utf8mb4',
    'p601229',
    '2:p2WpmX[0YTs7',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$upsert = $pdo->prepare(
    "INSERT INTO site_settings (setting_key, setting_value, setting_group, setting_type, description, is_public)
     VALUES (?, ?, 'whatsapp', 'string', ?, 0)
     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
);
$upsert->execute(['wa_api_version', $apiVersion,  'Meta Graph API version']);
$upsert->execute(['wa_api_token',   $token,        'Meta WhatsApp Cloud API bearer token']);

echo "=== DB Updated ===" . PHP_EOL;
echo "wa_api_version: {$apiVersion}" . PHP_EOL;
echo "wa_api_token:   ••••" . substr($token, -6) . PHP_EOL . PHP_EOL;

$url = "https://graph.facebook.com/{$apiVersion}/{$phoneNumId}/messages";

function sendWA(string $url, string $token, array $body): void {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
    ]);
    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($err) { echo "cURL ERROR: {$err}" . PHP_EOL; return; }

    $decoded = json_decode($resp, true);
    echo "HTTP: {$httpCode}" . PHP_EOL;
    echo json_encode($decoded, JSON_PRETTY_PRINT) . PHP_EOL;
    $wamid = $decoded['messages'][0]['id'] ?? null;
    if ($httpCode === 200 && $wamid) {
        echo "✅ Accepted  wamid: {$wamid}" . PHP_EOL;
    } else {
        $msg  = $decoded['error']['message'] ?? 'Unknown';
        $code = $decoded['error']['code']    ?? 'N/A';
        echo "❌ Error {$code}: {$msg}" . PHP_EOL;
    }
}

// --- 2. hello_world template (pre-approved — should physically arrive) ---
echo "=== TEST 1: hello_world template ===" . PHP_EOL;
echo "This is a pre-approved Meta template — should physically arrive on +{$toNumber}" . PHP_EOL;
sendWA($url, $token, [
    'messaging_product' => 'whatsapp',
    'to'                => $toNumber,
    'type'              => 'template',
    'template'          => [
        'name'     => 'hello_world',
        'language' => ['code' => 'en_US'],
    ],
]);

echo PHP_EOL;

// --- 3. Custom free-form text ---
echo "=== TEST 2: Custom free-form text ===" . PHP_EOL;
echo "Free-form — Meta will return 200+wamid but sandbox drops delivery unless recipient is verified" . PHP_EOL;
sendWA($url, $token, [
    'messaging_product' => 'whatsapp',
    'recipient_type'    => 'individual',
    'to'                => $toNumber,
    'type'              => 'text',
    'text'              => [
        'preview_url' => false,
        'body'        => "Hi! This is a custom test message from MotorLink.\n\nIf you receive this, free-form text is working — you're out of sandbox mode. 🎉",
    ],
]);
