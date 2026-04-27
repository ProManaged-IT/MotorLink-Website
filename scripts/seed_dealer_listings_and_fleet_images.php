<?php
/**
 * seed_dealer_listings_and_fleet_images.php
 * 
 * 1. Seeds 3 car listings per demo dealer (9 total) with 2 photos each
 * 2. Downloads fleet vehicle images for all 9 car hire fleet vehicles
 * 
 * Idempotent: skips already-seeded listings and already-downloaded images.
 * Run: php scripts/seed_dealer_listings_and_fleet_images.php
 */

require_once __DIR__ . '/_bootstrap.php';
$db = motorlink_script_pdo();

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

function downloadImage(string $unsplashId, string $destPath, int $minBytes = 20000): bool {
    if (file_exists($destPath) && filesize($destPath) > $minBytes) {
        echo "  skip (exists): $destPath\n";
        return true;
    }
    $url = "https://images.unsplash.com/photo-{$unsplashId}?w=900&q=80&fm=jpg&fit=crop";
    $ctx = stream_context_create([
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
        'http' => ['timeout' => 30, 'user_agent' => 'Mozilla/5.0 (compatible; MotorLinkSeed/1.0)'],
    ]);
    $bytes = @file_get_contents($url, false, $ctx);
    if ($bytes === false || strlen($bytes) < $minBytes) {
        echo "  FAIL (" . strlen($bytes ?: '') . " bytes): $destPath\n";
        return false;
    }
    file_put_contents($destPath, $bytes);
    echo "  ✓ " . basename($destPath) . " (" . round(strlen($bytes) / 1024) . "KB)\n";
    return true;
}

function genRef(): string {
    return 'ML' . strtoupper(bin2hex(random_bytes(4)));
}

// ─────────────────────────────────────────────────────────────────────────────
// PART 1 — Dealer Listings (9 listings, 2 photos each)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n========== PART 1: Dealer Listings ==========\n";

$uploadDir = __DIR__ . '/../uploads/';

// [dealer_id, user_id, location_id, ref_key, title, make_id, model_id, year, price,
//  fuel_type, transmission, condition_type, engine_size, drivetrain, mileage, color,
//  interior_color, doors, seats, description, features, photos[unsplash_id,...]]
$listings = [

    // ── Prestige Motors Blantyre (dealer_id=465, user_id=2381, location_id=1) ──
    [
        'dealer_id'    => 465,
        'user_id'      => 2381,
        'location_id'  => 1,
        'ref_key'      => 'PRDLR001',   // stable idempotency key
        'title'        => '2021 Toyota Land Cruiser 200 Series V8 4.5D VX-R – Loaded',
        'make_id'      => 1,
        'model_id'     => 9,
        'year'         => 2021,
        'price'        => 48000000.00,
        'fuel_type'    => 'diesel',
        'transmission' => 'automatic',
        'condition'    => 'excellent',
        'engine_size'  => '4500cc',
        'drivetrain'   => 'awd',
        'mileage'      => 32000,
        'color'        => 'Pearl White',
        'int_color'    => 'Beige Leather',
        'doors'        => 5,
        'seats'        => 8,
        'description'  => "Top-of-the-range Toyota Land Cruiser 200 VX-R finished in Pearl White with beige leather. "
                        . "Features include multi-terrain select, crawl control, 360° camera, electrically adjustable seats, "
                        . "rear entertainment screens, dual sunroof, Kinetic Dynamic Suspension System (KDSS), "
                        . "and a premium JBL sound system with 14 speakers. Service history available. Fully dealer-maintained.",
        'features'     => json_encode([
            'Rear Entertainment System', 'Multi-Terrain Select', 'Crawl Control',
            '360° Camera', 'Electric Sunroof', 'Heated & Cooled Seats',
            'JBL Premium Sound', 'Wireless Charging', 'Head-Up Display',
            'Power Running Boards', 'Apple CarPlay & Android Auto', 'Lane Departure Alert',
        ]),
        'photos'       => ['1519641471654', '1554744512'],
    ],
    [
        'dealer_id'    => 465,
        'user_id'      => 2381,
        'location_id'  => 1,
        'ref_key'      => 'PRDLR002',
        'title'        => '2022 Toyota Fortuner 2.8 GD-6 4×4 Legend – Sport Pack',
        'make_id'      => 1,
        'model_id'     => 6,
        'year'         => 2022,
        'price'        => 18500000.00,
        'fuel_type'    => 'diesel',
        'transmission' => 'automatic',
        'condition'    => 'excellent',
        'engine_size'  => '2755cc',
        'drivetrain'   => '4wd',
        'mileage'      => 18500,
        'color'        => 'Attitude Black',
        'int_color'    => 'Black Leather',
        'doors'        => 5,
        'seats'        => 7,
        'description'  => "Sought-after Fortuner Legend in Attitude Black. Built on the legendary IMV platform with "
                        . "2.8-litre turbodiesel producing 150kW/500Nm. Equipped with 4-wheel drive, sport suspension, "
                        . "20-inch alloys, sports-tuned exhaust, leather interior, digital cluster, forward collision warning, "
                        . "and 9-inch touchscreen with Toyota Connect. Ideal for Malawi's road conditions.",
        'features'     => json_encode([
            '4WD Hi-Lo Range', 'Sport Suspension', 'Digital Instrument Cluster',
            '9" Touchscreen', 'Toyota Safety Sense', 'Reversing Camera',
            '20-Inch Alloy Wheels', 'Full Leather Interior', 'Apple CarPlay',
            'Roof Rails', 'Auto-Folding Side Steps', 'Front & Rear Park Sensors',
        ]),
        'photos'       => ['1583121274602', '1503376780353'],
    ],
    [
        'dealer_id'    => 465,
        'user_id'      => 2381,
        'location_id'  => 1,
        'ref_key'      => 'PRDLR003',
        'title'        => '2020 Toyota Hilux 2.8 GD-6 D/Cab 4×4 LTD – One Owner',
        'make_id'      => 1,
        'model_id'     => 5,
        'year'         => 2020,
        'price'        => 14200000.00,
        'fuel_type'    => 'diesel',
        'transmission' => 'automatic',
        'condition'    => 'very_good',
        'engine_size'  => '2755cc',
        'drivetrain'   => '4wd',
        'mileage'      => 64000,
        'color'        => 'Glacier White',
        'int_color'    => 'Grey Leather',
        'doors'        => 4,
        'seats'        => 5,
        'description'  => "Toyota Hilux LTD 2.8 GD-6 in excellent condition, one careful owner. "
                        . "Full service history at Toyota Malawi. Features locking rear differential, "
                        . "automatic 4-wheel drive engagement, leather seats, 8-inch touchscreen with sat-nav, "
                        . "front and rear park distance control, towbar, and canopy. "
                        . "Payload 1045kg. Perfect for business or family use.",
        'features'     => json_encode([
            '4WD Lo-Hi Auto Engage', 'Locking Rear Differential', 'Canopy Included',
            'Towbar & Trailer Electrics', 'Leather Seats', '8" Touchscreen + Sat-Nav',
            'Front & Rear PDC', 'Reversing Camera', 'Electric Windows & Mirrors',
            'Cruise Control', 'Keyless Entry', 'USB & 12V Charging',
        ]),
        'photos'       => ['1449965408869', '1558618666'],
    ],

    // ── Capital Auto Centre (dealer_id=466, user_id=2382, location_id=2) ──
    [
        'dealer_id'    => 466,
        'user_id'      => 2382,
        'location_id'  => 2,
        'ref_key'      => 'CALDLR001',
        'title'        => '2023 Toyota Corolla 1.8 Hybrid XS CVT – Nearly New',
        'make_id'      => 1,
        'model_id'     => 1,
        'year'         => 2023,
        'price'        => 9500000.00,
        'fuel_type'    => 'hybrid',
        'transmission' => 'cvt',
        'condition'    => 'excellent',
        'engine_size'  => '1798cc',
        'drivetrain'   => 'fwd',
        'mileage'      => 8200,
        'color'        => 'Metallic Blue',
        'int_color'    => 'Black Fabric',
        'doors'        => 4,
        'seats'        => 5,
        'description'  => "Nearly-new Toyota Corolla 1.8 Hybrid, still under manufacturer warranty. "
                        . "The self-charging hybrid system delivers exceptional fuel economy of under 5L/100km in town. "
                        . "Equipped with Toyota Safety Sense 3.0, 10.5-inch multimedia touchscreen, wireless Apple CarPlay "
                        . "and Android Auto, lane departure alert, adaptive cruise control, and pre-collision system. "
                        . "Perfect urban commuter with low running costs.",
        'features'     => json_encode([
            'Self-Charging Hybrid', 'Toyota Safety Sense 3.0', 'Adaptive Cruise Control',
            '10.5" Multimedia Touchscreen', 'Wireless CarPlay & Android Auto', 'Lane Departure Alert',
            'Pre-Collision System', 'Wireless Phone Charging', 'Keyless Entry & Start',
            'LED Headlights', 'Rain-Sensing Wipers', 'Reversing Camera',
        ]),
        'photos'       => ['1503376780353', '1549317661'],
    ],
    [
        'dealer_id'    => 466,
        'user_id'      => 2382,
        'location_id'  => 2,
        'ref_key'      => 'CALDLR002',
        'title'        => '2022 Toyota RAV4 2.5 AWD Premium – Low Mileage',
        'make_id'      => 1,
        'model_id'     => 3,
        'year'         => 2022,
        'price'        => 13800000.00,
        'fuel_type'    => 'petrol',
        'transmission' => 'automatic',
        'condition'    => 'excellent',
        'engine_size'  => '2494cc',
        'drivetrain'   => 'awd',
        'mileage'      => 21000,
        'color'        => 'Super White',
        'int_color'    => 'Tan Leather',
        'doors'        => 5,
        'seats'        => 5,
        'description'  => "Toyota RAV4 2.5 Premium AWD in immaculate condition with ultra-low mileage. "
                        . "Dynamic Torque Vectoring AWD system for confident handling. "
                        . "Panoramic glass sunroof, full leather with heated front seats, 10.5-inch JBL touchscreen, "
                        . "power tailgate, Toyota Safety Sense 2.0, 8-sensor parking system, and 360° bird's-eye camera. "
                        . "Serviced at Capital Auto Centre throughout its life.",
        'features'     => json_encode([
            'Dynamic Torque Vectoring AWD', 'Panoramic Sunroof', 'Full Leather Interior',
            'Heated Front Seats', '10.5" JBL Touchscreen', 'Power Tailgate',
            '360° Bird\'s-Eye Camera', '8-Sensor Parking', 'Toyota Safety Sense 2.0',
            'Head-Up Display', 'Wireless Charging', 'Ventilated Seats',
        ]),
        'photos'       => ['1558618666', '1554744512'],
    ],
    [
        'dealer_id'    => 466,
        'user_id'      => 2382,
        'location_id'  => 2,
        'ref_key'      => 'CALDLR003',
        'title'        => '2021 Toyota Camry 2.5 Hybrid XSE – Executive Saloon',
        'make_id'      => 1,
        'model_id'     => 2,
        'year'         => 2021,
        'price'        => 11200000.00,
        'fuel_type'    => 'hybrid',
        'transmission' => 'automatic',
        'condition'    => 'excellent',
        'engine_size'  => '2487cc',
        'drivetrain'   => 'fwd',
        'mileage'      => 29000,
        'color'        => 'Midnight Black',
        'int_color'    => 'Red Leather',
        'doors'        => 4,
        'seats'        => 5,
        'description'  => "Stunning Toyota Camry 2.5 Hybrid XSE in executive-spec trim. "
                        . "The bold XSE Sport styling package features 19-inch alloy wheels, "
                        . "sport-tuned suspension, red-stitched leather interior with heated and ventilated front seats, "
                        . "10.1-inch infotainment with JBL premium audio, heads-up display, digital rear-view mirror, "
                        . "and the full Toyota Safety Sense suite. One of the most striking cars on the road in Malawi.",
        'features'     => json_encode([
            'Self-Charging Hybrid', '19-Inch XSE Alloy Wheels', 'Sport-Tuned Suspension',
            'Heated & Ventilated Front Seats', 'Red-Stitched Leather', '10.1" JBL Touchscreen',
            'Digital Rear-View Mirror', 'Head-Up Display', 'Toyota Safety Sense',
            'Ambient Interior Lighting', 'Blind Spot Monitor', 'Rear Cross Traffic Alert',
        ]),
        'photos'       => ['1549317661', '1492144534655'],
    ],

    // ── Northern Motors Ltd (dealer_id=467, user_id=2383, location_id=3) ──
    [
        'dealer_id'    => 467,
        'user_id'      => 2383,
        'location_id'  => 3,
        'ref_key'      => 'NTHDLR001',
        'title'        => '2020 Toyota Prado 2.8D TX-L 7-Seat – Safari Spec',
        'make_id'      => 1,
        'model_id'     => 4,
        'year'         => 2020,
        'price'        => 21500000.00,
        'fuel_type'    => 'diesel',
        'transmission' => 'automatic',
        'condition'    => 'very_good',
        'engine_size'  => '2755cc',
        'drivetrain'   => '4wd',
        'mileage'      => 52000,
        'color'        => 'Bronze Mica',
        'int_color'    => 'Camel Leather',
        'doors'        => 5,
        'seats'        => 7,
        'description'  => "Toyota Prado 2.8D TX-L in sought-after Bronze Mica with camel leather. "
                        . "Equipped for Malawi's diverse terrain: Terrain Select with Auto LSD, "
                        . "Downhill Assist Control (DAC), Hill-start Assist (HAC), Multi-terrain Monitor, "
                        . "Kinetic Dynamic Suspension System (KDSS), and all-terrain tyres. "
                        . "Third row seats fold flat. 9-inch touchscreen with satellite navigation, "
                        . "360° camera, and factory tow bar. Full service records at Northern Motors.",
        'features'     => json_encode([
            'Terrain Select System', 'KDSS Suspension', 'Downhill Assist Control',
            'Multi-Terrain Monitor', '360° Camera', 'Factory Tow Bar (3500kg)',
            'Third Row Seating', '9" Touchscreen + Sat-Nav', 'Keyless Entry',
            'Power Side Steps', 'Roof Rack', 'All-Terrain Tyres',
        ]),
        'photos'       => ['1554744512', '1519641471654'],
    ],
    [
        'dealer_id'    => 467,
        'user_id'      => 2383,
        'location_id'  => 3,
        'ref_key'      => 'NTHDLR002',
        'title'        => '2022 Toyota Hiace Commuter 15-Seater – Immaculate',
        'make_id'      => 1,
        'model_id'     => 10,
        'year'         => 2022,
        'price'        => 22000000.00,
        'fuel_type'    => 'diesel',
        'transmission' => 'manual',
        'condition'    => 'excellent',
        'engine_size'  => '2755cc',
        'drivetrain'   => 'rwd',
        'mileage'      => 39000,
        'color'        => 'Silver Metallic',
        'int_color'    => 'Grey Fabric',
        'doors'        => 4,
        'seats'        => 15,
        'description'  => "Brand-new generation Toyota Hiace Commuter in Silver Metallic. "
                        . "Seats 15 passengers in comfort with individual reading lights, overhead luggage rails, "
                        . "and Arctic-grade air conditioning throughout the cabin. "
                        . "The 2.8-litre turbodiesel produces 130kW/420Nm for strong performance even laden. "
                        . "Features Toyota Safety Sense, reversing camera, and built-in sat-nav. "
                        . "Ideal for corporate transfers, lodge shuttles, and school transport.",
        'features'     => json_encode([
            '15-Seater Commuter Config', 'Full-Width A/C System', 'Overhead Luggage Rails',
            'Individual Reading Lights', 'Toyota Safety Sense', 'Reversing Camera',
            'Sat-Nav & Radio', 'USB Charging Points', 'Tinted Privacy Glass',
            'Roof-Mounted LED Lights', 'Heavy-Duty Suspension', 'Keyless Entry',
        ]),
        'photos'       => ['1492144534655', '1503376780353'],
    ],
    [
        'dealer_id'    => 467,
        'user_id'      => 2383,
        'location_id'  => 3,
        'ref_key'      => 'NTHDLR003',
        'title'        => '2022 Toyota Vitz RS 1.5 G-Plus CVT – Sporty City Car',
        'make_id'      => 1,
        'model_id'     => 7,
        'year'         => 2022,
        'price'        => 3800000.00,
        'fuel_type'    => 'petrol',
        'transmission' => 'cvt',
        'condition'    => 'excellent',
        'engine_size'  => '1496cc',
        'drivetrain'   => 'fwd',
        'mileage'      => 12000,
        'color'        => 'Passion Red Mica',
        'int_color'    => 'Black Fabric',
        'doors'        => 5,
        'seats'        => 5,
        'description'  => "Sporty Toyota Vitz RS 1.5 G-Plus in eye-catching Passion Red. "
                        . "The RS package adds sport body kit, 16-inch alloy wheels, and RS-badged leather steering wheel. "
                        . "Ultra-low running costs with average fuel consumption of 5.5L/100km. "
                        . "Features Toyota Safety Sense, 8-inch touchscreen, Bluetooth connectivity, "
                        . "reversing camera, and half-leather seats. Perfect first car or city commuter in Mzuzu.",
        'features'     => json_encode([
            'RS Sport Body Kit', '16-Inch Alloy Wheels', 'Toyota Safety Sense',
            '8" Touchscreen', 'Bluetooth & USB', 'Reversing Camera',
            'Half-Leather Seats', 'Keyless Start', 'Auto A/C',
            'LED DRLs', 'Sport Suspension', 'Eco Drive Mode',
        ]),
        'photos'       => ['1449965408869', '1583121274602'],
    ],
];

// Prepare statements
$insListing = $db->prepare("
    INSERT INTO car_listings
        (user_id, dealer_id, reference_number, title, description, make_id, model_id, year, price,
         negotiable, mileage, fuel_type, transmission, condition_type, exterior_color, interior_color,
         engine_size, doors, seats, drivetrain, location_id, listing_type, status, approval_status,
         approval_date, approved_at, is_featured, is_premium, listing_email_verified,
         payment_status, expires_at, created_at, updated_at)
    VALUES
        (:user_id, :dealer_id, :ref, :title, :description, :make_id, :model_id, :year, :price,
         1, :mileage, :fuel, :trans, :cond, :color, :int_color,
         :engine, :doors, :seats, :drivetrain, :loc_id, 'premium', 'active', 'approved',
         NOW(), NOW(), 1, 1, 1,
         'free', DATE_ADD(NOW(), INTERVAL 1 YEAR), NOW(), NOW())
");

$insImage = $db->prepare("
    INSERT INTO car_listing_images
        (listing_id, filename, is_primary, sort_order, file_path, created_at)
    VALUES
        (:listing_id, :filename, :is_primary, :sort_order, :file_path, NOW())
");

$setFeatImg = $db->prepare("
    UPDATE car_listings SET featured_image_id = :img_id WHERE id = :lid
");

$checkRef = $db->prepare("SELECT id FROM car_listings WHERE reference_number = ?");

foreach ($listings as $l) {
    // Idempotency: skip if ref already exists
    $checkRef->execute([$l['ref_key']]);
    if ($checkRef->fetchColumn()) {
        echo "skip (exists): {$l['ref_key']} - {$l['title']}\n";
        continue;
    }

    echo "\n--- Seeding: {$l['ref_key']} ---\n";
    echo "  {$l['title']}\n";

    // Insert listing
    $insListing->execute([
        ':user_id'     => $l['user_id'],
        ':dealer_id'   => $l['dealer_id'],
        ':ref'         => $l['ref_key'],
        ':title'       => $l['title'],
        ':description' => $l['description'],
        ':make_id'     => $l['make_id'],
        ':model_id'    => $l['model_id'],
        ':year'        => $l['year'],
        ':price'       => $l['price'],
        ':mileage'     => $l['mileage'],
        ':fuel'        => $l['fuel_type'],
        ':trans'       => $l['transmission'],
        ':cond'        => $l['condition'],
        ':color'       => $l['color'],
        ':int_color'   => $l['int_color'],
        ':engine'      => $l['engine_size'],
        ':doors'       => $l['doors'],
        ':seats'       => $l['seats'],
        ':drivetrain'  => $l['drivetrain'],
        ':loc_id'      => $l['location_id'],
    ]);
    $listingId = $db->lastInsertId();
    echo "  listing_id: $listingId\n";

    // Download photos and attach
    $firstImgId = null;
    foreach ($l['photos'] as $idx => $unsplashId) {
        $filename = 'img_' . uniqid() . '.jpg';
        $destPath = $uploadDir . $filename;
        $ok = downloadImage($unsplashId, $destPath);
        if (!$ok) {
            echo "  WARNING: photo $unsplashId failed, skipping\n";
            continue;
        }
        $isPrimary = ($idx === 0) ? 1 : 0;
        $insImage->execute([
            ':listing_id' => $listingId,
            ':filename'   => $filename,
            ':is_primary' => $isPrimary,
            ':sort_order' => $idx,
            ':file_path'  => 'uploads/' . $filename,
        ]);
        $imageId = $db->lastInsertId();
        if ($firstImgId === null) {
            $firstImgId = $imageId;
        }
    }

    // Set featured image
    if ($firstImgId) {
        $setFeatImg->execute([':img_id' => $firstImgId, ':lid' => $listingId]);
        echo "  featured_image_id: $firstImgId\n";
    }

    echo "  ✓ Done: {$l['ref_key']}\n";
}

// ─────────────────────────────────────────────────────────────────────────────
// PART 2 — Fleet Vehicle Images
// ─────────────────────────────────────────────────────────────────────────────
echo "\n\n========== PART 2: Car Hire Fleet Images ==========\n";

$fleetDir = $uploadDir . 'fleet/';
if (!is_dir($fleetDir)) {
    mkdir($fleetDir, 0755, true);
    echo "Created uploads/fleet/ directory\n";
}

// [fleet_id, vehicle_name, unsplash_id]
// image stored as 'fleet/filename.jpg' so car-hire-company.js resolves uploads/fleet/filename.jpg
$fleetImages = [
    [26, 'Toyota Prado VX 2022',               '1554744512'],
    [27, 'Toyota Land Cruiser 200 Series 2021', '1519641471654'],
    [28, 'Toyota Hiace Commuter 15-Seater 2020','1492144534655'],
    [29, 'Toyota Corolla Cross 2023',            '1503376780353'],
    [30, 'Toyota RAV4 2022',                     '1558618666'],
    [31, 'Toyota Hilux D/Cab 2.8 GD-6 2021',    '1449965408869'],
    [32, 'Toyota RAV4 2022 4WD',                 '1583121274602'],
    [33, 'Toyota Hilux D/Cab Legend RS 2022',    '1549317661'],
    [34, 'Toyota Prado TZ-G 2020',               '1554744512'],
];

$getFleet = $db->prepare("SELECT image FROM car_hire_fleet WHERE id = ?");
$setFleet = $db->prepare("UPDATE car_hire_fleet SET image = ?, updated_at = NOW() WHERE id = ?");

foreach ($fleetImages as [$fleetId, $name, $unsplashId]) {
    echo "\n--- Fleet ID $fleetId: $name ---\n";

    // Check if already has image
    $getFleet->execute([$fleetId]);
    $row = $getFleet->fetch(PDO::FETCH_ASSOC);
    if (!empty($row['image'])) {
        $existingPath = $uploadDir . $row['image'];
        if (file_exists($existingPath) && filesize($existingPath) > 20000) {
            echo "  skip (already has image): {$row['image']}\n";
            continue;
        }
    }

    $filename     = 'fleet_' . $fleetId . '_' . time() . '.jpg';
    $destPath     = $fleetDir . $filename;
    $dbImageValue = 'fleet/' . $filename;  // stored as fleet/xxx.jpg

    $ok = downloadImage($unsplashId, $destPath);
    if (!$ok) {
        echo "  WARNING: fleet $fleetId image failed\n";
        continue;
    }

    $setFleet->execute([$dbImageValue, $fleetId]);
    echo "  image column: $dbImageValue\n";
}

// ─────────────────────────────────────────────────────────────────────────────
// Final Summary
// ─────────────────────────────────────────────────────────────────────────────
echo "\n\n========== SUMMARY ==========\n";

$dealerListings = $db->query("
    SELECT cl.reference_number, cl.title, cl.price,
           COUNT(cli.id) AS images,
           cl.featured_image_id,
           cd.business_name
    FROM car_listings cl
    LEFT JOIN car_listing_images cli ON cli.listing_id = cl.id
    LEFT JOIN car_dealers cd ON cl.dealer_id = cd.id
    WHERE cd.email LIKE '%@motorlink.demo'
    GROUP BY cl.id
    ORDER BY cl.dealer_id, cl.id
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($dealerListings as $r) {
    printf("  %-12s  images:%-2d  fi:%-5s  %-38s  MWK %s\n",
        $r['reference_number'],
        $r['images'],
        $r['featured_image_id'] ?? 'NULL',
        substr($r['business_name'], 0, 38),
        number_format($r['price'] / 1000000, 1) . 'M'
    );
}

echo "\n-- Fleet --\n";
$fleetRows = $db->query("
    SELECT f.id, f.vehicle_name, f.image, c.business_name
    FROM car_hire_fleet f
    JOIN car_hire_companies c ON f.company_id = c.id
    WHERE c.email LIKE '%@motorlink.demo'
    ORDER BY f.id
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($fleetRows as $f) {
    printf("  fleet_id:%-4d  image:%-40s  %s\n",
        $f['id'],
        $f['image'] ?? 'NULL',
        $f['vehicle_name']
    );
}

echo "\nDone.\n";
