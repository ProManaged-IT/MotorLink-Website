<?php
/**
 * seed_demo_images_users_listings.php
 * ─────────────────────────────────────────────────────────────────────────────
 * 1. Downloads logos from Unsplash for the 9 demo businesses.
 * 2. Creates 2 individual demo users with full car listings + images.
 * 3. Creates 2 guest listings (no user account) with images.
 *
 * All images are downloaded from Unsplash CDN and saved locally.
 * Idempotent: re-running skips already-downloaded files and existing users.
 *
 * Usage: php scripts/seed_demo_images_users_listings.php [--reset]
 * ─────────────────────────────────────────────────────────────────────────────
 */

$DEMO_PASS   = 'MotorLink@Demo1';
$BASE_DIR    = dirname(__DIR__);
$UPLOAD_DIR  = $BASE_DIR . '/uploads/';
$LOGOS_DIR   = $BASE_DIR . '/uploads/business_logos/';

@mkdir($LOGOS_DIR, 0755, true);

// ── DB ────────────────────────────────────────────────────────────────────────
$creds = require $BASE_DIR . '/admin/admin-secrets.local.php';
$db = new PDO(
    'mysql:host='.$creds['MOTORLINK_DB_HOST'].';dbname='.$creds['MOTORLINK_DB_NAME'].';charset=utf8mb4',
    $creds['MOTORLINK_DB_USER'], $creds['MOTORLINK_DB_PASS'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$reset = in_array('--reset', $argv ?? []);

// ── Download helper ────────────────────────────────────────────────────────────
function downloadImage(string $url, string $destPath, bool $overwrite = false): bool {
    if (!$overwrite && file_exists($destPath) && filesize($destPath) > 5000) {
        echo "    ↷ exists: ".basename($destPath)."\n";
        return true;
    }
    $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
                                  'http' => ['timeout' => 30, 'follow_location' => true,
                                             'header' => "User-Agent: Mozilla/5.0 MotorLinkSeed/1.0\r\n"]]);
    $data = @file_get_contents($url, false, $ctx);
    if (!$data || strlen($data) < 5000) {
        echo "    ✗ FAILED download: $url\n";
        return false;
    }
    file_put_contents($destPath, $data);
    echo "    ✓ ".basename($destPath)." (".round(strlen($data)/1024)."KB)\n";
    return true;
}

function imgRef(): string {
    return 'img_' . bin2hex(random_bytes(7)) . '.' . substr((string)microtime(true), -8) . '.jpg';
}

function listingRef(): string {
    return 'ML' . strtoupper(bin2hex(random_bytes(4)));
}

function j($v): string { return json_encode($v, JSON_UNESCAPED_UNICODE); }

// ─────────────────────────────────────────────────────────────────────────────
// PART 1 — BUSINESS LOGOS
// ─────────────────────────────────────────────────────────────────────────────
echo "\n=== PART 1: BUSINESS LOGOS ===\n";

// Curated Unsplash photo IDs (square 400×400 crop)
$logoImages = [
    // Dealers
    'prestige.motors@motorlink.demo'   => ['table'=>'car_dealers',       'col'=>'logo_url', 'photo'=>'1503376780353-7e6692767b70', 'slug'=>'prestige_motors'],
    'capital.auto@motorlink.demo'      => ['table'=>'car_dealers',       'col'=>'logo_url', 'photo'=>'1549317661-bd32c8ce0db2', 'slug'=>'capital_auto'],
    'northern.motors@motorlink.demo'   => ['table'=>'car_dealers',       'col'=>'logo_url', 'photo'=>'1554744512-d6c603f27c54', 'slug'=>'northern_motors'],
    // Garages
    'profix.workshop@motorlink.demo'   => ['table'=>'garages',           'col'=>'logo_url', 'photo'=>'1625047509248-ec889cbff17f', 'slug'=>'profix_workshop'],
    'city.mechanics@motorlink.demo'    => ['table'=>'garages',           'col'=>'logo_url', 'photo'=>'1486262715619-67b85e0b08d3', 'slug'=>'city_mechanics'],
    'mzuzu.auto@motorlink.demo'        => ['table'=>'garages',           'col'=>'logo_url', 'photo'=>'1558618666-fcd25c85cd64', 'slug'=>'mzuzu_auto'],
    // Car Hire
    'malawi.fleet@motorlink.demo'      => ['table'=>'car_hire_companies','col'=>'logo_url', 'photo'=>'1492144534655-ae79c964c9d7', 'slug'=>'malawi_fleet'],
    'capital.carhire@motorlink.demo'   => ['table'=>'car_hire_companies','col'=>'logo_url', 'photo'=>'1449965408869-eaa3f722e40d', 'slug'=>'capital_carhire'],
    'northern.wheels@motorlink.demo'   => ['table'=>'car_hire_companies','col'=>'logo_url', 'photo'=>'1544636331-9231028cdc4c', 'slug'=>'northern_wheels'],
];

foreach ($logoImages as $email => $info) {
    $filename = $info['slug'] . '_logo.jpg';
    $destPath = $LOGOS_DIR . $filename;
    $url = "https://images.unsplash.com/photo-{$info['photo']}?w=400&h=400&fit=crop&auto=format&q=85";
    echo "  [{$info['slug']}] ";
    if (downloadImage($url, $destPath, $reset)) {
        $relPath = 'uploads/business_logos/' . $filename;
        $db->prepare("UPDATE `{$info['table']}` SET logo_url=? WHERE email=?")->execute([$relPath, $email]);
        echo "    → DB updated: $relPath\n";
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// PART 2 — INDIVIDUAL USERS + LISTINGS
// ─────────────────────────────────────────────────────────────────────────────
echo "\n=== PART 2: INDIVIDUAL USERS + LISTINGS ===\n";

$now      = date('Y-m-d H:i:s');
$expiry   = date('Y-m-d', strtotime('+1 year'));
$phash    = password_hash($DEMO_PASS, PASSWORD_DEFAULT);

// Curated car listing photos (landscape 800×600 from Unsplash)
$carPhotos = [
    // Fortuner-style dark SUV
    'fortuner' => [
        '1544636331-9231028cdc4c',  // white SUV outdoor
        '1519641471654-76ce0107ad1b', // dark SUV parking
        '1574267432522-4893ed7d5bf3', // grey 4WD side
    ],
    // Prado/luxury SUV
    'prado' => [
        '1503376780353-7e6692767b70', // dark luxury car
        '1571068316409-e4e0cbab4e9b', // silver SUV road
        '1603386329808-f66b6e40bab3', // white SUV forest
    ],
    // Corolla/sedan
    'corolla' => [
        '1494976388531-d1058494cdd8', // white sedan front
        '1580273916550-e323be2ae537', // silver sedan profile
        '1590362891991-f776e747a588', // red sedan parked
    ],
    // RAV4/crossover
    'rav4' => [
        '1583121274602-3e2820c69888', // dark blue SUV
        '1506706534880-ece1eb4b9e15', // white crossover
        '1540066019-ad92a66fcd06', // grey SUV road
    ],
];

// Look up model IDs dynamically
$modelLookup = function(string $name, int $makeId) use ($db): int {
    $s = $db->prepare("SELECT id FROM car_models WHERE make_id=? AND name LIKE ? LIMIT 1");
    $s->execute([$makeId, "%$name%"]);
    $r = $s->fetch();
    return $r ? (int)$r['id'] : 1;
};

$fortunerModelId = $modelLookup('Fortuner', 1);
$prado4ModelId   = $modelLookup('Prado', 1);
$corollaModelId  = $modelLookup('Corolla', 1);
$rav4ModelId     = $modelLookup('RAV4', 1);

$users = [
    [
        'username'  => 'tendai_moyo_demo',
        'email'     => 'tendai.moyo@motorlink.demo',
        'full_name' => 'Tendai Moyo',
        'phone'     => '+265993001101',
        'city'      => 'Blantyre',
        'listing'   => [
            'title'       => '2019 Toyota Fortuner 2.8 GD-6 4×4 – Pristine Condition',
            'description' => "Selling my 2019 Toyota Fortuner 2.8 GD-6 4×4 automatic. Single-owner vehicle, purchased new from a Toyota dealership in South Africa and imported to Malawi in 2020. Always serviced at Toyota-authorised workshops with full service history available.\n\nHighlights:\n• 2.8L GD-6 Turbodiesel (177hp)\n• 4WD with electronic locking rear diff\n• Full leather interior, panoramic sunroof\n• Toyota Safety Sense (pre-collision, lane departure)\n• Android Auto / Apple CarPlay\n• Reverse camera + parking sensors\n• 7 seats, third row folds flat\n\nRecent work done:\n• Full service at 82,000km (belts, filters, fluids)\n• 4 new Bridgestone Dueler A/T tyres\n• Tinted windows (legal %35 tint)\n\nNo accidents, no flood damage. Logbook, import papers, and roadworthy certificate all in order. Viewing available in Blantyre (Ginnery Corner area). Serious buyers only — price is slightly negotiable for cash.",
            'make_id'     => 1, 'model_id' => $fortunerModelId,
            'year'        => 2019, 'price'   => 12500000.00,
            'negotiable'  => 1, 'mileage'  => 84000,
            'fuel_type'   => 'diesel', 'transmission' => 'automatic',
            'condition'   => 'excellent', 'exterior_color' => 'Attitude Black Mica',
            'interior_color' => 'Black Leather', 'engine_size' => '2.8L',
            'doors' => 5, 'seats' => 7, 'drivetrain' => '4wd',
            'location_id' => 1, 'type' => 'premium',
            'photos'      => 'fortuner',
        ],
    ],
    [
        'username'  => 'miriam_nkosi_demo',
        'email'     => 'miriam.nkosi@motorlink.demo',
        'full_name' => 'Miriam Nkosi',
        'phone'     => '+265991002202',
        'city'      => 'Lilongwe',
        'listing'   => [
            'title'       => '2020 Toyota Prado TZ-G 2.8D V6 – Fully Loaded',
            'description' => "Immaculate 2020 Toyota Land Cruiser Prado TZ-G 2.8 Diesel V6. This is the top-spec TZ-G model with all factory upgrades. The car is in showroom condition and has been pampered since new.\n\nKey features:\n• 2.8L 1GD-FTV turbodiesel, 147kW / 500Nm\n• 8-speed Super ECT automatic transmission\n• Crawl Control + Multi-Terrain Select (rock, mud, sand, loose rock, mogul)\n• 360° surround-view camera system\n• JBL premium 15-speaker sound system\n• Ventilated + heated front seats, heated rear seats\n• Power fold third row, full-leather tan interior\n• Kinetic Dynamic Suspension System (KDSS)\n• LED headlamps, fog lamps, DRL\n• 18-inch TZ-G alloy wheels on Bridgestone A/T tyres\n\nService history at Toyota Malawi. Original window sticker and all import documents included. Paint protection film on bonnet and front panels. Ceramic coating on body.\n\nLocated in Lilongwe Area 9. Price is firm — this is genuine value for a fully-loaded Prado.",
            'make_id'     => 1, 'model_id' => $prado4ModelId,
            'year'        => 2020, 'price'   => 16800000.00,
            'negotiable'  => 0, 'mileage'  => 61500,
            'fuel_type'   => 'diesel', 'transmission' => 'automatic',
            'condition'   => 'excellent', 'exterior_color' => 'White Pearl Crystal Shine',
            'interior_color' => 'Saddle Tan Leather', 'engine_size' => '2.8L',
            'doors' => 5, 'seats' => 7, 'drivetrain' => '4wd',
            'location_id' => 2, 'type' => 'premium',
            'photos'      => 'prado',
        ],
    ],
];

$insUser = $db->prepare("
    INSERT IGNORE INTO users
        (username, email, password_hash, full_name, phone, city, user_type, status, email_verified, phone_verified, created_at)
    VALUES
        (:username,:email,:password_hash,:full_name,:phone,:city,'individual','active',1,1,:now)
");

$insListing = $db->prepare("
    INSERT INTO car_listings
        (user_id, reference_number, title, description, make_id, model_id, year, price, negotiable,
         mileage, fuel_type, transmission, condition_type, exterior_color, interior_color,
         engine_size, doors, seats, drivetrain, location_id, listing_type,
         status, approval_status, approval_date, is_guest, is_featured, is_premium,
         featured_until, payment_status, views_count, expires_at, approved_at, created_at)
    VALUES
        (:uid,:ref,:title,:desc,:make,:model,:year,:price,:negotiable,
         :mileage,:fuel,:trans,:cond,:ext_col,:int_col,
         :engine,:doors,:seats,:drive,:loc,:ltype,
         'active','approved',:now,0,1,1,
         :premium_until,'free',0,:expiry,:now,:now)
");

$insImage = $db->prepare("
    INSERT INTO car_listing_images
        (listing_id, filename, original_filename, file_path, file_size, mime_type, is_primary, sort_order, uploaded_at)
    VALUES (:lid,:fname,:orig,:path,:size,'image/jpeg',:primary,:sort,:now)
");

$credentials = [];

foreach ($users as $u) {
    echo "  Processing user: {$u['full_name']}\n";
    $db->beginTransaction();
    try {
        $insUser->execute([
            ':username'=>$u['username'],':email'=>$u['email'],':password_hash'=>$phash,
            ':full_name'=>$u['full_name'],':phone'=>$u['phone'],':city'=>$u['city'],':now'=>$now,
        ]);
        $uid = $db->query("SELECT id FROM users WHERE email='".$u['email']."'")->fetchColumn();

        $l = $u['listing'];
        $ref = listingRef();
        $premiumUntil = date('Y-m-d H:i:s', strtotime('+6 months'));

        $insListing->execute([
            ':uid'=>$uid,':ref'=>$ref,':title'=>$l['title'],':desc'=>$l['description'],
            ':make'=>$l['make_id'],':model'=>$l['model_id'],':year'=>$l['year'],
            ':price'=>$l['price'],':negotiable'=>$l['negotiable'],':mileage'=>$l['mileage'],
            ':fuel'=>$l['fuel_type'],':trans'=>$l['transmission'],':cond'=>$l['condition'],
            ':ext_col'=>$l['exterior_color'],':int_col'=>$l['interior_color'],
            ':engine'=>$l['engine_size'],':doors'=>$l['doors'],':seats'=>$l['seats'],
            ':drive'=>$l['drivetrain'],':loc'=>$l['location_id'],':ltype'=>$l['type'],
            ':now'=>$now,':expiry'=>$expiry,':premium_until'=>$premiumUntil,
        ]);
        $listingId = (int)$db->lastInsertId();

        // Download & attach photos
        $photoUrls = $carPhotos[$l['photos']];
        foreach ($photoUrls as $idx => $photoId) {
            $fname = imgRef();
            $destPath = $UPLOAD_DIR . $fname;
            $url = "https://images.unsplash.com/photo-{$photoId}?w=900&h=600&fit=crop&auto=format&q=85";
            echo "    Photo $idx: ";
            if (downloadImage($url, $destPath)) {
                $size = filesize($destPath);
                $isPrimary = ($idx === 0) ? 1 : 0;
                $insImage->execute([
                    ':lid'=>$listingId,':fname'=>$fname,':orig'=>$fname,
                    ':path'=>'uploads/'.$fname,':size'=>$size,
                    ':primary'=>$isPrimary,':sort'=>$idx,':now'=>$now,
                ]);
                if ($isPrimary) {
                    // Set featured_image_id
                    $imgId = (int)$db->lastInsertId();
                    $db->prepare("UPDATE car_listings SET featured_image_id=? WHERE id=?")->execute([$imgId, $listingId]);
                }
            }
        }
        $db->commit();
        echo "  ✓ {$u['full_name']} → listing #{$listingId} ({$ref})\n";
        $credentials[] = ['type'=>'Individual','name'=>$u['full_name'],'email'=>$u['email'],'username'=>$u['username'],'listing'=>$l['title']];
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        echo "  ✗ {$u['full_name']}: ".$e->getMessage()."\n";
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// PART 3 — GUEST LISTINGS
// ─────────────────────────────────────────────────────────────────────────────
echo "\n=== PART 3: GUEST LISTINGS ===\n";

$guestListings = [
    [
        'guest_name'    => 'Patrick Chirwa',
        'guest_phone'   => '+265993003303',
        'guest_email'   => 'patrick.chirwa@motorlink.demo',
        'guest_whatsapp'=> '+265993003303',
        'listing'       => [
            'title'       => '2018 Toyota Corolla 1.6 Xi – Low Mileage, One Owner',
            'description' => "Very well maintained 2018 Toyota Corolla 1.6 Xi. This is a local car — no import hassle. One female owner since new, always garaged and serviced every 10,000km at Autoworld Blantyre.\n\nFeatures:\n• 1.6L Dual VVT-i petrol, 97kW\n• 6-speed manual gearbox\n• Toyota Touch 2 multimedia with Bluetooth & USB\n• Reverse camera\n• Pre-collision alert, lane departure warning\n• Dual-zone climate control\n• 16-inch alloy wheels\n\nWhat's been done recently:\n• Full service at 68,000km (filters, plugs, oils)\n• New front brake pads and discs\n• New battery (March 2026)\n\nOriginal floor mats, spare key, full service history booklet. No cracks, no rust, no smoke. Absolutely ready to drive. Viewing in Blantyre CBD (near Chichiri Mall). Call or WhatsApp Patrick.",
            'make_id'     => 1, 'model_id' => $corollaModelId,
            'year'        => 2018, 'price'   => 6200000.00,
            'negotiable'  => 1, 'mileage'  => 71000,
            'fuel_type'   => 'petrol', 'transmission' => 'manual',
            'condition'   => 'very_good', 'exterior_color' => 'Pearl White',
            'interior_color' => 'Black Fabric', 'engine_size' => '1.6L',
            'doors' => 4, 'seats' => 5, 'drivetrain' => 'fwd',
            'location_id' => 1, 'type' => 'featured',
            'photos'      => 'corolla',
        ],
    ],
    [
        'guest_name'    => 'Beatrice Gondwe',
        'guest_phone'   => '+265881004404',
        'guest_email'   => 'beatrice.gondwe@motorlink.demo',
        'guest_whatsapp'=> '+265881004404',
        'listing'       => [
            'title'       => '2019 Toyota RAV4 2.0 VVT-i – Excellent Family SUV',
            'description' => "Selling my 2019 Toyota RAV4 2.0L VVT-i in excellent condition. Purchased from reputable importer in 2021 with all documentation. The car has been my daily driver in Lilongwe city and occasional upcountry trips.\n\nKey features:\n• 2.0L 3ZR-FAE petrol DOHC Dual VVT-i engine\n• CVT automatic transmission, smooth city driving\n• All-wheel drive (AWD) — ideal for all road conditions\n• 7-inch touchscreen with GPS, Bluetooth, USB\n• Reversing camera with dynamic guidelines\n• Power tailgate, dual-zone climate control\n• LED headlights + DRL\n• 17-inch diamond-cut alloys\n\nService history:\n• 50,000km service done at Toyota dealer (full)\n• Timing chain in perfect condition\n• All 4 tyres at 70% tread (Michelin Primacy 4)\n\nCar is accident-free. CCTV footage of my garage available if needed. Import papers, logbook clear, no outstanding fines. Reason for sale: upgrading to Prado. Serious buyers contact Beatrice — available for viewing in Lilongwe Area 3.",
            'make_id'     => 1, 'model_id' => $rav4ModelId,
            'year'        => 2019, 'price'   => 8900000.00,
            'negotiable'  => 1, 'mileage'  => 53000,
            'fuel_type'   => 'petrol', 'transmission' => 'cvt',
            'condition'   => 'excellent', 'exterior_color' => 'Magnetic Grey Metallic',
            'interior_color' => 'Dark Chestnut Leather', 'engine_size' => '2.0L',
            'doors' => 5, 'seats' => 5, 'drivetrain' => 'awd',
            'location_id' => 2, 'type' => 'featured',
            'photos'      => 'rav4',
        ],
    ],
];

$insGuestListing = $db->prepare("
    INSERT INTO car_listings
        (user_id, reference_number, title, description, make_id, model_id, year, price, negotiable,
         mileage, fuel_type, transmission, condition_type, exterior_color, interior_color,
         engine_size, doors, seats, drivetrain, location_id, listing_type,
         status, approval_status, approval_date,
         is_guest, guest_seller_name, guest_seller_phone, guest_seller_email, guest_seller_whatsapp,
         listing_email_verified, is_featured, payment_status, views_count,
         expires_at, guest_listing_expires_at, approved_at, created_at)
    VALUES
        (NULL,:ref,:title,:desc,:make,:model,:year,:price,:negotiable,
         :mileage,:fuel,:trans,:cond,:ext_col,:int_col,
         :engine,:doors,:seats,:drive,:loc,:ltype,
         'active','approved',:now,
         1,:gname,:gphone,:gemail,:gwhatsapp,
         1,1,'free',0,
         :expiry,:guest_expiry,:now,:now)
");

foreach ($guestListings as $g) {
    echo "  Processing guest: {$g['guest_name']}\n";
    $db->beginTransaction();
    try {
        $l = $g['listing'];
        $ref = listingRef();
        $guestExpiry = date('Y-m-d H:i:s', strtotime('+90 days'));

        $insGuestListing->execute([
            ':ref'=>$ref,':title'=>$l['title'],':desc'=>$l['description'],
            ':make'=>$l['make_id'],':model'=>$l['model_id'],':year'=>$l['year'],
            ':price'=>$l['price'],':negotiable'=>$l['negotiable'],':mileage'=>$l['mileage'],
            ':fuel'=>$l['fuel_type'],':trans'=>$l['transmission'],':cond'=>$l['condition'],
            ':ext_col'=>$l['exterior_color'],':int_col'=>$l['interior_color'],
            ':engine'=>$l['engine_size'],':doors'=>$l['doors'],':seats'=>$l['seats'],
            ':drive'=>$l['drivetrain'],':loc'=>$l['location_id'],':ltype'=>$l['type'],
            ':now'=>$now,':expiry'=>$expiry,':guest_expiry'=>$guestExpiry,
            ':gname'=>$g['guest_name'],':gphone'=>$g['guest_phone'],
            ':gemail'=>$g['guest_email'],':gwhatsapp'=>$g['guest_whatsapp'],
        ]);
        $listingId = (int)$db->lastInsertId();

        // Photos
        $photoUrls = $carPhotos[$l['photos']];
        // Use only first 2 photos for guest listings
        foreach (array_slice($photoUrls, 0, 2) as $idx => $photoId) {
            $fname = imgRef();
            $destPath = $UPLOAD_DIR . $fname;
            $url = "https://images.unsplash.com/photo-{$photoId}?w=900&h=600&fit=crop&auto=format&q=85";
            echo "    Photo $idx: ";
            if (downloadImage($url, $destPath)) {
                $size = filesize($destPath);
                $isPrimary = ($idx === 0) ? 1 : 0;
                $insImage->execute([
                    ':lid'=>$listingId,':fname'=>$fname,':orig'=>$fname,
                    ':path'=>'uploads/'.$fname,':size'=>$size,
                    ':primary'=>$isPrimary,':sort'=>$idx,':now'=>$now,
                ]);
                if ($isPrimary) {
                    $imgId = (int)$db->lastInsertId();
                    $db->prepare("UPDATE car_listings SET featured_image_id=? WHERE id=?")->execute([$imgId, $listingId]);
                }
            }
        }
        $db->commit();
        echo "  ✓ Guest: {$g['guest_name']} → listing #{$listingId} ({$ref})\n";
        $credentials[] = ['type'=>'Guest','name'=>$g['guest_name'],'email'=>$g['guest_email'],'username'=>'(guest)','listing'=>$l['title']];
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        echo "  ✗ Guest {$g['guest_name']}: ".$e->getMessage()."\n";
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// SUMMARY
// ─────────────────────────────────────────────────────────────────────────────
echo "\n".str_repeat('=',90)."\n";
echo " DEMO USER & GUEST CREDENTIALS — Password: {$DEMO_PASS}\n";
echo str_repeat('=',90)."\n";
printf("%-12s %-22s %-35s %-22s\n",'Type','Name','Login Email','Username');
echo str_repeat('-',90)."\n";
foreach ($credentials as $c)
    printf("%-12s %-22s %-35s %-22s\n",$c['type'],$c['name'],$c['email'],$c['username']);
echo str_repeat('=',90)."\n";
echo " Business logos saved to: uploads/business_logos/\n";
echo " Listing images saved to: uploads/ (img_*.jpg)\n";
echo str_repeat('=',90)."\n";
