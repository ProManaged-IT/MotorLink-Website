<?php
require_once __DIR__ . '/_bootstrap.php';
$pdo = motorlink_script_pdo();
$token = $pdo->query("SELECT setting_value FROM site_settings WHERE setting_key='wa_api_token' LIMIT 1")->fetchColumn();
$ver   = 'v25.0';
$id    = '1276113317419157';

$ch = curl_init("https://graph.facebook.com/{$ver}/{$id}?fields=name,status,quality_score,rejected_reason,components");
curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_SSL_VERIFYHOST=>0,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token]]);
$resp = curl_exec($ch); curl_close($ch);
echo json_encode(json_decode($resp), JSON_PRETTY_PRINT) . PHP_EOL;
