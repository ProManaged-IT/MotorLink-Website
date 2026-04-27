<?php
/**
 * seed_makes_models_v3.php — Africa-market popular cars (expansion pack)
 *
 * Sourced from BE FORWARD top sellers, AutoTrader SA, WeBuyCars SA and
 * real-world market presence in Malawi / Southern & East Africa.
 *
 * New makes: Subaru, Ford, Hyundai, Kia, Isuzu, Land Rover
 * Expansions: Toyota (Sienta, Prius, Hilux, Fortuner, RAV4, Prado, LC70/200,
 *             Camry, Corolla, Yaris, Avanza, Rush, Harrier, Mark X, Allion),
 *             Daihatsu (Mira, Tanto, Boon, Rocky),
 *             Suzuki (Swift, Jimny, Ertiga, Vitara, Ignis, Alto, APV),
 *             Nissan (Note, March, Wingroad, X-Trail, Navara, NP200, Tiida, AD Wagon),
 *             Honda (Fit/Jazz, CR-V, HR-V/Vezel, Mobilio, City, Civic),
 *             Mazda (CX-3, CX-5, BT-50, Verisa, Mazda3/Axela),
 *             Mitsubishi (Pajero, Pajero Sport, L200/Triton, Outlander, Lancer, RVR, Attrage, Mirage)
 *
 * Idempotent: skips rows that already exist (unique on make+name+body+year_start+engine+fuel+trans)
 * Run: php scripts/seed_makes_models_v3.php
 */

require_once __DIR__ . '/_bootstrap.php';
$db = motorlink_script_pdo();

// ── load existing makes ──────────────────────────────────────────────────────
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
ensureMake('Subaru',     'Japan',        55, $db, $makeIdByName);
ensureMake('Ford',       'USA',          60, $db, $makeIdByName);
ensureMake('Hyundai',    'South Korea',  65, $db, $makeIdByName);
ensureMake('Kia',        'South Korea',  70, $db, $makeIdByName);
ensureMake('Isuzu',      'Japan',        75, $db, $makeIdByName);
ensureMake('Land Rover', 'United Kingdom', 80, $db, $makeIdByName);
echo "Done.\n\n";

// ── helpers ──────────────────────────────────────────────────────────────────
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

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // TOYOTA — popular African-market expansions
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    // Sienta — bestselling compact minivan via BE FORWARD Africa (Malawi, Zambia, Tanzania)
    m('Toyota','Sienta','minivan',2003,2015, 1.5,4,'petrol','cvt',   109,141, 7,5,1110,'fwd',42,8.0,5.5,6.4,null,4175,1695,1665,2750),
    m('Toyota','Sienta','minivan',2015,2022, 1.5,4,'petrol','cvt',   109,141, 7,5,1150,'fwd',42,7.8,5.3,6.2,null,4260,1695,1695,2750),
    m('Toyota','Sienta','minivan',2015,2022, 1.5,4,'hybrid','cvt',   100,141, 7,5,1230,'fwd',36,null,null,4.1, 94,4260,1695,1695,2750),
    m('Toyota','Sienta','minivan',2022,null, 1.5,3,'hybrid','cvt',   120,141, 7,5,1330,'fwd',38,null,null,3.8, 87,4260,1695,1695,2750),

    // Prius — hugely popular hybrid in Africa (low running costs, widely available)
    m('Toyota','Prius','hatchback',2009,2015, 1.8,4,'hybrid','cvt',  136,142, 5,5,1380,'fwd',44,null,null,3.9, 89,4480,1745,1490,2700),
    m('Toyota','Prius','hatchback',2015,2022, 1.8,4,'hybrid','cvt',  122,142, 5,5,1375,'fwd',43,null,null,3.4, 78,4540,1760,1470,2700),
    m('Toyota','Prius','hatchback',2022,null, 2.0,4,'hybrid','cvt',  152,190, 5,5,1420,'fwd',43,null,null,4.2, 95,4600,1780,1430,2750),

    // Corolla (sedan) — one of the most sold cars ever in Africa
    m('Toyota','Corolla','sedan',2006,2013, 1.6,4,'petrol','automatic',124,157,5,4,1175,'fwd',55,null,null,7.8,null,4540,1760,1470,2600),
    m('Toyota','Corolla','sedan',2013,2019, 1.6,4,'petrol','automatic',132,160,5,4,1195,'fwd',55,null,null,7.2,null,4620,1775,1455,2700),
    m('Toyota','Corolla','sedan',2013,2019, 1.8,4,'petrol','cvt',      140,179,5,4,1270,'fwd',50,null,null,6.4,null,4620,1775,1455,2700),
    m('Toyota','Corolla','sedan',2019,null, 1.8,4,'petrol','cvt',      139,177,5,4,1305,'fwd',50,null,null,6.6,150,4630,1780,1435,2700),
    m('Toyota','Corolla','sedan',2019,null, 1.8,4,'hybrid','cvt',      122,142,5,4,1365,'fwd',43,null,null,4.2, 97,4630,1780,1435,2700),

    // Camry — popular upper-mid sedan in Malawi and SA
    m('Toyota','Camry','sedan',2011,2017, 2.5,4,'petrol','automatic',182,231,5,4,1475,'fwd',60,null,null,8.5,null,4850,1825,1470,2775),
    m('Toyota','Camry','sedan',2017,null, 2.0,4,'petrol','automatic',175,205,5,4,1440,'fwd',60,null,null,7.8,179,4885,1840,1445,2825),
    m('Toyota','Camry','sedan',2017,null, 2.5,4,'hybrid','cvt',      218,221,5,4,1585,'fwd',50,null,null,4.2, 96,4885,1840,1445,2825),

    // Yaris / Vitz — popular city hatchback
    m('Toyota','Yaris','hatchback',2005,2011, 1.0,3,'petrol','manual', 68, 93,5,5, 845,'fwd',42,null,null,5.9,135,3695,1660,1530,2460),
    m('Toyota','Yaris','hatchback',2011,2020, 1.0,3,'petrol','manual', 69, 93,5,5, 875,'fwd',40,null,null,4.7,108,3885,1695,1510,2510),
    m('Toyota','Yaris','hatchback',2011,2020, 1.3,4,'petrol','cvt',    99,125,5,5, 960,'fwd',42,null,null,5.5,127,3885,1695,1510,2510),
    m('Toyota','Yaris','hatchback',2020,null, 1.5,3,'hybrid','cvt',   116,141,5,5,1050,'fwd',36,null,null,3.5, 80,3940,1745,1500,2560),

    // RAV4 — popular compact SUV
    m('Toyota','RAV4','suv',2013,2019, 2.0,4,'petrol','cvt',       151,206,5,5,1710,'fwd',55,7.3,5.7,6.4,null,4570,1845,1680,2660),
    m('Toyota','RAV4','suv',2013,2019, 2.5,4,'petrol','cvt',       178,233,5,5,1775,'awd',55,null,null,8.0,null,4570,1845,1680,2660),
    m('Toyota','RAV4','suv',2019,null, 2.0,4,'petrol','cvt',       171,207,5,5,1715,'fwd',55,null,null,7.0,160,4600,1855,1685,2690),
    m('Toyota','RAV4','suv',2019,null, 2.5,4,'hybrid','cvt',       222,221,5,5,1885,'awd',55,null,null,5.8,133,4600,1855,1685,2690),
    m('Toyota','RAV4','suv',2019,null, 2.0,4,'diesel','manual',    143,370,5,5,1815,'4wd',60,null,null,6.0,159,4600,1855,1685,2690),

    // Hilux — iconic Africa pickup, top seller in Southern Africa
    m('Toyota','Hilux','pickup',2005,2015, 2.5,4,'diesel','manual', 144,343,5,4,1700,'rwd',80,null,null,8.5,null,5325,1850,1760,3085),
    m('Toyota','Hilux','pickup',2005,2015, 2.5,4,'diesel','manual', 144,343,2,4,1545,'rwd',80,null,null,8.5,null,5040,1850,1595,3085),
    m('Toyota','Hilux','pickup',2015,null, 2.4,4,'diesel','manual', 150,400,5,4,1730,'4wd',80,null,null,8.0,200,5330,1855,1815,3085),
    m('Toyota','Hilux','pickup',2015,null, 2.4,4,'diesel','automatic',150,400,5,4,1800,'4wd',80,null,null,8.5,215,5330,1855,1815,3085),
    m('Toyota','Hilux','pickup',2015,null, 2.8,4,'diesel','automatic',204,500,5,4,1925,'4wd',80,null,null,9.5,251,5330,1855,1815,3085),
    m('Toyota','Hilux','pickup',2015,null, 2.7,4,'petrol','manual',  166,245,5,4,1680,'4wd',80,null,null,10.5,null,5330,1855,1815,3085),

    // Fortuner — hugely popular family SUV in Southern Africa
    m('Toyota','Fortuner','suv',2005,2015, 2.7,4,'petrol','automatic',163,245,7,5,1880,'4wd',80,null,null,11.0,null,4785,1840,1850,2750),
    m('Toyota','Fortuner','suv',2005,2015, 3.0,4,'diesel','manual',  163,343,7,5,1985,'4wd',80,null,null,9.5,null,4785,1840,1850,2750),
    m('Toyota','Fortuner','suv',2015,null, 2.4,4,'diesel','manual',  150,400,7,5,1975,'4wd',80,null,null,8.5,200,4795,1855,1835,2745),
    m('Toyota','Fortuner','suv',2015,null, 2.4,4,'diesel','automatic',150,400,7,5,2060,'4wd',80,null,null,8.8,208,4795,1855,1835,2745),
    m('Toyota','Fortuner','suv',2015,null, 2.8,4,'diesel','automatic',204,500,7,5,2100,'4wd',80,null,null,9.5,250,4795,1855,1835,2745),
    m('Toyota','Fortuner','suv',2015,null, 2.7,4,'petrol','automatic',166,245,7,5,1935,'4wd',80,null,null,11.5,null,4795,1855,1835,2745),

    // Land Cruiser Prado — iconic 4x4, very popular in Malawi
    m('Toyota','Land Cruiser Prado','suv',2002,2009, 2.7,4,'petrol','automatic',163,246,8,5,1815,'4wd',87,null,null,12.5,null,4680,1870,1765,2790),
    m('Toyota','Land Cruiser Prado','suv',2002,2009, 3.0,4,'diesel','manual',   173,410,8,5,1920,'4wd',87,null,null,9.8,null,4680,1870,1765,2790),
    m('Toyota','Land Cruiser Prado','suv',2009,2015, 2.7,4,'petrol','automatic',163,246,8,5,1830,'4wd',87,null,null,12.5,null,4780,1885,1850,2790),
    m('Toyota','Land Cruiser Prado','suv',2009,2015, 3.0,4,'diesel','automatic',173,410,8,5,2060,'4wd',87,null,null,9.5,null,4780,1885,1850,2790),
    m('Toyota','Land Cruiser Prado','suv',2015,null, 2.8,4,'diesel','automatic',177,450,8,5,2175,'4wd',87,null,null,9.5,242,4780,1885,1895,2790),
    m('Toyota','Land Cruiser Prado','suv',2015,null, 4.0,6,'petrol','automatic',282,381,8,5,2180,'4wd',87,null,null,13.5,null,4780,1885,1895,2790),

    // Land Cruiser 70 — Africa's workhorse (farmers, NGOs, remote areas)
    m('Toyota','Land Cruiser 70','suv',1984,null, 4.0,6,'petrol','manual', 228,381,5,3,2090,'4wd',87,null,null,13.5,null,3960,1870,2055,2730),
    m('Toyota','Land Cruiser 70','pickup',1984,null, 4.5,6,'diesel','manual',131,400,5,4,2055,'4wd',90,null,null,11.0,null,5200,1870,1840,3180),
    m('Toyota','Land Cruiser 70','suv',2007,null, 4.5,8,'diesel','manual', 202,650,5,3,2270,'4wd',90,null,null,11.5,null,3960,1870,2055,2730),

    // Land Cruiser 200 — luxury land cruiser
    m('Toyota','Land Cruiser 200','suv',2007,2021, 4.5,8,'diesel','automatic',232,615,8,5,2700,'4wd',110,null,null,11.7,307,4950,1980,1920,2850),
    m('Toyota','Land Cruiser 200','suv',2007,2021, 4.7,8,'petrol','automatic',288,460,8,5,2580,'4wd',110,null,null,14.5,null,4950,1980,1920,2850),

    // Avanza — popular 7-seat MPV in East Africa (Tanzania, Kenya, Uganda, Malawi)
    m('Toyota','Avanza','minivan',2003,2011, 1.3,4,'petrol','manual', 88,116,7,5,1000,'rwd',40,null,null,8.0,null,4065,1660,1695,2650),
    m('Toyota','Avanza','minivan',2003,2011, 1.5,4,'petrol','manual',102,136,7,5,1050,'rwd',40,null,null,8.5,null,4065,1660,1695,2650),
    m('Toyota','Avanza','minivan',2011,2021, 1.3,4,'petrol','manual', 88,116,7,5,1005,'rwd',40,null,null,7.8,null,4190,1660,1695,2655),
    m('Toyota','Avanza','minivan',2011,2021, 1.5,4,'petrol','cvt',   102,136,7,5,1080,'rwd',40,null,null,8.3,null,4190,1660,1695,2655),

    // Rush — compact 7-seat SUV popular in East Africa (Malawi, Tanzania)
    m('Toyota','Rush','suv',2006,2017, 1.5,4,'petrol','automatic',109,141,7,5,1200,'4wd',55,null,null,8.5,null,4215,1695,1795,2685),
    m('Toyota','Rush','suv',2017,null, 1.5,4,'petrol','cvt',       98,136,7,5,1170,'4wd',50,null,null,7.8,null,4435,1695,1705,2685),

    // Harrier — premium crossover, popular luxury market
    m('Toyota','Harrier','crossover',2003,2013, 2.4,4,'petrol','automatic',163,218,5,5,1630,'fwd',65,null,null,10.5,null,4735,1840,1700,2715),
    m('Toyota','Harrier','crossover',2013,2020, 2.0,4,'petrol','cvt',      151,194,5,5,1580,'fwd',60,null,null,9.5,null,4720,1835,1690,2660),
    m('Toyota','Harrier','crossover',2013,2020, 2.5,4,'hybrid','cvt',      197,209,5,5,1755,'awd',55,null,null,6.0,139,4720,1835,1690,2660),
    m('Toyota','Harrier','crossover',2020,null, 2.0,4,'petrol','cvt',      171,207,5,5,1640,'fwd',60,null,null,9.8,null,4740,1855,1660,2690),

    // Mark X — RWD sports sedan popular in Southern & East Africa
    m('Toyota','Mark X','sedan',2004,2009, 2.5,6,'petrol','automatic',215,264,5,4,1510,'rwd',70,null,null,10.5,null,4730,1795,1435,2850),
    m('Toyota','Mark X','sedan',2004,2009, 3.0,6,'petrol','automatic',256,318,5,4,1590,'rwd',70,null,null,12.0,null,4730,1795,1435,2850),
    m('Toyota','Mark X','sedan',2009,2019, 2.5,6,'petrol','automatic',215,264,5,4,1520,'rwd',70,null,null,10.3,null,4795,1800,1440,2850),
    m('Toyota','Mark X','sedan',2009,2019, 3.0,6,'petrol','automatic',256,318,5,4,1600,'rwd',70,null,null,12.0,null,4795,1800,1440,2850),

    // Allion / Premio — popular family sedans from Japan
    m('Toyota','Allion','sedan',2001,2021, 1.8,4,'petrol','cvt',  136,170,5,4,1320,'fwd',60,null,null,8.0,null,4585,1695,1475,2700),
    m('Toyota','Allion','sedan',2001,2021, 2.0,4,'petrol','cvt',  152,196,5,4,1380,'fwd',60,null,null,8.5,null,4585,1695,1475,2700),
    m('Toyota','Premio','sedan',2001,2021, 1.8,4,'petrol','cvt',  136,170,5,4,1320,'fwd',60,null,null,8.0,null,4585,1695,1475,2700),
    m('Toyota','Premio','sedan',2001,2021, 2.0,4,'petrol','cvt',  152,196,5,4,1380,'fwd',60,null,null,8.5,null,4585,1695,1475,2700),

    // Kluger / Highlander — large family SUV
    m('Toyota','Kluger','suv',2007,2013, 2.7,4,'petrol','automatic',188,252,7,5,1875,'fwd',70,null,null,10.5,null,4845,1925,1730,2790),
    m('Toyota','Kluger','suv',2007,2013, 3.5,6,'petrol','automatic',295,357,7,5,1975,'awd',72,null,null,12.8,null,4845,1925,1730,2790),
    m('Toyota','Kluger','suv',2013,2020, 3.5,6,'petrol','automatic',295,357,7,5,1940,'awd',72,null,null,12.5,null,4895,1925,1720,2790),
    m('Toyota','Kluger','suv',2020,null, 2.5,4,'hybrid','cvt',      243,239,8,5,1975,'awd',65,null,null,6.7,153,4965,1925,1740,2850),

    // GR Yaris — sought-after performance hatchback
    m('Toyota','GR Yaris','hatchback',2020,null, 1.6,3,'petrol','manual',261,360,5,5,1280,'4wd',50,null,null,7.1,163,3995,1805,1455,2560),

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // DAIHATSU — budget/kei market expansion
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    // Mira — THE kei car of Malawi; extremely popular affordable import
    m('Daihatsu','Mira','hatchback',1980,2006, 0.66,3,'petrol','manual',  58, 60,4,3, 660,'fwd',32,null,null,6.0,null,3395,1475,1450,2340),
    m('Daihatsu','Mira','hatchback',2006,2018, 0.66,3,'petrol','cvt',     58, 60,4,5, 700,'fwd',32,null,null,5.2,null,3395,1475,1525,2390),
    m('Daihatsu','Mira e:S','hatchback',2011,2018, 0.66,3,'petrol','cvt', 52, 57,4,5, 660,'fwd',27,null,null,3.7, 86,3395,1475,1525,2390),

    // Tanto — popular kei minivan (tall-body), widely available via BE FORWARD
    m('Daihatsu','Tanto','minivan',2007,2013, 0.66,3,'petrol','cvt',   58, 60,4,5, 840,'fwd',30,null,null,5.5,null,3395,1475,1750,2490),
    m('Daihatsu','Tanto','minivan',2007,2013, 0.66,3,'petrol','cvt',   64, 92,4,5, 870,'fwd',30,null,null,6.0,null,3395,1475,1750,2490),
    m('Daihatsu','Tanto','minivan',2013,2019, 0.66,3,'petrol','cvt',   52, 60,4,5, 860,'fwd',28,null,null,4.5,null,3395,1480,1750,2490),
    m('Daihatsu','Tanto Custom','minivan',2019,null, 0.66,3,'petrol','cvt',64,100,4,5,900,'fwd',30,null,null,5.8,null,3395,1480,1755,2490),

    // Boon — small practical hatchback
    m('Daihatsu','Boon','hatchback',2004,2010, 1.0,3,'petrol','cvt',   71, 91,5,5, 770,'fwd',36,null,null,5.5,null,3610,1665,1520,2440),
    m('Daihatsu','Boon','hatchback',2010,2016, 1.0,3,'petrol','cvt',   71, 91,5,5, 790,'fwd',36,null,null,4.7,null,3610,1665,1540,2440),
    m('Daihatsu','Boon','hatchback',2016,null, 1.0,3,'petrol','cvt',   71, 95,5,5, 800,'fwd',35,null,null,4.6,null,3650,1670,1535,2455),

    // Rocky — modern compact crossover, growing popularity
    m('Daihatsu','Rocky','crossover',2019,null, 1.0,3,'petrol','cvt',  98,140,5,5,1010,'fwd',36,null,null,5.8,null,3995,1695,1620,2525),
    m('Daihatsu','Rocky','crossover',2019,null, 1.2,4,'hybrid','cvt',  82,109,5,5,1110,'fwd',31,null,null,4.7,107,3995,1695,1620,2525),

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // SUZUKI — affordable range, massive Africa presence
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    // Swift — hugely popular fun hatchback
    m('Suzuki','Swift','hatchback',2004,2010, 1.3,4,'petrol','manual', 91,122,5,5, 835,'fwd',42,null,null,5.9,null,3695,1690,1510,2390),
    m('Suzuki','Swift','hatchback',2010,2017, 1.2,4,'petrol','cvt',    94,118,5,5, 870,'fwd',40,null,null,5.0,115,3840,1695,1495,2450),
    m('Suzuki','Swift','hatchback',2017,null, 1.2,4,'petrol','cvt',    90,120,5,5, 890,'fwd',37,null,null,4.6,106,3840,1695,1495,2450),
    m('Suzuki','Swift Sport','hatchback',2017,null,1.4,4,'petrol','manual',140,230,5,5,970,'fwd',47,null,null,6.0,139,3840,1735,1495,2450),

    // Jimny — cult Africa 4x4, excellent off-road budget overlander
    m('Suzuki','Jimny','suv',1998,2018, 1.3,4,'petrol','manual', 85,110,4,3, 975,'4wd',40,null,null,8.5,null,3625,1600,1705,2250),
    m('Suzuki','Jimny','suv',1998,2018, 1.3,4,'petrol','automatic',85,110,4,3,1005,'4wd',40,null,null,8.8,null,3625,1600,1705,2250),
    m('Suzuki','Jimny','suv',2018,null, 1.5,4,'petrol','manual', 102,130,4,3,1035,'4wd',40,null,null,7.1,163,3645,1645,1720,2250),
    m('Suzuki','Jimny','suv',2018,null, 1.5,4,'petrol','automatic',102,130,4,3,1065,'4wd',40,null,null,7.5,172,3645,1645,1720,2250),
    m('Suzuki','Jimny Sierra','suv',2018,null, 1.5,4,'petrol','manual',102,130,5,5,1195,'4wd',40,null,null,7.5,172,3985,1645,1720,2590),

    // Alto — entry-level kei/A-segment
    m('Suzuki','Alto','hatchback',2009,2014, 0.66,3,'petrol','cvt', 52, 57,5,5, 720,'fwd',28,null,null,4.7,null,3395,1475,1490,2360),
    m('Suzuki','Alto','hatchback',2014,2021, 0.66,3,'petrol','cvt', 52, 57,5,5, 730,'fwd',28,null,null,4.4, 98,3395,1475,1500,2360),
    m('Suzuki','Alto','hatchback',2022,null, 0.66,3,'petrol','cvt', 46, 58,4,5, 610,'fwd',27,null,null,3.4, 79,3395,1475,1525,2385),

    // Ertiga — popular 7-seat minivan, East & Southern Africa (cheap family van)
    m('Suzuki','Ertiga','minivan',2012,2018, 1.4,4,'petrol','automatic', 95,130,7,5,1105,'fwd',42,null,null,7.2,null,4265,1695,1685,2740),
    m('Suzuki','Ertiga','minivan',2018,null, 1.5,4,'petrol','automatic',105,138,7,5,1160,'fwd',43,null,null,7.5,null,4395,1735,1690,2740),

    // Vitara — stylish compact crossover
    m('Suzuki','Vitara','crossover',2015,null, 1.6,4,'petrol','manual',  120,156,5,5,1100,'fwd',47,7.4,5.4,6.3,144,4175,1775,1610,2500),
    m('Suzuki','Vitara','crossover',2015,null, 1.4,4,'petrol','automatic',140,220,5,5,1200,'4wd',47,null,null,6.0,138,4175,1775,1610,2500),

    // Ignis — funky micro crossover
    m('Suzuki','Ignis','crossover',2016,null, 1.2,4,'petrol','cvt', 83,107,5,5, 855,'fwd',32,null,null,4.7,108,3700,1660,1595,2435),

    // APV — popular commercial van in Africa as microbus/shared transport
    m('Suzuki','APV','commercial',2004,2019, 1.5,4,'petrol','manual', 95,130,8,5,1080,'rwd',45,null,null,9.5,null,4155,1655,1820,2520),

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // NISSAN — strong presence in East & Southern Africa
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    // Note — popular compact hatchback from Japan
    m('Nissan','Note','hatchback',2005,2012, 1.4,4,'petrol','cvt',  98,130,5,5, 930,'fwd',41,null,null,6.5,null,3990,1690,1535,2600),
    m('Nissan','Note','hatchback',2012,2020, 1.2,3,'petrol','cvt',  79,107,5,5, 980,'fwd',41,null,null,4.7,109,4100,1695,1525,2600),
    m('Nissan','Note e-Power','hatchback',2016,null,1.2,3,'hybrid','cvt',136,254,5,5,1200,'fwd',41,null,null,2.7, 62,4100,1695,1525,2600),

    // March / Micra — budget city hatchback
    m('Nissan','March','hatchback',2002,2010, 1.0,3,'petrol','manual', 65, 90,5,5, 820,'fwd',38,null,null,5.5,null,3750,1660,1525,2430),
    m('Nissan','March','hatchback',2010,2019, 1.0,3,'petrol','cvt',   65, 90,5,5, 860,'fwd',36,null,null,4.5,104,3780,1665,1525,2440),
    m('Nissan','March','hatchback',2010,2019, 1.2,4,'petrol','cvt',   79,107,5,5, 915,'fwd',36,null,null,5.0,116,3780,1665,1525,2440),

    // Wingroad — practical estate/wagon
    m('Nissan','Wingroad','wagon',2005,2018, 1.5,4,'petrol','cvt', 109,150,5,5,1080,'fwd',50,null,null,7.0,null,4395,1695,1515,2700),

    // AD Wagon — popular estate taxi/family in Malawi
    m('Nissan','AD Wagon','wagon',1999,2019, 1.5,4,'petrol','cvt', 109,150,5,5,1050,'fwd',50,null,null,6.8,null,4395,1695,1480,2680),

    // Tiida — popular compact sedan/hatchback
    m('Nissan','Tiida','sedan',2004,2012, 1.5,4,'petrol','cvt',   109,152,5,4,1140,'fwd',50,null,null,7.0,null,4420,1695,1520,2600),
    m('Nissan','Tiida','hatchback',2004,2012, 1.5,4,'petrol','cvt',109,152,5,5,1095,'fwd',50,null,null,6.8,null,4220,1695,1520,2600),

    // X-Trail — popular compact SUV
    m('Nissan','X-Trail','suv',2007,2013, 2.0,4,'petrol','cvt',   140,196,5,5,1640,'fwd',60,null,null,9.0,null,4635,1800,1685,2705),
    m('Nissan','X-Trail','suv',2007,2013, 2.5,4,'petrol','cvt',   169,233,5,5,1720,'4wd',65,null,null,10.5,null,4635,1800,1685,2705),
    m('Nissan','X-Trail','suv',2013,2022, 2.0,4,'petrol','cvt',   144,200,7,5,1640,'fwd',60,null,null,8.5,198,4640,1820,1695,2705),
    m('Nissan','X-Trail','suv',2013,2022, 2.0,4,'diesel','manual',177,380,7,5,1740,'4wd',60,null,null,6.5,150,4640,1820,1695,2705),

    // Navara / NP300 Hardbody — iconic Africa pickup
    m('Nissan','NP300 Hardbody','pickup',1997,2015, 2.0,4,'petrol','manual', 98,162,2,2,1285,'rwd',60,null,null,9.5,null,4990,1695,1685,2600),
    m('Nissan','NP300 Hardbody','pickup',1997,2015, 2.5,4,'diesel','manual',133,305,2,2,1370,'rwd',65,null,null,8.0,null,4990,1695,1685,2600),
    m('Nissan','Navara','pickup',2004,2014, 2.5,4,'diesel','manual',174,450,5,4,1900,'4wd',80,null,null,8.5,null,5260,1850,1780,3200),
    m('Nissan','Navara','pickup',2014,null, 2.3,4,'diesel','manual',163,403,5,4,1800,'4wd',80,null,null,7.9,200,5265,1850,1790,3150),
    m('Nissan','Navara','pickup',2014,null, 2.3,4,'diesel','automatic',190,450,5,4,1930,'4wd',80,null,null,8.3,215,5265,1850,1790,3150),

    // NP200 — popular small bakkie in South Africa
    m('Nissan','NP200','pickup',2008,null, 1.6,4,'petrol','manual', 87,147,2,2,1010,'rwd',50,null,null,8.5,null,4716,1671,1510,2700),

    // Qashqai — popular crossover in SA
    m('Nissan','Qashqai','crossover',2006,2013, 1.6,4,'petrol','manual',  115,155,5,5,1365,'fwd',52,null,null,7.5,null,4315,1790,1615,2630),
    m('Nissan','Qashqai','crossover',2006,2013, 2.0,4,'petrol','cvt',    140,199,5,5,1490,'fwd',60,null,null,9.0,null,4315,1790,1615,2630),
    m('Nissan','Qashqai','crossover',2013,2021, 1.2,4,'petrol','cvt',    115,190,5,5,1310,'fwd',48,null,null,5.8,134,4377,1806,1590,2646),
    m('Nissan','Qashqai','crossover',2013,2021, 1.5,4,'diesel','manual', 110,260,5,5,1465,'fwd',48,null,null,4.5,104,4377,1806,1590,2646),

    // Juke — compact crossover popular in SA
    m('Nissan','Juke','crossover',2010,2019, 1.6,4,'petrol','manual',  117,158,5,5,1200,'fwd',48,null,null,6.9,159,4135,1765,1570,2530),
    m('Nissan','Juke','crossover',2010,2019, 1.6,4,'petrol','cvt',    190,240,5,5,1370,'4wd',50,null,null,8.0,184,4135,1765,1570,2530),

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // HONDA — reliable Japanese brand with strong African following
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    // Fit / Jazz — among the most popular Japanese imports in Africa
    m('Honda','Fit','hatchback',2001,2007, 1.3,4,'petrol','cvt',   83,121,5,5, 950,'fwd',40,null,null,5.5,null,3830,1675,1525,2450),
    m('Honda','Fit','hatchback',2007,2013, 1.3,4,'petrol','cvt',  100,127,5,5, 960,'fwd',40,null,null,5.5,null,3900,1695,1525,2500),
    m('Honda','Fit','hatchback',2007,2013, 1.5,4,'petrol','cvt',  118,145,5,5,1010,'fwd',40,null,null,5.8,null,3900,1695,1525,2500),
    m('Honda','Fit','hatchback',2013,2020, 1.3,4,'hybrid','cvt',   88,127,5,5,1060,'fwd',40,null,null,3.4, 79,3990,1695,1525,2530),
    m('Honda','Fit','hatchback',2013,2020, 1.5,4,'petrol','cvt',  132,155,5,5,1010,'fwd',40,null,null,5.5,null,3990,1695,1525,2530),
    m('Honda','Fit','hatchback',2020,null, 1.5,4,'hybrid','cvt',  109,135,5,5,1080,'fwd',40,null,null,3.6, 83,3995,1695,1540,2530),

    // CR-V — popular family SUV
    m('Honda','CR-V','suv',2006,2011, 2.0,4,'petrol','automatic',150,190,5,5,1590,'fwd',58,null,null,9.0,null,4500,1820,1680,2620),
    m('Honda','CR-V','suv',2006,2011, 2.4,4,'petrol','automatic',166,220,5,5,1660,'4wd',58,null,null,10.5,null,4500,1820,1680,2620),
    m('Honda','CR-V','suv',2012,2016, 2.0,4,'petrol','automatic',155,190,5,5,1600,'fwd',58,null,null,8.8,null,4590,1820,1685,2620),
    m('Honda','CR-V','suv',2016,2021, 1.5,4,'petrol','cvt',       193,243,5,5,1620,'fwd',57,null,null,7.8,183,4605,1855,1680,2660),
    m('Honda','CR-V','suv',2016,2021, 2.0,4,'hybrid','cvt',       184,315,5,5,1735,'4wd',57,null,null,6.5,148,4605,1855,1680,2660),

    // HR-V / Vezel — popular compact crossover
    m('Honda','HR-V','crossover',2013,2021, 1.5,4,'petrol','cvt',  130,155,5,5,1240,'fwd',40,null,null,7.0,163,4340,1790,1605,2610),
    m('Honda','HR-V','crossover',2013,2021, 1.5,4,'hybrid','cvt',  131,160,5,5,1280,'4wd',40,null,null,5.4,125,4340,1790,1605,2610),
    m('Honda','HR-V','crossover',2021,null, 1.5,4,'petrol','cvt',  119,145,5,5,1320,'fwd',40,null,null,6.8,155,4385,1790,1590,2610),
    m('Honda','HR-V','crossover',2021,null, 1.5,4,'hybrid','cvt',  131,253,5,5,1390,'4wd',40,null,null,4.7,108,4385,1790,1590,2610),

    // Mobilio — 7-seat MPV popular in East Africa
    m('Honda','Mobilio','minivan',2013,null, 1.5,4,'petrol','cvt', 118,145,7,5,1140,'fwd',42,null,null,7.5,null,4385,1683,1603,2652),

    // City — popular affordable sedan
    m('Honda','City','sedan',2008,2014, 1.5,4,'petrol','automatic',120,145,5,4,1060,'fwd',42,null,null,6.8,null,4395,1695,1495,2550),
    m('Honda','City','sedan',2014,2020, 1.5,4,'petrol','cvt',      120,145,5,4,1095,'fwd',42,null,null,6.5,150,4440,1695,1467,2600),
    m('Honda','City','sedan',2020,null, 1.5,4,'petrol','cvt',      121,145,5,4,1140,'fwd',40,null,null,6.0,136,4553,1748,1467,2589),
    m('Honda','City','sedan',2020,null, 1.5,4,'hybrid','cvt',      109,253,5,4,1180,'fwd',40,null,null,4.7,108,4553,1748,1467,2589),

    // Civic — popular compact sedan
    m('Honda','Civic','sedan',2011,2015, 1.8,4,'petrol','automatic',142,172,5,4,1255,'fwd',50,null,null,8.0,null,4540,1755,1470,2670),
    m('Honda','Civic','sedan',2015,2021, 1.5,4,'petrol','cvt',     182,240,5,4,1250,'fwd',47,null,null,6.8,157,4650,1800,1415,2700),
    m('Honda','Civic','sedan',2021,null, 1.5,4,'petrol','cvt',     182,240,5,4,1280,'fwd',47,null,null,6.7,154,4675,1802,1415,2735),

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // MAZDA — quality Japanese brand
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    // Mazda3 / Axela — popular compact hatchback/sedan
    m('Mazda','Mazda3','sedan',2009,2013, 1.6,4,'petrol','manual',105,145,5,4,1265,'fwd',51,null,null,7.0,null,4500,1755,1465,2640),
    m('Mazda','Mazda3','sedan',2009,2013, 2.0,4,'petrol','manual',150,188,5,4,1340,'fwd',60,null,null,8.0,null,4500,1755,1465,2640),
    m('Mazda','Mazda3','hatchback',2013,2019, 2.0,4,'petrol','automatic',165,213,5,5,1320,'fwd',56,7.3,5.2,6.0,138,4460,1795,1450,2700),
    m('Mazda','Mazda3','sedan',2013,2019, 2.0,4,'petrol','automatic',165,213,5,4,1325,'fwd',56,null,null,6.2,143,4580,1795,1450,2700),
    m('Mazda','Mazda3','hatchback',2019,null, 2.0,4,'petrol','automatic',165,213,5,5,1355,'fwd',51,6.7,5.2,5.9,135,4460,1797,1440,2726),

    // Verisa — compact practical hatchback
    m('Mazda','Verisa','hatchback',2004,2015, 1.5,4,'petrol','cvt',  113,148,5,5, 965,'fwd',45,null,null,6.5,null,4060,1695,1530,2490),

    // CX-3 — popular small crossover
    m('Mazda','CX-3','crossover',2015,null, 1.5,4,'diesel','manual',  105,270,5,5,1290,'fwd',48,null,null,4.4,116,4275,1765,1550,2570),
    m('Mazda','CX-3','crossover',2015,null, 2.0,4,'petrol','automatic',120,213,5,5,1240,'fwd',44,6.8,5.2,5.9,136,4275,1765,1550,2570),

    // CX-5 — popular mid-size SUV, growing in Africa
    m('Mazda','CX-5','suv',2012,2017, 2.0,4,'petrol','automatic',155,200,5,5,1545,'fwd',58,7.0,5.5,6.0,138,4555,1840,1670,2700),
    m('Mazda','CX-5','suv',2012,2017, 2.2,4,'diesel','automatic',150,380,5,5,1675,'4wd',56,null,null,5.5,147,4555,1840,1670,2700),
    m('Mazda','CX-5','suv',2017,null, 2.0,4,'petrol','automatic',165,213,5,5,1555,'fwd',58,7.0,5.5,6.1,140,4550,1840,1680,2700),
    m('Mazda','CX-5','suv',2017,null, 2.5,4,'petrol','automatic',194,258,5,5,1640,'fwd',58,null,null,8.0,null,4550,1840,1680,2700),
    m('Mazda','CX-5','suv',2017,null, 2.2,4,'diesel','automatic',175,420,5,5,1750,'4wd',56,null,null,5.6,148,4550,1840,1680,2700),

    // BT-50 — practical pickup truck for Africa
    m('Mazda','BT-50','pickup',2006,2011, 2.5,4,'diesel','manual',  110,330,5,4,1820,'4wd',80,null,null,8.5,null,5285,1860,1730,3100),
    m('Mazda','BT-50','pickup',2011,2020, 2.2,4,'diesel','manual',  110,375,5,4,1780,'4wd',80,null,null,7.5,null,5285,1850,1785,3100),
    m('Mazda','BT-50','pickup',2011,2020, 3.2,5,'diesel','automatic',200,470,5,4,2020,'4wd',80,null,null,8.8,null,5285,1850,1785,3100),
    m('Mazda','BT-50','pickup',2020,null, 3.0,4,'diesel','automatic',190,450,5,4,1965,'4wd',80,null,null,8.3,null,5290,1860,1785,3140),

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // MITSUBISHI — more popular models
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    // Pajero / Montero — THE iconic Africa 4x4
    m('Mitsubishi','Pajero','suv',1999,2006, 3.2,4,'diesel','manual',   165,343,7,5,2085,'4wd',90,null,null,10.5,null,4770,1875,1870,2725),
    m('Mitsubishi','Pajero','suv',1999,2006, 3.5,6,'petrol','automatic',202,304,7,5,1975,'4wd',90,null,null,13.5,null,4770,1875,1870,2725),
    m('Mitsubishi','Pajero','suv',2006,2021, 3.2,4,'diesel','automatic',200,441,7,5,2130,'4wd',90,null,null,10.0,264,4900,1875,1840,2780),
    m('Mitsubishi','Pajero','suv',2006,2021, 3.8,6,'petrol','automatic',250,329,7,5,2000,'4wd',87,null,null,14.0,null,4900,1875,1840,2780),

    // Pajero Sport — mid-size SUV popular in Africa
    m('Mitsubishi','Pajero Sport','suv',2008,2015, 2.5,4,'diesel','manual',   178,400,7,5,1845,'4wd',73,null,null,9.8,null,4695,1815,1800,2800),
    m('Mitsubishi','Pajero Sport','suv',2015,null,  2.4,4,'diesel','automatic',181,430,7,5,1940,'4wd',73,null,null,9.5,250,4795,1815,1800,2800),

    // L200 / Triton — popular pickup in Africa
    m('Mitsubishi','L200 Triton','pickup',2005,2015, 2.5,4,'diesel','manual',  136,314,5,4,1745,'4wd',75,null,null,8.5,null,5125,1815,1730,3000),
    m('Mitsubishi','L200 Triton','pickup',2015,null,  2.4,4,'diesel','manual',  181,430,5,4,1785,'4wd',75,null,null,8.5,226,5180,1815,1780,3000),
    m('Mitsubishi','L200 Triton','pickup',2015,null,  2.4,4,'diesel','automatic',181,430,5,4,1840,'4wd',75,null,null,9.0,234,5180,1815,1780,3000),

    // Outlander — popular family SUV
    m('Mitsubishi','Outlander','suv',2006,2012, 2.0,4,'petrol','cvt',   143,196,7,5,1545,'fwd',60,null,null,9.0,null,4640,1800,1680,2670),
    m('Mitsubishi','Outlander','suv',2006,2012, 2.4,4,'petrol','cvt',   165,220,7,5,1660,'4wd',62,null,null,10.0,null,4640,1800,1680,2670),
    m('Mitsubishi','Outlander','suv',2012,2021, 2.0,4,'petrol','cvt',   150,196,7,5,1560,'fwd',60,null,null,8.5,198,4695,1800,1680,2670),
    m('Mitsubishi','Outlander','suv',2012,2021, 2.4,4,'hybrid','cvt',   224,332,7,5,1800,'4wd',45,null,null,4.5,104,4695,1800,1680,2670),

    // Lancer — popular sporty sedan
    m('Mitsubishi','Lancer','sedan',2003,2007, 1.5,4,'petrol','cvt',  109,141,5,4,1100,'fwd',50,null,null,7.5,null,4490,1695,1465,2600),
    m('Mitsubishi','Lancer','sedan',2007,2017, 1.5,4,'petrol','cvt',  109,141,5,4,1120,'fwd',50,null,null,7.0,null,4570,1760,1480,2635),
    m('Mitsubishi','Lancer','sedan',2007,2017, 1.8,4,'petrol','cvt',  143,172,5,4,1190,'fwd',55,null,null,8.0,null,4570,1760,1480,2635),

    // RVR — compact crossover (popular alternative to C-HR)
    m('Mitsubishi','RVR','crossover',2010,null, 1.8,4,'petrol','cvt',  140,172,5,5,1395,'fwd',50,null,null,8.0,null,4295,1770,1620,2670),
    m('Mitsubishi','RVR','crossover',2010,null, 2.0,4,'petrol','cvt',  150,196,5,5,1510,'4wd',55,null,null,9.0,null,4295,1770,1620,2670),

    // Attrage — popular budget sedan in Africa
    m('Mitsubishi','Attrage','sedan',2013,null, 1.2,3,'petrol','cvt', 78,100,5,4, 890,'fwd',35,null,null,4.7,107,4240,1670,1510,2580),

    // Mirage — popular budget hatchback
    m('Mitsubishi','Mirage','hatchback',2012,null, 1.0,3,'petrol','cvt', 71, 88,5,5, 870,'fwd',35,null,null,4.5,103,3710,1665,1490,2450),
    m('Mitsubishi','Mirage','hatchback',2012,null, 1.2,4,'petrol','cvt', 78,100,5,5, 905,'fwd',35,null,null,4.9,113,3710,1665,1490,2450),

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // SUBARU — AWD specialist, growing in Southern Africa
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    // Forester — iconic AWD SUV popular in SA and outdoor-oriented Malawi buyers
    m('Subaru','Forester','suv',2008,2013, 2.0,4,'petrol','automatic',148,196,5,5,1480,'awd',60,null,null,9.0,null,4560,1780,1715,2615),
    m('Subaru','Forester','suv',2008,2013, 2.5,4,'petrol','automatic',173,224,5,5,1565,'awd',60,null,null,10.5,null,4560,1780,1715,2615),
    m('Subaru','Forester','suv',2013,2018, 2.0,4,'petrol','cvt',      150,197,5,5,1505,'awd',60,null,null,9.0,null,4595,1795,1735,2640),
    m('Subaru','Forester','suv',2013,2018, 2.0,4,'diesel','manual',   147,350,5,5,1620,'awd',60,null,null,6.5,null,4595,1795,1735,2640),
    m('Subaru','Forester','suv',2018,null, 2.5,4,'petrol','cvt',      182,239,5,5,1520,'awd',63,null,null,9.5,null,4625,1815,1730,2670),
    m('Subaru','Forester','suv',2018,null, 2.0,4,'hybrid','cvt',      150,300,5,5,1580,'awd',63,null,null,6.0,138,4625,1815,1730,2670),

    // Impreza — popular compact AWD
    m('Subaru','Impreza','sedan',2007,2011, 1.5,4,'petrol','automatic',107,145,5,4,1275,'awd',50,null,null,8.0,null,4580,1740,1430,2620),
    m('Subaru','Impreza','hatchback',2011,2016, 1.6,4,'petrol','cvt', 114,150,5,5,1295,'awd',48,null,null,7.5,null,4420,1740,1480,2645),
    m('Subaru','Impreza','sedan',2016,null, 2.0,4,'petrol','cvt',     154,196,5,4,1335,'awd',48,null,null,8.0,null,4625,1776,1460,2670),
    m('Subaru','Impreza','hatchback',2016,null, 2.0,4,'petrol','cvt', 154,196,5,5,1310,'awd',48,null,null,7.5,null,4460,1776,1465,2670),

    // Legacy & Outback — practical AWD estate/SUV
    m('Subaru','Legacy','sedan',2009,2014, 2.5,4,'petrol','cvt',     175,235,5,4,1445,'awd',60,null,null,9.5,null,4730,1775,1500,2740),
    m('Subaru','Outback','wagon',2009,2014, 2.5,4,'petrol','cvt',    175,235,5,5,1555,'awd',65,null,null,9.5,null,4795,1820,1605,2745),
    m('Subaru','Outback','wagon',2014,2020, 2.5,4,'petrol','cvt',    175,235,5,5,1535,'awd',65,null,null,9.5,null,4815,1840,1610,2745),
    m('Subaru','Outback','wagon',2020,null, 2.5,4,'petrol','cvt',    182,239,5,5,1540,'awd',65,null,null,8.0,183,4870,1875,1675,2745),

    // XV / Crosstrek — crossover version of Impreza
    m('Subaru','XV','crossover',2012,2017, 2.0,4,'petrol','cvt',  150,197,5,5,1400,'awd',50,null,null,7.1,163,4450,1800,1615,2635),
    m('Subaru','XV','crossover',2017,null, 2.0,4,'petrol','cvt',  156,196,5,5,1440,'awd',50,null,null,7.0,159,4465,1800,1620,2670),
    m('Subaru','XV','crossover',2017,null, 2.0,4,'hybrid','cvt',  150,300,5,5,1480,'awd',50,null,null,5.5,126,4465,1800,1620,2670),

    // WRX — popular performance AWD sedan
    m('Subaru','WRX','sedan',2014,2021, 2.0,4,'petrol','manual',  268,350,5,4,1480,'awd',50,null,null,9.5,218,4595,1795,1470,2650),
    m('Subaru','WRX STI','sedan',2014,2021, 2.5,4,'petrol','manual',304,407,5,4,1500,'awd',53,null,null,11.5,264,4595,1795,1470,2650),

    // BRZ — lightweight coupe
    m('Subaru','BRZ','coupe',2012,2021, 2.0,4,'petrol','manual', 200,205,4,2,1270,'rwd',50,null,null,8.8,202,4240,1775,1320,2570),

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // FORD — dominates SA bakkie market; growing in East Africa
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    // Ranger — #1 best-selling bakkie in South Africa for 10+ years
    m('Ford','Ranger','pickup',2011,2022, 2.2,4,'diesel','manual',   160,385,5,4,1837,'4wd',80,null,null,7.9,209,5359,1850,1821,3220),
    m('Ford','Ranger','pickup',2011,2022, 2.2,4,'diesel','automatic',160,385,5,4,1911,'4wd',80,null,null,8.3,220,5359,1850,1821,3220),
    m('Ford','Ranger','pickup',2011,2022, 3.2,5,'diesel','manual',   200,470,5,4,2015,'4wd',80,null,null,9.1,240,5359,1850,1821,3220),
    m('Ford','Ranger','pickup',2011,2022, 3.2,5,'diesel','automatic',200,470,5,4,2060,'4wd',80,null,null,9.5,251,5359,1850,1821,3220),
    m('Ford','Ranger','pickup',2022,null, 2.0,4,'diesel','manual',   170,405,5,4,1900,'4wd',82,null,null,8.0,212,5370,1918,1869,3270),
    m('Ford','Ranger','pickup',2022,null, 2.0,4,'diesel','automatic',205,500,5,4,2030,'4wd',82,null,null,8.5,224,5370,1918,1869,3270),
    m('Ford','Ranger Raptor','pickup',2019,null, 2.0,4,'petrol','automatic',213,500,5,4,2100,'4wd',80,null,null,10.5,null,5398,1910,1873,3270),

    // Everest — family SUV counterpart to Ranger
    m('Ford','Everest','suv',2015,2022, 2.2,4,'diesel','manual',   160,385,7,5,2038,'4wd',80,null,null,8.5,224,4892,1916,1837,2933),
    m('Ford','Everest','suv',2015,2022, 3.2,5,'diesel','automatic',200,470,7,5,2127,'4wd',80,null,null,9.6,255,4892,1916,1837,2933),
    m('Ford','Everest','suv',2022,null, 2.0,4,'diesel','automatic',170,405,7,5,2150,'4wd',80,null,null,8.5,224,4897,1923,1843,2900),
    m('Ford','Everest','suv',2022,null, 3.0,6,'diesel','automatic',250,600,7,5,2280,'4wd',80,null,null,9.5,250,4897,1923,1843,2900),

    // EcoSport — popular compact crossover in SA and Africa
    m('Ford','EcoSport','crossover',2013,2017, 1.0,3,'petrol','manual',125,170,5,5,1109,'fwd',52,null,null,5.9,136,3998,1765,1647,2519),
    m('Ford','EcoSport','crossover',2013,2017, 1.5,4,'petrol','manual',112,140,5,5,1155,'fwd',52,null,null,7.0,161,3998,1765,1647,2519),
    m('Ford','EcoSport','crossover',2017,2022, 1.5,4,'petrol','automatic',123,150,5,5,1200,'fwd',50,null,null,6.8,156,4001,1765,1647,2519),
    m('Ford','EcoSport','crossover',2013,2022, 1.5,4,'diesel','manual', 90,205,5,5,1255,'fwd',52,null,null,4.5,104,3998,1765,1647,2519),

    // Fiesta — popular small hatchback in SA
    m('Ford','Fiesta','hatchback',2008,2017, 1.0,3,'petrol','manual', 80,112,5,5, 999,'fwd',42,null,null,4.5,104,4039,1722,1481,2489),
    m('Ford','Fiesta','hatchback',2008,2017, 1.4,4,'petrol','manual', 96,128,5,5,1049,'fwd',42,null,null,5.6,130,4039,1722,1481,2489),
    m('Ford','Fiesta','hatchback',2017,2022, 1.0,3,'petrol','manual',100,170,5,5,1080,'fwd',42,null,null,5.0,115,4039,1722,1481,2560),
    m('Ford','Fiesta ST','hatchback',2017,2022, 1.5,3,'petrol','manual',200,290,5,5,1211,'fwd',48,null,null,8.0,182,4039,1722,1481,2560),

    // Focus — popular compact hatchback/sedan
    m('Ford','Focus','hatchback',2010,2018, 1.0,3,'petrol','manual',125,170,5,5,1267,'fwd',52,null,null,5.0,116,4358,1823,1484,2648),
    m('Ford','Focus','hatchback',2010,2018, 1.5,4,'petrol','automatic',150,240,5,5,1345,'fwd',52,null,null,6.4,149,4358,1823,1484,2648),
    m('Ford','Focus','hatchback',2018,null, 1.5,3,'petrol','automatic',150,240,5,5,1350,'fwd',52,null,null,6.0,138,4378,1825,1474,2700),
    m('Ford','Focus ST','hatchback',2018,null, 2.3,4,'petrol','manual',280,420,5,5,1534,'fwd',52,null,null,9.5,218,4378,1825,1474,2700),

    // Puma — modern crossover gaining popularity
    m('Ford','Puma','crossover',2019,null, 1.0,3,'petrol','manual',125,170,5,5,1298,'fwd',42,null,null,5.8,133,4207,1805,1537,2588),
    m('Ford','Puma ST','crossover',2020,null, 1.5,3,'petrol','manual',200,320,5,5,1480,'fwd',42,null,null,8.0,184,4207,1805,1537,2588),

    // Territory — growing in SA market
    m('Ford','Territory','crossover',2018,null, 1.5,4,'petrol','automatic',138,225,5,5,1560,'fwd',50,null,null,7.3,168,4584,1862,1662,2712),

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // HYUNDAI — top seller in South Africa; growing in East Africa
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    // i10 — very popular entry-level hatchback
    m('Hyundai','i10','hatchback',2007,2013, 1.1,4,'petrol','manual', 66, 97,5,5, 855,'fwd',35,null,null,5.2,119,3565,1595,1500,2380),
    m('Hyundai','i10','hatchback',2013,2019, 1.0,3,'petrol','manual', 66, 96,5,5, 853,'fwd',35,null,null,4.8,110,3670,1660,1500,2385),
    m('Hyundai','i10','hatchback',2013,2019, 1.2,4,'petrol','automatic',87,118,5,5, 942,'fwd',35,null,null,5.5,126,3670,1660,1500,2385),
    m('Hyundai','i10','hatchback',2019,null, 1.0,3,'petrol','manual', 67, 96,5,5, 873,'fwd',35,null,null,4.7,108,3670,1660,1500,2385),

    // i20 — popular affordable hatchback
    m('Hyundai','i20','hatchback',2008,2014, 1.2,4,'petrol','manual', 78,120,5,5, 960,'fwd',40,null,null,5.5,126,3990,1710,1480,2500),
    m('Hyundai','i20','hatchback',2014,2020, 1.0,3,'petrol','manual',100,172,5,5,1040,'fwd',40,null,null,5.5,127,4035,1734,1470,2570),
    m('Hyundai','i20','hatchback',2014,2020, 1.4,4,'petrol','manual', 100,134,5,5,1065,'fwd',40,null,null,5.8,133,4035,1734,1470,2570),
    m('Hyundai','i20','hatchback',2020,null, 1.0,3,'petrol','manual',100,172,5,5,1085,'fwd',40,null,null,5.1,117,4040,1775,1450,2580),
    m('Hyundai','i20 N','hatchback',2020,null, 1.6,4,'petrol','manual',204,275,5,5,1340,'fwd',40,null,null,8.2,189,4040,1775,1450,2580),

    // Grand i10 — popular affordable sedan/hatch for Africa
    m('Hyundai','Grand i10','hatchback',2013,2019, 1.0,3,'petrol','manual', 66, 94,5,5, 905,'fwd',38,null,null,5.5,127,3765,1660,1520,2425),
    m('Hyundai','Grand i10','sedan',2014,2019, 1.2,4,'petrol','manual', 83,115,5,4, 970,'fwd',38,null,null,5.5,126,4120,1680,1500,2490),
    m('Hyundai','Grand i10','hatchback',2019,null, 1.2,4,'petrol','manual', 83,115,5,5, 952,'fwd',38,null,null,5.2,119,3995,1720,1505,2450),

    // Creta — HUGELY popular crossover in Southern Africa and East Africa
    m('Hyundai','Creta','crossover',2015,2020, 1.4,4,'petrol','manual',   100,132,5,5,1154,'fwd',45,null,null,6.0,138,4270,1780,1627,2570),
    m('Hyundai','Creta','crossover',2015,2020, 1.6,4,'petrol','automatic',121,151,5,5,1230,'fwd',45,null,null,7.0,161,4270,1780,1627,2570),
    m('Hyundai','Creta','crossover',2015,2020, 1.6,4,'diesel','manual',   128,260,5,5,1370,'4wd',45,null,null,5.5,130,4270,1780,1627,2570),
    m('Hyundai','Creta','crossover',2020,null, 1.5,4,'petrol','cvt',       115,144,5,5,1260,'fwd',45,null,null,6.2,144,4300,1790,1635,2610),
    m('Hyundai','Creta','crossover',2020,null, 1.4,4,'petrol','manual',   140,242,5,5,1380,'fwd',45,null,null,7.5,173,4300,1790,1635,2610),

    // Accent / Verna — affordable sedan
    m('Hyundai','Accent','sedan',2011,2017, 1.4,4,'petrol','manual',100,134,5,4,1085,'fwd',45,null,null,6.2,143,4370,1700,1465,2570),
    m('Hyundai','Accent','sedan',2011,2017, 1.6,4,'petrol','automatic',123,157,5,4,1145,'fwd',48,null,null,7.0,163,4370,1700,1465,2570),
    m('Hyundai','Accent','sedan',2017,null, 1.4,4,'petrol','cvt',100,134,5,4,1090,'fwd',45,null,null,5.8,134,4440,1729,1400,2600),

    // Tucson — popular mid-size SUV
    m('Hyundai','Tucson','suv',2015,2021, 1.6,4,'petrol','manual',   130,265,5,5,1503,'fwd',50,null,null,7.5,173,4480,1850,1655,2670),
    m('Hyundai','Tucson','suv',2015,2021, 2.0,4,'petrol','automatic',155,192,5,5,1560,'fwd',53,null,null,9.0,208,4480,1850,1655,2670),
    m('Hyundai','Tucson','suv',2015,2021, 2.0,4,'diesel','automatic',185,400,5,5,1636,'4wd',48,null,null,6.5,150,4480,1850,1655,2670),
    m('Hyundai','Tucson','suv',2021,null, 1.6,4,'petrol','automatic',150,253,5,5,1545,'fwd',52,null,null,7.8,180,4500,1865,1650,2680),

    // Santa Fe — larger 7-seat SUV
    m('Hyundai','Santa Fe','suv',2012,2018, 2.0,4,'petrol','automatic',233,397,7,5,1736,'4wd',67,null,null,10.5,null,4690,1888,1690,2700),
    m('Hyundai','Santa Fe','suv',2012,2018, 2.2,4,'diesel','automatic',197,440,7,5,1855,'4wd',67,null,null,7.0,184,4690,1888,1690,2700),
    m('Hyundai','Santa Fe','suv',2018,null, 2.2,4,'diesel','automatic',200,440,7,5,1895,'4wd',67,null,null,7.2,189,4770,1890,1685,2765),

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // KIA — fastest growing brand in Southern Africa
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    // Picanto — small affordable city car
    m('Kia','Picanto','hatchback',2004,2011, 1.1,4,'petrol','manual', 65, 97,5,5, 820,'fwd',35,null,null,5.5,127,3495,1595,1480,2335),
    m('Kia','Picanto','hatchback',2011,2017, 1.0,3,'petrol','manual', 65, 95,5,5, 830,'fwd',35,null,null,4.8,110,3595,1595,1480,2385),
    m('Kia','Picanto','hatchback',2011,2017, 1.2,4,'petrol','manual', 85,118,5,5, 870,'fwd',35,null,null,5.5,126,3595,1595,1480,2385),
    m('Kia','Picanto','hatchback',2017,null, 1.0,3,'petrol','manual', 67, 96,5,5, 895,'fwd',35,null,null,4.7,108,3595,1595,1485,2400),
    m('Kia','Picanto','hatchback',2017,null, 1.2,4,'petrol','manual', 84,118,5,5, 932,'fwd',35,null,null,5.2,119,3595,1595,1485,2400),

    // Rio — popular budget hatchback/sedan
    m('Kia','Rio','hatchback',2011,2017, 1.0,3,'petrol','manual',100,172,5,5,1040,'fwd',40,null,null,4.8,110,4045,1720,1455,2570),
    m('Kia','Rio','hatchback',2011,2017, 1.4,4,'petrol','manual',100,134,5,5,1065,'fwd',40,null,null,5.8,133,4045,1720,1455,2570),
    m('Kia','Rio','sedan',2011,2017, 1.4,4,'petrol','automatic',107,135,5,4,1135,'fwd',40,null,null,6.2,143,4370,1700,1465,2570),
    m('Kia','Rio','hatchback',2017,null, 1.0,3,'petrol','manual',100,172,5,5,1085,'fwd',40,null,null,4.8,110,4065,1720,1450,2580),
    m('Kia','Rio','hatchback',2017,null, 1.4,4,'petrol','manual',100,134,5,5,1090,'fwd',40,null,null,5.5,127,4065,1720,1450,2580),

    // Cerato / Forte — popular compact sedan
    m('Kia','Cerato','sedan',2013,2018, 1.6,4,'petrol','automatic',130,165,5,4,1260,'fwd',50,null,null,7.5,174,4560,1780,1455,2700),
    m('Kia','Cerato','sedan',2013,2018, 2.0,4,'petrol','automatic',156,197,5,4,1325,'fwd',52,null,null,8.5,197,4560,1780,1455,2700),
    m('Kia','Cerato','sedan',2018,null, 1.6,4,'petrol','automatic',130,165,5,4,1280,'fwd',50,null,null,7.2,168,4640,1800,1425,2700),

    // Sportage — hugely popular compact SUV
    m('Kia','Sportage','suv',2010,2015, 1.6,4,'petrol','manual',  135,163,5,5,1384,'fwd',52,null,null,7.5,172,4440,1855,1635,2640),
    m('Kia','Sportage','suv',2010,2015, 2.0,4,'petrol','automatic',163,196,5,5,1490,'fwd',58,null,null,9.0,208,4440,1855,1635,2640),
    m('Kia','Sportage','suv',2015,2022, 1.6,4,'petrol','manual',  177,265,5,5,1492,'fwd',52,null,null,7.8,180,4480,1855,1635,2670),
    m('Kia','Sportage','suv',2015,2022, 2.0,4,'petrol','automatic',163,196,5,5,1535,'fwd',55,null,null,9.0,208,4480,1855,1635,2670),
    m('Kia','Sportage','suv',2015,2022, 2.0,4,'diesel','automatic',185,400,5,5,1640,'4wd',52,null,null,6.5,150,4480,1855,1635,2670),
    m('Kia','Sportage','suv',2022,null, 1.6,4,'petrol','automatic',150,253,5,5,1560,'fwd',55,null,null,7.5,174,4515,1865,1645,2680),
    m('Kia','Sportage','suv',2022,null, 1.6,4,'diesel','automatic',136,320,5,5,1650,'4wd',55,null,null,5.5,127,4515,1865,1645,2680),

    // Sorento — large family SUV
    m('Kia','Sorento','suv',2014,2020, 2.0,4,'petrol','automatic',240,394,7,5,1753,'4wd',67,null,null,10.0,null,4780,1890,1685,2780),
    m('Kia','Sorento','suv',2014,2020, 2.2,4,'diesel','automatic',200,440,7,5,1890,'4wd',67,null,null,7.0,184,4780,1890,1685,2780),
    m('Kia','Sorento','suv',2020,null, 2.2,4,'diesel','automatic',202,440,7,5,1945,'4wd',67,null,null,7.2,189,4810,1900,1690,2815),

    // Seltos — modern compact crossover gaining traction
    m('Kia','Seltos','crossover',2019,null, 1.4,4,'petrol','manual',  140,242,5,5,1341,'fwd',45,null,null,7.2,167,4370,1800,1635,2610),
    m('Kia','Seltos','crossover',2019,null, 1.5,4,'petrol','cvt',     115,144,5,5,1380,'fwd',45,null,null,6.5,150,4370,1800,1635,2610),
    m('Kia','Seltos','crossover',2019,null, 1.4,4,'diesel','manual',   115,250,5,5,1470,'4wd',45,null,null,5.5,127,4370,1800,1635,2610),

    // Stinger — performance sedan (growing in SA premium market)
    m('Kia','Stinger','sedan',2017,null, 2.5,4,'petrol','automatic',304,422,5,4,1795,'rwd',60,null,null,11.5,null,4830,1870,1400,2905),
    m('Kia','Stinger','sedan',2017,null, 3.3,6,'petrol','automatic',368,510,5,4,1855,'awd',60,null,null,13.0,null,4830,1870,1400,2905),

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // ISUZU — popular commercial/pickup trucks in Africa
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    // D-Max — very popular robust pickup in Southern & East Africa
    m('Isuzu','D-Max','pickup',2011,2019, 2.5,4,'diesel','manual',   163,350,5,4,1750,'4wd',76,null,null,8.5,null,5280,1850,1770,3095),
    m('Isuzu','D-Max','pickup',2011,2019, 3.0,4,'diesel','manual',   163,380,5,4,1830,'4wd',76,null,null,8.8,null,5280,1850,1770,3095),
    m('Isuzu','D-Max','pickup',2011,2019, 3.0,4,'diesel','automatic',177,380,5,4,1910,'4wd',76,null,null,9.2,null,5280,1850,1770,3095),
    m('Isuzu','D-Max','pickup',2019,null, 1.9,4,'diesel','manual',   163,360,5,4,1780,'4wd',76,null,null,7.8,205,5295,1870,1790,3095),
    m('Isuzu','D-Max','pickup',2019,null, 1.9,4,'diesel','automatic',163,360,5,4,1860,'4wd',76,null,null,8.0,210,5295,1870,1790,3095),
    m('Isuzu','D-Max X-Rider','pickup',2019,null, 3.0,4,'diesel','automatic',190,450,5,4,1980,'4wd',76,null,null,9.0,null,5310,1870,1800,3095),

    // mu-X — 7-seat SUV on D-Max platform
    m('Isuzu','mu-X','suv',2013,2020, 2.5,4,'diesel','automatic',163,350,7,5,1930,'4wd',76,null,null,9.5,null,4840,1860,1795,2840),
    m('Isuzu','mu-X','suv',2013,2020, 3.0,4,'diesel','automatic',177,380,7,5,2010,'4wd',76,null,null,9.8,null,4840,1860,1795,2840),
    m('Isuzu','mu-X','suv',2020,null, 1.9,4,'diesel','automatic',163,360,7,5,1985,'4wd',76,null,null,8.5,224,4840,1860,1795,2840),
    m('Isuzu','mu-X','suv',2020,null, 3.0,4,'diesel','automatic',190,450,7,5,2095,'4wd',76,null,null,9.5,null,4840,1860,1795,2840),

    // KB — older but still widely used in Africa
    m('Isuzu','KB','pickup',1988,2013, 2.5,4,'diesel','manual',100,260,2,2,1390,'rwd',65,null,null,8.5,null,4855,1695,1590,2800),
    m('Isuzu','KB','pickup',1988,2013, 3.0,4,'diesel','manual',130,320,5,4,1720,'4wd',75,null,null,9.5,null,5220,1850,1685,3085),

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // LAND ROVER — aspirational and practical 4x4 in Southern Africa
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    // Defender — iconic global 4x4
    m('Land Rover','Defender 90','suv',2020,null, 2.0,4,'petrol','automatic',300,400,5,3,2160,'4wd',90,null,null,11.5,265,4323,1996,1974,2587),
    m('Land Rover','Defender 90','suv',2020,null, 3.0,6,'diesel','automatic',249,570,5,3,2200,'4wd',90,null,null,9.5,249,4323,1996,1974,2587),
    m('Land Rover','Defender 110','suv',2020,null, 2.0,4,'petrol','automatic',300,400,5,5,2185,'4wd',90,null,null,11.5,265,4758,1996,1974,2587),
    m('Land Rover','Defender 110','suv',2020,null, 3.0,6,'diesel','automatic',249,570,7,5,2255,'4wd',90,null,null,9.5,249,4758,1996,1974,2587),
    // Classic Defender (Africa icon)
    m('Land Rover','Defender','suv',1990,2016, 2.2,4,'diesel','manual', 122,360,5,5,1670,'4wd',60,null,null,9.5,null,3820,1790,2035,2360),
    m('Land Rover','Defender','pickup',1990,2016, 2.2,4,'diesel','manual', 122,360,2,4,1580,'4wd',60,null,null,9.5,null,4520,1790,1965,2794),

    // Discovery 3 / LR3 — popular used 4x4 in Africa
    m('Land Rover','Discovery 3','suv',2004,2009, 2.7,6,'diesel','automatic',190,440,7,5,2320,'4wd',80,null,null,11.5,null,4700,1920,1887,2885),
    m('Land Rover','Discovery 3','suv',2004,2009, 4.4,8,'petrol','automatic',299,410,7,5,2190,'4wd',80,null,null,16.5,null,4700,1920,1887,2885),

    // Discovery 4 / LR4 — more popular and capable
    m('Land Rover','Discovery 4','suv',2009,2017, 3.0,6,'diesel','automatic',256,600,7,5,2392,'4wd',80,null,null,11.5,304,4826,2022,1877,2885),
    m('Land Rover','Discovery 4','suv',2009,2017, 5.0,8,'petrol','automatic',375,515,7,5,2262,'4wd',80,null,null,16.0,null,4826,2022,1877,2885),

    // Discovery 5 — current generation
    m('Land Rover','Discovery 5','suv',2017,null, 2.0,4,'petrol','automatic',300,400,7,5,2148,'4wd',85,null,null,11.0,254,4970,2073,1887,2923),
    m('Land Rover','Discovery 5','suv',2017,null, 3.0,6,'diesel','automatic',249,600,7,5,2314,'4wd',85,null,null,9.5,249,4970,2073,1887,2923),

    // Range Rover Sport — prestigious 4x4
    m('Land Rover','Range Rover Sport','suv',2005,2013, 2.7,6,'diesel','automatic',190,440,5,5,2198,'4wd',88,null,null,12.0,null,4788,1928,1796,2745),
    m('Land Rover','Range Rover Sport','suv',2005,2013, 3.0,6,'diesel','automatic',245,600,5,5,2240,'4wd',88,null,null,11.0,null,4788,1928,1796,2745),
    m('Land Rover','Range Rover Sport','suv',2013,2022, 3.0,6,'diesel','automatic',306,700,5,5,2100,'4wd',85,null,null,9.0,238,4850,2073,1780,2923),
    m('Land Rover','Range Rover Sport','suv',2013,2022, 5.0,8,'petrol','automatic',510,625,5,5,2130,'4wd',85,null,null,14.5,null,4850,2073,1780,2923),

    // Range Rover Evoque — popular entry-level Range Rover in SA
    m('Land Rover','Range Rover Evoque','suv',2011,2019, 2.0,4,'petrol','automatic',240,340,5,5,1700,'4wd',50,null,null,9.5,218,4355,1900,1605,2660),
    m('Land Rover','Range Rover Evoque','suv',2011,2019, 2.2,4,'diesel','manual',  150,420,5,5,1760,'4wd',50,null,null,6.5,154,4355,1900,1605,2660),
    m('Land Rover','Range Rover Evoque','suv',2019,null, 2.0,4,'petrol','automatic',249,365,5,5,1745,'4wd',50,null,null,9.5,218,4371,1904,1649,2681),
    m('Land Rover','Range Rover Evoque','suv',2019,null, 2.0,4,'diesel','automatic',180,430,5,5,1805,'4wd',50,null,null,6.5,150,4371,1904,1649,2681),
];

foreach ($models as $row) {
    addM($row, $db, $chk, $ins, $makeIdByName, $added, $skipped);
}

echo "\n=== DONE ===\n";
echo "  Added:   $added\n";
echo "  Skipped: $skipped\n";
