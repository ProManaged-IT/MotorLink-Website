<?php
/**
 * seed_demo_businesses.php
 * -----------------------------------------------------------------------
 * Seeds 3 fully-featured demo businesses for each type:
 *   Dealers × 3 | Garages × 3 | Car Hire Companies × 3 (with fleet)
 *
 * Idempotent: uses INSERT IGNORE on users (unique email), and
 * INSERT … ON DUPLICATE KEY UPDATE on business tables (unique email col).
 *
 * Password for ALL demo accounts: MotorLink@Demo1
 *
 * Usage: php scripts/seed_demo_businesses.php [--reset]
 *   --reset  Deletes and re-inserts all demo records first
 * -----------------------------------------------------------------------
 */

$DEMO_PASS   = 'MotorLink@Demo1';
$DEMO_MARKER = '@motorlink.demo'; // all demo emails end in this

$reset = in_array('--reset', $argv ?? []);

// ── DB connection ─────────────────────────────────────────────────────────────
$creds = require __DIR__ . '/../admin/admin-secrets.local.php';
try {
    $db = new PDO(
        'mysql:host='.$creds['MOTORLINK_DB_HOST'].';dbname='.$creds['MOTORLINK_DB_NAME'].';charset=utf8mb4',
        $creds['MOTORLINK_DB_USER'], $creds['MOTORLINK_DB_PASS'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "DB connection failed: ".$e->getMessage()."\n");
    exit(1);
}

$passwordHash = password_hash($DEMO_PASS, PASSWORD_DEFAULT);

// ── Optional reset ────────────────────────────────────────────────────────────
if ($reset) {
    echo "⚠  --reset: removing all existing demo records...\n";
    $emails = $db->query("SELECT email FROM users WHERE email LIKE '%@motorlink.demo'")->fetchAll(PDO::FETCH_COLUMN);
    if ($emails) {
        $in = implode(',', array_fill(0, count($emails), '?'));
        $db->prepare("DELETE FROM car_hire_fleet WHERE company_id IN (SELECT id FROM car_hire_companies WHERE email IN ($in))")->execute($emails);
        $db->prepare("DELETE FROM car_dealers WHERE email IN ($in)")->execute($emails);
        $db->prepare("DELETE FROM garages WHERE email IN ($in)")->execute($emails);
        $db->prepare("DELETE FROM car_hire_companies WHERE email IN ($in)")->execute($emails);
        $db->prepare("DELETE FROM users WHERE email IN ($in)")->execute($emails);
        echo "   Removed ".count($emails)." demo user(s) + associated records.\n\n";
    }
}

// ── Helper ────────────────────────────────────────────────────────────────────
function j($v): string { return json_encode($v, JSON_UNESCAPED_UNICODE); }

function getUserId(PDO $db, string $email): ?int {
    $s = $db->prepare("SELECT id FROM users WHERE email = ?");
    $s->execute([$email]);
    $r = $s->fetch();
    return $r ? (int)$r['id'] : null;
}

function getBusinessId(PDO $db, string $table, string $email): ?int {
    $s = $db->prepare("SELECT id FROM `$table` WHERE email = ?");
    $s->execute([$email]);
    $r = $s->fetch();
    return $r ? (int)$r['id'] : null;
}

$now = date('Y-m-d H:i:s');
$credentials = []; // collected for final print

// ─────────────────────────────────────────────────────────────────────────────
// 1. DEALERS
// ─────────────────────────────────────────────────────────────────────────────
echo "=== DEALERS ===\n";

$dealers = [
    [
        'username'    => 'prestige_motors',
        'email'       => 'prestige.motors@motorlink.demo',
        'full_name'   => 'James Phiri',
        'phone'       => '+265993100001',
        'city'        => 'Blantyre',
        'biz_name'    => 'Prestige Motors Blantyre',
        'owner'       => 'James Phiri',
        'address'     => 'Ginnery Corner, Chilobwe Road, Blantyre, Malawi',
        'location_id' => 1,
        'whatsapp'    => '+265993100001',
        'specialization' => ['Toyota', 'BMW', 'Mercedes-Benz'],
        'years'       => 2010,
        'hours'       => "Monday–Friday: 07:30–17:30\nSaturday: 08:00–13:00\nSunday: Closed",
        'website'     => 'https://prestigemotors.mw',
        'facebook'    => 'https://facebook.com/prestigemotorsmw',
        'instagram'   => 'https://instagram.com/prestigemotorsmw',
        'twitter'     => 'https://twitter.com/prestigemotorsmw',
        'linkedin'    => 'https://linkedin.com/company/prestige-motors-malawi',
        'description' => 'Prestige Motors is Blantyre\'s leading multi-brand dealership established in 2010. We specialise in quality Toyota, BMW and Mercedes-Benz vehicles — both new and certified pre-owned. Our showroom features 40+ vehicles at any time, transparent pricing, flexible financing through FDH Bank and NBS Bank, and a dedicated after-sales service centre. Trade-ins welcome. Find us off Ginnery Corner near the Chileka Road junction.',
    ],
    [
        'username'    => 'capital_auto',
        'email'       => 'capital.auto@motorlink.demo',
        'full_name'   => 'Grace Banda',
        'phone'       => '+265991200002',
        'city'        => 'Lilongwe',
        'biz_name'    => 'Capital Auto Centre',
        'owner'       => 'Grace Banda',
        'address'     => 'Area 3, Presidential Way, Lilongwe, Malawi',
        'location_id' => 2,
        'whatsapp'    => '+265991200002',
        'specialization' => ['Toyota', 'Nissan', 'Honda'],
        'years'       => 2012,
        'hours'       => "Monday–Friday: 08:00–18:00\nSaturday: 08:00–14:00\nSunday: Closed",
        'website'     => 'https://capitalauto.mw',
        'facebook'    => 'https://facebook.com/capitalautomw',
        'instagram'   => 'https://instagram.com/capitalautomw',
        'twitter'     => 'https://twitter.com/capitalautomw',
        'linkedin'    => 'https://linkedin.com/company/capital-auto-centre-malawi',
        'description' => 'Capital Auto Centre has been serving Lilongwe\'s vehicle buyers since 2012. We stock Toyota, Nissan and Honda models — Japan and SA import quality. Our dedicated finance desk can arrange hire purchase through major Malawian banks. All used vehicles undergo a 101-point inspection before sale. Walk-ins welcome at our Area 3 showroom, just minutes from the Presidential Way roundabout.',
    ],
    [
        'username'    => 'northern_motors',
        'email'       => 'northern.motors@motorlink.demo',
        'full_name'   => 'Peter Mwenye',
        'phone'       => '+265888300003',
        'city'        => 'Mzuzu',
        'biz_name'    => 'Northern Motors Ltd',
        'owner'       => 'Peter Mwenye',
        'address'     => 'Katoto Industrial Area, Mzuzu, Malawi',
        'location_id' => 3,
        'whatsapp'    => '+265888300003',
        'specialization' => ['Toyota', 'Ford', 'Isuzu'],
        'years'       => 2015,
        'hours'       => "Monday–Friday: 07:30–17:00\nSaturday: 08:00–12:00\nSunday: By appointment only",
        'website'     => 'https://northernmotors.mw',
        'facebook'    => 'https://facebook.com/northernmotorsmzuzu',
        'instagram'   => 'https://instagram.com/northernmotorsmzuzu',
        'twitter'     => 'https://twitter.com/northernmotors',
        'linkedin'    => 'https://linkedin.com/company/northern-motors-mzuzu',
        'description' => 'Northern Motors Ltd is the North\'s most trusted vehicle dealership, serving Mzuzu and surrounding districts since 2015. We specialise in Toyota 4WD, Ford Ranger pickups, and Isuzu trucks — perfectly suited for the Northern Region\'s terrain. We offer a fully equipped workshop for post-purchase servicing and genuine spare parts sourced directly from certified importers. Visit us at Katoto Industrial Area.',
    ],
];

$insUser = $db->prepare("
    INSERT IGNORE INTO users
        (username, email, password_hash, full_name, phone, whatsapp, city, user_type, status, email_verified, phone_verified, business_name, created_at)
    VALUES
        (:username, :email, :password_hash, :full_name, :phone, :whatsapp, :city, 'dealer', 'active', 1, 1, :business_name, :created_at)
");

$insDeal = $db->prepare("
    INSERT INTO car_dealers
        (user_id, business_name, owner_name, email, phone, whatsapp, address, location_id,
         specialization, years_established, business_hours, website, facebook_url, instagram_url,
         twitter_url, linkedin_url, description, verified, certified, featured, status, approved_at, created_at)
    VALUES
        (:user_id, :business_name, :owner_name, :email, :phone, :whatsapp, :address, :location_id,
         :specialization, :years, :hours, :website, :facebook, :instagram,
         :twitter, :linkedin, :description, 1, 1, 1, 'active', :approved_at, :created_at)
    ON DUPLICATE KEY UPDATE
        business_name=VALUES(business_name), owner_name=VALUES(owner_name), phone=VALUES(phone),
        whatsapp=VALUES(whatsapp), address=VALUES(address), location_id=VALUES(location_id),
        specialization=VALUES(specialization), years_established=VALUES(years_established),
        business_hours=VALUES(business_hours), website=VALUES(website),
        facebook_url=VALUES(facebook_url), instagram_url=VALUES(instagram_url),
        twitter_url=VALUES(twitter_url), linkedin_url=VALUES(linkedin_url),
        description=VALUES(description), verified=1, certified=1, featured=1, status='active'
");

foreach ($dealers as $d) {
    $db->beginTransaction();
    try {
        $insUser->execute([
            ':username' => $d['username'], ':email' => $d['email'],
            ':password_hash' => $passwordHash, ':full_name' => $d['full_name'],
            ':phone' => $d['phone'], ':whatsapp' => $d['phone'],
            ':city' => $d['city'], ':business_name' => $d['biz_name'],
            ':created_at' => $now,
        ]);
        $uid = getUserId($db, $d['email']);
        $insDeal->execute([
            ':user_id' => $uid, ':business_name' => $d['biz_name'], ':owner_name' => $d['owner'],
            ':email' => $d['email'], ':phone' => $d['phone'], ':whatsapp' => $d['whatsapp'],
            ':address' => $d['address'], ':location_id' => $d['location_id'],
            ':specialization' => j($d['specialization']), ':years' => $d['years'],
            ':hours' => $d['hours'], ':website' => $d['website'],
            ':facebook' => $d['facebook'], ':instagram' => $d['instagram'],
            ':twitter' => $d['twitter'], ':linkedin' => $d['linkedin'],
            ':description' => $d['description'],
            ':approved_at' => $now, ':created_at' => $now,
        ]);
        $bizId = getBusinessId($db, 'car_dealers', $d['email']);
        $db->prepare("UPDATE users SET business_id=?, user_type='dealer' WHERE id=?")->execute([$bizId, $uid]);
        $db->commit();
        echo "  ✓ {$d['biz_name']} (user_id=$uid, dealer_id=$bizId)\n";
        $credentials[] = ['type'=>'Dealer', 'name'=>$d['biz_name'], 'email'=>$d['email'], 'username'=>$d['username'], 'phone'=>$d['phone'], 'city'=>$d['city']];
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        echo "  ✗ {$d['biz_name']}: ".$e->getMessage()."\n";
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// 2. GARAGES
// ─────────────────────────────────────────────────────────────────────────────
echo "\n=== GARAGES ===\n";

$allServices  = ['Engine Repair','Gearbox Overhaul','Oil & Filter Change','Brake Service','Tyre Fitting & Balancing','Wheel Alignment','Electrical & Diagnostics','Air Conditioning','Body & Panel Work','Spray Painting','Welding & Fabrication','Suspension & Steering','Full Vehicle Inspection','Roadworthy Certificate'];
$emergencyAll = ['Breakdown Recovery','24/7 Call-out','Towing Service','Roadside Battery Jump-start','Emergency Tyre Change'];

$garages = [
    [
        'username'    => 'profix_workshop',
        'email'       => 'profix.workshop@motorlink.demo',
        'full_name'   => 'Emmanuel Kamanga',
        'phone'       => '+265993400004',
        'city'        => 'Blantyre',
        'name'        => 'ProFix Auto Workshop',
        'owner'       => 'Emmanuel Kamanga',
        'address'     => 'Ndirande Industrial Road, Blantyre, Malawi',
        'location_id' => 1,
        'whatsapp'    => '+265993400004',
        'recovery'    => '+265993400004',
        'services'    => $allServices,
        'emergency'   => $emergencyAll,
        'specialization' => ['Japanese Vehicles','European Vehicles','4WD & Off-road'],
        'specializes_in_cars' => ['Toyota','Nissan','Honda','BMW','Mercedes-Benz'],
        'years'       => 12,
        'hours'       => "Monday–Friday: 07:00–18:00\nSaturday: 07:00–14:00\nSunday: Emergency only",
        'website'     => 'https://profixauto.mw',
        'facebook'    => 'https://facebook.com/profixautomw',
        'instagram'   => 'https://instagram.com/profixautomw',
        'twitter'     => 'https://twitter.com/profixauto',
        'linkedin'    => 'https://linkedin.com/company/profix-auto-workshop',
        'description' => 'ProFix Auto Workshop is Blantyre\'s most comprehensive mechanical workshop with over 12 years of trusted service. Our team of 15 qualified technicians uses computerised diagnostic equipment (Autel MaxiSys, Launch X431) to accurately diagnose and repair all makes and models. We are official Castrol Oil service partners. 24/7 emergency recovery available across Greater Blantyre. Free vehicle health check with every service booking.',
    ],
    [
        'username'    => 'city_mechanics',
        'email'       => 'city.mechanics@motorlink.demo',
        'full_name'   => 'Linda Tembo',
        'phone'       => '+265995500005',
        'city'        => 'Lilongwe',
        'name'        => 'City Mechanics Centre',
        'owner'       => 'Linda Tembo',
        'address'     => 'Area 25, Kaunda Road, Lilongwe, Malawi',
        'location_id' => 2,
        'whatsapp'    => '+265995500005',
        'recovery'    => '+265995500005',
        'services'    => array_slice($allServices, 0, 10),
        'emergency'   => array_slice($emergencyAll, 0, 3),
        'specialization' => ['Japanese Vehicles','Korean Vehicles','Light Commercial'],
        'specializes_in_cars' => ['Toyota','Nissan','Hyundai','Kia','Isuzu'],
        'years'       => 8,
        'hours'       => "Monday–Friday: 07:30–17:30\nSaturday: 08:00–13:00\nSunday: Closed",
        'website'     => 'https://citymechanics.mw',
        'facebook'    => 'https://facebook.com/citymechanicslilongwe',
        'instagram'   => 'https://instagram.com/citymechanicsmw',
        'twitter'     => 'https://twitter.com/citymechanics',
        'linkedin'    => 'https://linkedin.com/company/city-mechanics-lilongwe',
        'description' => 'City Mechanics Centre brings 8 years of quality vehicle repair to Lilongwe. Based in Area 25, we service Japanese and Korean vehicles with genuine or OEM-quality parts. Our workshop offers full diagnostics, engine repair, brake servicing, and wheel alignment. We maintain a transparent pricing policy — no hidden fees — and provide written service reports with every job. Courtesy car available for extended repairs.',
    ],
    [
        'username'    => 'mzuzu_auto_works',
        'email'       => 'mzuzu.auto@motorlink.demo',
        'full_name'   => 'Charles Mhango',
        'phone'       => '+265881600006',
        'city'        => 'Mzuzu',
        'name'        => 'Mzuzu Auto & Body Works',
        'owner'       => 'Charles Mhango',
        'address'     => 'Luwinga Township, Mzuzu, Malawi',
        'location_id' => 3,
        'whatsapp'    => '+265881600006',
        'recovery'    => '+265881600006',
        'services'    => ['Engine Repair','Oil & Filter Change','Brake Service','Tyre Fitting & Balancing','Wheel Alignment','Electrical & Diagnostics','Body & Panel Work','Spray Painting','Welding & Fabrication','Full Vehicle Inspection'],
        'emergency'   => ['Breakdown Recovery','Towing Service','Emergency Tyre Change'],
        'specialization' => ['All Makes & Models','Body & Paint','Light & Heavy Commercial'],
        'specializes_in_cars' => ['Toyota','Ford','Isuzu','Mitsubishi','Land Rover'],
        'years'       => 6,
        'hours'       => "Monday–Friday: 07:30–17:00\nSaturday: 08:00–12:00\nSunday: Closed",
        'website'     => 'https://mzuzuauto.mw',
        'facebook'    => 'https://facebook.com/mzuzuautomw',
        'instagram'   => 'https://instagram.com/mzuzuauto',
        'twitter'     => 'https://twitter.com/mzuzuauto',
        'linkedin'    => 'https://linkedin.com/company/mzuzu-auto-body-works',
        'description' => 'Mzuzu Auto & Body Works is the North\'s leading workshop for mechanical repairs and automotive body work. With 6 years serving Mzuzu and surrounding districts, we handle everything from routine oil changes to full engine rebuilds and accident repair spray-painting. Our body shop uses waterborne paints for a factory-match finish. We work on 4WDs, pickups, buses, and light commercial vehicles. Free towing from within Mzuzu city.',
    ],
];

$insUser2 = $db->prepare("
    INSERT IGNORE INTO users
        (username, email, password_hash, full_name, phone, whatsapp, city, user_type, status, email_verified, phone_verified, business_name, created_at)
    VALUES
        (:username, :email, :password_hash, :full_name, :phone, :whatsapp, :city, 'garage', 'active', 1, 1, :business_name, :created_at)
");

$insGarage = $db->prepare("
    INSERT INTO garages
        (user_id, name, owner_name, email, phone, recovery_number, whatsapp, address, location_id,
         services, emergency_services, specialization, specializes_in_cars,
         years_experience, business_hours, website, facebook_url, instagram_url,
         twitter_url, linkedin_url, description, verified, certified, featured, status, approved_at, created_at)
    VALUES
        (:user_id, :name, :owner_name, :email, :phone, :recovery, :whatsapp, :address, :location_id,
         :services, :emergency, :specialization, :spec_cars,
         :years, :hours, :website, :facebook, :instagram,
         :twitter, :linkedin, :description, 1, 1, 1, 'active', :approved_at, :created_at)
    ON DUPLICATE KEY UPDATE
        name=VALUES(name), owner_name=VALUES(owner_name), phone=VALUES(phone),
        recovery_number=VALUES(recovery_number), whatsapp=VALUES(whatsapp),
        address=VALUES(address), location_id=VALUES(location_id),
        services=VALUES(services), emergency_services=VALUES(emergency_services),
        specialization=VALUES(specialization), specializes_in_cars=VALUES(specializes_in_cars),
        years_experience=VALUES(years_experience), business_hours=VALUES(business_hours),
        website=VALUES(website), facebook_url=VALUES(facebook_url), instagram_url=VALUES(instagram_url),
        twitter_url=VALUES(twitter_url), linkedin_url=VALUES(linkedin_url),
        description=VALUES(description), verified=1, certified=1, featured=1, status='active'
");

foreach ($garages as $g) {
    $db->beginTransaction();
    try {
        $insUser2->execute([
            ':username' => $g['username'], ':email' => $g['email'],
            ':password_hash' => $passwordHash, ':full_name' => $g['full_name'],
            ':phone' => $g['phone'], ':whatsapp' => $g['phone'],
            ':city' => $g['city'], ':business_name' => $g['name'],
            ':created_at' => $now,
        ]);
        $uid = getUserId($db, $g['email']);
        $insGarage->execute([
            ':user_id' => $uid, ':name' => $g['name'], ':owner_name' => $g['owner'],
            ':email' => $g['email'], ':phone' => $g['phone'],
            ':recovery' => $g['recovery'], ':whatsapp' => $g['whatsapp'],
            ':address' => $g['address'], ':location_id' => $g['location_id'],
            ':services' => j($g['services']), ':emergency' => j($g['emergency']),
            ':specialization' => j($g['specialization']), ':spec_cars' => j($g['specializes_in_cars']),
            ':years' => $g['years'], ':hours' => $g['hours'],
            ':website' => $g['website'], ':facebook' => $g['facebook'],
            ':instagram' => $g['instagram'], ':twitter' => $g['twitter'],
            ':linkedin' => $g['linkedin'], ':description' => $g['description'],
            ':approved_at' => $now, ':created_at' => $now,
        ]);
        $bizId = getBusinessId($db, 'garages', $g['email']);
        $db->prepare("UPDATE users SET business_id=?, user_type='garage' WHERE id=?")->execute([$bizId, $uid]);
        $db->commit();
        echo "  ✓ {$g['name']} (user_id=$uid, garage_id=$bizId)\n";
        $credentials[] = ['type'=>'Garage', 'name'=>$g['name'], 'email'=>$g['email'], 'username'=>$g['username'], 'phone'=>$g['phone'], 'city'=>$g['city']];
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        echo "  ✗ {$g['name']}: ".$e->getMessage()."\n";
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// 3. CAR HIRE COMPANIES + FLEET
// ─────────────────────────────────────────────────────────────────────────────
echo "\n=== CAR HIRE COMPANIES ===\n";

$openHours = j(['monday'=>'08:00-17:00','tuesday'=>'08:00-17:00','wednesday'=>'08:00-17:00',
                'thursday'=>'08:00-17:00','friday'=>'08:00-17:00','saturday'=>'08:00-13:00','sunday'=>'closed']);

$hireCompanies = [
    [
        'username'    => 'malawi_fleet',
        'email'       => 'malawi.fleet@motorlink.demo',
        'full_name'   => 'Sophia Kaunda',
        'phone'       => '+265993700007',
        'city'        => 'Blantyre',
        'biz_name'    => 'Malawi Fleet Services',
        'owner'       => 'Sophia Kaunda',
        'address'     => 'Kaoshung Road, Limbe, Blantyre, Malawi',
        'location_id' => 1,
        'whatsapp'    => '+265993700007',
        'vehicle_types' => ['Sedan','SUV','Limousine','Van','Minibus'],
        'services'    => ['Self-drive Rental','Driver Included','Airport Transfers','Corporate Packages','Long-term Rental'],
        'special'     => ['Wedding Cars','Corporate Events','VIP Chauffeur','Prom & Graduation','Music Video Shoots'],
        'daily_from'  => 45000.00, 'weekly_from' => 280000.00, 'monthly_from' => 950000.00,
        'total_v'     => 18, 'avail_v' => 14,
        'years'       => 2018, 'hours24' => 0,
        'opening'     => $openHours,
        'hours'       => "Monday–Friday: 07:00–19:00\nSaturday: 07:00–17:00\nSunday: 08:00–14:00",
        'website'     => 'https://malawifleet.mw',
        'facebook'    => 'https://facebook.com/malawifleetservices',
        'instagram'   => 'https://instagram.com/malawifleet',
        'twitter'     => 'https://twitter.com/malawifleet',
        'linkedin'    => 'https://linkedin.com/company/malawi-fleet-services',
        'hire_cat'    => 'events',
        'event_types' => 'Wedding, Corporate, VIP, Graduation, Media Production',
        'description' => 'Malawi Fleet Services is the premier events and corporate car hire company in Blantyre. Established in 2018, we manage a fleet of 18 premium vehicles including luxury SUVs, stretch limousines, and executive sedans. Whether it\'s a lavish wedding, high-profile corporate event, or airport VIP transfer, our professionally-presented chauffeurs and immaculately maintained vehicles guarantee an unforgettable experience. All vehicles are GPS-tracked and insured to the highest standard.',
    ],
    [
        'username'    => 'capital_carhire',
        'email'       => 'capital.carhire@motorlink.demo',
        'full_name'   => 'Robert Nkosi',
        'phone'       => '+265991800008',
        'city'        => 'Lilongwe',
        'biz_name'    => 'Capital Car Hire',
        'owner'       => 'Robert Nkosi',
        'address'     => 'Area 47, Lilongwe, Malawi (opposite Sunbird Lilongwe Hotel)',
        'location_id' => 2,
        'whatsapp'    => '+265991800008',
        'vehicle_types' => ['Sedan','SUV','Pickup','Minibus'],
        'services'    => ['Self-drive Rental','Driver Included','Airport Transfers','Daily Hire','Weekly Hire','Monthly Lease'],
        'special'     => ['Kamuzu International Airport Pickup/Drop','Border Run Hire','Long-distance Trips'],
        'daily_from'  => 35000.00, 'weekly_from' => 210000.00, 'monthly_from' => 720000.00,
        'total_v'     => 22, 'avail_v' => 17,
        'years'       => 2016, 'hours24' => 0,
        'opening'     => $openHours,
        'hours'       => "Monday–Friday: 07:00–18:00\nSaturday: 08:00–16:00\nSunday: 09:00–13:00",
        'website'     => 'https://capitalcarhire.mw',
        'facebook'    => 'https://facebook.com/capitalcarhiremw',
        'instagram'   => 'https://instagram.com/capitalcarhire',
        'twitter'     => 'https://twitter.com/capitalcarhire',
        'linkedin'    => 'https://linkedin.com/company/capital-car-hire-malawi',
        'hire_cat'    => 'standard',
        'event_types' => '',
        'description' => 'Capital Car Hire has been Lilongwe\'s most reliable self-drive car hire service since 2016. Our diverse fleet of 22 vehicles — from compact sedans to rugged pickups — suits every budget and terrain. Competitive daily rates from MWK 35,000. We offer flexible pick-up at Kamuzu International Airport and our Area 47 depot. All vehicles come with full insurance, 24/7 roadside assistance, and a GPS unit at no extra charge.',
    ],
    [
        'username'    => 'northern_wheels',
        'email'       => 'northern.wheels@motorlink.demo',
        'full_name'   => 'Agnes Msowoya',
        'phone'       => '+265881900009',
        'city'        => 'Mzuzu',
        'biz_name'    => 'Northern Wheels Car Hire',
        'owner'       => 'Agnes Msowoya',
        'address'     => 'M1 Road, Mzimba Turn-off, Mzuzu, Malawi',
        'location_id' => 3,
        'whatsapp'    => '+265881900009',
        'vehicle_types' => ['SUV','Pickup','Van','Minibus'],
        'services'    => ['Self-drive Rental','Driver Included','Airport Transfers','Safari Hire','Long-distance Trips'],
        'special'     => ['Nyika Plateau Safari Trips','Livingstonia Escarpment Trips','Lake Malawi Shore Drives','NGO & Aid Organisation Rates'],
        'daily_from'  => 30000.00, 'weekly_from' => 180000.00, 'monthly_from' => 600000.00,
        'total_v'     => 12, 'avail_v' => 9,
        'years'       => 2019, 'hours24' => 1,
        'opening'     => j(['monday'=>'00:00-23:59','tuesday'=>'00:00-23:59','wednesday'=>'00:00-23:59',
                            'thursday'=>'00:00-23:59','friday'=>'00:00-23:59','saturday'=>'00:00-23:59','sunday'=>'00:00-23:59']),
        'hours'       => "Open 24 hours, 7 days a week\nBookings: Call or WhatsApp +265881900009",
        'website'     => 'https://northernwheels.mw',
        'facebook'    => 'https://facebook.com/northernwheelsmw',
        'instagram'   => 'https://instagram.com/northernwheels',
        'twitter'     => 'https://twitter.com/northernwheels',
        'linkedin'    => 'https://linkedin.com/company/northern-wheels-car-hire',
        'hire_cat'    => 'standard',
        'event_types' => '',
        'description' => 'Northern Wheels Car Hire operates 24/7 to serve the North\'s growing tourism, NGO, and business travel needs. Our rugged 4WD SUVs and double-cab pickups are equipped for Malawi\'s challenging Northern terrain — from the Nyika Plateau to Livingstonia\'s winding escarpment road. Special long-term rates available for NGOs and development organisations. GPS, satellite comms equipment available on request. Based on M1 Road, Mzuzu.',
    ],
];

// Fleet data: [company_email, make_id, model_id, make_name, model_name, year, vehicle_name, reg, transmission, fuel, seats, color, features, daily, weekly, monthly, category, event_suitable]
$fleet = [
    // Malawi Fleet Services (Blantyre)
    ['malawi.fleet@motorlink.demo', 1, 4, 'Toyota', 'Prado', 2022, 'Toyota Prado VX 2022', 'BY 2022 A', 'automatic', 'diesel', 7, 'Pearl White', ['Leather Seats','Sunroof','360° Camera','Android Auto','Apple CarPlay','GPS Navigation','Premium Sound System','Heated Seats','Cooler Box'], 85000.00, 520000.00, 1750000.00, 'suv', 1],
    ['malawi.fleet@motorlink.demo', 1, 9, 'Toyota', 'Land Cruiser', 2021, 'Toyota Land Cruiser 200 Series 2021', 'BY 2021 B', 'automatic', 'diesel', 8, 'Midnight Black', ['Full Leather','Electric Sunroof','360° Camera','Rear Entertainment','Multizone AC','GPS','Premium Sound','Fridge','Power Running Boards'], 110000.00, 680000.00, 2300000.00, 'suv', 1],
    ['malawi.fleet@motorlink.demo', 1, 10, 'Toyota', 'Hiace', 2020, 'Toyota Hiace Commuter 15-Seater 2020', 'BY 2020 C', 'manual', 'diesel', 15, 'Silver', ['AC','Tinted Windows','Luggage Rack','USB Charging Ports','GPS Tracking'], 65000.00, 395000.00, 1300000.00, 'bus', 1],

    // Capital Car Hire (Lilongwe)
    ['capital.carhire@motorlink.demo', 1, 1, 'Toyota', 'Corolla', 2023, 'Toyota Corolla Cross 2023', 'LI 2023 A', 'automatic', 'petrol', 5, 'Metallic Blue', ['Lane Departure Alert','Adaptive Cruise Control','Reversing Camera','Apple CarPlay','Android Auto','Wireless Charging','GPS'], 35000.00, 210000.00, 700000.00, 'car', 0],
    ['capital.carhire@motorlink.demo', 1, 3, 'Toyota', 'RAV4', 2022, 'Toyota RAV4 2022', 'LI 2022 B', 'automatic', 'petrol', 5, 'Graphite', ['Panoramic Roof','Leather Seats','360° Camera','Power Tailgate','Heated Seats','CarPlay/AA','GPS','Blind Spot Monitor'], 55000.00, 335000.00, 1100000.00, 'suv', 0],
    ['capital.carhire@motorlink.demo', 1, 5, 'Toyota', 'Hilux', 2021, 'Toyota Hilux D/Cab 2.8 GD-6 2021', 'LI 2021 C', 'manual', 'diesel', 5, 'White', ['Canopy','LED Lightbar','Tow Bar','Diff-lock','4WD Lo-Hi','GPS','USB Charging'], 50000.00, 305000.00, 1000000.00, 'pickup', 0],

    // Northern Wheels (Mzuzu)
    ['northern.wheels@motorlink.demo', 1, 3, 'Toyota', 'RAV4', 2022, 'Toyota RAV4 2022 4WD', 'MZ 2022 A', 'automatic', 'petrol', 5, 'Quartz Gold', ['4WD','Leather Seats','Reversing Camera','CarPlay/AA','GPS Navigation','Roof Rails','All-terrain Tyres'], 45000.00, 275000.00, 900000.00, 'suv', 0],
    ['northern.wheels@motorlink.demo', 1, 5, 'Toyota', 'Hilux', 2022, 'Toyota Hilux D/Cab Legend RS 2022', 'MZ 2022 B', 'manual', 'diesel', 5, 'Gunmetal Grey', ['Canopy','Lift Kit','All-terrain Tyres','Bull Bar','4WD','Tow Bar','GPS','Snorkel'], 42000.00, 255000.00, 840000.00, 'pickup', 0],
    ['northern.wheels@motorlink.demo', 1, 4, 'Toyota', 'Prado', 2020, 'Toyota Prado TZ-G 2020', 'MZ 2020 C', 'automatic', 'diesel', 7, 'Black', ['Leather','Sunroof','360 Camera','GPS','Locking Diffs','Terrain Select','All-terrain Tyres','Running Boards'], 75000.00, 455000.00, 1500000.00, 'suv', 0],
];

$insUser3 = $db->prepare("
    INSERT IGNORE INTO users
        (username, email, password_hash, full_name, phone, whatsapp, city, user_type, status, email_verified, phone_verified, business_name, created_at)
    VALUES
        (:username, :email, :password_hash, :full_name, :phone, :whatsapp, :city, 'car_hire', 'active', 1, 1, :business_name, :created_at)
");

$insHire = $db->prepare("
    INSERT INTO car_hire_companies
        (user_id, business_name, owner_name, email, phone, whatsapp, address, location_id,
         vehicle_types, services, special_services, daily_rate_from, weekly_rate_from, monthly_rate_from,
         currency, total_vehicles, available_vehicles, years_established, operates_24_7,
         opening_hours, business_hours, website, facebook_url, instagram_url,
         twitter_url, linkedin_url, description, hire_category, event_types,
         verified, certified, featured, status, approved_at, created_at)
    VALUES
        (:user_id, :biz_name, :owner, :email, :phone, :whatsapp, :address, :location_id,
         :vtypes, :services, :special, :daily, :weekly, :monthly,
         'MWK', :total_v, :avail_v, :years, :hours24,
         :opening, :hours, :website, :facebook, :instagram,
         :twitter, :linkedin, :description, :hire_cat, :event_types,
         1, 1, 1, 'active', :approved_at, :created_at)
    ON DUPLICATE KEY UPDATE
        business_name=VALUES(business_name), owner_name=VALUES(owner_name),
        phone=VALUES(phone), whatsapp=VALUES(whatsapp), address=VALUES(address),
        location_id=VALUES(location_id), vehicle_types=VALUES(vehicle_types),
        services=VALUES(services), special_services=VALUES(special_services),
        daily_rate_from=VALUES(daily_rate_from), weekly_rate_from=VALUES(weekly_rate_from),
        monthly_rate_from=VALUES(monthly_rate_from), total_vehicles=VALUES(total_vehicles),
        available_vehicles=VALUES(available_vehicles), years_established=VALUES(years_established),
        operates_24_7=VALUES(operates_24_7), opening_hours=VALUES(opening_hours),
        business_hours=VALUES(business_hours), website=VALUES(website),
        facebook_url=VALUES(facebook_url), instagram_url=VALUES(instagram_url),
        twitter_url=VALUES(twitter_url), linkedin_url=VALUES(linkedin_url),
        description=VALUES(description), hire_category=VALUES(hire_category),
        event_types=VALUES(event_types), verified=1, certified=1, featured=1, status='active'
");

$insFleet = $db->prepare("
    INSERT INTO car_hire_fleet
        (company_id, company_name, company_phone, company_email, company_location_id,
         make_id, model_id, make_name, model_name, year, vehicle_name,
         registration_number, transmission, fuel_type, seats, exterior_color,
         features, daily_rate, weekly_rate, monthly_rate,
         is_available, status, vehicle_category, event_suitable, is_active, created_at)
    VALUES
        (:cid, :cname, :cphone, :cemail, :cloc,
         :make_id, :model_id, :make_name, :model_name, :year, :vehicle_name,
         :reg, :trans, :fuel, :seats, :color,
         :features, :daily, :weekly, :monthly,
         1, 'available', :category, :event_suit, 1, :created_at)
");

foreach ($hireCompanies as $h) {
    $db->beginTransaction();
    try {
        $insUser3->execute([
            ':username' => $h['username'], ':email' => $h['email'],
            ':password_hash' => $passwordHash, ':full_name' => $h['full_name'],
            ':phone' => $h['phone'], ':whatsapp' => $h['phone'],
            ':city' => $h['city'], ':business_name' => $h['biz_name'],
            ':created_at' => $now,
        ]);
        $uid = getUserId($db, $h['email']);
        $insHire->execute([
            ':user_id' => $uid, ':biz_name' => $h['biz_name'], ':owner' => $h['owner'],
            ':email' => $h['email'], ':phone' => $h['phone'], ':whatsapp' => $h['whatsapp'],
            ':address' => $h['address'], ':location_id' => $h['location_id'],
            ':vtypes' => j($h['vehicle_types']), ':services' => j($h['services']),
            ':special' => j($h['special']),
            ':daily' => $h['daily_from'], ':weekly' => $h['weekly_from'], ':monthly' => $h['monthly_from'],
            ':total_v' => $h['total_v'], ':avail_v' => $h['avail_v'],
            ':years' => $h['years'], ':hours24' => $h['hours24'],
            ':opening' => $h['opening'], ':hours' => $h['hours'],
            ':website' => $h['website'], ':facebook' => $h['facebook'],
            ':instagram' => $h['instagram'], ':twitter' => $h['twitter'],
            ':linkedin' => $h['linkedin'], ':description' => $h['description'],
            ':hire_cat' => $h['hire_cat'], ':event_types' => $h['event_types'],
            ':approved_at' => $now, ':created_at' => $now,
        ]);
        $bizId = getBusinessId($db, 'car_hire_companies', $h['email']);
        $db->prepare("UPDATE users SET business_id=?, user_type='car_hire' WHERE id=?")->execute([$bizId, $uid]);
        $db->commit();
        echo "  ✓ {$h['biz_name']} (user_id=$uid, hire_id=$bizId)\n";
        $credentials[] = ['type'=>'Car Hire', 'name'=>$h['biz_name'], 'email'=>$h['email'], 'username'=>$h['username'], 'phone'=>$h['phone'], 'city'=>$h['city']];

        // Insert fleet
        $companyVehicles = array_filter($fleet, fn($f) => $f[0] === $h['email']);
        foreach ($companyVehicles as $fv) {
            // Check existing to avoid duplicate registration
            $existsCheck = $db->prepare("SELECT id FROM car_hire_fleet WHERE company_id=? AND registration_number=?");
            $existsCheck->execute([$bizId, $fv[7]]);
            if ($existsCheck->fetch()) {
                echo "    ↷ Fleet vehicle {$fv[6]} already exists, skipping\n";
                continue;
            }
            $insFleet->execute([
                ':cid' => $bizId, ':cname' => $h['biz_name'],
                ':cphone' => $h['phone'], ':cemail' => $h['email'], ':cloc' => $h['location_id'],
                ':make_id' => $fv[1], ':model_id' => $fv[2],
                ':make_name' => $fv[3], ':model_name' => $fv[4],
                ':year' => $fv[5], ':vehicle_name' => $fv[6],
                ':reg' => $fv[7], ':trans' => $fv[8], ':fuel' => $fv[9],
                ':seats' => $fv[10], ':color' => $fv[11],
                ':features' => j($fv[12]),
                ':daily' => $fv[13], ':weekly' => $fv[14], ':monthly' => $fv[15],
                ':category' => $fv[16], ':event_suit' => $fv[17],
                ':created_at' => $now,
            ]);
            echo "    + Fleet: {$fv[6]}\n";
        }
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        echo "  ✗ {$h['biz_name']}: ".$e->getMessage()."\n";
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// 4. PRINT CREDENTIALS TABLE
// ─────────────────────────────────────────────────────────────────────────────
echo "\n";
echo str_repeat('=', 90)."\n";
echo " DEMO BUSINESS CREDENTIALS — Password for ALL: {$DEMO_PASS}\n";
echo str_repeat('=', 90)."\n";
printf("%-10s %-35s %-35s %-22s %-12s\n", 'Type', 'Business Name', 'Login Email', 'Username', 'City');
echo str_repeat('-', 90)."\n";
foreach ($credentials as $c) {
    printf("%-10s %-35s %-35s %-22s %-12s\n",
        $c['type'], $c['name'], $c['email'], $c['username'], $c['city']);
}
echo str_repeat('=', 90)."\n";
echo " All accounts: verified=1, certified=1, featured=1, status=active\n";
echo " Logo upload: Use each business dashboard → Settings → Upload Logo\n";
echo str_repeat('=', 90)."\n";
