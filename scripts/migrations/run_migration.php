<?php
// Simple SQL migration runner for MotorLink.

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$_SERVER['PHP_SELF'] = $_SERVER['PHP_SELF'] ?? 'scripts/migrations/run_migration.php';

require_once __DIR__ . '/../../api-common.php';

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(1);
}

$direction = strtolower($argv[1] ?? 'up');
if (!in_array($direction, ['up', 'down'], true)) {
    fwrite(STDERR, "Usage: php scripts/migrations/run_migration.php [up|down]\n");
    exit(1);
}

$filename = __DIR__ . '/2026-04-11_schema_hardening_' . $direction . '.sql';
if (!is_file($filename)) {
    fwrite(STDERR, "Migration file not found: {$filename}\n");
    exit(1);
}

$sql = file_get_contents($filename);
if ($sql === false) {
    fwrite(STDERR, "Failed to read migration file: {$filename}\n");
    exit(1);
}

// Strip whole-line SQL comments before splitting.
$sql = preg_replace('/^\s*--.*$/m', '', $sql);

$mysqli = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($mysqli->connect_errno) {
    fwrite(STDERR, "MySQL connection failed: " . $mysqli->connect_error . "\n");
    exit(1);
}

$executedBatches = 0;
if (!$mysqli->multi_query($sql)) {
    fwrite(STDERR, "Migration {$direction} failed before execution: " . $mysqli->error . "\n");
    $mysqli->close();
    exit(1);
}

do {
    if ($result = $mysqli->store_result()) {
        $result->free();
    }
    $executedBatches++;
    if (!$mysqli->more_results()) {
        break;
    }
    if (!$mysqli->next_result()) {
        fwrite(STDERR, "Migration {$direction} failed at batch {$executedBatches}: " . $mysqli->error . "\n");
        $mysqli->close();
        exit(1);
    }
} while (true);

$mysqli->close();
echo "Migration {$direction} completed successfully. Batches executed: {$executedBatches}\n";
exit(0);
