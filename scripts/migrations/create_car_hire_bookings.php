<?php
/**
 * Migration: create_car_hire_bookings
 * Creates the car_hire_bookings table and seeds WhatsApp API settings rows.
 */
require_once __DIR__ . '/../../api-common.php';

echo "Running migration: create_car_hire_bookings\n";

$db = getDB();

// 1. Bookings table
$db->exec("
    CREATE TABLE IF NOT EXISTS car_hire_bookings (
        id              INT PRIMARY KEY AUTO_INCREMENT,
        company_id      INT NOT NULL,
        fleet_id        INT DEFAULT NULL,
        vehicle_name    VARCHAR(200) DEFAULT NULL  COMMENT 'Snapshot at time of booking',
        daily_rate      DECIMAL(12,2) DEFAULT NULL COMMENT 'Snapshot at time of booking',
        renter_name     VARCHAR(150) NOT NULL,
        renter_phone    VARCHAR(30)  NOT NULL,
        renter_whatsapp VARCHAR(30)  DEFAULT NULL,
        start_date      DATE NOT NULL,
        end_date        DATE NOT NULL,
        duration_days   SMALLINT UNSIGNED NOT NULL DEFAULT 1,
        total_estimate  DECIMAL(12,2) DEFAULT NULL,
        special_requests TEXT DEFAULT NULL,
        status          ENUM('pending','confirmed','declined','cancelled','completed') NOT NULL DEFAULT 'pending',
        wa_sent         TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 if WhatsApp API message was sent to owner',
        wa_message_id   VARCHAR(120) DEFAULT NULL  COMMENT 'Meta Cloud API wamid',
        created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_company (company_id),
        INDEX idx_fleet   (fleet_id),
        INDEX idx_status  (status),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "✓ car_hire_bookings table ready.\n";

$db->exec("\n    ALTER TABLE car_hire_bookings\n    MODIFY status ENUM('pending','confirmed','declined','cancelled','completed') NOT NULL DEFAULT 'pending'\n");
echo "✓ car_hire_bookings status enum updated.\n";

// 2. Seed WhatsApp API settings into site_settings (blank — admin fills these in)
$waSettings = [
    'wa_enabled'         => ['value' => '0',  'desc' => 'Enable WhatsApp Cloud API integration (1=on, 0=off)'],
    'wa_public_buttons_enabled' => ['value' => '1', 'desc' => 'Show public WhatsApp buttons and wa.me chat links (1=show, 0=hide)', 'public' => 1],
    'wa_api_token'       => ['value' => '',   'desc' => 'Meta WhatsApp Cloud API bearer token (permanent token)'],
    'wa_phone_number_id' => ['value' => '',   'desc' => 'Meta WhatsApp Phone Number ID from Business Manager'],
    'wa_api_version'     => ['value' => 'v25.0', 'desc' => 'Meta Graph API version (e.g. v25.0)'],
];

$check  = $db->prepare("SELECT id FROM site_settings WHERE setting_key = ? LIMIT 1");
$insert = $db->prepare(
    "INSERT INTO site_settings (setting_key, setting_value, setting_group, setting_type, description, is_public)
    VALUES (?, ?, 'whatsapp', 'string', ?, ?)"
);

foreach ($waSettings as $key => $cfg) {
    $check->execute([$key]);
    if (!$check->fetch()) {
        $insert->execute([$key, $cfg['value'], $cfg['desc'], !empty($cfg['public']) ? 1 : 0]);
        echo "  + Seeded site_settings[$key]\n";
    } else {
        echo "  ~ site_settings[$key] already exists, skipped.\n";
    }
}

echo "✓ WhatsApp site_settings rows ready.\n";
echo "Migration complete.\n";
