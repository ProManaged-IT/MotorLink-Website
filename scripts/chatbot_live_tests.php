<?php
// Live regression harness for AI chat precision and follow-up behavior.

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$_SERVER['PHP_SELF'] = $_SERVER['PHP_SELF'] ?? 'scripts/chatbot_live_tests.php';

require_once __DIR__ . '/../api-common.php';
require_once __DIR__ . '/../ai-car-chat-api.php';

$db = getDB();

function printLine($text) {
    echo $text . PHP_EOL;
}

function allLocationsMatch(array $rows, string $expected): bool {
    $expectedLower = strtolower(trim($expected));
    foreach ($rows as $row) {
        $loc = strtolower(trim((string)($row['location_name'] ?? '')));
        if ($loc !== $expectedLower) {
            return false;
        }
    }
    return true;
}

function runQueryPath(PDO $db, string $message): array {
    $params = simpleExtractParams($db, $message);
    if (!hasMeaningfulSearchParams($params)) {
        $ai = extractSearchParams($db, $message);
        if (!empty($ai)) {
            $params = $ai;
        }
    }

    $params = normalizeSearchParams($db, $params, $message);

    $query = [];

    if (!empty($params['body_type'])) {
        $query['category'] = $params['body_type'];
    }

    if (!empty($params['location'])) {
        $location = ucfirst(strtolower(trim($params['location'])));
        $query['location'] = $location;

        $locStmt = $db->prepare("SELECT id FROM locations WHERE LOWER(name) = ? OR LOWER(name) LIKE ? LIMIT 1");
        $locLower = strtolower($location);
        $locStmt->execute([$locLower, '%' . $locLower . '%']);
        $locationData = $locStmt->fetch(PDO::FETCH_ASSOC);
        if ($locationData) {
            $query['location_id'] = (int)$locationData['id'];
        }
    }

    if (!empty($params['make'])) {
        $makeStmt = $db->prepare("SELECT id FROM car_makes WHERE LOWER(name) LIKE ? LIMIT 1");
        $makeStmt->execute(['%' . strtolower($params['make']) . '%']);
        $make = $makeStmt->fetch(PDO::FETCH_ASSOC);
        if ($make) {
            $query['make_id'] = (int)$make['id'];
        }
    }

    if (!empty($params['model'])) {
        $modelSql = "SELECT id FROM car_models WHERE LOWER(name) LIKE ?";
        $modelParams = ['%' . strtolower($params['model']) . '%'];
        if (!empty($query['make_id'])) {
            $modelSql .= " AND make_id = ?";
            $modelParams[] = $query['make_id'];
        }
        $modelStmt = $db->prepare($modelSql . " LIMIT 1");
        $modelStmt->execute($modelParams);
        $model = $modelStmt->fetch(PDO::FETCH_ASSOC);
        if ($model) {
            $query['model_id'] = (int)$model['id'];
        }
    }

    $rows = searchListings($db, $query);

    return [
        'params' => $params,
        'query' => $query,
        'rows' => $rows,
    ];
}

printLine('=== MotorLink Chatbot Live Test Harness ===');
printLine('Time: ' . date('Y-m-d H:i:s'));

$scenarios = [
    [
        'name' => 'Primary: SUV in Lilongwe',
        'message' => 'I am looking for an SUV in lilongwe',
        'expected_location' => 'Lilongwe',
    ],
    [
        'name' => 'Typo tolerance: SUCV in lilonwe',
        'message' => 'I am looking for an SUCV in lilonwe',
        'expected_location' => 'Lilongwe',
    ],
];

foreach ($scenarios as $scenario) {
    $result = runQueryPath($db, $scenario['message']);
    $rows = $result['rows'];
    $ok = empty($rows) ? true : allLocationsMatch($rows, $scenario['expected_location']);

    printLine('--- ' . $scenario['name'] . ' ---');
    printLine('Message: ' . $scenario['message']);
    printLine('detectSearchQuery: ' . (detectSearchQuery($scenario['message']) ? 'true' : 'false'));
    printLine('Normalized params: ' . json_encode($result['params'], JSON_UNESCAPED_UNICODE));
    printLine('Search query: ' . json_encode($result['query'], JSON_UNESCAPED_UNICODE));
    printLine('Rows: ' . count($rows));
    printLine('Location leakage: ' . ($ok ? 'NO' : 'YES'));
}

printLine('--- Follow-up scenario ---');
$history = [
    ['role' => 'user', 'content' => 'I am looking for an SUV in lilongwe'],
];
$followUp = 'What about Salima';
$rewritten = buildLocationFollowUpSearchMessage($db, $followUp, $history);
printLine('Follow-up input: ' . $followUp);
printLine('Rewritten query: ' . ($rewritten !== false ? $rewritten : '[false]'));
if ($rewritten !== false) {
    $result = runQueryPath($db, $rewritten);
    $rows = $result['rows'];
    $ok = empty($rows) ? true : allLocationsMatch($rows, 'Salima');
    printLine('Rows: ' . count($rows));
    printLine('Location leakage: ' . ($ok ? 'NO' : 'YES'));
}

printLine('--- Car-hire follow-up scenario ---');
$carHireHistory = [
    ['role' => 'user', 'content' => 'Looking for an SUV to hire in Lilongwe'],
];
$carHireFollowUp = 'What about Salima';
$carHireRewritten = buildLocationFollowUpCarHireMessage($db, $carHireFollowUp, $carHireHistory);
printLine('Car-hire follow-up input: ' . $carHireFollowUp);
printLine('Car-hire rewritten query: ' . ($carHireRewritten !== false ? $carHireRewritten : '[false]'));
if ($carHireRewritten !== false) {
    $carHireParams = extractCarHireSearchParams($carHireRewritten);
    $carHireResults = searchCarHire($db, $carHireParams, null);
    $carHireRows = $carHireResults['companies'] ?? [];
    $carHireOk = empty($carHireRows) ? true : allLocationsMatch($carHireRows, 'Salima');
    printLine('Companies: ' . count($carHireRows));
    printLine('Location leakage: ' . ($carHireOk ? 'NO' : 'YES'));
}

printLine('--- Car-hire comparative follow-up scenario ---');
$carHireComparativeHistory = [
    ['role' => 'user', 'content' => 'Looking for a car hire in Blantyre'],
    ['role' => 'assistant', 'content' => 'Found 2 car hire companies in Blantyre'],
    ['role' => 'user', 'content' => 'And in Blantrye'],
];
$carHireComparativeInput = 'What is the cheapest';
$carHireComparativeRewritten = buildCarHireComparativeFollowUpMessage($db, $carHireComparativeInput, $carHireComparativeHistory);
printLine('Car-hire comparative input: ' . $carHireComparativeInput);
printLine('Car-hire comparative rewritten query: ' . ($carHireComparativeRewritten !== false ? $carHireComparativeRewritten : '[false]'));
if ($carHireComparativeRewritten !== false) {
    $carHireComparativeParams = extractCarHireSearchParams($carHireComparativeRewritten);
    $carHireComparativeResults = searchCarHire($db, $carHireComparativeParams, null);
    $carHireComparativeRows = $carHireComparativeResults['companies'] ?? [];
    $comparativeInScope = !isOutOfScopeQuery($carHireComparativeRewritten);
    $comparativeIsCarHire = detectCarHireQuery($carHireComparativeRewritten);

    $isSortedCheapest = true;
    if (count($carHireComparativeRows) > 1) {
        $first = (float)($carHireComparativeRows[0]['daily_rate_from'] ?? INF);
        foreach ($carHireComparativeRows as $row) {
            $rate = (float)($row['daily_rate_from'] ?? INF);
            if ($rate < $first) {
                $isSortedCheapest = false;
                break;
            }
        }
    }

    printLine('Comparative in-scope: ' . ($comparativeInScope ? 'YES' : 'NO'));
    printLine('Comparative detected as car-hire: ' . ($comparativeIsCarHire ? 'YES' : 'NO'));
    printLine('Comparative cheapest ordering: ' . ($isSortedCheapest ? 'YES' : 'NO'));
}

printLine('--- Car-hire contact follow-up scenario ---');
$carHireContactHistory = [
    ['role' => 'user', 'content' => 'Loking for car hire in lilongwe'],
    ['role' => 'assistant', 'content' => 'Found 2 car hire companies in Lilongwe'],
    ['role' => 'user', 'content' => 'What is the cheapest'],
    ['role' => 'assistant', 'content' => 'The cheapest car hire option I found is Capital Auto Rentals in Lilongwe.'],
];
$carHireContactInput = 'Give me their contact number';
$carHireContactRewritten = buildContactFollowUpMessage($db, $carHireContactInput, $carHireContactHistory);
printLine('Car-hire contact input: ' . $carHireContactInput);
printLine('Car-hire contact rewritten query: ' . ($carHireContactRewritten !== false ? $carHireContactRewritten : '[false]'));
if ($carHireContactRewritten !== false) {
    $contactInScope = !isOutOfScopeQuery($carHireContactRewritten);
    $contactIsCarHire = detectCarHireQuery($carHireContactRewritten);
    $contactParams = extractCarHireSearchParams($carHireContactRewritten);
    $contactResults = searchCarHire($db, $contactParams, null);
    $contactRows = $contactResults['companies'] ?? [];
    $contactHasPhone = false;
    foreach ($contactRows as $company) {
        if (!empty($company['phone'])) {
            $contactHasPhone = true;
            break;
        }
    }

    printLine('Contact in-scope: ' . ($contactInScope ? 'YES' : 'NO'));
    printLine('Contact detected as car-hire: ' . ($contactIsCarHire ? 'YES' : 'NO'));
    printLine('Contact companies with phone available: ' . ($contactHasPhone ? 'YES' : 'NO'));
}

printLine('--- Garage follow-up scenario ---');
$garageHistory = [
    ['role' => 'user', 'content' => 'Find a garage in Lilongwe'],
];
$garageFollowUp = 'What about Salima';
$garageRewritten = buildLocationFollowUpGarageMessage($db, $garageFollowUp, $garageHistory);
printLine('Garage follow-up input: ' . $garageFollowUp);
printLine('Garage rewritten query: ' . ($garageRewritten !== false ? $garageRewritten : '[false]'));
if ($garageRewritten !== false) {
    $garageParams = extractGarageSearchParams($garageRewritten);
    $garageLocationOk = strtolower((string)($garageParams['location'] ?? '')) === 'salima';
    printLine('Garage location resolved: ' . ($garageLocationOk ? 'YES' : 'NO'));
}

printLine('--- Dealer follow-up scenario ---');
$dealerHistory = [
    ['role' => 'user', 'content' => 'Find a dealer in Lilongwe'],
];
$dealerFollowUp = 'What about Salima';
$dealerRewritten = buildLocationFollowUpDealerMessage($db, $dealerFollowUp, $dealerHistory);
printLine('Dealer follow-up input: ' . $dealerFollowUp);
printLine('Dealer rewritten query: ' . ($dealerRewritten !== false ? $dealerRewritten : '[false]'));
if ($dealerRewritten !== false) {
    $dealerParams = extractDealerSearchParams($db, $dealerRewritten);
    $dealerLocationOk = strtolower((string)($dealerParams['location'] ?? '')) === 'salima';
    printLine('Dealer location resolved: ' . ($dealerLocationOk ? 'YES' : 'NO'));
}

printLine('--- General automotive follow-up scenario ---');
$generalHistory = [
    ['role' => 'user', 'content' => 'Tell me about Toyota Fortuner engine specs'],
];
$generalFollowUp = 'What about Hilux';
$generalRewritten = buildGeneralAutomotiveFollowUpMessage($generalFollowUp, $generalHistory);
printLine('General follow-up input: ' . $generalFollowUp);
printLine('General rewritten query: ' . ($generalRewritten !== false ? $generalRewritten : '[false]'));
if ($generalRewritten !== false) {
    $generalInScope = !isOutOfScopeQuery($generalRewritten);
    $generalSpecDetected = detectCarSpecQuery($generalRewritten);
    printLine('General in-scope after rewrite: ' . ($generalInScope ? 'YES' : 'NO'));
    printLine('General spec intent detected: ' . ($generalSpecDetected ? 'YES' : 'NO'));
}

printLine('--- Recommendation path scenario ---');
$recMessage = 'I am looking for an SUV in lilongwe';
$requirements = extractCarRequirements($db, $recMessage);
$recRows = searchCarsByRequirements($db, $requirements);
$recOk = empty($recRows) ? true : allLocationsMatch($recRows, 'Lilongwe');
printLine('Requirements: ' . json_encode($requirements, JSON_UNESCAPED_UNICODE));
printLine('Rows: ' . count($recRows));
printLine('Location leakage: ' . ($recOk ? 'NO' : 'YES'));

printLine('--- Randomized DB tests (10 samples) ---');
$locRows = $db->query("SELECT name FROM locations ORDER BY RAND() LIMIT 10")->fetchAll(PDO::FETCH_COLUMN);
$bodyTypes = ['SUV', 'Sedan', 'Hatchback', 'Pickup', 'Truck', 'Wagon', 'Van'];
$randomFailures = 0;
foreach ($locRows as $locName) {
    $body = $bodyTypes[array_rand($bodyTypes)];
    $msg = "I am looking for a {$body} in {$locName}";
    $result = runQueryPath($db, $msg);
    $rows = $result['rows'];
    $ok = empty($rows) ? true : allLocationsMatch($rows, (string)$locName);
    if (!$ok) {
        $randomFailures++;
    }
    printLine("Sample: {$msg} | rows=" . count($rows) . " | leakage=" . ($ok ? 'NO' : 'YES'));
}

printLine('Random test leakage failures: ' . $randomFailures);

printLine('--- Randomized follow-up conversation tests (10 samples) ---');
$followUpFailures = 0;
foreach ($locRows as $locName) {
    $baseLocation = 'Lilongwe';
    $baseMessage = 'I am looking for an SUV in ' . $baseLocation;
    $history = [
        ['role' => 'user', 'content' => $baseMessage],
    ];

    $followUpInput = 'What about ' . $locName;
    $rewritten = buildLocationFollowUpSearchMessage($db, $followUpInput, $history);

    if ($rewritten === false) {
        $followUpFailures++;
        printLine("Follow-up: {$followUpInput} | rewritten=[false] | leakage=UNKNOWN");
        continue;
    }

    $result = runQueryPath($db, $rewritten);
    $rows = $result['rows'];
    $ok = empty($rows) ? true : allLocationsMatch($rows, (string)$locName);
    if (!$ok) {
        $followUpFailures++;
    }

    printLine("Follow-up: {$followUpInput} | rewritten={$rewritten} | rows=" . count($rows) . " | leakage=" . ($ok ? 'NO' : 'YES'));
}

printLine('Follow-up test failures: ' . $followUpFailures);

$carHireFollowUpFailures = 0;
if ($carHireRewritten === false) {
    $carHireFollowUpFailures++;
} elseif (isset($carHireOk) && !$carHireOk) {
    $carHireFollowUpFailures++;
}

$carHireComparativeFailures = 0;
if ($carHireComparativeRewritten === false) {
    $carHireComparativeFailures++;
} elseif (
    (isset($comparativeInScope) && !$comparativeInScope) ||
    (isset($comparativeIsCarHire) && !$comparativeIsCarHire) ||
    (isset($isSortedCheapest) && !$isSortedCheapest)
) {
    $carHireComparativeFailures++;
}

$carHireContactFailures = 0;
if ($carHireContactRewritten === false) {
    $carHireContactFailures++;
} elseif (
    (isset($contactInScope) && !$contactInScope) ||
    (isset($contactIsCarHire) && !$contactIsCarHire) ||
    (isset($contactHasPhone) && !$contactHasPhone)
) {
    $carHireContactFailures++;
}

$garageFollowUpFailures = 0;
if ($garageRewritten === false) {
    $garageFollowUpFailures++;
} elseif (isset($garageLocationOk) && !$garageLocationOk) {
    $garageFollowUpFailures++;
}

$dealerFollowUpFailures = 0;
if ($dealerRewritten === false) {
    $dealerFollowUpFailures++;
} elseif (isset($dealerLocationOk) && !$dealerLocationOk) {
    $dealerFollowUpFailures++;
}

$generalFollowUpFailures = 0;
if ($generalRewritten === false) {
    $generalFollowUpFailures++;
} elseif ((isset($generalInScope) && !$generalInScope) || (isset($generalSpecDetected) && !$generalSpecDetected)) {
    $generalFollowUpFailures++;
}

$totalFailures = $randomFailures
    + $followUpFailures
    + $carHireFollowUpFailures
    + $carHireComparativeFailures
    + $carHireContactFailures
    + $garageFollowUpFailures
    + $dealerFollowUpFailures
    + $generalFollowUpFailures;
printLine('--- Harness Summary ---');
printLine('Total failures: ' . $totalFailures);
printLine('Status: ' . ($totalFailures === 0 ? 'PASS' : 'FAIL'));

printLine('=== End of tests ===');
