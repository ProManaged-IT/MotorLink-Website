<?php
/**
 * Migration: Create business_reviews table
 * Unified 5-star rating + review table for dealers, garages, and car hire companies.
 * Run: php scripts/migrations/create_business_reviews.php
 */
declare(strict_types=1);
chdir(dirname(__DIR__, 2));
require 'api-common.php';

$db = Database::getInstance()->getConnection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "Running migration: create_business_reviews\n";

$db->exec("
    CREATE TABLE IF NOT EXISTS business_reviews (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        business_type ENUM('dealer','garage','car_hire') NOT NULL,
        business_id   INT UNSIGNED NOT NULL,
        user_id       INT UNSIGNED NOT NULL,
        rating        TINYINT UNSIGNED NOT NULL COMMENT '1-5 stars',
        review_text   TEXT NULL,
        status        ENUM('active','hidden') NOT NULL DEFAULT 'active',
        created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_business (business_type, business_id, user_id),
        INDEX idx_business (business_type, business_id, status),
        INDEX idx_user    (user_id),
        CONSTRAINT chk_rating CHECK (rating BETWEEN 1 AND 5)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

echo "✓ business_reviews table ready.\n";

$db->exec("
    CREATE TABLE IF NOT EXISTS business_review_settings (
        business_type ENUM('dealer','garage','car_hire') NOT NULL,
        business_id   INT UNSIGNED NOT NULL,
        show_reviews  TINYINT(1) NOT NULL DEFAULT 1,
        created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (business_type, business_id),
        INDEX idx_show_reviews (business_type, show_reviews)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

echo "✓ business_review_settings table ready.\n";
