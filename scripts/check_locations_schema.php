<?php
$pdo = new PDO(
    'mysql:host=promanaged-it.com;dbname=p601229_motorlinkmalawi_db;charset=utf8mb4',
    'p601229',
    '2:p2WpmX[0YTs7',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$cols = $pdo->query("SHOW COLUMNS FROM locations")->fetchAll(PDO::FETCH_COLUMN);
echo "locations columns: " . implode(', ', $cols) . PHP_EOL;
