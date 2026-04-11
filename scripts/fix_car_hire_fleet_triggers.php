<?php
// Fix car_hire_fleet triggers that cause MySQL 1442 during inserts/updates.

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$_SERVER['PHP_SELF'] = $_SERVER['PHP_SELF'] ?? 'scripts/fix_car_hire_fleet_triggers.php';

require_once __DIR__ . '/../api-common.php';

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(1);
}

try {
    $db = getDB();

    $db->exec("DROP TRIGGER IF EXISTS trg_fleet_insert_count");
    $db->exec("DROP TRIGGER IF EXISTS trg_fleet_update_count");

    $db->exec("
        CREATE TRIGGER trg_fleet_insert_count
        AFTER INSERT ON car_hire_fleet
        FOR EACH ROW
        BEGIN
            CALL UpdateCompanyVehicleCounts(NEW.company_id);
        END
    ");

    $db->exec("
        CREATE TRIGGER trg_fleet_update_count
        AFTER UPDATE ON car_hire_fleet
        FOR EACH ROW
        BEGIN
            IF OLD.company_id != NEW.company_id THEN
                CALL UpdateCompanyVehicleCounts(OLD.company_id);
                CALL UpdateCompanyVehicleCounts(NEW.company_id);
            ELSE
                CALL UpdateCompanyVehicleCounts(NEW.company_id);
            END IF;
        END
    ");

    echo "car_hire_fleet triggers fixed successfully.\n";
    exit(0);
} catch (Exception $e) {
    fwrite(STDERR, "Failed to fix triggers: " . $e->getMessage() . "\n");
    exit(1);
}
