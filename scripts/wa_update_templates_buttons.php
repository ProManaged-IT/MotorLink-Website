<?php
/**
 * MotorLink — Add interactive buttons to existing WhatsApp templates.
 *
 * This script deletes the 9 UTILITY templates and recreates them with buttons.
 * motorlink_otp (AUTHENTICATION) is skipped — it already has a Copy Code button.
 *
 * Button strategy:
 *  QUICK_REPLY (webhook processes taps):
 *    - motorlink_booking        → Accept Booking | Decline Booking | Propose New Dates
 *    - motorlink_hire_reminder  → Got it, I'm ready! | I Need to Cancel
 *
 *  URL call-to-action (no webhook needed):
 *    - motorlink_booking_confirmed  → View My Booking
 *    - motorlink_booking_declined   → Browse Cars
 *    - motorlink_new_lead           → Open MotorLink
 *    - motorlink_listing_live       → View My Listings
 *    - motorlink_listing_rejected   → Update Listing
 *    - motorlink_rate_experience    → Leave a Review
 *    - motorlink_new_user           → Get Started
 *
 * ⚠️  IMPORTANT: Deleted APPROVED templates go PENDING for Meta review.
 *     Approval typically takes a few minutes to a few hours.
 *     During that window, template sends fall back to free-form messages.
 *
 * Usage: php scripts/wa_update_templates_buttons.php
 */

require_once __DIR__ . '/_bootstrap.php';
$pdo = motorlink_script_pdo();

$rows = $pdo->query(
    "SELECT setting_key, setting_value FROM site_settings WHERE setting_group='whatsapp'"
)->fetchAll(PDO::FETCH_KEY_PAIR);

$token  = $rows['wa_api_token']           ?? '';
$wabaId = $rows['wa_business_account_id'] ?? '';
$apiVer = !empty($rows['wa_api_version']) ? $rows['wa_api_version'] : 'v25.0';

if (!$token || !$wabaId) {
    echo "ERROR: Missing wa_api_token or wa_business_account_id in DB.\n";
    exit(1);
}

echo "=== MotorLink — WhatsApp Template Button Updater ===\n";
echo "WABA ID : $wabaId\n";
echo "API ver : $apiVer\n";
echo "Token   : ..." . substr($token, -6) . "\n\n";

// ---------------------------------------------------------------------------
// HTTP helpers
// ---------------------------------------------------------------------------
function waReq(string $method, string $url, string $token, ?array $body = null): array {
    $ch   = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode($resp ?: '{}', true) ?? [], 'curl_err' => $err];
}

function fetchExistingTemplates(string $wabaId, string $apiVer, string $token): array {
    $map = [];
    $url = "https://graph.facebook.com/{$apiVer}/{$wabaId}/message_templates?limit=100";
    do {
        $r    = waReq('GET', $url, $token);
        $data = $r['body']['data'] ?? [];
        foreach ($data as $t) {
            $map[$t['name']] = ['id' => $t['id'], 'status' => strtoupper($t['status'] ?? '')];
        }
        $url = $r['body']['paging']['next'] ?? null;
    } while ($url);
    return $map;
}

function deleteTemplate(string $wabaId, string $apiVer, string $token, string $name): void {
    $url = "https://graph.facebook.com/{$apiVer}/{$wabaId}/message_templates?name={$name}";
    $r   = waReq('DELETE', $url, $token);
    $ok  = $r['code'] === 200 || ($r['body']['success'] ?? false);
    echo "   DELETE $name → HTTP:{$r['code']} " . ($ok ? 'OK' : json_encode($r['body'])) . "\n";
}

function createTemplate(string $wabaId, string $apiVer, string $token, array $tpl): array {
    $url = "https://graph.facebook.com/{$apiVer}/{$wabaId}/message_templates";
    return waReq('POST', $url, $token, $tpl);
}

// ---------------------------------------------------------------------------
// Updated template definitions WITH buttons
// ---------------------------------------------------------------------------
$templates = [

    // 1 ── New booking request → car hire OWNER ────────────────────────────
    // QUICK_REPLY: tap replies trigger wa_webhook → auto-updates booking status
    [
        'name'     => 'motorlink_booking',
        'category' => 'UTILITY',
        'language' => 'en_US',
        'components' => [
            ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'New Booking - MotorLink'],
            [
                'type' => 'BODY',
                'text' => "You have a new car hire booking request!\n\n*Vehicle:* {{1}}\n*Customer:* {{2}}\n*Phone:* {{3}}\n*From:* {{4}}\n*To:* {{5}}\n\nTap a button below or log in to MotorLink to manage this booking.",
                'example' => ['body_text' => [['Toyota Hilux 2023', 'John Banda', '+265888000000', '01 May 2026', '04 May 2026']]],
            ],
            ['type' => 'FOOTER', 'text' => 'MotorLink Car Hire Platform'],
            [
                'type'    => 'BUTTONS',
                'buttons' => [
                    ['type' => 'QUICK_REPLY', 'text' => 'Accept Booking'],
                    ['type' => 'QUICK_REPLY', 'text' => 'Decline Booking'],
                    ['type' => 'QUICK_REPLY', 'text' => 'Propose New Dates'],
                ],
            ],
        ],
    ],

    // 2 ── Booking confirmed → RENTER ──────────────────────────────────────
    [
        'name'     => 'motorlink_booking_confirmed',
        'category' => 'UTILITY',
        'language' => 'en_US',
        'components' => [
            ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Booking Confirmed - MotorLink'],
            [
                'type' => 'BODY',
                'text' => "Great news, {{1}}! Your car hire booking has been confirmed.\n\n*Vehicle:* {{2}}\n*Pick-up:* {{3}}\n*Return:* {{4}}\n\nContact the owner: {{5}}\n\nEnjoy your journey!",
                'example' => ['body_text' => [['Sarah Phiri', 'Toyota Hilux 2023', '01 May 2026', '04 May 2026', '+265888000001']]],
            ],
            ['type' => 'FOOTER', 'text' => 'MotorLink Car Hire Platform'],
            [
                'type'    => 'BUTTONS',
                'buttons' => [
                    ['type' => 'URL', 'text' => 'View My Booking', 'url' => 'https://motorlink.mw/car-hire.html'],
                ],
            ],
        ],
    ],

    // 3 ── Booking declined → RENTER ───────────────────────────────────────
    [
        'name'     => 'motorlink_booking_declined',
        'category' => 'UTILITY',
        'language' => 'en_US',
        'components' => [
            ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Booking Update - MotorLink'],
            [
                'type' => 'BODY',
                'text' => "Hi {{1}}, unfortunately your booking request for *{{2}}* on {{3}} could not be accommodated.\n\nPlease visit MotorLink to browse other available vehicles for your dates.",
                'example' => ['body_text' => [['Sarah Phiri', 'Toyota Hilux 2023', '01-04 May 2026']]],
            ],
            ['type' => 'FOOTER', 'text' => 'MotorLink Car Hire Platform'],
            [
                'type'    => 'BUTTONS',
                'buttons' => [
                    ['type' => 'URL', 'text' => 'Browse Cars', 'url' => 'https://motorlink.mw/car-hire.html'],
                ],
            ],
        ],
    ],

    // 4 ── Booking pickup reminder → RENTER ───────────────────────────────
    // QUICK_REPLY: "Got it!" is informational; "I Need to Cancel" triggers cancellation
    [
        'name'     => 'motorlink_hire_reminder',
        'category' => 'UTILITY',
        'language' => 'en_US',
        'components' => [
            ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Pickup Reminder - MotorLink'],
            [
                'type' => 'BODY',
                'text' => "Hi {{1}}, just a reminder that your *{{2}}* hire starts tomorrow ({{3}}).\n\nOwner contact: {{4}}\n\nHave a safe trip!",
                'example' => ['body_text' => [['Sarah Phiri', 'Toyota Hilux 2023', '01 May 2026', '+265888000001']]],
            ],
            ['type' => 'FOOTER', 'text' => 'MotorLink Car Hire Platform'],
            [
                'type'    => 'BUTTONS',
                'buttons' => [
                    ['type' => 'QUICK_REPLY', 'text' => "Got it, I'm ready!"],
                    ['type' => 'QUICK_REPLY', 'text' => 'I Need to Cancel'],
                ],
            ],
        ],
    ],

    // 5 ── New buyer enquiry → DEALER/SELLER ──────────────────────────────
    [
        'name'     => 'motorlink_new_lead',
        'category' => 'UTILITY',
        'language' => 'en_US',
        'components' => [
            ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'New Lead - MotorLink'],
            [
                'type' => 'BODY',
                'text' => "Hi {{1}}, you have a new enquiry on MotorLink!\n\n*Listing:* {{2}}\n*From:* {{3}}\n\n*Message:*\n{{4}}\n\nReply via MotorLink or contact the buyer directly.",
                'example' => ['body_text' => [['James Dealer', '2021 Nissan Navara Double Cab', 'Peter Buyer', 'Hi, is this still available for viewing this weekend?']]],
            ],
            ['type' => 'FOOTER', 'text' => 'MotorLink Malawi'],
            [
                'type'    => 'BUTTONS',
                'buttons' => [
                    ['type' => 'URL', 'text' => 'Open MotorLink', 'url' => 'https://motorlink.mw/chat_system.html'],
                ],
            ],
        ],
    ],

    // 6 ── Listing approved/live → SELLER ─────────────────────────────────
    [
        'name'     => 'motorlink_listing_live',
        'category' => 'UTILITY',
        'language' => 'en_US',
        'components' => [
            ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Listing Approved - MotorLink'],
            [
                'type' => 'BODY',
                'text' => "Congratulations, {{1}}! Your listing for the *{{2}}* has been approved and is now live on MotorLink.\n\nBuyers can now see and enquire about your vehicle.",
                'example' => ['body_text' => [['James Seller', '2021 Nissan Navara Double Cab']]],
            ],
            ['type' => 'FOOTER', 'text' => 'MotorLink Malawi'],
            [
                'type'    => 'BUTTONS',
                'buttons' => [
                    ['type' => 'URL', 'text' => 'View My Listings', 'url' => 'https://motorlink.mw/my-listings.html'],
                ],
            ],
        ],
    ],

    // 7 ── Listing rejected → SELLER ──────────────────────────────────────
    [
        'name'     => 'motorlink_listing_rejected',
        'category' => 'UTILITY',
        'language' => 'en_US',
        'components' => [
            ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Listing Review - MotorLink'],
            [
                'type' => 'BODY',
                'text' => "Hi {{1}}, your listing for *{{2}}* requires attention.\n\n*Reason:* {{3}}\n\nPlease log in to MotorLink, update your listing, and resubmit for review.",
                'example' => ['body_text' => [['James Seller', '2021 Nissan Navara', 'Photos are too blurry. Please upload clear images.']]],
            ],
            ['type' => 'FOOTER', 'text' => 'MotorLink Malawi'],
            [
                'type'    => 'BUTTONS',
                'buttons' => [
                    ['type' => 'URL', 'text' => 'Update Listing', 'url' => 'https://motorlink.mw/sell.html'],
                ],
            ],
        ],
    ],

    // 8 ── Post-hire review request → RENTER ──────────────────────────────
    [
        'name'     => 'motorlink_rate_experience',
        'category' => 'UTILITY',
        'language' => 'en_US',
        'components' => [
            ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Share Your Experience - MotorLink'],
            [
                'type' => 'BODY',
                'text' => "Hi {{1}}, we hope you enjoyed your hire with *{{2}}*!\n\nWould you mind leaving a quick review? It helps other customers and supports local businesses.\n\nIt only takes a minute.",
                'example' => ['body_text' => [['Sarah Phiri', 'Lilongwe Car Rentals']]],
            ],
            ['type' => 'FOOTER', 'text' => 'MotorLink Malawi'],
            [
                'type'    => 'BUTTONS',
                'buttons' => [
                    ['type' => 'URL', 'text' => 'Leave a Review', 'url' => 'https://motorlink.mw/car-hire.html'],
                ],
            ],
        ],
    ],

    // 9 ── Welcome new registered user ────────────────────────────────────
    [
        'name'     => 'motorlink_new_user',
        'category' => 'UTILITY',
        'language' => 'en_US',
        'components' => [
            ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Welcome to MotorLink'],
            [
                'type' => 'BODY',
                'text' => "Welcome to MotorLink, {{1}}!\n\nYou can now:\n- Browse and buy cars\n- List your vehicle for sale\n- Book car hire\n- Find trusted garages\n\nVisit motorlink.mw to get started.",
                'example' => ['body_text' => [['Grace Mwale']]],
            ],
            ['type' => 'FOOTER', 'text' => 'MotorLink Malawi'],
            [
                'type'    => 'BUTTONS',
                'buttons' => [
                    ['type' => 'URL', 'text' => 'Get Started', 'url' => 'https://motorlink.mw'],
                ],
            ],
        ],
    ],
];

// ---------------------------------------------------------------------------
// Fetch existing templates
// ---------------------------------------------------------------------------
echo "=== Fetching existing templates from Meta ===\n";
$existing = fetchExistingTemplates($wabaId, $apiVer, $token);
foreach ($existing as $name => $info) {
    echo "  Found: $name [status:{$info['status']}]\n";
}
echo "\n";

// ---------------------------------------------------------------------------
// Confirm before proceeding
// ---------------------------------------------------------------------------
echo "This will DELETE and RECREATE the following 9 templates:\n";
foreach ($templates as $tpl) {
    echo "  - {$tpl['name']}\n";
}
echo "\nmotorlink_otp will NOT be touched.\n\n";
echo "Approved templates will become PENDING during Meta's re-review.\n";
echo "Continue? [y/N]: ";
$input = trim(fgets(STDIN));
if (strtolower($input) !== 'y') {
    echo "Aborted.\n";
    exit(0);
}
echo "\n";

// ---------------------------------------------------------------------------
// Delete → Recreate each template
// ---------------------------------------------------------------------------
$created = [];
$failed  = [];

foreach ($templates as $tpl) {
    $name   = $tpl['name'];
    $exists = isset($existing[$name]);

    echo "── $name ──────────────────────────────────────\n";

    if ($exists) {
        deleteTemplate($wabaId, $apiVer, $token, $name);
        sleep(2); // brief pause to let Meta process the deletion
    } else {
        echo "   (not found at Meta — will create fresh)\n";
    }

    $r   = createTemplate($wabaId, $apiVer, $token, $tpl);
    $id  = $r['body']['id'] ?? null;
    $st  = strtoupper($r['body']['status'] ?? '');
    $err = $r['body']['error']['error_user_msg'] ?? $r['body']['error']['message'] ?? '';

    if ($id) {
        echo "   CREATE OK  ID:$id  Status:$st\n";
        $created[] = $name;
    } else {
        echo "   CREATE FAILED  $err\n";
        $failed[] = $name;
    }
    sleep(1);
    echo "\n";
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "=== Summary ===\n";
echo "Created (" . count($created) . "): " . implode(', ', $created) . "\n";
if ($failed) {
    echo "Failed  (" . count($failed)  . "): " . implode(', ', $failed) . "\n";
    echo "\nRetry failed templates with: php scripts/wa_retry_create.php\n";
}
echo "\nTemplates are now PENDING Meta review.\n";
echo "Run php scripts/wa_check_templates.php to monitor approval status.\n";
echo "\nNOTE: The motorlink_booking webhook handler is at:\n";
echo "  https://motorlink.mw/api.php?action=wa_webhook\n";
echo "Configure this URL in your Meta app's webhook settings.\n";
