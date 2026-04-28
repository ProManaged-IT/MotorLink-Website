<?php
/**
 * MotorLink — WhatsApp template retry creator.
 *
 * Tries to create all 9 UTILITY templates (OTP is already APPROVED).
 * Polls every 20s until all are created or 15 attempts are exhausted.
 * Safe to run multiple times — skips APPROVED/PENDING templates.
 *
 * Usage: php scripts/wa_retry_create.php
 */

require_once __DIR__ . '/_bootstrap.php';
$pdo  = motorlink_script_pdo();
$rows = $pdo->query(
    "SELECT setting_key, setting_value FROM site_settings WHERE setting_group='whatsapp'"
)->fetchAll(PDO::FETCH_KEY_PAIR);

$token  = $rows['wa_api_token']           ?? '';
$wabaId = $rows['wa_business_account_id'] ?? '';
$apiVer = $rows['wa_api_version']         ?? 'v25.0';

if (!$token || !$wabaId) { echo "ERROR: missing WA credentials in DB\n"; exit(1); }

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function waGet(string $url, string $token): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
    ]);
    $r = curl_exec($ch); curl_close($ch);
    return json_decode($r ?: '{}', true) ?? [];
}

function waPost(string $url, string $token, array $body): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
        CURLOPT_POSTFIELDS => json_encode($body),
    ]);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['code' => $code, 'body' => json_decode($r ?: '{}', true) ?? []];
}

function fetchExisting(string $wabaId, string $apiVer, string $token): array {
    $map = [];
    $url = "https://graph.facebook.com/{$apiVer}/{$wabaId}/message_templates?limit=100";
    do {
        $r   = waGet($url, $token);
        foreach ($r['data'] ?? [] as $t) $map[$t['name']] = strtoupper($t['status'] ?? '');
        $url = $r['paging']['next'] ?? null;
    } while ($url);
    return $map;
}

// ---------------------------------------------------------------------------
// Template definitions (no emojis in HEADER TEXT)
// ---------------------------------------------------------------------------
$templates = [
    'motorlink_booking' => [
        'name' => 'motorlink_booking', 'category' => 'UTILITY', 'language' => 'en_US',
        'components' => [
            ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'New Booking - MotorLink'],
            ['type' => 'BODY',
             'text' => "You have a new car hire booking request!\n\n*Vehicle:* {{1}}\n*Customer:* {{2}}\n*Phone:* {{3}}\n*From:* {{4}}\n*To:* {{5}}\n\nLog in to MotorLink to confirm or decline.",
             'example' => ['body_text' => [['Toyota Hilux 2023','John Banda','+265888000000','01 May 2026','04 May 2026']]]],
            ['type' => 'FOOTER', 'text' => 'MotorLink Car Hire Platform'],
        ],
    ],
    'motorlink_booking_confirmed' => [
        'name' => 'motorlink_booking_confirmed', 'category' => 'UTILITY', 'language' => 'en_US',
        'components' => [
            ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Booking Confirmed - MotorLink'],
            ['type' => 'BODY',
             'text' => "Great news, {{1}}! Your car hire booking is confirmed.\n\n*Vehicle:* {{2}}\n*Pick-up:* {{3}}\n*Return:* {{4}}\n\nOwner contact: {{5}}\n\nEnjoy your journey!",
             'example' => ['body_text' => [['Sarah Phiri','Toyota Hilux 2023','01 May 2026','04 May 2026','+265888000001']]]],
            ['type' => 'FOOTER', 'text' => 'MotorLink Car Hire Platform'],
        ],
    ],
    'motorlink_booking_declined' => [
        'name' => 'motorlink_booking_declined', 'category' => 'UTILITY', 'language' => 'en_US',
        'components' => [
            ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Booking Update - MotorLink'],
            ['type' => 'BODY',
             'text' => "Hi {{1}}, unfortunately your booking request for *{{2}}* on {{3}} could not be accommodated.\n\nVisit MotorLink to browse other available vehicles.",
             'example' => ['body_text' => [['Sarah Phiri','Toyota Hilux 2023','01-04 May 2026']]]],
            ['type' => 'FOOTER', 'text' => 'MotorLink Car Hire Platform'],
        ],
    ],
    'motorlink_hire_reminder' => [
        'name' => 'motorlink_hire_reminder', 'category' => 'UTILITY', 'language' => 'en_US',
        'components' => [
            ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Pickup Reminder - MotorLink'],
            ['type' => 'BODY',
             'text' => "Hi {{1}}, reminder that your *{{2}}* hire starts tomorrow ({{3}}).\n\nOwner contact: {{4}}\n\nHave a safe trip!",
             'example' => ['body_text' => [['Sarah Phiri','Toyota Hilux 2023','01 May 2026','+265888000001']]]],
            ['type' => 'FOOTER', 'text' => 'MotorLink Car Hire Platform'],
        ],
    ],
    'motorlink_new_lead' => [
        'name' => 'motorlink_new_lead', 'category' => 'UTILITY', 'language' => 'en_US',
        'components' => [
            ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'New Lead - MotorLink'],
            ['type' => 'BODY',
             'text' => "Hi {{1}}, you have a new enquiry on MotorLink!\n\n*Listing:* {{2}}\n*From:* {{3}}\n\n*Message:* {{4}}\n\nReply via MotorLink or contact the buyer directly.",
             'example' => ['body_text' => [['James Dealer','2021 Nissan Navara','Peter Buyer','Is this still available for viewing?']]]],
            ['type' => 'FOOTER', 'text' => 'MotorLink Malawi'],
        ],
    ],
    'motorlink_listing_live' => [
        'name' => 'motorlink_listing_live', 'category' => 'UTILITY', 'language' => 'en_US',
        'components' => [
            ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Listing Approved - MotorLink'],
            ['type' => 'BODY',
             'text' => "Congratulations, {{1}}! Your listing for *{{2}}* is now live on MotorLink.\n\nBuyers can now see and enquire about your vehicle.",
             'example' => ['body_text' => [['James Seller','2021 Nissan Navara Double Cab']]]],
            ['type' => 'FOOTER', 'text' => 'MotorLink Malawi'],
        ],
    ],
    'motorlink_listing_rejected' => [
        'name' => 'motorlink_listing_rejected', 'category' => 'UTILITY', 'language' => 'en_US',
        'components' => [
            ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Listing Review - MotorLink'],
            ['type' => 'BODY',
             'text' => "Hi {{1}}, your listing for *{{2}}* requires attention.\n\n*Reason:* {{3}}\n\nPlease log in to MotorLink, update your listing, and resubmit for review.",
             'example' => ['body_text' => [['James Seller','2021 Nissan Navara','Photos are too blurry. Please upload clear images.']]]],
            ['type' => 'FOOTER', 'text' => 'MotorLink Malawi'],
        ],
    ],
    'motorlink_rate_experience' => [
        'name' => 'motorlink_rate_experience', 'category' => 'UTILITY', 'language' => 'en_US',
        'components' => [
            ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Share Your Experience - MotorLink'],
            ['type' => 'BODY',
             'text' => "Hi {{1}}, we hope you enjoyed your hire with *{{2}}*!\n\nWould you mind leaving a quick review? It helps other customers and supports local businesses.\n\nVisit MotorLink - it only takes a minute.",
             'example' => ['body_text' => [['Sarah Phiri','Lilongwe Car Rentals']]]],
            ['type' => 'FOOTER', 'text' => 'MotorLink Malawi'],
        ],
    ],
    'motorlink_new_user' => [
        'name' => 'motorlink_new_user', 'category' => 'UTILITY', 'language' => 'en_US',
        'components' => [
            ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Welcome to MotorLink'],
            ['type' => 'BODY',
             'text' => "Welcome to MotorLink, {{1}}!\n\nYou can now:\n- Browse and buy cars\n- List your vehicle for sale\n- Book car hire\n- Find trusted garages\n\nVisit motorlink.mw to get started.",
             'example' => ['body_text' => [['Grace Mwale']]]],
            ['type' => 'FOOTER', 'text' => 'MotorLink Malawi'],
        ],
    ],
];

$apiUrl   = "https://graph.facebook.com/{$apiVer}/{$wabaId}/message_templates";
$maxTries = 20;
$waitSecs = 120; // 2-min polling → 40 min total coverage
$attempt  = 0;

echo "=== MotorLink Template Retry Creator ===\n";
echo "Retrying up to {$maxTries} times ({$waitSecs}s between attempts = ~" . round($maxTries * $waitSecs / 60) . " min coverage).\n\n";

while ($attempt < $maxTries) {
    $attempt++;
    echo "── Attempt $attempt / $maxTries [" . date('H:i:s') . "] ──────────────\n";

    $existing   = fetchExisting($wabaId, $apiVer, $token);
    $remaining  = [];

    foreach ($templates as $name => $tpl) {
        $status = $existing[$name] ?? null;
        if ($status === 'APPROVED') {
            echo "  ✅  APPROVED  $name\n";
        } elseif ($status === 'PENDING') {
            echo "  ⏳  PENDING   $name\n";
        } else {
            $remaining[] = $name;
            echo "  ❓  MISSING   $name\n";
        }
    }

    if (empty($remaining)) {
        echo "\nAll 9 templates exist at Meta (APPROVED or PENDING).\n";
        break;
    }

    echo "\n  Creating " . count($remaining) . " missing templates...\n";
    foreach ($remaining as $name) {
        $r   = waPost($apiUrl, $token, $templates[$name]);
        $id  = $r['body']['id'] ?? null;
        $st  = strtoupper($r['body']['status'] ?? '');
        $err = $r['body']['error']['error_user_msg'] ?? $r['body']['error']['message'] ?? '';
        if ($id) {
            echo "    CREATED  $name  ID:$id  Status:$st\n";
        } else {
            echo "    FAILED   $name  $err\n";
            // Abort immediately on auth errors — no point retrying
            if (str_contains($err, 'access token') || str_contains($err, 'OAuthException')) {
                echo "\nToken expired or invalid — aborting.\n";
                echo "Run: php scripts/wa_update_token.php <new_token>\n";
                exit(1);
            }
        }
        sleep(1);
    }

    if ($attempt < $maxTries) {
        echo "\n  Waiting {$waitSecs}s before next check...\n\n";
        sleep($waitSecs);
    }
}

echo "\nDone. Run: php scripts/wa_check_templates.php  to see final statuses.\n";
