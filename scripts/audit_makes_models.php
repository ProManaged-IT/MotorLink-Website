<?php
require_once __DIR__ . '/_bootstrap.php';
$db = motorlink_script_pdo();

echo "=== CAR MAKES ===\n";
$makes = $db->query('SELECT id, name, country FROM car_makes ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
foreach ($makes as $m) {
    echo $m['id'] . "\t" . $m['name'] . "\t(" . $m['country'] . ")\n";
}

echo "\n=== CAR MODELS (with key specs) ===\n";
$models = $db->query('
    SELECT cm.id, mk.name AS make, cm.name AS model, cm.body_type, cm.year_start, cm.year_end,
           cm.engine_size_liters, cm.engine_cylinders, cm.fuel_type, cm.transmission_type,
           cm.horsepower_hp, cm.torque_nm, cm.seating_capacity, cm.drive_type
    FROM car_models cm
    JOIN car_makes mk ON cm.make_id = mk.id
    ORDER BY mk.name, cm.name, cm.year_start
')->fetchAll(PDO::FETCH_ASSOC);
foreach ($models as $r) {
    echo sprintf(
        "%d\t%s\t%s\t%s\t%s-%s\t%.1fL %s\t%s\t%dhp\t%s\n",
        $r['id'], $r['make'], $r['model'], $r['body_type'] ?? '-',
        $r['year_start'] ?? '?', $r['year_end'] ?? 'pres',
        $r['engine_size_liters'] ?? 0, $r['fuel_type'] ?? '-',
        $r['transmission_type'] ?? '-', $r['horsepower_hp'] ?? 0,
        $r['drive_type'] ?? '-'
    );
}
echo "\nTotal makes: " . count($makes) . "\n";
echo "Total models: " . count($models) . "\n";
