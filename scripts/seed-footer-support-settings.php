<?php
// Idempotent seed script for DB-managed footer support/help links.

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run via CLI.\n");
    exit(1);
}

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';

require_once __DIR__ . '/../api-common.php';

try {
    $db = getDB();

    $settings = [
        ['footer_support_help_label', 'Help Center', 'footer', 'string', 'Footer support link label: Help Center', 1],
        ['footer_support_help_href', 'help.html#top', 'footer', 'url', 'Footer support link target: Help Center', 1],
        ['footer_support_help_type', 'page', 'footer', 'string', 'Footer support link type: page|modal (Help Center)', 1],

        ['footer_support_safety_label', 'Safety Tips', 'footer', 'string', 'Footer support link label: Safety Tips', 1],
        ['footer_support_safety_href', 'safety.html#top', 'footer', 'url', 'Footer support link target: Safety Tips', 1],
        ['footer_support_safety_type', 'page', 'footer', 'string', 'Footer support link type: page|modal (Safety Tips)', 1],

        ['footer_support_contact_label', 'Contact Us', 'footer', 'string', 'Footer support link label: Contact Us', 1],
        ['footer_support_contact_href', 'contact.html#channels', 'footer', 'url', 'Footer support link target: Contact Us', 1],
        ['footer_support_contact_type', 'page', 'footer', 'string', 'Footer support link type: page|modal (Contact Us)', 1],

        ['footer_support_terms_label', 'Terms of Service', 'footer', 'string', 'Footer support link label: Terms of Service', 1],
        ['footer_support_terms_href', 'terms.html', 'footer', 'url', 'Footer support link target: Terms of Service', 1],
        ['footer_support_terms_type', 'page', 'footer', 'string', 'Footer support link type: page|modal (Terms of Service)', 1],

        ['footer_support_cookie_label', 'Cookie Policy', 'footer', 'string', 'Footer support link label: Cookie Policy', 1],
        ['footer_support_cookie_href', 'cookie-policy.html', 'footer', 'url', 'Footer support link target: Cookie Policy', 1],
        ['footer_support_cookie_type', 'page', 'footer', 'string', 'Footer support link type: page|modal (Cookie Policy)', 1],

        ['support_response_target', 'Support responses are typically within 24 business hours.', 'support', 'text', 'Support response expectation text', 1],
        ['support_emergency_note', 'For urgent fraud or safety concerns, include listing URL, screenshots, and transaction details.', 'support', 'text', 'Support emergency note', 1]
    ];

    $sql = "INSERT INTO site_settings (setting_key, setting_value, setting_group, setting_type, description, is_public)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                setting_group = VALUES(setting_group),
                setting_type = VALUES(setting_type),
                description = VALUES(description),
                is_public = VALUES(is_public)";

    $stmt = $db->prepare($sql);
    $db->beginTransaction();

    foreach ($settings as $row) {
        $stmt->execute($row);
    }

    $db->commit();
    echo "Footer support/help settings upserted: " . count($settings) . "\n";
    exit(0);
} catch (Exception $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, "Failed to seed footer support settings: " . $e->getMessage() . "\n");
    exit(1);
}
