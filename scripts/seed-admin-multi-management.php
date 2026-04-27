<?php
declare(strict_types=1);

define('ONBOARDING_API_AS_LIB', true);
require __DIR__ . '/../onboarding/api-onboarding.php';

$db = getDB();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$userStmt = $db->prepare("SELECT id, full_name, email FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
$userStmt->execute(['admin@motorlink.mw']);
$adminUser = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$adminUser) {
    throw new RuntimeException('admin@motorlink.mw was not found in users.');
}

$vehicleSeedStmt = $db->query("
    SELECT m.id AS make_id, m.name AS make_name, mo.id AS model_id, mo.name AS model_name
    FROM car_makes m
    INNER JOIN car_models mo ON mo.make_id = m.id
    WHERE COALESCE(m.is_active, 1) = 1 AND COALESCE(mo.is_active, 1) = 1
    ORDER BY CASE WHEN LOWER(m.name) = 'toyota' THEN 0 ELSE 1 END, m.name ASC, mo.name ASC
    LIMIT 1
");
$vehicleSeed = $vehicleSeedStmt->fetch(PDO::FETCH_ASSOC);

if (!$vehicleSeed) {
    throw new RuntimeException('No active make/model pair was found for listing and fleet seeds.');
}

$locations = [
    'dealer_a' => 1,
    'dealer_b' => 2,
    'garage_a' => 1,
    'garage_b' => 3,
    'hire_a' => 2,
    'hire_b' => 3,
];

function firstExistingLocation(PDO $db, int $preferredId): int {
    $stmt = $db->prepare('SELECT id FROM locations WHERE id = ? LIMIT 1');
    $stmt->execute([$preferredId]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int)$id;
    }

    $fallback = $db->query('SELECT id FROM locations ORDER BY id ASC LIMIT 1')->fetchColumn();
    if (!$fallback) {
        throw new RuntimeException('No locations exist for seeding businesses.');
    }

    return (int)$fallback;
}

function ensureDealer(PDO $db, array $user, string $name, int $locationId): int {
    $stmt = $db->prepare('SELECT id FROM car_dealers WHERE user_id = ? AND business_name = ? LIMIT 1');
    $stmt->execute([(int)$user['id'], $name]);
    $id = $stmt->fetchColumn();

    $params = [
        $name,
        $user['full_name'],
        $user['email'],
        '+265999000101',
        '+265999000101',
        'MotorLink multi-management test address',
        $locationId,
        5,
        'Seeded dealer used to verify one login can manage multiple dealer businesses.',
        1,
        1,
        0,
        'active',
        (int)$user['id'],
    ];

    if ($id) {
        $params[] = (int)$id;
        $params[] = (int)$user['id'];
        $db->prepare("
            UPDATE car_dealers
            SET business_name = ?, owner_name = ?, email = ?, phone = ?, whatsapp = ?, address = ?, location_id = ?,
                years_established = ?, description = ?, verified = ?, certified = ?, featured = ?, status = ?,
                approved_at = NOW(), approved_by = ?, updated_at = NOW()
            WHERE id = ? AND user_id = ?
        ")->execute($params);
        return (int)$id;
    }

    $db->prepare("
        INSERT INTO car_dealers (
            business_name, owner_name, email, phone, whatsapp, address, location_id,
            years_established, description, verified, certified, featured, status, approved_at, approved_by, user_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)
    ")->execute(array_merge($params, [(int)$user['id']]));

    return (int)$db->lastInsertId();
}

function ensureGarage(PDO $db, array $user, string $name, int $locationId): int {
    $stmt = $db->prepare('SELECT id FROM garages WHERE user_id = ? AND name = ? LIMIT 1');
    $stmt->execute([(int)$user['id'], $name]);
    $id = $stmt->fetchColumn();
    $services = json_encode(['Diagnostics', 'Servicing', 'Brake Repairs'], JSON_UNESCAPED_SLASHES);

    $params = [
        $name,
        $user['full_name'],
        $user['email'],
        '+265999000202',
        '+265999000202',
        'MotorLink multi-management test garage address',
        $locationId,
        $services,
        4,
        'Seeded garage used to verify one login can manage multiple garage businesses.',
        1,
        1,
        0,
        'active',
        (int)$user['id'],
    ];

    if ($id) {
        $params[] = (int)$id;
        $params[] = (int)$user['id'];
        $db->prepare("
            UPDATE garages
            SET name = ?, owner_name = ?, email = ?, phone = ?, whatsapp = ?, address = ?, location_id = ?,
                services = ?, years_experience = ?, description = ?, verified = ?, certified = ?, featured = ?, status = ?,
                approved_at = NOW(), approved_by = ?, updated_at = NOW()
            WHERE id = ? AND user_id = ?
        ")->execute($params);
        return (int)$id;
    }

    $db->prepare("
        INSERT INTO garages (
            name, owner_name, email, phone, whatsapp, address, location_id,
            services, years_experience, description, verified, certified, featured, status, approved_at, approved_by, user_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)
    ")->execute(array_merge($params, [(int)$user['id']]));

    return (int)$db->lastInsertId();
}

function ensureCarHireCompany(PDO $db, array $user, string $name, int $locationId, string $category): int {
    $stmt = $db->prepare('SELECT id FROM car_hire_companies WHERE user_id = ? AND business_name = ? LIMIT 1');
    $stmt->execute([(int)$user['id'], $name]);
    $id = $stmt->fetchColumn();
    $eventTypes = json_encode(['weddings', 'corporate'], JSON_UNESCAPED_SLASHES);

    $params = [
        $name,
        $user['full_name'],
        $user['email'],
        '+265999000303',
        '+265999000303',
        'MotorLink multi-management test car hire address',
        $locationId,
        55000,
        350000,
        1200000,
        3,
        'Seeded car hire company used to verify one login can manage multiple hire businesses.',
        $category,
        $eventTypes,
        1,
        1,
        0,
        'active',
        (int)$user['id'],
    ];

    if ($id) {
        $params[] = (int)$id;
        $params[] = (int)$user['id'];
        $db->prepare("
            UPDATE car_hire_companies
            SET business_name = ?, owner_name = ?, email = ?, phone = ?, whatsapp = ?, address = ?, location_id = ?,
                daily_rate_from = ?, weekly_rate_from = ?, monthly_rate_from = ?, years_established = ?, description = ?,
                hire_category = ?, event_types = ?, verified = ?, certified = ?, featured = ?, status = ?,
                approved_at = NOW(), approved_by = ?, updated_at = NOW()
            WHERE id = ? AND user_id = ?
        ")->execute($params);
        return (int)$id;
    }

    $db->prepare("
        INSERT INTO car_hire_companies (
            business_name, owner_name, email, phone, whatsapp, address, location_id,
            daily_rate_from, weekly_rate_from, monthly_rate_from, years_established, description,
            hire_category, event_types, verified, certified, featured, status, approved_at, approved_by, user_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)
    ")->execute(array_merge($params, [(int)$user['id']]));

    return (int)$db->lastInsertId();
}

function ensureListing(PDO $db, array $user, int $dealerId, array $vehicleSeed, string $reference, string $dealerName, int $locationId, int $year, float $price): int {
    $title = $year . ' ' . $vehicleSeed['make_name'] . ' ' . $vehicleSeed['model_name'] . ' - ' . $dealerName;
    $description = 'Seeded listing for MotorLink multi-management testing.';

    $stmt = $db->prepare('SELECT id FROM car_listings WHERE reference_number = ? LIMIT 1');
    $stmt->execute([$reference]);
    $id = $stmt->fetchColumn();

    $params = [
        (int)$user['id'],
        $dealerId,
        $reference,
        $title,
        $description,
        (int)$vehicleSeed['make_id'],
        (int)$vehicleSeed['model_id'],
        $year,
        $price,
        48500,
        'petrol',
        'automatic',
        'good',
        'White',
        $locationId,
        'active',
        'approved',
        (int)$user['id'],
    ];

    if ($id) {
        $params[] = (int)$id;
        $db->prepare("
            UPDATE car_listings
            SET user_id = ?, dealer_id = ?, reference_number = ?, title = ?, description = ?, make_id = ?, model_id = ?,
                year = ?, price = ?, mileage = ?, fuel_type = ?, transmission = ?, condition_type = ?, exterior_color = ?,
                location_id = ?, status = ?, approval_status = ?, approval_date = NOW(), approved_at = NOW(), approved_by = ?,
                expires_at = DATE_ADD(CURDATE(), INTERVAL 90 DAY), updated_at = NOW()
            WHERE id = ?
        ")->execute($params);
        return (int)$id;
    }

    $db->prepare("
        INSERT INTO car_listings (
            user_id, dealer_id, reference_number, title, description, make_id, model_id, year, price,
            mileage, fuel_type, transmission, condition_type, exterior_color, location_id, status,
            approval_status, approval_date, approved_at, approved_by, expires_at, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?, DATE_ADD(CURDATE(), INTERVAL 90 DAY), NOW(), NOW())
    ")->execute($params);

    return (int)$db->lastInsertId();
}

function ensureFleetVehicle(PDO $db, int $companyId, array $company, array $vehicleSeed, string $registration, int $year, float $dailyRate, string $category): int {
    $stmt = $db->prepare('SELECT id FROM car_hire_fleet WHERE company_id = ? AND registration_number = ? LIMIT 1');
    $stmt->execute([$companyId, $registration]);
    $id = $stmt->fetchColumn();

    $vehicleName = $year . ' ' . $vehicleSeed['make_name'] . ' ' . $vehicleSeed['model_name'];
    $params = [
        $companyId,
        $company['business_name'],
        $company['phone'],
        $company['email'],
        (int)$company['location_id'],
        (int)$vehicleSeed['make_id'],
        (int)$vehicleSeed['model_id'],
        $vehicleSeed['make_name'],
        $vehicleSeed['model_name'],
        $year,
        $vehicleName,
        $registration,
        'automatic',
        'petrol',
        5,
        'Silver',
        $dailyRate,
        'available',
        $category,
        $category === 'truck' ? '1 tonne' : null,
        $category === 'car' ? 1 : 0,
    ];

    if ($id) {
        $params[] = (int)$id;
        $db->prepare("
            UPDATE car_hire_fleet
            SET company_id = ?, company_name = ?, company_phone = ?, company_email = ?, company_location_id = ?,
                make_id = ?, model_id = ?, make_name = ?, model_name = ?, year = ?, vehicle_name = ?, registration_number = ?,
                transmission = ?, fuel_type = ?, seats = ?, exterior_color = ?, daily_rate = ?, is_available = 1, status = ?,
                vehicle_category = ?, cargo_capacity = ?, event_suitable = ?, is_active = 1, updated_at = NOW()
            WHERE id = ?
        ")->execute($params);
        return (int)$id;
    }

    $db->prepare("
        INSERT INTO car_hire_fleet (
            company_id, company_name, company_phone, company_email, company_location_id,
            make_id, model_id, make_name, model_name, year, vehicle_name, registration_number,
            transmission, fuel_type, seats, exterior_color, daily_rate, is_available, status,
            vehicle_category, cargo_capacity, event_suitable, is_active, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, 1, NOW(), NOW())
    ")->execute($params);

    return (int)$db->lastInsertId();
}

$db->beginTransaction();

try {
    $dealerA = ensureDealer($db, $adminUser, 'MotorLink Admin Test Dealer A', firstExistingLocation($db, $locations['dealer_a']));
    $dealerB = ensureDealer($db, $adminUser, 'MotorLink Admin Test Dealer B', firstExistingLocation($db, $locations['dealer_b']));
    $garageA = ensureGarage($db, $adminUser, 'MotorLink Admin Test Garage A', firstExistingLocation($db, $locations['garage_a']));
    $garageB = ensureGarage($db, $adminUser, 'MotorLink Admin Test Garage B', firstExistingLocation($db, $locations['garage_b']));
    $hireA = ensureCarHireCompany($db, $adminUser, 'MotorLink Admin Test Hire A', firstExistingLocation($db, $locations['hire_a']), 'events');
    $hireB = ensureCarHireCompany($db, $adminUser, 'MotorLink Admin Test Hire B', firstExistingLocation($db, $locations['hire_b']), 'vans_trucks');

    $listingA = ensureListing($db, $adminUser, $dealerA, $vehicleSeed, 'MLTST-DEAL-A', 'MotorLink Admin Test Dealer A', firstExistingLocation($db, $locations['dealer_a']), 2020, 14500000);
    $listingB = ensureListing($db, $adminUser, $dealerB, $vehicleSeed, 'MLTST-DEAL-B', 'MotorLink Admin Test Dealer B', firstExistingLocation($db, $locations['dealer_b']), 2021, 16500000);

    $companyStmt = $db->prepare('SELECT id, business_name, phone, email, location_id FROM car_hire_companies WHERE id = ? AND user_id = ? LIMIT 1');
    $companyStmt->execute([$hireA, (int)$adminUser['id']]);
    $companyA = $companyStmt->fetch(PDO::FETCH_ASSOC);
    $companyStmt->execute([$hireB, (int)$adminUser['id']]);
    $companyB = $companyStmt->fetch(PDO::FETCH_ASSOC);

    $fleetA = ensureFleetVehicle($db, $hireA, $companyA, $vehicleSeed, 'MLT-HIRE-A', 2021, 65000, 'car');
    $fleetB = ensureFleetVehicle($db, $hireB, $companyB, $vehicleSeed, 'MLT-HIRE-B', 2022, 95000, 'truck');

    $countStmt = $db->prepare('SELECT COUNT(*) FROM car_hire_fleet WHERE company_id = ? AND is_active = 1');
    foreach ([$hireA, $hireB] as $companyId) {
        $countStmt->execute([$companyId]);
        $total = (int)$countStmt->fetchColumn();
        $availableStmt = $db->prepare("SELECT COUNT(*) FROM car_hire_fleet WHERE company_id = ? AND is_active = 1 AND status = 'available'");
        $availableStmt->execute([$companyId]);
        $available = (int)$availableStmt->fetchColumn();
        $db->prepare('UPDATE car_hire_companies SET total_vehicles = ?, available_vehicles = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$total, $available, $companyId]);
    }

    $db->commit();

    echo json_encode([
        'user_id' => (int)$adminUser['id'],
        'dealers' => [$dealerA, $dealerB],
        'garages' => [$garageA, $garageB],
        'car_hire_companies' => [$hireA, $hireB],
        'listings' => [$listingA, $listingB],
        'fleet' => [$fleetA, $fleetB],
    ], JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    throw $e;
}