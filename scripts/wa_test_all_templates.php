<?php
/**
 * MotorLink — WhatsApp template smoke test
 * Sends one test message per template to a target number.
 *
 * Usage: php scripts/wa_test_all_templates.php --send [+353860081635]
 */

require_once __DIR__ . '/_bootstrap.php';
$pdo  = motorlink_script_pdo();
$rows = $pdo->query(
    "SELECT setting_key, setting_value FROM site_settings WHERE setting_group='whatsapp'"
)->fetchAll(PDO::FETCH_KEY_PAIR);

$token   = $rows['wa_api_token']       ?? '';
$phoneId = $rows['wa_phone_number_id'] ?? '';
$apiVer  = $rows['wa_api_version']     ?? 'v25.0';

if (!$token || !$phoneId) { echo "ERROR: missing WA credentials in DB\n"; exit(1); }

$sendConfirmed = in_array('--send', $argv, true);
$targetArg = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg !== '--send') {
        $targetArg = $arg;
        break;
    }
}

$to = $targetArg ? preg_replace('/[^0-9]/', '', $targetArg) : '353860081635';
echo "=== MotorLink WhatsApp Template Smoke Tests ===\n";
echo "To:       +$to\n";
echo "Phone ID: $phoneId\n";
echo "Token:    ..." . substr($token, -6) . "\n\n";

if (!$sendConfirmed) {
    echo "DRY RUN ONLY — no WhatsApp messages sent. Add --send to intentionally send paid template test messages.\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// Helper: send one template message
// ---------------------------------------------------------------------------
function sendTpl(string $phoneId, string $apiVer, string $token, string $to,
                 string $name, array $bodyParams, array $headerParams = [], array $buttonPayloads = []): void {
    $url      = "https://graph.facebook.com/{$apiVer}/{$phoneId}/messages";
    $comps    = [];
    if ($headerParams) {
        $comps[] = ['type' => 'header', 'parameters' => array_map(
            fn($v) => ['type' => 'text', 'text' => (string)$v], $headerParams
        )];
    }
    $comps[] = ['type' => 'body', 'parameters' => array_map(
        fn($v) => ['type' => 'text', 'text' => (string)$v], $bodyParams
    )];
    foreach ($buttonPayloads as $idx => $payload) {
        $comps[] = [
            'type'       => 'button',
            'sub_type'   => 'quick_reply',
            'index'      => (string)$idx,
            'parameters' => [['type' => 'payload', 'payload' => (string)$payload]],
        ];
    }

    $payload = json_encode([
        'messaging_product' => 'whatsapp',
        'to'                => $to,
        'type'              => 'template',
        'template'          => [
            'name'       => $name,
            'language'   => ['code' => 'en_US'],
            'components' => $comps,
        ],
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $body  = json_decode($resp ?: '{}', true) ?? [];
    $wamid = $body['messages'][0]['id'] ?? null;
    $err   = $body['error']['message']  ?? null;

    if ($wamid) {
        echo "  OK     {$name}\n         wamid: {$wamid}\n\n";
    } else {
        echo "  FAILED {$name}\n         {$err}\n\n";
    }
    sleep(1); // rate-limit buffer
}

// ---------------------------------------------------------------------------
// Test cases — realistic sample data matching each template's parameters
// ---------------------------------------------------------------------------

// 1. motorlink_booking_v2 — new booking request → car hire owner
// Params: vehicle, customer name, customer phone, from date, to date
echo "── 1. motorlink_booking_v2 ──────────────────────────────────\n";
sendTpl($phoneId, $apiVer, $token, $to, 'motorlink_booking_v2', [
    'Toyota Hilux 2023',
    'Grace Mwale',
    '+353860081635',
    '01 May 2026',
    '04 May 2026',
], [], [
    'ACCEPT_BOOKING_0',
    'DECLINE_BOOKING_0',
    'PROPOSE_DATES_0',
]);

// 2. motorlink_booking_confirmed_v2 — booking confirmed → renter
// Params: renter name, vehicle, pickup date, return date, owner phone
echo "── 2. motorlink_booking_confirmed_v2 ───────────────────────\n";
sendTpl($phoneId, $apiVer, $token, $to, 'motorlink_booking_confirmed_v2', [
    'Grace Mwale',
    'Toyota Hilux 2023',
    '01 May 2026',
    '04 May 2026',
    '+265888123456',
]);

// 3. motorlink_booking_declined_v2 — booking declined → renter
// Params: renter name, vehicle, dates
echo "── 3. motorlink_booking_declined_v2 ────────────────────────\n";
sendTpl($phoneId, $apiVer, $token, $to, 'motorlink_booking_declined_v2', [
    'Grace Mwale',
    'Toyota Hilux 2023',
    '01-04 May 2026',
]);

// 4. motorlink_hire_reminder_v2 — pickup reminder → renter
// Params: renter name, vehicle, pickup date, owner phone
echo "── 4. motorlink_hire_reminder_v2 ───────────────────────────\n";
sendTpl($phoneId, $apiVer, $token, $to, 'motorlink_hire_reminder_v2', [
    'Grace Mwale',
    'Toyota Hilux 2023',
    '01 May 2026',
    '+265888123456',
], [], [
    'REMINDER_ACK_0',
    'CANCEL_BOOKING_0',
]);

// 5. motorlink_new_lead_v2 — new buyer enquiry → dealer/seller
// Params: seller name, listing, buyer name, message
echo "── 5. motorlink_new_lead_v2 ────────────────────────────────\n";
sendTpl($phoneId, $apiVer, $token, $to, 'motorlink_new_lead_v2', [
    'James Dealer',
    '2021 Nissan Navara Double Cab',
    'Peter Buyer',
    'Hi, is this still available for viewing this weekend?',
]);

// 6. motorlink_listing_live_v2 — listing approved/live → seller
// Params: seller name, vehicle
echo "── 6. motorlink_listing_live_v2 ────────────────────────────\n";
sendTpl($phoneId, $apiVer, $token, $to, 'motorlink_listing_live_v2', [
    'James Dealer',
    '2021 Nissan Navara Double Cab',
]);

// 7. motorlink_listing_rejected_v2 — listing needs attention → seller
// Params: seller name, vehicle, reason
echo "── 7. motorlink_listing_rejected_v2 ────────────────────────\n";
sendTpl($phoneId, $apiVer, $token, $to, 'motorlink_listing_rejected_v2', [
    'James Dealer',
    '2021 Nissan Navara Double Cab',
    'Photos are too blurry. Please upload clear, well-lit images.',
]);

// 8. motorlink_rate_experience_v2 — post-hire review request → renter
// Params: renter name, business name
echo "── 8. motorlink_rate_experience_v2 ─────────────────────────\n";
sendTpl($phoneId, $apiVer, $token, $to, 'motorlink_rate_experience_v2', [
    'Grace Mwale',
    'Lilongwe Car Rentals',
]);

// 9. motorlink_new_user_v2 — welcome new registrant
// Params: user name
echo "── 9. motorlink_new_user_v2 ────────────────────────────────\n";
sendTpl($phoneId, $apiVer, $token, $to, 'motorlink_new_user_v2', [
    'Grace Mwale',
]);

// 10. motorlink_otp — OTP code (AUTHENTICATION — no body params; code sent via OTP button)
// Note: AUTHENTICATION templates must be sent with the OTP component, not body params.
echo "── 10. motorlink_otp ───────────────────────────────────────\n";
$otpCode = '847291';
$otpPayload = json_encode([
    'messaging_product' => 'whatsapp',
    'to'                => $to,
    'type'              => 'template',
    'template'          => [
        'name'       => 'motorlink_otp',
        'language'   => ['code' => 'en_US'],
        'components' => [
            [
                'type'       => 'body',
                'parameters' => [
                    ['type' => 'text', 'text' => $otpCode],
                ],
            ],
            [
                'type'       => 'button',
                'sub_type'   => 'url',
                'index'      => '0',
                'parameters' => [
                    ['type' => 'text', 'text' => $otpCode],
                ],
            ],
        ],
    ],
]);
$ch = curl_init("https://graph.facebook.com/{$apiVer}/{$phoneId}/messages");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 15,
    CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
    CURLOPT_POSTFIELDS     => $otpPayload,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
]);
$resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
$body  = json_decode($resp ?: '{}', true) ?? [];
$wamid = $body['messages'][0]['id'] ?? null;
$err   = $body['error']['message']  ?? null;
if ($wamid) echo "  OK     motorlink_otp\n         wamid: {$wamid}\n         Code sent: {$otpCode}\n\n";
else        echo "  FAILED motorlink_otp\n         {$err}\n\n";

echo str_repeat('═', 55) . "\n";
echo "Done. Check +$to on WhatsApp.\n";
echo "\nNote — 4 templates use bypass names due to Meta deletion-limbo:\n";
echo "  motorlink_booking_reminder → motorlink_hire_reminder\n";
echo "  motorlink_listing_approved → motorlink_listing_live\n";
echo "  motorlink_review_request   → motorlink_rate_experience\n";
echo "  motorlink_welcome          → motorlink_new_user\n";
