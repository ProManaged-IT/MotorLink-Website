<?php
/**
 * Submit the 4 templates that are stuck in deletion limbo under their original names.
 * Uses alternative names to bypass Meta's deletion-propagation lock.
 *
 * Name mapping (original → bypass name used here):
 *   motorlink_booking_reminder → motorlink_hire_reminder
 *   motorlink_listing_approved → motorlink_listing_live
 *   motorlink_review_request   → motorlink_rate_experience
 *   motorlink_welcome          → motorlink_new_user
 */

require_once __DIR__ . '/_bootstrap.php';
$pdo  = motorlink_script_pdo();
$rows = $pdo->query(
    "SELECT setting_key, setting_value FROM site_settings WHERE setting_group='whatsapp'"
)->fetchAll(PDO::FETCH_KEY_PAIR);

$token  = $rows['wa_api_token']           ?? '';
$wabaId = $rows['wa_business_account_id'] ?? '';
$apiVer = $rows['wa_api_version']         ?? 'v25.0';

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

$url = "https://graph.facebook.com/{$apiVer}/{$wabaId}/message_templates";

$templates = [
    // Booking pickup reminder (bypass name: motorlink_hire_reminder)
    [
        'name' => 'motorlink_hire_reminder', 'category' => 'UTILITY', 'language' => 'en_US',
        'components' => [
            ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Pickup Reminder - MotorLink'],
            ['type' => 'BODY',
             'text' => "Hi {{1}}, reminder that your *{{2}}* hire starts tomorrow ({{3}}).\n\nOwner contact: {{4}}\n\nHave a safe trip!",
             'example' => ['body_text' => [['Sarah Phiri', 'Toyota Hilux 2023', '01 May 2026', '+265888000001']]]],
            ['type' => 'FOOTER', 'text' => 'MotorLink Car Hire Platform'],
        ],
    ],
    // Listing approved/live (bypass name: motorlink_listing_live)
    [
        'name' => 'motorlink_listing_live', 'category' => 'UTILITY', 'language' => 'en_US',
        'components' => [
            ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Listing Approved - MotorLink'],
            ['type' => 'BODY',
             'text' => "Congratulations, {{1}}! Your listing for *{{2}}* is now live on MotorLink.\n\nBuyers can now see and enquire about your vehicle.",
             'example' => ['body_text' => [['James Seller', '2021 Nissan Navara Double Cab']]]],
            ['type' => 'FOOTER', 'text' => 'MotorLink Malawi'],
        ],
    ],
    // Post-hire review request (bypass name: motorlink_rate_experience)
    [
        'name' => 'motorlink_rate_experience', 'category' => 'UTILITY', 'language' => 'en_US',
        'components' => [
            ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Share Your Experience - MotorLink'],
            ['type' => 'BODY',
             'text' => "Hi {{1}}, we hope you enjoyed your hire with *{{2}}*!\n\nWould you mind leaving a quick review? It helps other customers and supports local businesses.\n\nVisit MotorLink - it only takes a minute.",
             'example' => ['body_text' => [['Sarah Phiri', 'Lilongwe Car Rentals']]]],
            ['type' => 'FOOTER', 'text' => 'MotorLink Malawi'],
        ],
    ],
    // Welcome new user (bypass name: motorlink_new_user)
    [
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

echo "=== Submitting 4 bypass-name templates ===\n\n";
foreach ($templates as $tpl) {
    $r   = waPost($url, $token, $tpl);
    $id  = $r['body']['id'] ?? null;
    $st  = strtoupper($r['body']['status'] ?? '');
    $err = $r['body']['error']['error_user_msg'] ?? $r['body']['error']['message'] ?? '';
    if ($id) {
        echo "  CREATED  {$tpl['name']}  ID:{$id}  Status:{$st}\n";
    } else {
        echo "  FAILED   {$tpl['name']}  {$err}\n";
    }
    sleep(1);
}
echo "\nDone.\n";
