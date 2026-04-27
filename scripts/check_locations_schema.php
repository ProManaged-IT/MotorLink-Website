<?php
require_once __DIR__ . '/_bootstrap.php';
$pdo = motorlink_script_pdo();
$cols = $pdo->query("SHOW COLUMNS FROM locations")->fetchAll(PDO::FETCH_COLUMN);
echo "locations columns: " . implode(', ', $cols) . PHP_EOL;
