<?php
/**
 * seed_makes_models_v2.php — German + Japanese car models for African market
 * Idempotent: skips rows that already exist
 * Run: php scripts/seed_makes_models_v2.php
 */

$creds = require __DIR__ . '/../admin/admin-secrets.local.php';
$db = new PDO(
    'mysql:host=' . $creds['MOTORLINK_DB_HOST'] . ';dbname=' . $creds['MOTORLINK_DB_NAME'] . ';charset=utf8mb4',
    $creds['MOTORLINK_DB_USER'],
    $creds['MOTORLINK_DB_PASS'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// ── resolve existing makes ────────────────────────
$makeIdByName = [];
foreach ($db->query("SELECT id, name FROM car_makes ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $makeIdByName[strtolower($r['name'])] = (int)$r['id'];
}

function getMakeId(string $name, array &$map): int {
    $k = strtolower($name);
    if (!isset($map[$k])) throw new RuntimeException("Make not found: $name");
    return $map[$k];
}

function ensureMake(string $name, string $country, int $sort, PDO $db, array &$map): int {
    $k = strtolower($name);
    if (isset($map[$k])) return $map[$k];
    $s = $db->prepare("INSERT INTO car_makes (name,country,is_active,sort_order,created_at,updated_at) VALUES (?,?,1,?,NOW(),NOW())");
    $s->execute([$name, $country, $sort]);
    $id = (int)$db->lastInsertId();
    $map[$k] = $id;
    echo "  + Make: $name ($country) id=$id\n";
    return $id;
}

echo "=== MAKES ===\n";
ensureMake('Daihatsu', 'Japan', 50, $db, $makeIdByName);
echo "Done.\n\n";

// ── fix existing data quality issues ─────────────
echo "=== DATA FIXES ===\n";
$n = $db->exec("UPDATE car_models SET transmission_type='cvt', updated_at=NOW()
                WHERE make_id=1 AND name IN ('Corolla','RAV4')
                  AND fuel_type='hybrid' AND transmission_type IS NULL");
echo "  hybrid NULL transmission fixed: $n rows\n";

$n = $db->exec("UPDATE car_models SET year_end=NULL, updated_at=NOW()
                WHERE make_id=1 AND name='Corolla' AND year_end=2025");
echo "  Corolla year_end 2025 to NULL: $n rows\n";
echo "Done.\n\n";

// ── helpers ───────────────────────────────────────
$chk = $db->prepare("SELECT COUNT(*) FROM car_models
    WHERE make_id=? AND LOWER(name)=LOWER(?)
      AND (body_type<=>?)
      AND (year_start<=>?)
      AND (engine_size_liters<=>?)
      AND (fuel_type<=>?)
      AND (transmission_type<=>?)");

$ins = $db->prepare("
    INSERT INTO car_models (
        make_id,name,body_type,is_active,
        year_start,year_end,
        fuel_tank_capacity_liters,engine_size_liters,engine_cylinders,
        fuel_consumption_urban_l100km,fuel_consumption_highway_l100km,fuel_consumption_combined_l100km,
        fuel_type,transmission_type,
        horsepower_hp,torque_nm,
        seating_capacity,doors,
        weight_kg,drive_type,
        co2_emissions_gkm,
        length_mm,width_mm,height_mm,wheelbase_mm,
        created_at,updated_at
    ) VALUES (?,?,?,1, ?,?, ?,?,?, ?,?,?, ?,?, ?,?, ?,?, ?,?, ?, ?,?,?,?, NOW(),NOW())
");

$added = 0;
$skipped = 0;

function addM(array $m, PDO $db, PDOStatement $chk, PDOStatement $ins, array &$map, int &$added, int &$skipped): void {
    $mid = getMakeId($m['make'], $map);
    $chk->execute([$mid, $m['name'], $m['body'], $m['ys'], $m['eng'], $m['fuel'], $m['trans']]);
    if ($chk->fetchColumn() > 0) { $skipped++; return; }
    $ins->execute([
        $mid, $m['name'], $m['body'],
        $m['ys'], $m['ye'] ?? null,
        $m['tank'] ?? null, $m['eng'], $m['cyl'],
        $m['urban'] ?? null, $m['hwy'] ?? null, $m['comb'] ?? null,
        $m['fuel'], $m['trans'],
        $m['hp'], $m['torque'] ?? null,
        $m['seats'], $m['doors'],
        $m['weight'] ?? null, $m['drive'],
        $m['co2'] ?? null,
        $m['len'] ?? null, $m['wid'] ?? null, $m['hgt'] ?? null, $m['wb'] ?? null,
    ]);
    $added++;
    echo "  + {$m['make']} {$m['name']} {$m['body']} {$m['ys']} {$m['eng']}L {$m['fuel']}/{$m['trans']}\n";
}

function m(string $make, string $name, string $body, int $ys, ?int $ye,
           ?float $eng, ?int $cyl, string $fuel, ?string $trans,
           int $hp, ?int $torque, int $seats, int $doors, ?int $weight, string $drive,
           ?float $tank=null, ?float $urban=null, ?float $hwy=null, ?float $comb=null,
           ?int $co2=null, ?int $len=null, ?int $wid=null, ?int $hgt=null, ?int $wb=null): array {
    return compact('make','name','body','ys','ye','eng','cyl','fuel','trans','hp','torque',
                   'seats','doors','weight','drive','tank','urban','hwy','comb','co2','len','wid','hgt','wb');
}

echo "=== NEW MODELS ===\n";

$models = [
    // ━━━ TOYOTA ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // ProBox
    m('Toyota','ProBox','wagon',2002,null, 1.3,4,'petrol','manual',   87,121,5,5, 910,'fwd', 42,null,null,5.5,null,4195,1695,1500,2550),
    m('Toyota','ProBox','wagon',2002,null, 1.5,4,'petrol','cvt',     109,141,5,5, 960,'fwd', 42,null,null,5.8,null,4195,1695,1500,2550),
    // Corolla Fielder
    m('Toyota','Corolla Fielder','wagon',2000,null, 1.5,4,'petrol','cvt',    109,141,5,5,1090,'fwd',42,null,null,6.0,null,4390,1695,1475,2600),
    m('Toyota','Corolla Fielder','wagon',2000,null, 1.8,4,'petrol','cvt',    136,173,5,5,1110,'fwd',50,null,null,6.2,null,4390,1695,1475,2600),
    m('Toyota','Corolla Fielder','wagon',2015,null, 1.5,4,'hybrid','cvt',    100,163,5,5,1155,'fwd',38,null,null,4.2,null,4390,1695,1490,2600),
    // Noah
    m('Toyota','Noah','minivan',2001,null, 2.0,4,'petrol','cvt',    152,193,8,5,1680,'fwd',60,null,null,8.6,null,4620,1695,1870,2850),
    m('Toyota','Noah','minivan',2022,null, 1.8,4,'hybrid','cvt',    121,163,8,5,1760,'fwd',40,null,null,5.0,null,4695,1730,1895,2850),
    // Voxy
    m('Toyota','Voxy','minivan',2001,null, 2.0,4,'petrol','cvt',    152,193,8,5,1700,'fwd',60,null,null,8.7,null,4620,1695,1870,2850),
    m('Toyota','Voxy','minivan',2022,null, 2.0,4,'hybrid','cvt',    184,210,8,5,1800,'fwd',50,null,null,5.4,null,4695,1730,1895,2850),
    // Alphard
    m('Toyota','Alphard','minivan',2002,null, 3.5,6,'petrol','automatic',280,350,7,5,2065,'fwd',75,null,null,9.5,null,4945,1850,1895,3000),
    m('Toyota','Alphard','minivan',2015,null, 2.5,4,'hybrid','cvt',      182,235,7,5,2025,'fwd',55,null,null,6.3,null,4945,1850,1895,3000),
    // Vellfire
    m('Toyota','Vellfire','minivan',2008,null, 3.5,6,'petrol','automatic',280,350,7,5,2065,'fwd',75,null,null,9.5,null,4945,1850,1895,3000),
    m('Toyota','Vellfire','minivan',2015,null, 2.5,4,'hybrid','cvt',      182,235,7,5,2025,'fwd',55,null,null,6.3,null,4945,1850,1895,3000),
    // Wish
    m('Toyota','Wish','minivan',2003,null, 1.8,4,'petrol','cvt',      132,173,7,5,1430,'fwd',55,null,null,7.0,null,4555,1720,1615,2750),
    m('Toyota','Wish','minivan',2003,null, 2.0,4,'petrol','automatic', 156,196,7,5,1490,'awd',55,null,null,8.0,null,4555,1720,1615,2750),
    // Aqua
    m('Toyota','Aqua','hatchback',2012,2021, 1.5,4,'hybrid','cvt',100,111,5,5,1050,'fwd',36,null,null,3.4,79,3995,1695,1445,2550),
    // C-HR
    m('Toyota','C-HR','crossover',2016,null, 1.2,4,'petrol','cvt',116,185,5,5,1375,'fwd',50,7.1,5.3,6.0,null,4360,1795,1565,2640),
    m('Toyota','C-HR','crossover',2016,null, 1.8,4,'hybrid','cvt',122,142,5,5,1455,'fwd',43,null,null,4.3,99,4360,1795,1565,2640),
    // Land Cruiser 300
    m('Toyota','Land Cruiser 300','suv',2021,null, 3.3,6,'diesel','automatic',305,700,8,5,2690,'4wd',110,null,null,9.5,250,4985,1980,1925,2850),
    m('Toyota','Land Cruiser 300','suv',2021,null, 3.5,6,'petrol','automatic',415,650,8,5,2630,'4wd',110,null,null,12.0,null,4985,1980,1925,2850),
    // 4Runner
    m('Toyota','4Runner','suv',2003,null, 4.0,6,'petrol','automatic',270,380,7,5,2050,'4wd',87,null,null,12.6,null,4815,1925,1760,2790),
    // FJ Cruiser
    m('Toyota','FJ Cruiser','suv',2007,2014, 4.0,6,'petrol','automatic',260,381,5,3,1975,'4wd',72,null,null,13.4,null,4670,1905,1840,2690),
    // HiAce commuter diesel
    m('Toyota','HiAce','minivan',2019,null, 2.8,4,'diesel','automatic',177,450,15,5,2005,'rwd',70,null,null,8.1,null,5380,1950,2285,3210),

    // ━━━ MITSUBISHI ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    m('Mitsubishi','Delica D:5','minivan',2007,null, 2.4,4,'petrol','cvt',      170,226,8,5,1980,'4wd',60,null,null,10.2,null,4795,1795,1875,2850),
    m('Mitsubishi','Delica D:5','minivan',2012,null, 2.3,4,'diesel','automatic',150,380,8,5,2030,'4wd',60,null,null, 8.5,null,4795,1795,1875,2850),
    m('Mitsubishi','Delica Space Gear','minivan',1994,2007, 2.8,4,'diesel','automatic',95,240,8,5,1870,'4wd',70,null,null,10.5,null,4670,1780,1890,2755),
    m('Mitsubishi','Galant','sedan',1993,2012, 2.0,4,'petrol','automatic',145,190,5,4,1430,'fwd',60,null,null,9.5,null,4700,1740,1445,2750),
    m('Mitsubishi','Galant','sedan',1993,2012, 2.4,4,'petrol','automatic',160,220,5,4,1490,'fwd',60,null,null,10.2,null,4700,1740,1445,2750),
    m('Mitsubishi','ASX','suv',2010,null, 2.0,4,'petrol','cvt',      148,196,5,5,1460,'fwd',60,null,null,7.6,180,4295,1770,1625,2670),
    m('Mitsubishi','ASX','suv',2010,null, 2.0,4,'petrol','automatic',148,196,5,5,1490,'awd',60,null,null,8.9,null,4295,1770,1625,2670),
    m('Mitsubishi','Eclipse Cross','suv',2017,null, 1.5,4,'petrol','cvt',      150,250,5,5,1590,'fwd',51,null,null,7.1,160,4405,1805,1685,2670),
    m('Mitsubishi','Eclipse Cross','suv',2021,null, 2.4,4,'hybrid','automatic',188,346,5,5,1890,'awd',40,null,null,5.3,null,4545,1805,1685,2670),
    m('Mitsubishi','Challenger','suv',2008,null, 3.0,6,'petrol','automatic',220,275,7,5,1920,'4wd',72,null,null,11.5,null,4695,1815,1740,2750),
    m('Mitsubishi','Challenger','suv',2008,null, 2.5,4,'diesel','automatic',178,400,7,5,1980,'4wd',72,null,null, 8.6,null,4695,1815,1740,2750),

    // ━━━ HONDA ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    m('Honda','Stream','minivan',2001,2006, 1.7,4,'petrol','cvt',      125,155,7,5,1340,'fwd',50,null,null,7.8,null,4460,1695,1565,2750),
    m('Honda','Stream','minivan',2001,2006, 2.0,4,'petrol','cvt',      156,185,7,5,1390,'awd',55,null,null,9.0,null,4460,1695,1565,2750),
    m('Honda','Freed','minivan',2008,null, 1.5,4,'petrol','cvt',  118,145,6,5,1360,'fwd',42,null,null,6.6,null,4215,1695,1715,2740),
    m('Honda','Freed','minivan',2016,null, 1.5,4,'hybrid','cvt',  110,134,6,5,1470,'fwd',36,null,null,4.9,null,4265,1695,1735,2740),
    m('Honda','StepWGN','minivan',2005,2015, 2.0,4,'petrol','automatic',156,186,8,5,1740,'fwd',60,null,null,9.2,null,4690,1695,1840,2900),
    m('Honda','StepWGN','minivan',2015,null, 1.5,4,'petrol','cvt',     150,203,8,5,1720,'fwd',50,null,null,6.7,null,4690,1695,1840,2900),

    // ━━━ NISSAN ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    m('Nissan','Patrol Y61','suv',1997,2013, 3.0,6,'diesel','automatic',158,350,8,5,2470,'4wd',95,null,null,12.5,null,4880,1940,1930,2970),
    m('Nissan','Patrol Y61','suv',1997,2013, 4.8,6,'petrol','automatic',245,340,8,5,2450,'4wd',95,null,null,16.0,null,4880,1940,1930,2970),
    m('Nissan','Leaf','hatchback',2011,2017, 0.0,0,'electric','automatic',110,254,5,5,1545,'fwd',null,null,null,null,0,4490,1788,1540,2700),
    m('Nissan','Leaf','hatchback',2018,null,  0.0,0,'electric','automatic',214,340,5,5,1680,'fwd',null,null,null,null,0,4490,1788,1540,2700),
    m('Nissan','Terra','suv',2018,null, 2.3,4,'diesel','automatic',190,450,7,5,1995,'4wd',80,null,null,8.5,null,4870,1850,1805,2800),

    // ━━━ MERCEDES-BENZ ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    m('Mercedes-Benz','G-Class','suv',2018,null, 3.0,6,'diesel','automatic', 286,600,5,5,2535,'4wd',100,null,null, 9.6,253,4597,1931,1969,2873),
    m('Mercedes-Benz','G-Class','suv',2018,null, 4.0,8,'petrol','automatic', 422,610,5,5,2560,'4wd',100,null,null,13.1,299,4597,1931,1969,2873),
    m('Mercedes-Benz','G-Class','suv',2018,null, 4.0,8,'petrol','automatic', 585,850,5,5,2580,'4wd',100,null,null,14.0,320,4597,1931,1969,2873),
    m('Mercedes-Benz','B-Class','hatchback',2011,2018, 1.6,4,'petrol','automatic',122,200,5,5,1495,'fwd',50,null,null,6.5,150,4359,1786,1557,2699),
    m('Mercedes-Benz','B-Class','hatchback',2018,null, 1.3,4,'petrol','automatic',136,230,5,5,1545,'fwd',50,null,null,5.8,134,4422,1796,1557,2729),
    m('Mercedes-Benz','CLS','coupe',2010,2018, 3.0,6,'diesel','automatic',258,620,4,4,1795,'rwd',66,null,null,6.2,163,4996,1881,1416,2874),
    m('Mercedes-Benz','CLS','coupe',2018,null, 2.0,4,'diesel','automatic',194,400,4,4,1820,'rwd',66,null,null,5.5,144,4996,1893,1434,2939),
    m('Mercedes-Benz','CLS','coupe',2018,null, 3.0,6,'petrol','automatic',435,520,4,4,1855,'awd',66,null,null,8.2,187,4996,1893,1434,2939),
    m('Mercedes-Benz','EQC','suv',2019,null, 0.0,0,'electric','automatic',408,765,5,5,2495,'awd',null,null,null,null,0,4762,1884,1624,2873),

    // ━━━ BMW ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    m('BMW','2 Series','coupe',2014,null, 1.5,3,'petrol','automatic',140,220,4,2,1390,'rwd',44,7.3,5.0,6.0,139,4432,1774,1418,2690),
    m('BMW','2 Series','coupe',2014,null, 2.0,4,'petrol','automatic',184,270,4,2,1440,'rwd',44,null,null,6.9,null,4432,1774,1418,2690),
    m('BMW','2 Series Gran Coupe','hatchback',2019,null, 1.5,3,'petrol','automatic',136,220,5,5,1490,'fwd',40,null,null,5.9,null,4526,1800,1420,2670),
    m('BMW','M3','sedan',2014,2020, 3.0,6,'petrol','manual',   431,550,5,4,1495,'rwd',57,null,null,10.0,229,4671,1877,1424,2812),
    m('BMW','M3','sedan',2014,2020, 3.0,6,'petrol','automatic',450,550,5,4,1495,'rwd',57,null,null,10.2,232,4671,1877,1424,2812),
    m('BMW','M3','sedan',2021,null, 3.0,6,'petrol','automatic',510,650,5,4,1730,'awd',59,null,null,10.3,234,4794,1903,1433,2857),
    m('BMW','M4','coupe',2014,2020, 3.0,6,'petrol','manual',   431,550,4,2,1485,'rwd',57,null,null, 9.7,null,4671,1877,1380,2812),
    m('BMW','M4','coupe',2021,null, 3.0,6,'petrol','automatic',510,650,4,2,1725,'awd',59,null,null,10.3,null,4794,1887,1393,2857),
    m('BMW','M5','sedan',2012,2017, 4.4,8,'petrol','automatic',560,680,5,4,1870,'rwd',68,null,null,10.9,null,4936,1864,1464,2968),
    m('BMW','M5','sedan',2018,null, 4.4,8,'petrol','automatic',625,750,5,4,1995,'awd',68,null,null,11.3,258,4943,1903,1468,2982),
    m('BMW','iX3','suv',2021,null, 0.0,0,'electric','automatic',286,400,5,5,2185,'rwd',null,null,null,null,0,4734,1891,1668,2864),

    // ━━━ VOLKSWAGEN ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    m('Volkswagen','Golf GTI','hatchback',2004,null, 2.0,4,'petrol','manual',   245,370,5,5,1345,'fwd',45,9.2,5.7,7.1,162,4284,1799,1452,2648),
    m('Volkswagen','Golf GTI','hatchback',2004,null, 2.0,4,'petrol','automatic',245,370,5,5,1380,'fwd',45,null,null,7.1,162,4284,1799,1452,2648),
    m('Volkswagen','Golf Variant','wagon',2007,null, 1.4,4,'petrol','manual',   150,250,5,5,1355,'fwd',50,null,null,5.8,null,4562,1799,1480,2631),
    m('Volkswagen','Golf Variant','wagon',2020,null, 1.5,4,'petrol','automatic',150,250,5,5,1425,'fwd',50,null,null,5.4,125,4638,1789,1497,2686),
    m('Volkswagen','Transporter T6','minivan',2015,null, 2.0,4,'diesel','manual',   102,250,9,5,1985,'rwd',70,null,null,7.5,null,4904,1904,1970,3000),
    m('Volkswagen','Transporter T6','minivan',2015,null, 2.0,4,'diesel','automatic',150,340,9,5,2015,'rwd',70,null,null,7.6,null,4904,1904,1970,3000),
    m('Volkswagen','Caddy','minivan',2004,null, 2.0,4,'diesel','manual',102,250,5,5,1445,'fwd',55,null,null,5.9,157,4500,1794,1837,2682),

    // ━━━ PORSCHE ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    m('Porsche','911 Carrera','coupe',2016,null, 3.0,6,'petrol','manual',   385,450,4,2,1430,'rwd',67,12.0,7.4,9.1,205,4519,1852,1300,2450),
    m('Porsche','911 Carrera','coupe',2016,null, 3.0,6,'petrol','automatic',385,450,4,2,1450,'rwd',67,null,null,8.7,199,4519,1852,1300,2450),
    m('Porsche','911 Carrera S','coupe',2016,null, 3.0,6,'petrol','automatic',450,530,4,2,1480,'rwd',67,null,null,9.4,null,4519,1852,1300,2450),
    m('Porsche','911 Carrera 4S','coupe',2016,null, 3.0,6,'petrol','automatic',450,530,4,2,1535,'awd',67,null,null,10.0,null,4519,1900,1300,2450),
    m('Porsche','Panamera','sedan',2009,2016, 3.0,6,'petrol','automatic',330,450,4,4,1970,'awd',80,null,null,8.2,189,5049,1937,1428,2950),
    m('Porsche','Panamera','sedan',2017,null, 2.9,6,'petrol','automatic',440,550,4,4,1940,'awd',80,null,null,8.1,185,5049,1937,1428,2950),
    m('Porsche','Panamera Turbo','sedan',2017,null, 4.0,8,'petrol','automatic',550,770,4,4,2035,'awd',80,null,null,9.8,null,5049,1937,1428,2950),
    m('Porsche','Taycan','sedan',2019,null, 0.0,0,'electric','automatic',476,650,4,4,2295,'awd',null,null,null,null,0,4963,1966,1381,2900),
    m('Porsche','Taycan 4S','sedan',2019,null, 0.0,0,'electric','automatic',571,640,4,4,2305,'awd',null,null,null,null,0,4963,1966,1381,2900),

    // ━━━ SUZUKI ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    m('Suzuki','Grand Vitara','suv',2005,2015, 1.6,4,'petrol','manual',   106,145,5,5,1290,'4wd',55,null,null,8.0,null,3845,1780,1695,2480),
    m('Suzuki','Grand Vitara','suv',2005,2015, 2.4,4,'petrol','automatic',169,230,5,5,1450,'4wd',66,null,null,9.8,null,4510,1810,1685,2640),
    m('Suzuki','Baleno','hatchback',2015,null, 1.2,4,'petrol','manual',   90,120,5,5, 830,'fwd',37,null,null,4.9,113,3995,1745,1470,2520),
    m('Suzuki','Baleno','hatchback',2015,null, 1.4,4,'petrol','automatic',100,130,5,5, 870,'fwd',37,null,null,5.5,null,3995,1745,1470,2520),
    m('Suzuki','Dzire','sedan',2008,2017, 1.2,4,'petrol','manual',83,113,5,4, 840,'fwd',37,null,null,5.0,null,3995,1735,1495,2450),
    m('Suzuki','Dzire','sedan',2017,null, 1.2,4,'petrol','cvt',  90,113,5,4, 865,'fwd',37,null,null,4.7,107,3995,1735,1515,2450),
    m('Suzuki','S-Cross','crossover',2013,2021, 1.6,4,'petrol','manual',   120,156,5,5,1215,'fwd',50,null,null,6.0,139,4300,1785,1590,2600),
    m('Suzuki','S-Cross','crossover',2021,null,  1.4,4,'petrol','automatic',129,235,5,5,1265,'fwd',47,null,null,5.5,null,4300,1785,1580,2600),
    m('Suzuki','Wagon R','hatchback',1993,2016, 1.0,3,'petrol','manual',68, 92,4,5, 810,'fwd',35,null,null,4.9,null,3395,1475,1670,2360),
    m('Suzuki','Wagon R','hatchback',2017,null, 1.2,4,'petrol','cvt',  82,108,5,5, 855,'fwd',32,null,null,4.6,103,3595,1595,1700,2435),

    // ━━━ MAZDA ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    m('Mazda','Demio','hatchback',2002,2019, 1.3,4,'petrol','manual',91,121,5,5, 895,'fwd',40,null,null,6.0,null,3885,1680,1520,2490),
    m('Mazda','Demio','hatchback',2007,2019, 1.5,4,'petrol','cvt',  110,140,5,5, 950,'fwd',40,null,null,5.5,null,3885,1695,1520,2570),
    m('Mazda','Mazda2','hatchback',2014,null, 1.5,4,'petrol','manual',109,140,5,5, 940,'fwd',44,6.3,4.5,5.2,121,4060,1695,1500,2570),
    m('Mazda','Mazda2','hatchback',2014,null, 1.5,4,'diesel','manual', 75,220,5,5,1060,'fwd',44,null,null,3.8,100,4060,1695,1500,2570),
    m('Mazda','CX-8','suv',2017,null, 2.5,4,'petrol','automatic',190,252,6,5,1815,'fwd',62,null,null,8.2,null,4900,1840,1730,2930),
    m('Mazda','CX-8','suv',2017,null, 2.5,4,'petrol','automatic',231,420,6,5,1870,'awd',62,null,null,8.9,null,4900,1840,1730,2930),
    m('Mazda','CX-8','suv',2017,null, 2.2,4,'diesel','automatic',175,450,6,5,1890,'awd',62,null,null,6.4,170,4900,1840,1730,2930),
    m('Mazda','Atenza','sedan',2002,2012, 2.0,4,'petrol','automatic',147,186,5,4,1425,'fwd',62,null,null,8.5,null,4730,1795,1440,2750),
    m('Mazda','Atenza','sedan',2012,null, 2.5,4,'petrol','automatic',192,252,5,4,1510,'fwd',62,null,null,6.7,null,4865,1840,1450,2830),

    // ━━━ LEXUS ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    m('Lexus','IS','sedan',2005,2013, 2.5,6,'petrol','automatic',208,252,5,4,1555,'rwd',65,null,null,9.8,null,4600,1795,1410,2730),
    m('Lexus','IS','sedan',2013,null,  2.0,4,'petrol','automatic',241,350,5,4,1595,'rwd',66,null,null,8.1,null,4665,1810,1430,2800),
    m('Lexus','IS','sedan',2005,null,  3.5,6,'petrol','automatic',315,377,5,4,1675,'rwd',65,null,null,11.3,null,4665,1810,1430,2800),
    m('Lexus','LX 570','suv',2008,2021, 5.7,8,'petrol','automatic',381,535,8,5,2650,'4wd',93,18.5,12.5,15.0,354,5085,1980,1910,2850),
    m('Lexus','LX 600','suv',2022,null, 3.5,6,'petrol','automatic',409,650,7,5,2800,'4wd',93,null,null,13.5,308,5100,1990,1920,2850),

    // ━━━ DAIHATSU ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    m('Daihatsu','Terios','suv',1997,2005, 1.3,4,'petrol','manual',   86,118,5,5, 960,'4wd',42,null,null,7.5,null,3760,1620,1660,2270),
    m('Daihatsu','Terios','suv',2006,2017, 1.5,4,'petrol','automatic',105,138,5,5,1010,'4wd',42,null,null,8.2,null,3855,1695,1690,2460),
    m('Daihatsu','Charade','hatchback',1977,2001, 1.0,3,'petrol','manual',58,88,5,5,660,'fwd',35,null,null,5.5,null,3525,1550,1440,2300),
    m('Daihatsu','Move','hatchback',1995,null, 0.66,3,'petrol','cvt',52,63,4,5,700,'fwd',27,null,null,4.0,null,3395,1475,1680,2400),
    m('Daihatsu','Sirion','hatchback',1998,2004, 1.0,3,'petrol','manual',56,86,5,5,680,'fwd',35,null,null,5.8,null,3625,1600,1535,2400),
    m('Daihatsu','Sirion','hatchback',2005,null, 1.3,4,'petrol','automatic',90,121,5,5,870,'fwd',40,null,null,6.0,null,3810,1660,1540,2470),
];

foreach ($models as $m) {
    addM($m, $db, $chk, $ins, $makeIdByName, $added, $skipped);
}

echo "\nSeed complete — added: $added | skipped (already exist): $skipped\n";
