<?php
/**
 * cleanup_irrelevant_businesses.php
 *
 * Removes / inactivates businesses that are clearly NOT relevant to MotorLink:
 *  - Test/blank seeding records (hard DELETE)
 *  - Businesses outside Malawi
 *  - Non-automotive businesses (banks, retail, electronics, lodges, govt)
 *  - Pure fuel stations (all TotalEnergies / Puma Energy chains)
 *  - Invalid / location-only names
 *  - Bus depots, airports, taxi ranks placed in wrong tables
 *
 * Usage: php scripts/cleanup_irrelevant_businesses.php [--dry-run]
 * --dry-run  Print what would be done without touching the DB.
 */

declare(strict_types=1);
chdir(dirname(__DIR__));
require 'api-common.php';

$dry = in_array('--dry-run', $argv ?? [], true);

$db = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$totalRemoved = 0;

function run(PDO $db, string $label, string $sql, bool $dry): int
{
    global $totalRemoved;
    if ($dry) {
        // Count what would be affected
        $countSql = preg_replace('/^(DELETE FROM|UPDATE\s+\S+\s+SET\s+[^W]+)/i', '', $sql);
        // Simple approach: just show the SQL
        echo "[DRY-RUN] $label\n  SQL: " . substr($sql, 0, 120) . "...\n\n";
        return 0;
    }
    $affected = $db->exec($sql);
    echo "  [OK] $label — $affected rows\n";
    $totalRemoved += $affected;
    return (int) $affected;
}

echo "=== MotorLink Business Cleanup ===\n";
echo ($dry ? "(DRY-RUN mode — no DB changes)\n" : "(LIVE mode)\n") . "\n";

// ─────────────────────────────────────────────────────────────────────────────
// 1. HARD DELETE: Blank/test suspended records  (no real business name)
// ─────────────────────────────────────────────────────────────────────────────
echo "--- Phase 1: Hard-delete blank/test suspended records ---\n";

run($db, "car_dealers: blank suspended", "
    DELETE FROM car_dealers
    WHERE status = 'suspended'
      AND (business_name IS NULL OR TRIM(business_name) = '')
", $dry);

run($db, "garages: blank suspended", "
    DELETE FROM garages
    WHERE status = 'suspended'
      AND (name IS NULL OR TRIM(name) = '')
", $dry);

run($db, "car_hire_companies: blank suspended", "
    DELETE FROM car_hire_companies
    WHERE status = 'suspended'
      AND (business_name IS NULL OR TRIM(business_name) = '')
", $dry);

run($db, "car_dealers: e2e/upload test records", "
    DELETE FROM car_dealers
    WHERE business_name LIKE '%Dealer Upload Tester%'
       OR business_name LIKE '%Dealer Biz e2e%'
       OR business_name LIKE '%DEALER Updated e2e%'
       OR business_name LIKE '%Dealer Tester%'
", $dry);

run($db, "car_hire_companies: e2e/upload test records", "
    DELETE FROM car_hire_companies
    WHERE business_name LIKE '%Car Hire Upload Tester%'
       OR business_name LIKE '%Car Hire e2e%'
", $dry);

// ─────────────────────────────────────────────────────────────────────────────
// 2. INACTIVATE: Businesses OUTSIDE MALAWI
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- Phase 2: Inactivate businesses outside Malawi ---\n";

$foreignAddressPatterns = "
    address LIKE '%, Tanzania%'
    OR address LIKE '%, Zambia%'
    OR address LIKE '%, Zimbabwe%'
    OR address LIKE '%, South Africa%'
    OR address LIKE '%, Mozambique%'
    OR address LIKE '%, Kenya%'
    OR address LIKE '%, Uganda%'
    OR address LIKE '%, Barbados%'
    OR address LIKE '%, Sri Lanka%'
    OR address LIKE '%, Namibia%'
    OR address LIKE '%, Cape Town%'
    OR address LIKE '%, London%'
    OR address LIKE '%, Glasgow%'
    OR address LIKE '%, Botswana%'
    OR address LIKE '%, Jamaica%'
    OR address LIKE '%, Cameroon%'
    OR address LIKE '%Dar es Salaam%'
    OR address LIKE '%Lusaka%'
    OR address LIKE '%Mombasa%'
    OR address LIKE '%Maputo%'
    OR address LIKE '%Arusha%'
    OR address LIKE '%Mwanza%'
    OR address LIKE '%Johannesburg%'
    OR address LIKE '%Pretoria%'
";

run($db, "car_dealers: foreign address", "
    UPDATE car_dealers SET status = 'inactive', updated_at = NOW()
    WHERE status = 'active' AND ($foreignAddressPatterns)
", $dry);

run($db, "garages: foreign address", "
    UPDATE garages SET status = 'inactive', updated_at = NOW()
    WHERE status = 'active' AND ($foreignAddressPatterns)
", $dry);

run($db, "car_hire_companies: foreign address", "
    UPDATE car_hire_companies SET status = 'inactive', updated_at = NOW()
    WHERE status = 'active' AND ($foreignAddressPatterns)
", $dry);

// ─────────────────────────────────────────────────────────────────────────────
// 3. INACTIVATE: Fuel stations (all chains — not automotive service businesses)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- Phase 3: Inactivate pure fuel station chains ---\n";

$fuelStationPatterns = "
    name LIKE 'TotalEnergies%'
    OR name LIKE 'Puma Energy%'
    OR name LIKE 'Total Energies%'
    OR name LIKE 'Mt Meru Filling Station%'
    OR name LIKE 'Meru Filling Station%'
    OR name = 'Presidential Way Total Filling Station'
    OR name LIKE '%Filling Station' AND name NOT LIKE '%service%' AND name NOT LIKE '%repair%'
    OR name LIKE 'Engen Filling%'
    OR name LIKE 'JEMEC ENGEN%'
    OR name LIKE 'Meru%Filling%'
    OR name LIKE 'Supersink filling%'
    OR name LIKE 'Petrol Pump'
    OR name LIKE 'Petroda%Filling%'
";

run($db, "garages: fuel station chains", "
    UPDATE garages SET status = 'inactive', updated_at = NOW()
    WHERE status = 'active' AND ($fuelStationPatterns)
", $dry);

// Same for car_hire (fuel stations ended up there by mistake)
run($db, "car_hire_companies: fuel station chains", "
    UPDATE car_hire_companies SET status = 'inactive', updated_at = NOW()
    WHERE status = 'active'
      AND (business_name LIKE 'TotalEnergies%'
        OR business_name LIKE 'Puma Energy%'
        OR business_name LIKE '%Filling Station%'
        OR business_name LIKE '%Puma filling%'
        OR business_name LIKE '%Meru%Filling%'
        OR business_name LIKE 'JEMEC ENGEN%'
        OR business_name LIKE 'Supersink filling%')
", $dry);

// ─────────────────────────────────────────────────────────────────────────────
// 4. INACTIVATE: Non-automotive businesses by keyword patterns
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- Phase 4: Inactivate non-automotive businesses ---\n";

// GARAGES TABLE — things that ended up there but are clearly irrelevant
$garageIrrelevantIds = implode(',', [
    // Banks / financial
    366, 428, 430, 425,
    // Electronics / phones
    486, 330, 203, 198, 442, 358, 379, 383, 485, 373, 359, 459,
    // Retail shops (non-auto)
    199, 333, 408, 388, 450, 405, 445, 384, 407,
    // Hardware (non-auto)
    401, 447,
    // Fashion/tailoring/gym/salon/printer
    456, 403, 380, 346,
    // Electrical (non-auto)
    266, 264, 194, 193, 292, 293,
    // Location names only
    139, 411, 347, 329,
    // Government / non-public
    376, 351,
    // Phone/computer repair
    383, 373,
    // Car wash only (not automotive repair)
    439, 394,
    // Other non-automotive
    346, 383,
]);
if ($garageIrrelevantIds) {
    run($db, "garages: non-automotive by specific IDs", "
        UPDATE garages SET status = 'inactive', updated_at = NOW()
        WHERE id IN ($garageIrrelevantIds) AND status = 'active'
    ", $dry);
}

// GARAGES: keyword-based catches  
run($db, "garages: banks/financial keywords", "
    UPDATE garages SET status = 'inactive', updated_at = NOW()
    WHERE status = 'active'
      AND (name LIKE 'National Bank%' OR name LIKE 'NBS Bank%' OR name LIKE '%Pinnacle Financial%'
        OR name LIKE 'Mukuru%' OR name LIKE '%PEP %' OR name LIKE 'PEP%Kasungu%'
        OR name LIKE 'Sana Cash%' OR name LIKE '%Superette%' OR name LIKE '%Beauty Salon%'
        OR name LIKE '%Electronics%' AND name NOT LIKE '%Auto%'
        OR name LIKE '%Phone%' AND name NOT LIKE '%Car%'
        OR name LIKE '%Cellphone%' OR name LIKE '%Smartphone%'
        OR name LIKE 'Sprint printers%' OR name LIKE '%Fitness%' OR name LIKE '%Gym%'
        OR name LIKE '%Computer%' AND name NOT LIKE '%Car%'
        OR name = 'Mechanic shop' OR name = 'Mechanic'
        OR name LIKE '%Fashion%' OR name LIKE '%Tailoring%'
        OR name LIKE 'Mzuzu Malawi' OR name LIKE 'M1 Road')
", $dry);

// CAR_DEALERS TABLE — irrelevant entries
$dealerIrrelevantIds = implode(',', [
    // Location names only
    411, 416, 358, 359, 282, 433, 442, 373, 301, 313,
    // Police stations / govt
    421, 457, 311, 257, 210, 450, 342, 287,
    // Hotels/Lodges
    181, 318, 458, 288, 280, 277, 462,
    // Church / NGO
    279, 315, 197, 347,
    // Financial/Banks
    195, 208,
    // Markets / malls / parking
    400, 432, 136, 377, 430, 402, 231, 425,
    // Retail (non-auto)
    232, 139,
    // Schools
    454, 194,
    // Bus depots
    169, 211, 302, 317, 316,
    // Invalid names
    448, 401,
    // Gyms
    461,
    // Cafes/restaurants
    213, 464,
    // Fuel stations (dealer table)
    443, 354, 371, 330, 335, 362, 291, 363,
    // Car hire in wrong table (should only be in car_hire_companies)
    82, 85, 88, 86, 118, 388, 424, 91, 404, 413, 420, 87, 423, 446,
    // Other non-automotive
    370, 374, 418, 183, 285, 151, 222, 161, 299, 184, 281, 201, 237, 129, 196, 338, 176, 343, 102,
    // Freight/logistics only
    455,
]);
if ($dealerIrrelevantIds) {
    run($db, "car_dealers: non-automotive by specific IDs", "
        UPDATE car_dealers SET status = 'inactive', updated_at = NOW()
        WHERE id IN ($dealerIrrelevantIds) AND status != 'suspended'
    ", $dry);
}

// CAR_DEALERS: keyword-based
run($db, "car_dealers: banks/govt/non-automotive keywords", "
    UPDATE car_dealers SET status = 'inactive', updated_at = NOW()
    WHERE status = 'active'
      AND (business_name LIKE '%Police Station%'
        OR business_name LIKE '%District Assembly%'
        OR business_name LIKE '%Revenue Authority%'
        OR business_name LIKE '%Bus Depot%'
        OR business_name LIKE '%Bus Station%'
        OR business_name LIKE '%Bus Stop%'
        OR business_name LIKE 'Mchinji Border%'
        OR business_name LIKE '%Minibus deport%'
        OR business_name LIKE '%Assemblies of God%'
        OR business_name LIKE '%Mountain View Lodge%'
        OR business_name LIKE '%Pottery and Lodge%'
        OR business_name LIKE '%Adventure Lodge%'
        OR business_name LIKE '%Hotel%' AND business_name NOT LIKE '%Motor%'
        OR business_name LIKE 'FINCA%'
        OR business_name LIKE '%Car Park%' AND business_name NOT LIKE '%Care%'
        OR business_name LIKE '%Shopping Centre%' AND business_name NOT LIKE '%Auto%'
        OR business_name LIKE '%Market Center%'
        OR business_name = 'City Mall'
        OR business_name LIKE '%Gym%'
        OR business_name LIKE '%General Dealers%' AND business_name NOT LIKE '%Auto%'
        OR business_name = 'Meru' OR business_name = 'Kawale' OR business_name = 'Biwi'
        OR business_name = 'Kanengo' OR business_name = 'Kasungu' OR business_name = 'Mchinji'
        OR business_name = 'Dedza' OR business_name LIKE 'balaka' OR business_name = 'Balaka'
        OR business_name LIKE 'Mchinji road')
", $dry);

// CAR_HIRE: irrelevant entries
$hireIrrelevantIds = implode(',', [
    // Airports
    293, 174, 245, 196, 169, 156, 279, 190, 133, 106, 191, 242,
    // Bus depots / stations
    160, 115, 141, 144, 150, 88, 145, 227, 312, 117, 143, 114, 201, 202, 116, 152, 272, 274, 256, 300, 277,
    // Hotels/Lodges/Tourism (not car hire)
    266, 172, 284, 199, 301, 270, 308, 183, 224, 307, 297, 232, 233, 235, 234, 231, 221, 285, 171, 264, 170, 311,
    // Beauty/Bridal
    281, 189, 216,
    // Bicycle repair
    306, 243,
    // Location names only
    240, 275, 303, 244, 248, 296, 317,
    // Test entries handled above but specific active ones
    // Chinese company
    292,
    // Moving/courier/freight (not car hire)
    259, 178, 318, 217, 218, 276, 146, 192, 157, 210,
    // Security company
    167,
    // Heavy equipment
    113, 258,
    // Travel agencies
    109, 148, 265, 269, 179, 236, 222,
    // Non-service names
    215, 159, 229, 260, 282, 316, 314, 98,
    // Car washes in car_hire table (wrong table)
    206, 154, 209, 261, 132, 238,
    // Irish/unknown websites masquerading
    198,
    // Park/national park
    226,
    // Security/car tracking (not hire)
    // Motorcycle mechanic in wrong table
    299,
    // Mechanical services in wrong table (should be in garages)
    155, 164, 241,
    // Residences
    159,
    // Barbershop/beauty in wrong table
    281,
    // Plant hire (heavy equipment)
    158,
]);
if ($hireIrrelevantIds) {
    run($db, "car_hire_companies: non-hire by specific IDs", "
        UPDATE car_hire_companies SET status = 'inactive', updated_at = NOW()
        WHERE id IN ($hireIrrelevantIds) AND status = 'active'
    ", $dry);
}

// CAR_HIRE: keyword-based
run($db, "car_hire_companies: airports/bus/govt keywords", "
    UPDATE car_hire_companies SET status = 'inactive', updated_at = NOW()
    WHERE status = 'active'
      AND (business_name LIKE 'Aeroportul%'
        OR business_name LIKE '%International Airport%'
        OR business_name LIKE '%Airport%' AND business_name NOT LIKE '%Taxi%' AND business_name NOT LIKE '%Car Hire%' AND business_name NOT LIKE '%Transfers%' AND business_name NOT LIKE '%Rental%'
        OR business_name LIKE '%Bus Station%'
        OR business_name LIKE '%Bus Depot%'
        OR business_name LIKE '%Bus Stop%'
        OR business_name LIKE '%Coach Station%'
        OR business_name LIKE '%Taxi Rank%'
        OR business_name LIKE '%Lodge%' AND business_name NOT LIKE '%Car%' AND business_name NOT LIKE '%Hire%' AND business_name NOT LIKE '%Rental%'
        OR business_name LIKE '%Safari Camp%'
        OR business_name LIKE '%Backpackers%'
        OR business_name LIKE '%Eco Lodge%'
        OR business_name LIKE '%National Park%'
        OR business_name LIKE '%Car Wash%'
        OR business_name LIKE '%Car wash%'
        OR business_name LIKE 'GOLDEN EX%CAR WASH%'
        OR business_name LIKE '%Beauty%' AND business_name NOT LIKE '%Car%'
        OR business_name LIKE '%Bridal%'
        OR business_name LIKE '%Bicycle repair%'
        OR business_name = 'Ekwendeni' OR business_name = 'Monkey Bay' OR business_name = 'Mzimba'
        OR business_name = 'Blantyre, Malawi' OR business_name = 'Blanytre'
        OR business_name LIKE 'Sole proprietorship'
        OR business_name LIKE '%Speed Courier%'
        OR business_name LIKE '%CTS Courier%'
        OR business_name LIKE '%Liwonde National Park%'
        OR business_name LIKE '%Barloworld Equipment%'
        OR business_name LIKE 'Sososo Coach%' OR business_name LIKE 'Kwezy Bus%'
        OR business_name LIKE 'AXA Bus%' OR business_name LIKE 'Machawi Coach%'
        OR business_name LIKE 'ULEMU COACHES%' OR business_name LIKE 'National bus company%'
        OR business_name LIKE 'G4S%')
", $dry);

// ─────────────────────────────────────────────────────────────────────────────
// 5. INACTIVATE: Duplicate/redundant scraped entries (same franchise, 5+ entries)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- Phase 5: Inactivate excessive chain duplicates ---\n";

// BE FORWARD Malawi — keep only the main 2 (Lilongwe + Blantyre), inactivate extras
run($db, "car_dealers: BE FORWARD excess branches", "
    UPDATE car_dealers SET status = 'inactive', updated_at = NOW()
    WHERE business_name LIKE 'BE FORWARD Malawi%'
      AND status = 'active'
      AND id NOT IN (
          SELECT id FROM (
              SELECT id FROM car_dealers
              WHERE business_name LIKE 'BE FORWARD Malawi%' AND status = 'active'
              ORDER BY id ASC
              LIMIT 3
          ) AS keep
      )
", $dry);

// Avis — keep one Lilongwe, one Blantyre, one airport; too many duplicates
run($db, "car_hire_companies: Avis excess duplicates", "
    UPDATE car_hire_companies SET status = 'inactive', updated_at = NOW()
    WHERE business_name LIKE 'Avis%'
      AND status = 'active'
      AND id NOT IN (
          SELECT id FROM (
              SELECT id FROM car_hire_companies
              WHERE business_name LIKE 'Avis%' AND status = 'active'
              ORDER BY id ASC
              LIMIT 3
          ) AS keep
      )
", $dry);

// ─────────────────────────────────────────────────────────────────────────────
// Summary
// ─────────────────────────────────────────────────────────────────────────────
echo "\n=== Done. Total rows affected: $totalRemoved ===\n\n";

// Print new counts
if (!$dry) {
    echo "--- Post-cleanup active counts ---\n";
    foreach (['car_dealers' => 'business_name', 'garages' => 'name', 'car_hire_companies' => 'business_name'] as $tbl => $col) {
        $active   = (int) $db->query("SELECT COUNT(*) FROM `$tbl` WHERE status = 'active'")->fetchColumn();
        $inactive = (int) $db->query("SELECT COUNT(*) FROM `$tbl` WHERE status = 'inactive'")->fetchColumn();
        echo "  $tbl: $active active, $inactive inactive\n";
    }
}
