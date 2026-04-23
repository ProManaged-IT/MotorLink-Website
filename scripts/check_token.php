<?php
$pdo = new PDO(
    'mysql:host=promanaged-it.com;dbname=p601229_motorlinkmalawi_db;charset=utf8mb4',
    'p601229',
    '2:p2WpmX[0YTs7',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$token = $pdo->query("SELECT setting_value FROM site_settings WHERE setting_key='wa_api_token'")->fetchColumn();
echo 'Token ends in: ' . substr($token, -8) . PHP_EOL;
echo 'Length: ' . strlen($token) . PHP_EOL;
