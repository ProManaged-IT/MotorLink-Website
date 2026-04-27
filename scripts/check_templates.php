<?php
require_once __DIR__ . '/_bootstrap.php';
$pdo = motorlink_script_pdo();

$token  = $pdo->query("SELECT setting_value FROM site_settings WHERE setting_key='wa_api_token'")->fetchColumn();
$wabaId = $pdo->query("SELECT setting_value FROM site_settings WHERE setting_key='wa_business_account_id'")->fetchColumn();
echo "WABA ID: " . ($wabaId ?: '(not set)') . PHP_EOL;

if (!$wabaId) {
    // Try to get it from the token itself via /me
    $ch = curl_init('https://graph.facebook.com/v25.0/me?fields=id,name');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token]]);
    $resp = json_decode(curl_exec($ch), true);
    curl_close($ch);
    echo "Token identity: " . json_encode($resp) . PHP_EOL;

    // Get WABA list via business portfolios
    $ch2 = curl_init('https://graph.facebook.com/v25.0/me/businesses?fields=id,name');
    curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token]]);
    $resp2 = json_decode(curl_exec($ch2), true);
    curl_close($ch2);
    echo "Businesses: " . json_encode($resp2, JSON_PRETTY_PRINT) . PHP_EOL;
    exit;
}

$url = "https://graph.facebook.com/v25.0/{$wabaId}/message_templates?fields=name,status,rejected_reason,quality_score,components&limit=20";

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
]);
$resp = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($resp, true);
echo "HTTP: {$http}" . PHP_EOL . PHP_EOL;

if (!empty($data['data'])) {
    foreach ($data['data'] as $t) {
        echo "──────────────────────────────" . PHP_EOL;
        echo "Name:     " . $t['name'] . PHP_EOL;
        echo "Status:   " . $t['status'] . PHP_EOL;
        if (!empty($t['rejected_reason'])) {
            echo "Rejected: " . $t['rejected_reason'] . PHP_EOL;
        }
        if (!empty($t['quality_score'])) {
            echo "Quality:  " . json_encode($t['quality_score']) . PHP_EOL;
        }
    }
} else {
    echo json_encode($data, JSON_PRETTY_PRINT) . PHP_EOL;
}
