<?php
/**
 * audit_dealers.php - Lists car_dealers only
 */
declare(strict_types=1);
chdir(dirname(__DIR__));
require 'api-common.php';
$db = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$rows = $db->query("SELECT id, business_name, address, phone, website, facebook_url, status FROM car_dealers ORDER BY business_name")->fetchAll(PDO::FETCH_ASSOC);
echo "=== car_dealers (" . count($rows) . ") ===\n";
foreach($rows as $r) {
    echo "  [{$r['id']}] {$r['business_name']} | {$r['address']} | ph:{$r['phone']} | web:{$r['website']} | {$r['status']}\n";
}
