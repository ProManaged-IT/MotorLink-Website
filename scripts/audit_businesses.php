<?php
/**
 * audit_businesses.php - Lists all businesses in the live DB for review
 * Usage: php scripts/audit_businesses.php
 */
declare(strict_types=1);
chdir(dirname(__DIR__));
require 'api-common.php';

$db = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

foreach (['car_dealers' => 'business_name', 'garages' => 'name', 'car_hire_companies' => 'business_name'] as $table => $nameCol) {
    $rows = $db->query("SELECT id, `$nameCol` AS bname, address, phone, website, facebook_url, status FROM `$table` ORDER BY `$nameCol`")->fetchAll();
    echo "\n=== $table (" . count($rows) . ") ===\n";
    foreach ($rows as $r) {
        echo "  [{$r['id']}] {$r['bname']} | {$r['address']} | ph:{$r['phone']} | web:{$r['website']} | {$r['status']}\n";
    }
}
