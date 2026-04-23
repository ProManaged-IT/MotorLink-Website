<?php
/**
 * Create user_feedback table + seed feedback/walkthrough settings.
 * Idempotent — safe to re-run.
 */
$pdo = new PDO(
    'mysql:host=promanaged-it.com;dbname=p601229_motorlinkmalawi_db;charset=utf8mb4',
    'p601229',
    '2:p2WpmX[0YTs7',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

echo "Creating user_feedback table..." . PHP_EOL;
$pdo->exec("
CREATE TABLE IF NOT EXISTS user_feedback (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NULL,
    user_name       VARCHAR(120) NULL,
    email           VARCHAR(255) NULL,
    rating          TINYINT NOT NULL DEFAULT 0,
    category        VARCHAR(40) NOT NULL DEFAULT 'general',
    message         TEXT NOT NULL,
    page_url        VARCHAR(500) NULL,
    user_agent      VARCHAR(500) NULL,
    ip_address      VARCHAR(64)  NULL,
    status          ENUM('new','reviewed','archived') NOT NULL DEFAULT 'new',
    admin_notes     TEXT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at     TIMESTAMP NULL,
    reviewed_by     INT NULL,
    INDEX idx_status (status),
    INDEX idx_created (created_at),
    INDEX idx_user (user_id),
    INDEX idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "OK" . PHP_EOL;

echo "Adding walkthrough_completed_at to users..." . PHP_EOL;
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN walkthrough_completed_at DATETIME NULL AFTER preferences");
    echo "Column added" . PHP_EOL;
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "Column already exists" . PHP_EOL;
    } else {
        // Try without `after preferences` if preferences doesn't exist
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN walkthrough_completed_at DATETIME NULL");
            echo "Column added (no anchor)" . PHP_EOL;
        } catch (PDOException $e2) {
            if (strpos($e2->getMessage(), 'Duplicate column') !== false) {
                echo "Column already exists" . PHP_EOL;
            } else {
                throw $e2;
            }
        }
    }
}

echo "Seeding site_settings for feedback + walkthrough..." . PHP_EOL;
$defaults = [
    'feedback_enabled'         => '1',
    'feedback_delay_minutes'   => '5',
    'feedback_show_on_unload'  => '1',
    'feedback_cooldown_days'   => '30',
    'walkthrough_enabled'      => '1',
];
$ins = $pdo->prepare("INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES (?, ?)");
foreach ($defaults as $k => $v) {
    $ins->execute([$k, $v]);
    echo "  $k = $v" . PHP_EOL;
}

echo "Done." . PHP_EOL;
