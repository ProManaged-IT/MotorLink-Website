<?php
/**
 * Update the WhatsApp API token in the live DB and immediately
 * submit/retry all pending templates.
 *
 * Usage:
 *   php scripts/wa_update_token.php <new_token>
 *
 * Example:
 *   php scripts/wa_update_token.php EAAxxxYourNewTokenHere
 */

if ($argc < 2 || strlen(trim($argv[1])) < 20) {
    echo "Usage: php scripts/wa_update_token.php <new_token>\n";
    exit(1);
}

require_once __DIR__ . '/_bootstrap.php';
$pdo   = motorlink_script_pdo();
$token = trim($argv[1]);

// ---------------------------------------------------------------------------
// 1. Update token in DB
// ---------------------------------------------------------------------------
$stmt = $pdo->prepare(
    "UPDATE site_settings SET setting_value = ? WHERE setting_group = 'whatsapp' AND setting_key = 'wa_api_token'"
);
$stmt->execute([$token]);

if ($stmt->rowCount() === 0) {
    // Row may not exist — insert it
    $ins = $pdo->prepare(
        "INSERT INTO site_settings (setting_group, setting_key, setting_value) VALUES ('whatsapp','wa_api_token',?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    $ins->execute([$token]);
}

echo "Token updated in DB (last 6: ..." . substr($token, -6) . ")\n\n";

// ---------------------------------------------------------------------------
// 2. Verify token with a lightweight GET
// ---------------------------------------------------------------------------
$rows = $pdo->query(
    "SELECT setting_key, setting_value FROM site_settings WHERE setting_group='whatsapp'"
)->fetchAll(PDO::FETCH_KEY_PAIR);

$wabaId = $rows['wa_business_account_id'] ?? '';
$apiVer = $rows['wa_api_version']         ?? 'v25.0';

$ch = curl_init("https://graph.facebook.com/{$apiVer}/{$wabaId}?fields=id,name");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
]);
$resp = json_decode(curl_exec($ch) ?: '{}', true) ?? [];
curl_close($ch);

if (isset($resp['error'])) {
    echo "Token validation FAILED: " . ($resp['error']['message'] ?? 'unknown') . "\n";
    echo "Please generate a new token and try again.\n";
    exit(1);
}
echo "Token valid — WABA: " . ($resp['name'] ?? $resp['id'] ?? 'OK') . "\n\n";

// ---------------------------------------------------------------------------
// 3. Run the retry creator
// ---------------------------------------------------------------------------
echo "Handing off to wa_retry_create.php...\n\n";
// Update token in $rows for the included script's use
passthru('php ' . escapeshellarg(__DIR__ . '/wa_retry_create.php'));
