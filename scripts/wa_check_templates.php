<?php
require_once __DIR__ . '/_bootstrap.php';
$pdo = motorlink_script_pdo();
$rows   = $pdo->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('wa_api_token','wa_business_account_id','wa_api_version')")->fetchAll(PDO::FETCH_KEY_PAIR);
$token  = $rows['wa_api_token']          ?? '';
$wabaId = $rows['wa_business_account_id'] ?? '';
$ver    = $rows['wa_api_version']         ?? 'v25.0';

echo "WABA ID: {$wabaId}" . PHP_EOL;
echo "Version: {$ver}" . PHP_EOL . PHP_EOL;

if (!$wabaId) { echo "ERROR: No WABA ID in DB. Save it in Admin → Settings → WhatsApp first.\n"; exit(1); }
if (!$token)  { echo "ERROR: No token in DB.\n"; exit(1); }

$url = "https://graph.facebook.com/{$ver}/{$wabaId}/message_templates?fields=name,status,quality_score,rejected_reason,components&limit=20";
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
]);
$resp = curl_exec($ch);
curl_close($ch);

$data = json_decode($resp, true);
if (!empty($data['error'])) {
    echo "API error: " . $data['error']['message'] . " (code: " . $data['error']['code'] . ")\n";
    exit(1);
}

$templates = $data['data'] ?? [];
if (empty($templates)) {
    echo "No templates found for this WABA.\n";
    exit(0);
}

foreach ($templates as $t) {
    echo "─────────────────────────────────" . PHP_EOL;
    echo "Name:    " . $t['name'] . PHP_EOL;
    echo "Status:  " . $t['status'] . PHP_EOL;
    if (!empty($t['rejected_reason'])) {
        echo "REJECTED REASON: " . $t['rejected_reason'] . PHP_EOL;
    }
    if (!empty($t['quality_score'])) {
        echo "Quality: " . json_encode($t['quality_score']) . PHP_EOL;
    }
    // Show body text of first body component
    foreach (($t['components'] ?? []) as $c) {
        if ($c['type'] === 'BODY') {
            echo "Body:    " . ($c['text'] ?? '') . PHP_EOL;
        }
    }
}
