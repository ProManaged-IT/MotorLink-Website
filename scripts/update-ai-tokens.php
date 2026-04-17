<?php
// One-time script: Update AI chat settings for improved responses
try {
    $pdo = new PDO(
        'mysql:host=promanaged-it.com;dbname=p601229_motorlinkmalawi_db;charset=utf8mb4',
        'p601229',
        '2:p2WpmX[0YTs7',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $stmt = $pdo->prepare('UPDATE ai_chat_settings SET max_tokens_per_request = 1200, temperature = 0.7 WHERE id = 1');
    $stmt->execute();
    echo 'Updated: ' . $stmt->rowCount() . " row(s)\n";
    $check = $pdo->query('SELECT max_tokens_per_request, temperature FROM ai_chat_settings LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    echo 'Current: max_tokens=' . $check['max_tokens_per_request'] . ', temp=' . $check['temperature'] . "\n";
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
}
