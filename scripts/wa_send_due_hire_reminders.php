<?php
/**
 * Send due car-hire pickup reminders via WhatsApp template.
 *
 * Intended cron usage:
 *   php scripts/wa_send_due_hire_reminders.php
 */

require_once __DIR__ . '/_bootstrap.php';

$pdo = motorlink_script_pdo();

function waReminderSettingRows(PDO $pdo): array {
    $stmt = $pdo->prepare(
        "SELECT setting_key, setting_value FROM site_settings
         WHERE setting_key IN ('wa_enabled','wa_api_token','wa_phone_number_id','wa_api_version')"
    );
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
}

function waReminderEnsureColumns(PDO $pdo): void {
    $columns = [
        'wa_pickup_reminder_sent' => "ALTER TABLE car_hire_bookings ADD COLUMN wa_pickup_reminder_sent TINYINT(1) NOT NULL DEFAULT 0",
        'wa_pickup_reminder_at' => "ALTER TABLE car_hire_bookings ADD COLUMN wa_pickup_reminder_at DATETIME DEFAULT NULL",
    ];

    foreach ($columns as $column => $sql) {
        $check = $pdo->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'car_hire_bookings' AND COLUMN_NAME = ?"
        );
        $check->execute([$column]);
        if ((int)$check->fetchColumn() === 0) {
            $pdo->exec($sql);
        }
    }
}

function waReminderSendTemplate(array $settings, string $toNumber, array $params, array $buttonPayloads = []): array {
    $toNumber = preg_replace('/[^0-9]/', '', $toNumber);
    if (strlen($toNumber) < 7) {
        return ['success' => false, 'error' => 'Invalid recipient number'];
    }

    $bodyParams = array_map(fn($value) => ['type' => 'text', 'text' => (string)$value], $params);
    $components = [['type' => 'body', 'parameters' => $bodyParams]];

    // Inject QUICK_REPLY payloads so the webhook can identify and cancel the booking
    foreach ($buttonPayloads as $idx => $btnPayload) {
        $components[] = [
            'type'       => 'button',
            'sub_type'   => 'quick_reply',
            'index'      => (string)$idx,
            'parameters' => [['type' => 'payload', 'payload' => (string)$btnPayload]],
        ];
    }

    $payload = json_encode([
        'messaging_product' => 'whatsapp',
        'to' => $toNumber,
        'type' => 'template',
        'template' => [
            'name' => 'motorlink_hire_reminder_v2',
            'language' => ['code' => 'en_US'],
            'components' => $components,
        ],
    ]);

    $url = "https://graph.facebook.com/{$settings['api_version']}/{$settings['phone_number_id']}/messages";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $settings['api_token'],
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['success' => false, 'error' => $curlError];
    }

    $decoded = json_decode($response ?: '{}', true) ?: [];
    $wamid = $decoded['messages'][0]['id'] ?? null;
    if ($httpCode === 200 && $wamid) {
        return ['success' => true, 'wamid' => $wamid];
    }

    return ['success' => false, 'error' => $decoded['error']['message'] ?? "HTTP {$httpCode}"];
}

$rows = waReminderSettingRows($pdo);
if (($rows['wa_enabled'] ?? '0') !== '1') {
    echo "WhatsApp API disabled. No reminders sent.\n";
    exit(0);
}

$settings = [
    'api_token' => $rows['wa_api_token'] ?? '',
    'phone_number_id' => $rows['wa_phone_number_id'] ?? '',
    'api_version' => !empty($rows['wa_api_version']) ? $rows['wa_api_version'] : 'v25.0',
];

if ($settings['api_token'] === '' || $settings['phone_number_id'] === '') {
    echo "WhatsApp credentials incomplete. No reminders sent.\n";
    exit(1);
}

waReminderEnsureColumns($pdo);

$stmt = $pdo->query(
    "SELECT b.id, b.vehicle_name, b.renter_name, b.renter_phone, b.renter_whatsapp, b.start_date,
            c.phone AS owner_phone, c.whatsapp AS owner_whatsapp
     FROM car_hire_bookings b
     INNER JOIN car_hire_companies c ON c.id = b.company_id
     WHERE b.status = 'confirmed'
       AND COALESCE(b.wa_pickup_reminder_sent, 0) = 0
       AND b.start_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 1 DAY)
     ORDER BY b.start_date ASC, b.id ASC
     LIMIT 100"
);

$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
if (!$bookings) {
    echo "No due pickup reminders.\n";
    exit(0);
}

$sent = 0;
$failed = 0;
$markSent = $pdo->prepare("UPDATE car_hire_bookings SET wa_pickup_reminder_sent = 1, wa_pickup_reminder_at = NOW() WHERE id = ?");

foreach ($bookings as $booking) {
    $to = !empty($booking['renter_whatsapp']) ? $booking['renter_whatsapp'] : ($booking['renter_phone'] ?? '');
    $ownerContact = !empty($booking['owner_whatsapp']) ? $booking['owner_whatsapp'] : ($booking['owner_phone'] ?? '');

    $result = waReminderSendTemplate($settings, $to, [
        $booking['renter_name'] ?? 'Customer',
        $booking['vehicle_name'] ?? 'your rental vehicle',
        $booking['start_date'] ?? '',
        $ownerContact,
    ], [
        'REMINDER_ACK_' . $booking['id'],   // index 0 → "Got it, I'm ready!"
        'CANCEL_BOOKING_' . $booking['id'], // index 1 → "I Need to Cancel"
    ]);

    if (!empty($result['success'])) {
        $markSent->execute([(int)$booking['id']]);
        $sent++;
        echo "SENT booking #{$booking['id']} wamid: " . ($result['wamid'] ?? 'n/a') . "\n";
    } else {
        $failed++;
        echo "FAILED booking #{$booking['id']}: " . ($result['error'] ?? 'unknown') . "\n";
    }
}

echo "Done. Sent: {$sent}, Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);