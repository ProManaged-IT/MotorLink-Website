<?php
/**
 * Poll approval status of motorlink_booking template, then send a test.
 * Run after wa_create_booking_template.php once PENDING → APPROVED.
 *
 * Usage: php scripts/wa_test_booking_template.php
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
$apiVersion = !empty($rows['wa_api_version']) ? $rows['wa_api_version'] : 'v25.0';
$testTo     = '353860081635';
$templateId = '1276113317419157';
$templateName = 'motorlink_booking';

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

// --- Check current status ---
echo "=== Checking template status ===" . PHP_EOL;
$r = waGet("https://graph.facebook.com/{$apiVersion}/{$templateId}?fields=status,name,quality_score", $token);
$status = strtoupper($r['body']['status'] ?? 'UNKNOWN');
$name   = $r['body']['name'] ?? $templateName;
echo "Template: {$name}" . PHP_EOL;
echo "Status:   {$status}" . PHP_EOL . PHP_EOL;

if ($status !== 'APPROVED') {
    echo "Template is still '{$status}'. Try again in a few minutes." . PHP_EOL;
    echo "Meta typically approves UTILITY templates within 1-5 minutes." . PHP_EOL;
    exit(0);
}

// --- Send test ---
echo "=== Sending motorlink_booking template to +{$testTo} ===" . PHP_EOL;

$payload = [
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

$r2 = waPost("https://graph.facebook.com/{$apiVersion}/{$phoneNumId}/messages", $token, $payload);
echo "HTTP: {$r2['code']}" . PHP_EOL;
echo json_encode($r2['body'], JSON_PRETTY_PRINT) . PHP_EOL . PHP_EOL;

$wamid = $r2['body']['messages'][0]['id'] ?? null;
if ($r2['code'] === 200 && $wamid) {
    echo "SUCCESS -- booking template sent to +{$testTo}" . PHP_EOL;
    echo "wamid: {$wamid}" . PHP_EOL;
} else {
    echo "FAILED: " . ($r2['body']['error']['message'] ?? 'Unknown error') . PHP_EOL;
}
