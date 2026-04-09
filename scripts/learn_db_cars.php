<?php
/**
 * Intentional DB car learning runner.
 * Usage: php scripts/learn_db_cars.php [count]
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

require_once __DIR__ . '/../api-common.php';
require_once __DIR__ . '/../ai-learning-api.php';

$count = isset($argv[1]) ? (int)$argv[1] : 300;
$count = max(1, min($count, 5000));

try {
    $db = getDB();
    $result = learnWebCacheFromDatabaseCars($db, $count);

    echo json_encode([
        'timestamp' => date('Y-m-d H:i:s'),
        'count_requested' => $count,
        'result' => $result
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

    exit(!empty($result['success']) ? 0 : 2);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}
