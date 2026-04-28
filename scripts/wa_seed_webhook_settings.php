<?php
/**
 * Seed wa_webhook_verify_token and wa_app_secret in site_settings.
 * Safe to run multiple times — uses INSERT IGNORE logic.
 *
 * Usage: php scripts/wa_seed_webhook_settings.php
 */

require_once __DIR__ . '/_bootstrap.php';
$pdo = motorlink_script_pdo();

$token = bin2hex(random_bytes(16));

$rows = [
    [
        'key'   => 'wa_webhook_verify_token',
        'value' => $token,
        'desc'  => 'Webhook verify token — must match the token set in Meta App webhook config',
    ],
    [
        'key'   => 'wa_app_secret',
        'value' => '',
        'desc'  => 'Meta App Secret for X-Hub-Signature-256 webhook validation (get from Meta App dashboard)',
    ],
];

$sql = "INSERT INTO site_settings
            (setting_key, setting_value, setting_group, setting_type, is_public, description)
        VALUES
            (:key, :val, 'whatsapp', 'string', 0, :desc)
        ON DUPLICATE KEY UPDATE setting_key = setting_key";

foreach ($rows as $r) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':key' => $r['key'], ':val' => $r['value'], ':desc' => $r['desc']]);
    $inserted = $stmt->rowCount() > 0;
    echo ($inserted ? 'SEEDED' : 'EXISTS') . "  {$r['key']}" . ($inserted && $r['value'] ? " = {$r['value']}" : '') . "\n";
}

echo "\nAction required:\n";
echo "1. Copy wa_webhook_verify_token from DB and paste it in Meta App > WhatsApp > Configuration > Webhook > Verify Token\n";
echo "2. Set Webhook URL to: https://motorlink.mw/api.php?action=wa_webhook\n";
echo "3. Subscribe to 'messages' webhook field\n";
echo "4. Copy App Secret from Meta App dashboard and save it to wa_app_secret in site_settings\n";
