<?php
require_once __DIR__ . '/_bootstrap.php';
$pdo = motorlink_script_pdo();
$token = $pdo->query("SELECT setting_value FROM site_settings WHERE setting_key='wa_api_token'")->fetchColumn();
echo 'Token ends in: ' . substr($token, -8) . PHP_EOL;
echo 'Length: ' . strlen($token) . PHP_EOL;
