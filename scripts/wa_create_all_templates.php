<?php
/**
 * MotorLink — Create all WhatsApp message templates and submit for Meta approval.
 *
 * Templates:
 *  1. motorlink_booking          — New booking request → car hire owner
 *  2. motorlink_booking_confirmed — Booking confirmed  → renter
 *  3. motorlink_booking_declined  — Booking declined   → renter
 *  4. motorlink_hire_reminder     — Pickup reminder    → renter
 *  5. motorlink_new_lead          — New buyer enquiry  → dealer/seller
 *  6. motorlink_listing_live      — Listing went live  → seller
 *  7. motorlink_listing_rejected  — Listing rejected   → seller
 *  8. motorlink_rate_experience   — Post-hire review ask → renter
 *  9. motorlink_new_user          — Welcome new user   → new registrant
 * 10. motorlink_otp               — OTP/verification   → user (AUTHENTICATION)
 *
 * Usage: php scripts/wa_create_all_templates.php
 */

require_once __DIR__ . '/_bootstrap.php';
$pdo = motorlink_script_pdo();

$rows = $pdo->query(
    "SELECT setting_key, setting_value FROM site_settings WHERE setting_group='whatsapp'"
)->fetchAll(PDO::FETCH_KEY_PAIR);

$token      = $rows['wa_api_token']           ?? '';
$phoneNumId = $rows['wa_phone_number_id']     ?? '';
$wabaId     = $rows['wa_business_account_id'] ?? '';
$apiVer     = !empty($rows['wa_api_version']) ? $rows['wa_api_version'] : 'v25.0';

if (!$token || !$phoneNumId || !$wabaId) {
    echo "ERROR: Missing wa_api_token, wa_phone_number_id, or wa_business_account_id in DB.\n";
    exit(1);
}

echo "=== MotorLink — WhatsApp Template Creator ===\n";
echo "WABA ID   : $wabaId\n";
echo "Phone ID  : $phoneNumId\n";
echo "API ver   : $apiVer\n";
echo "Token     : ..." . substr($token, -6) . "\n\n";

// ---------------------------------------------------------------------------
// HTTP helpers
// ---------------------------------------------------------------------------
function waReq(string $method, string $url, string $token, ?array $body = null): array {
    $ch = curl_init($url);
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

// ---------------------------------------------------------------------------
// Fetch existing templates from Meta so we can skip already-approved ones
// and delete rejected/pending ones before recreating
// ---------------------------------------------------------------------------
function fetchExistingTemplates(string $wabaId, string $apiVer, string $token): array {
    $map = [];
    $url = "https://graph.facebook.com/{$apiVer}/{$wabaId}/message_templates?limit=100";
    do {
        $r    = waReq('GET', $url, $token);
        $data = $r['body']['data'] ?? [];
        foreach ($data as $t) {
            $map[$t['name']] = ['id' => $t['id'], 'status' => strtoupper($t['status'] ?? '')];
        }
        $url  = $r['body']['paging']['next'] ?? null;
    } while ($url);
    return $map;
}

// ---------------------------------------------------------------------------
// Delete a template by name (needed to recreate REJECTED or change body)
// ---------------------------------------------------------------------------
function deleteTemplate(string $wabaId, string $apiVer, string $token, string $name): void {
    $url = "https://graph.facebook.com/{$apiVer}/{$wabaId}/message_templates?name={$name}";
    $r   = waReq('DELETE', $url, $token);
    $ok  = $r['code'] === 200 || ($r['body']['success'] ?? false);
    echo "   DELETE $name → HTTP:{$r['code']} " . ($ok ? 'OK' : json_encode($r['body'])) . "\n";
}

// ---------------------------------------------------------------------------
// Create one template and return result array
// ---------------------------------------------------------------------------
function createTemplate(string $wabaId, string $apiVer, string $token, array $tpl): array {
    $url = "https://graph.facebook.com/{$apiVer}/{$wabaId}/message_templates";
    return waReq('POST', $url, $token, $tpl);
}

// ---------------------------------------------------------------------------
// Template definitions
// ---------------------------------------------------------------------------
$templates = [

    // 1 ── New booking request → car hire OWNER ────────────────────────────
    [
        'name'     => 'motorlink_booking',
        'category' => 'UTILITY',
        'language' => 'en_US',
        'components' => [
            ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'New Booking - MotorLink'],
            [
                'type' => 'BODY',
                'text' => "You have a new car hire booking request!\n\n*Vehicle:* {{1}}\n*Customer:* {{2}}\n*Phone:* {{3}}\n*From:* {{4}}\n*To:* {{5}}\n\nLog in to MotorLink to confirm or decline this booking.",
                'example' => ['body_text' => [['Toyota Hilux 2023', 'John Banda', '+265888000000', '01 May 2026', '04 May 2026']]],
            ],
            ['type' => 'FOOTER', 'text' => 'MotorLink Car Hire Platform'],
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
        ],
    ],

    // 3 ── Booking declined → RENTER ───────────────────────────────────────
    [
        'name'     => 'motorlink_booking_declined',
        'category' => 'UTILITY',
        'language' => 'en_US',
        'components' => [
            ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Booking Update — MotorLink'],
            [
                'type' => 'BODY',
                'text' => "Hi {{1}}, unfortunately your booking request for *{{2}}* on {{3}} could not be accommodated.\n\nPlease visit MotorLink to browse other available vehicles for your dates.",
                'example' => ['body_text' => [['Sarah Phiri', 'Toyota Hilux 2023', '01–04 May 2026']]],
            ],
            ['type' => 'FOOTER', 'text' => 'MotorLink Car Hire Platform'],
        ],
    ],

    // 4 ── Booking pickup reminder → RENTER ───────────────────────────────
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
        ],
    ],

    // 7 ── Listing rejected → SELLER ──────────────────────────────────────
    [
        'name'     => 'motorlink_listing_rejected',
        'category' => 'UTILITY',
        'language' => 'en_US',
        'components' => [
            ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Listing Review — MotorLink'],
            [
                'type' => 'BODY',
                'text' => "Hi {{1}}, your listing for *{{2}}* requires attention.\n\n*Reason:* {{3}}\n\nPlease log in to MotorLink, update your listing, and resubmit for review.",
                'example' => ['body_text' => [['James Seller', '2021 Nissan Navara', 'Photos are too blurry. Please upload clear images.']]],
            ],
            ['type' => 'FOOTER', 'text' => 'MotorLink Malawi'],
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
                'text' => "Hi {{1}}, we hope you enjoyed your hire with *{{2}}*!\n\nWould you mind leaving a quick review? It helps other customers and supports local businesses.\n\nVisit MotorLink to leave your review — it only takes a minute.",
                'example' => ['body_text' => [['Sarah Phiri', 'Lilongwe Car Rentals']]],
            ],
            ['type' => 'FOOTER', 'text' => 'MotorLink Malawi'],
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
        ],
    ],

    // 10 ── OTP / one-time code (AUTHENTICATION category) ─────────────────
    // AUTHENTICATION templates: Meta auto-generates the body text.
    // Format: "[CODE] is your [AppName] verification code."
    // You cannot supply custom body text for this category.
    [
        'name'     => 'motorlink_otp',
        'category' => 'AUTHENTICATION',
        'language' => 'en_US',
        'components' => [
            [
                'type'                        => 'BODY',
                'add_security_recommendation' => true,
            ],
            [
                'type'                    => 'FOOTER',
                'code_expiration_minutes' => 10,
            ],
            [
                'type'    => 'BUTTONS',
                'buttons' => [[
                    'type'     => 'OTP',
                    'otp_type' => 'COPY_CODE',
                    'text'     => 'Copy Code',
                ]],
            ],
        ],
    ],
];

// ---------------------------------------------------------------------------
// Fetch existing templates
// ---------------------------------------------------------------------------
echo "=== Fetching existing templates from Meta ===\n";
$existing = fetchExistingTemplates($wabaId, $apiVer, $token);
if ($existing) {
    foreach ($existing as $name => $info) {
        echo "  Found: $name [status:{$info['status']}]\n";
    }
} else {
    echo "  None found (or fetch failed).\n";
}
echo "\n";

// ---------------------------------------------------------------------------
// Submit each template
// ---------------------------------------------------------------------------
$results = [];

foreach ($templates as $tpl) {
    $name = $tpl['name'];
    echo "── $name ─────────────────────────────────\n";

    $current = $existing[$name] ?? null;

    // Skip already-approved templates (no-op)
    if ($current && $current['status'] === 'APPROVED') {
        echo "  ✅ Already APPROVED — skipping.\n\n";
        $results[$name] = 'APPROVED (skipped)';
        continue;
    }

    // Skip PENDING templates — they are already submitted and under review
    if ($current && $current['status'] === 'PENDING') {
        echo "  ⏳ Already PENDING (under review) — skipping.\n\n";
        $results[$name] = 'PENDING (skipped)';
        continue;
    }

    // Delete REJECTED/PAUSED/DISABLED before recreating (not PENDING)
    if ($current && in_array($current['status'], ['REJECTED', 'PAUSED', 'DISABLED'], true)) {
        echo "  Status is {$current['status']} — deleting to recreate...\n";
        deleteTemplate($wabaId, $apiVer, $token, $name);
        sleep(2);
    }

    // Submit
    $r = createTemplate($wabaId, $apiVer, $token, $tpl);
    $status = strtoupper($r['body']['status'] ?? '');
    $id     = $r['body']['id'] ?? null;
    $err    = $r['body']['error']['message'] ?? null;

    if ($r['code'] === 200 && $id) {
        echo "  ✅ Submitted — ID:$id  Status:$status\n\n";
        $results[$name] = "SUBMITTED (status:$status)";
    } elseif ($r['body']['error']['code'] ?? null) {
        $errMsg = $r['body']['error']['message'] ?? '';
        $errUsr = $r['body']['error']['error_user_msg'] ?? '';
        $detail = $errUsr ?: $errMsg;
        echo "  ❌ Error ({$r['body']['error']['code']}): $detail\n\n";
        $results[$name] = "FAILED: $detail";
    } else {
        echo "  ⚠ HTTP:{$r['code']} — " . json_encode($r['body']) . "\n\n";
        $results[$name] = "HTTP:{$r['code']}";
    }

    // Brief pause between submissions to avoid rate limits
    sleep(1);
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "═══════════════════════════════════════════\n";
echo "           SUBMISSION SUMMARY\n";
echo "═══════════════════════════════════════════\n";
$passed = 0;
foreach ($results as $name => $status) {
    $icon = str_contains($status, 'FAILED') ? '❌' : (str_contains($status, 'skipped') ? '✅' : '✅');
    echo "  $icon $name\n       $status\n";
    if (!str_contains($status, 'FAILED')) $passed++;
}
echo "\n  Total: $passed/" . count($results) . " submitted or already approved\n";
echo "\nMeta review is typically instant to a few hours.\n";
echo "Run: php scripts/wa_check_templates.php  to poll approval status.\n";
