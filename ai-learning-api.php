<?php
/**
 * AI Learning API
 * Handles automatic and manual learning for ai_web_cache and ai_parts_cache tables
 */

// Don't require api-common.php - it sends headers and conflicts with admin API
// The functions in this file don't use getDB() - they receive $db as a parameter
// This allows the file to be used from both main API and admin API

// API keys are loaded from database settings for security.

function getSupportedAIProviders() {
    return ['openai', 'deepseek', 'qwen', 'glm'];
}

function normalizeAIProvider($provider, $default = 'openai') {
    $provider = strtolower(trim((string)$provider));
    return in_array($provider, getSupportedAIProviders(), true) ? $provider : $default;
}

function getAIProviderLabel($provider) {
    $labels = [
        'openai' => 'OpenAI',
        'deepseek' => 'DeepSeek',
        'qwen' => 'Qwen',
        'glm' => 'GLM'
    ];
    return $labels[$provider] ?? ucfirst($provider);
}

function isAIProviderEnabledInSettings($settings, $provider) {
    $field = $provider . '_enabled';
    return (int)($settings[$field] ?? 1) === 1;
}

function getAIProviderEndpointAndDefaultModel($provider) {
    $configs = [
        'openai' => [
            'url' => 'https://api.openai.com/v1/chat/completions',
            'default_model' => 'gpt-4o-mini'
        ],
        'deepseek' => [
            'url' => 'https://api.deepseek.com/v1/chat/completions',
            'default_model' => 'deepseek-chat'
        ],
        'qwen' => [
            'url' => 'https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions',
            'default_model' => 'qwen-plus'
        ],
        'glm' => [
            'url' => 'https://open.bigmodel.cn/api/paas/v4/chat/completions',
            'default_model' => 'glm-4-flash'
        ]
    ];

    $provider = normalizeAIProvider($provider);
    return $configs[$provider];
}

/**
 * Get AI provider settings from database
 */
function getAILearningSettings($db) {
    try {
        $stmt = $db->prepare("SELECT * FROM ai_chat_settings WHERE id = 1");
        $stmt->execute();
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$settings) {
            return [
                'openai_enabled' => 1,
                'deepseek_enabled' => 1,
                'qwen_enabled' => 1,
                'glm_enabled' => 1,
                'ai_provider' => 'openai',
                'web_cache_limit' => 20,
                'parts_cache_limit' => 500
            ];
        }

        $provider = normalizeAIProvider($settings['ai_provider'] ?? 'openai');
        
        return [
            'openai_enabled' => (int)($settings['openai_enabled'] ?? 1),
            'deepseek_enabled' => (int)($settings['deepseek_enabled'] ?? 1),
            'qwen_enabled' => (int)($settings['qwen_enabled'] ?? 1),
            'glm_enabled' => (int)($settings['glm_enabled'] ?? 1),
            'ai_provider' => $provider,
            'web_cache_limit' => (int)($settings['web_cache_daily_limit'] ?? 20),
            'parts_cache_limit' => (int)($settings['parts_cache_daily_limit'] ?? 500)
        ];
    } catch (Exception $e) {
        error_log("Error getting AI learning settings: " . $e->getMessage());
        return [
            'openai_enabled' => 1,
            'deepseek_enabled' => 1,
            'qwen_enabled' => 1,
            'glm_enabled' => 1,
            'ai_provider' => 'openai',
            'web_cache_limit' => 20,
            'parts_cache_limit' => 500
        ];
    }
}

/**
 * Get API key for the selected provider
 */
function getAPIKey($db, $provider) {
    static $cachedKeys = null;
    $provider = normalizeAIProvider($provider);

    if ($cachedKeys === null) {
        $cachedKeys = [
            'openai' => null,
            'deepseek' => null,
            'qwen' => null,
            'glm' => null
        ];

        try {
            $stmt = $db->prepare("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('openai_api_key', 'deepseek_api_key', 'qwen_api_key', 'glm_api_key')");
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                if ($row['setting_key'] === 'openai_api_key') {
                    $cachedKeys['openai'] = trim((string)$row['setting_value']);
                } elseif ($row['setting_key'] === 'deepseek_api_key') {
                    $cachedKeys['deepseek'] = trim((string)$row['setting_value']);
                } elseif ($row['setting_key'] === 'qwen_api_key') {
                    $cachedKeys['qwen'] = trim((string)$row['setting_value']);
                } elseif ($row['setting_key'] === 'glm_api_key') {
                    $cachedKeys['glm'] = trim((string)$row['setting_value']);
                }
            }
        } catch (Exception $e) {
            error_log("Failed to load AI provider keys from database: " . $e->getMessage());
        }
    }

    $key = $cachedKeys[$provider] ?? null;
    if (empty($key)) {
        error_log(getAIProviderLabel($provider) . " API key not configured in site_settings");
        return null;
    }

    return $key;
}

/**
 * Call multiple AI APIs concurrently
 */
function callAILearningAPIBatch($db, $provider, $requests, $model = null) {
    $provider = normalizeAIProvider($provider);
    $apiKey = getAPIKey($db, $provider);
    if (!$apiKey) {
        return array_fill(0, count($requests), ['error' => 'API key not configured']);
    }
    $providerConfig = getAIProviderEndpointAndDefaultModel($provider);
    $url = $providerConfig['url'];
    $defaultModel = $providerConfig['default_model'];
    
    $model = $model ?? $defaultModel;
    
    // Create multi-handle
    $mh = curl_multi_init();
    $handles = [];
    $results = [];
    
    // Create curl handles for each request
    foreach ($requests as $index => $messages) {
        $requestBody = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.7,
            'max_tokens' => 2000
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody));
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        
        $isLocalDev = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false || 
                       strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') !== false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !$isLocalDev);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, !$isLocalDev ? 2 : 0);
        
        curl_multi_add_handle($mh, $ch);
        $handles[$index] = $ch;
        $results[$index] = null;
    }
    
    // Execute all handles concurrently
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 0.1);
    } while ($running > 0);
    
    // Get results
    foreach ($handles as $index => $ch) {
        $response = curl_multi_getcontent($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_multi_remove_handle($mh, $ch);
        if (function_exists('curl_close')) {
            @curl_close($ch);
        }
        
        if ($error) {
            $results[$index] = ['error' => 'cURL error: ' . $error];
        } elseif ($httpCode !== 200) {
            $results[$index] = ['error' => 'API error: HTTP ' . $httpCode];
        } else {
            $data = json_decode($response, true);
            if (isset($data['choices'][0]['message']['content'])) {
                $results[$index] = ['content' => $data['choices'][0]['message']['content']];
            } else {
                $results[$index] = ['error' => 'Invalid API response'];
            }
        }
    }
    
    curl_multi_close($mh);
    
    return $results;
}

/**
 * Call AI API (OpenAI or DeepSeek) - Single request
 */
function callAILearningAPI($db, $provider, $messages, $model = null) {
    $provider = normalizeAIProvider($provider);
    $apiKey = getAPIKey($db, $provider);
    if (!$apiKey) {
        return ['error' => 'API key not configured'];
    }
    $providerConfig = getAIProviderEndpointAndDefaultModel($provider);
    $url = $providerConfig['url'];
    $defaultModel = $providerConfig['default_model'];
    
    $model = $model ?? $defaultModel;
    
    $requestBody = [
        'model' => $model,
        'messages' => $messages,
        'temperature' => 0.7,
        'max_tokens' => 2000
    ];
    
    // Increase execution time limit for API calls
    set_time_limit(120);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody));
    curl_setopt($ch, CURLOPT_TIMEOUT, 90); // Increase timeout to 90 seconds
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30); // Connection timeout
    
    $isLocalDev = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false || 
                   strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') !== false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !$isLocalDev);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, !$isLocalDev ? 2 : 0);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    // curl_close is deprecated in PHP 8.5+, but still safe to use
    if (function_exists('curl_close')) {
        @curl_close($ch);
    }
    
    if ($error) {
        return ['error' => 'cURL error: ' . $error];
    }
    
    if ($httpCode !== 200) {
        return ['error' => 'API error: HTTP ' . $httpCode, 'response' => $response];
    }
    
    $data = json_decode($response, true);
    if (!isset($data['choices'][0]['message']['content'])) {
        return ['error' => 'Invalid API response'];
    }
    
    return ['content' => $data['choices'][0]['message']['content']];
}

/**
 * Check if query already exists in cache (by query_hash)
 */
function queryExistsInCache($db, $tableName, $queryHash) {
    try {
        // Validate table name to prevent SQL injection
        $allowedTables = ['ai_web_cache', 'ai_parts_cache'];
        if (!in_array($tableName, $allowedTables)) {
            error_log("Invalid table name: " . $tableName);
            return false;
        }
        $stmt = $db->prepare("SELECT id FROM `{$tableName}` WHERE query_hash = ?");
        $stmt->execute([$queryHash]);
        return $stmt->fetch() !== false;
    } catch (Exception $e) {
        error_log("Error checking query existence: " . $e->getMessage());
        return false;
    }
}

/**
 * Get topics learned today
 */
function getTopicsLearnedToday($db, $tableName) {
    try {
        // Validate table name to prevent SQL injection
        $allowedTables = ['ai_web_cache', 'ai_parts_cache'];
        if (!in_array($tableName, $allowedTables)) {
            error_log("Invalid table name: " . $tableName);
            return 0;
        }
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM `{$tableName}` WHERE DATE(created_at) = CURDATE()");
        $stmt->execute();
        $result = $stmt->fetch();
        return (int)($result['count'] ?? 0);
    } catch (Exception $e) {
        error_log("Error getting topics learned today: " . $e->getMessage());
        return 0;
    }
}

/**
 * Fetch a site_settings value by key.
 */
function getLearningTelemetrySetting($db, $key, $defaultValue = null) {
    try {
        $stmt = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value !== false ? $value : $defaultValue;
    } catch (Exception $e) {
        error_log("getLearningTelemetrySetting error: " . $e->getMessage());
        return $defaultValue;
    }
}

/**
 * Upsert a site_settings value by key.
 */
function setLearningTelemetrySetting($db, $key, $value, $description = null) {
    try {
        $stmt = $db->prepare("SELECT id FROM site_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $exists = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($exists) {
            $update = $db->prepare("UPDATE site_settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = ?");
            $update->execute([(string)$value, $key]);
            return;
        }

        $insert = $db->prepare("INSERT INTO site_settings (setting_key, setting_value, setting_group, setting_type, description, is_public, created_at, updated_at) VALUES (?, ?, 'ai_learning', 'string', ?, 0, NOW(), NOW())");
        $insert->execute([$key, (string)$value, (string)($description ?? 'AI learning telemetry')]);
    } catch (Exception $e) {
        error_log("setLearningTelemetrySetting error: " . $e->getMessage());
    }
}

/**
 * Increment persistent AI learning telemetry counters.
 * Tracks both total and per-day counters in site_settings.
 */
function incrementLearningTelemetry($db, $metric) {
    $allowedMetrics = ['attempts', 'success', 'skipped_limit', 'errors'];
    if (!in_array($metric, $allowedMetrics, true)) {
        return;
    }

    try {
        $today = date('Y-m-d');
        $dateKey = 'ai_learning_telemetry_date';
        $storedDate = (string)getLearningTelemetrySetting($db, $dateKey, '');

        if ($storedDate !== $today) {
            // Reset daily counters when date changes.
            foreach ($allowedMetrics as $dailyMetric) {
                setLearningTelemetrySetting(
                    $db,
                    'ai_learning_' . $dailyMetric . '_today',
                    0,
                    'AI learning telemetry daily counter'
                );
            }
            setLearningTelemetrySetting($db, $dateKey, $today, 'AI learning telemetry date marker');
        }

        $totalKey = 'ai_learning_' . $metric . '_total';
        $todayKey = 'ai_learning_' . $metric . '_today';

        $totalValue = (int)getLearningTelemetrySetting($db, $totalKey, 0);
        $todayValue = (int)getLearningTelemetrySetting($db, $todayKey, 0);

        setLearningTelemetrySetting($db, $totalKey, $totalValue + 1, 'AI learning telemetry total counter');
        setLearningTelemetrySetting($db, $todayKey, $todayValue + 1, 'AI learning telemetry daily counter');
    } catch (Exception $e) {
        error_log("incrementLearningTelemetry error: " . $e->getMessage());
    }
}

/**
 * Read AI learning telemetry counters from site_settings.
 */
function getLearningTelemetryCounters($db) {
    $metrics = ['attempts', 'success', 'skipped_limit', 'errors'];
    $counters = [
        'date' => (string)getLearningTelemetrySetting($db, 'ai_learning_telemetry_date', date('Y-m-d')),
        'total' => [],
        'today' => []
    ];

    foreach ($metrics as $metric) {
        $counters['total'][$metric] = (int)getLearningTelemetrySetting($db, 'ai_learning_' . $metric . '_total', 0);
        $counters['today'][$metric] = (int)getLearningTelemetrySetting($db, 'ai_learning_' . $metric . '_today', 0);
    }

    return $counters;
}

/**
 * Build a deterministic summary from MotorLink car model specs.
 */
function buildIntentionalDatabaseCarSummary(array $row) {
    $make = trim((string)($row['make_name'] ?? 'Unknown Make'));
    $model = trim((string)($row['model_name'] ?? 'Unknown Model'));

    $lines = [];
    $lines[] = "MotorLink database profile for {$make} {$model}:";

    $yearStart = $row['year_start'] ?? null;
    $yearEnd = $row['year_end'] ?? null;
    if (!empty($yearStart) && !empty($yearEnd)) {
        $lines[] = "- Production years: {$yearStart} to {$yearEnd}";
    } elseif (!empty($yearStart)) {
        $lines[] = "- Production started: {$yearStart}";
    }

    if (!empty($row['body_type'])) {
        $lines[] = "- Body type: " . $row['body_type'];
    }
    if (!empty($row['engine_size_liters'])) {
        $lines[] = "- Engine size: " . $row['engine_size_liters'] . "L";
    }
    if (!empty($row['engine_cylinders'])) {
        $lines[] = "- Cylinders: " . $row['engine_cylinders'];
    }
    if (!empty($row['horsepower_hp'])) {
        $lines[] = "- Power: " . $row['horsepower_hp'] . " hp";
    }
    if (!empty($row['torque_nm'])) {
        $lines[] = "- Torque: " . $row['torque_nm'] . " Nm";
    }
    if (!empty($row['fuel_type'])) {
        $lines[] = "- Fuel type: " . $row['fuel_type'];
    }
    if (!empty($row['fuel_tank_capacity_liters'])) {
        $lines[] = "- Fuel tank capacity: " . $row['fuel_tank_capacity_liters'] . "L";
    }
    if (!empty($row['fuel_consumption_combined_l100km'])) {
        $lines[] = "- Combined fuel consumption: " . $row['fuel_consumption_combined_l100km'] . " L/100km";
    }
    if (!empty($row['transmission_type'])) {
        $lines[] = "- Transmission: " . $row['transmission_type'];
    }
    if (!empty($row['drive_type'])) {
        $lines[] = "- Drivetrain: " . $row['drive_type'];
    }
    if (!empty($row['seating_capacity'])) {
        $lines[] = "- Seating capacity: " . $row['seating_capacity'];
    }

    $lines[] = "Source: MotorLink internal car_models database.";

    return implode("\n", $lines);
}

/**
 * Build intentional query variants per model to improve retrieval coverage.
 */
function buildIntentionalDatabaseCarQueries(array $row) {
    $make = trim((string)($row['make_name'] ?? ''));
    $model = trim((string)($row['model_name'] ?? ''));
    if ($make === '' || $model === '') {
        return [];
    }

    return [
        "{$make} {$model} specifications",
        "{$make} {$model} fuel economy and engine details",
        "{$make} {$model} transmission and drivetrain"
    ];
}

/**
 * Intentionally learn from models already in MotorLink DB.
 * This seeds ai_web_cache with deterministic, database-sourced knowledge.
 */
function learnWebCacheFromDatabaseCars($db, $count = 300) {
    try {
        $target = max(1, min((int)$count, 5000));
        $modelFetchLimit = max(50, (int)ceil($target / 2));

        $sql = "
            SELECT
                mk.name AS make_name,
                cm.name AS model_name,
                cm.year_start,
                cm.year_end,
                cm.body_type,
                cm.engine_size_liters,
                cm.engine_cylinders,
                cm.horsepower_hp,
                cm.torque_nm,
                cm.fuel_type,
                cm.fuel_tank_capacity_liters,
                cm.fuel_consumption_combined_l100km,
                cm.transmission_type,
                cm.drive_type,
                cm.seating_capacity
            FROM car_models cm
            INNER JOIN car_makes mk ON cm.make_id = mk.id
            WHERE cm.is_active = 1 AND mk.is_active = 1
            ORDER BY mk.name ASC, cm.name ASC
            LIMIT {$modelFetchLimit}
        ";

        $stmt = $db->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return [
                'success' => false,
                'message' => 'No active models found in database for intentional learning',
                'learned' => 0,
                'requested' => $target
            ];
        }

        $learned = 0;
        $skippedExisting = 0;
        $errors = [];
        $learnedTopics = [];

        foreach ($rows as $row) {
            if ($learned >= $target) {
                break;
            }

            $summary = buildIntentionalDatabaseCarSummary($row);
            $queries = buildIntentionalDatabaseCarQueries($row);

            foreach ($queries as $queryText) {
                if ($learned >= $target) {
                    break 2;
                }

                incrementLearningTelemetry($db, 'attempts');

                $queryHash = hash('sha256', strtolower(trim($queryText)));
                if (queryExistsInCache($db, 'ai_web_cache', $queryHash)) {
                    $skippedExisting++;
                    continue;
                }

                try {
                    $sourcesJson = json_encode([
                        [
                            'title' => 'MotorLink Database',
                            'link' => null,
                            'snippet' => 'Structured data from MotorLink car_models'
                        ]
                    ]);

                    $insert = $db->prepare("INSERT INTO ai_web_cache (query_hash, query_text, summary, sources_json, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())");
                    $insert->execute([$queryHash, $queryText, $summary, $sourcesJson]);

                    incrementLearningTelemetry($db, 'success');
                    $learned++;
                    $learnedTopics[] = $queryText;
                } catch (Exception $e) {
                    incrementLearningTelemetry($db, 'errors');
                    $errors[] = $e->getMessage();
                }
            }
        }

        return [
            'success' => true,
            'mode' => 'database_intentional',
            'requested' => $target,
            'learned' => $learned,
            'skipped_existing' => $skippedExisting,
            'errors' => $errors,
            'learned_topics' => $learnedTopics
        ];
    } catch (Exception $e) {
        incrementLearningTelemetry($db, 'errors');
        error_log("learnWebCacheFromDatabaseCars error: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Learn topics for ai_web_cache (general car topics)
 */
function learnWebCacheTopics($db, $count = 20, $provider = 'openai') {
    try {
        $settings = getAILearningSettings($db);
        $limit = min($count, $settings['web_cache_limit']);
        $provider = $provider === 'auto' ? $settings['ai_provider'] : $provider;
        $provider = normalizeAIProvider($provider);
        
        if (!isAIProviderEnabledInSettings($settings, $provider)) {
            return ['success' => false, 'message' => getAIProviderLabel($provider) . ' is disabled in settings'];
        }
        
        $learnedToday = getTopicsLearnedToday($db, 'ai_web_cache');
        if ($learnedToday >= $settings['web_cache_limit']) {
            return ['success' => false, 'message' => "Daily limit of {$settings['web_cache_limit']} topics already reached"];
        }
        
        $remaining = $settings['web_cache_limit'] - $learnedToday;
        $toLearn = min($limit, $remaining);
        
        if ($toLearn <= 0) {
            return ['success' => false, 'message' => 'No more topics can be learned today'];
        }
        
        // Generate topics to learn
        $messages = [
            [
                'role' => 'system',
                'content' => 'You are a helpful assistant that generates educational car-related topics. Generate a list of ' . $toLearn . ' diverse and useful car-related topics that would be helpful for car owners, buyers, and enthusiasts. Each topic should be a clear question or topic title. Return only a JSON array of strings, one topic per item. Topics should cover various aspects: maintenance, specifications, repairs, buying advice, technology, safety, etc.'
            ],
            [
                'role' => 'user',
                'content' => 'Generate ' . $toLearn . ' car-related topics to learn about.'
            ]
        ];
        
        $response = callAILearningAPI($db, $provider, $messages);
        if (isset($response['error'])) {
            return ['success' => false, 'message' => $response['error']];
        }
        
        // Parse topics from response
        $content = $response['content'];
        $topics = [];
        
        // Try to extract JSON array
        if (preg_match('/\[.*\]/s', $content, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                $topics = $decoded;
            }
        }
        
        // Fallback: extract lines that look like topics
        if (empty($topics)) {
            $lines = explode("\n", $content);
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                // Remove numbering and bullet points
                $line = preg_replace('/^[\d\s\.\-\*\•]+/', '', $line);
                $line = trim($line, '"\'');
                if (!empty($line) && strlen($line) > 10) {
                    $topics[] = $line;
                }
                if (count($topics) >= $toLearn) break;
            }
        }
        
        // Filter out topics that already exist
        $topicsToLearn = [];
        $topicHashes = [];
        foreach ($topics as $topic) {
            if (count($topicsToLearn) >= $toLearn) break;
            
            $topic = trim($topic);
            if (empty($topic)) continue;
            
            $queryHash = hash('sha256', strtolower(trim($topic)));
            
            // Check if already exists
            if (queryExistsInCache($db, 'ai_web_cache', $queryHash)) {
                continue;
            }
            
            $topicsToLearn[] = $topic;
            $topicHashes[] = $queryHash;
        }
        
        if (empty($topicsToLearn)) {
            return [
                'success' => true,
                'learned' => 0,
                'requested' => $toLearn,
                'errors' => [],
                'learned_topics' => []
            ];
        }
        
        // Prepare batch requests
        $batchSize = 10; // Process 10 topics concurrently
        $learned = 0;
        $errors = [];
        $learnedTopics = [];
        
        $batches = array_chunk($topicsToLearn, $batchSize);
        $hashBatches = array_chunk($topicHashes, $batchSize);
        
        foreach ($batches as $batchIndex => $batch) {
            $hashBatch = $hashBatches[$batchIndex];
            
            // Prepare messages for batch
            $messagesBatch = [];
            foreach ($batch as $topic) {
                $messagesBatch[] = [
                    [
                        'role' => 'system',
                        'content' => 'You are a knowledgeable car expert. Provide comprehensive, accurate, and helpful information about car-related topics. Structure your response clearly with headings, bullet points, and detailed explanations.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $topic . ' Please provide detailed and comprehensive information about this topic.'
                    ]
                ];
            }
            
            // Make concurrent API calls
            $responses = callAILearningAPIBatch($db, $provider, $messagesBatch);
            
            // Process results
            foreach ($batch as $idx => $topic) {
                if ($learned >= $toLearn) break 2;
                
                $response = $responses[$idx] ?? null;
                if (!$response || isset($response['error'])) {
                    $errors[] = $response['error'] ?? 'Failed to learn topic';
                    continue;
                }
                
                $queryHash = $hashBatch[$idx];
                $summary = $response['content'];
                $sourcesJson = json_encode([['title' => 'AI Research - ' . ucfirst($provider), 'link' => null, 'snippet' => 'AI-generated content']]);
                
                // Insert into database
                try {
                    $stmt = $db->prepare("
                        INSERT INTO ai_web_cache (query_hash, query_text, summary, sources_json, created_at, updated_at)
                        VALUES (?, ?, ?, ?, NOW(), NOW())
                    ");
                    $stmt->execute([$queryHash, $topic, $summary, $sourcesJson]);
                    
                    $learned++;
                    $learnedTopics[] = $topic;
                } catch (Exception $e) {
                    error_log("Error inserting web cache topic: " . $e->getMessage());
                    $errors[] = 'Database error: ' . $e->getMessage();
                }
            }
        }
        
        return [
            'success' => true,
            'learned' => $learned,
            'requested' => $toLearn,
            'errors' => $errors,
            'learned_topics' => $learnedTopics
        ];
        
    } catch (Exception $e) {
        error_log("Error learning web cache topics: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Learn a single web topic
 */
function learnSingleWebTopic($db, $topic, $provider = 'openai') {
    try {
        $queryHash = hash('sha256', strtolower(trim($topic)));
        
        // Check if already exists
        if (queryExistsInCache($db, 'ai_web_cache', $queryHash)) {
            return ['success' => false, 'message' => 'Topic already exists in cache'];
        }
        
        // Generate comprehensive answer
        $messages = [
            [
                'role' => 'system',
                'content' => 'You are a knowledgeable car expert. Provide comprehensive, accurate, and helpful information about car-related topics. Structure your response clearly with headings, bullet points, and detailed explanations.'
            ],
            [
                'role' => 'user',
                'content' => $topic . ' Please provide detailed and comprehensive information about this topic.'
            ]
        ];
        
        $response = callAILearningAPI($db, $provider, $messages);
        if (isset($response['error'])) {
            return ['success' => false, 'message' => $response['error']];
        }
        
        $summary = $response['content'];
        $sourcesJson = json_encode([['title' => 'AI Research - ' . ucfirst($provider), 'link' => null, 'snippet' => 'AI-generated content']]);
        
        // Insert into database
        $stmt = $db->prepare("
            INSERT INTO ai_web_cache (query_hash, query_text, summary, sources_json, created_at, updated_at)
            VALUES (?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([$queryHash, $topic, $summary, $sourcesJson]);
        
        return ['success' => true, 'id' => $db->lastInsertId()];
        
    } catch (Exception $e) {
        error_log("Error learning single web topic: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Learn topics for ai_parts_cache (car parts and part numbers)
 */
function learnPartsCacheTopics($db, $count = 500, $provider = 'openai') {
    try {
        $settings = getAILearningSettings($db);
        $limit = min($count, $settings['parts_cache_limit']);
        $provider = $provider === 'auto' ? $settings['ai_provider'] : $provider;
        $provider = normalizeAIProvider($provider);
        
        if (!isAIProviderEnabledInSettings($settings, $provider)) {
            return ['success' => false, 'message' => getAIProviderLabel($provider) . ' is disabled in settings'];
        }
        
        $learnedToday = getTopicsLearnedToday($db, 'ai_parts_cache');
        if ($learnedToday >= $settings['parts_cache_limit']) {
            return ['success' => false, 'message' => "Daily limit of {$settings['parts_cache_limit']} parts already reached"];
        }
        
        $remaining = $settings['parts_cache_limit'] - $learnedToday;
        $toLearn = min($limit, $remaining);
        
        if ($toLearn <= 0) {
            return ['success' => false, 'message' => 'No more parts can be learned today'];
        }
        
        // Get makes and models from database to prioritize
        $dbMakes = [];
        $dbModels = [];
        try {
            $stmt = $db->query("SELECT DISTINCT name FROM car_makes WHERE is_active = 1 ORDER BY name");
            $makes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($makes as $make) {
                $dbMakes[] = strtolower($make['name']);
            }
            
            $stmt = $db->query("
                SELECT DISTINCT mk.name as make_name, cm.name as model_name 
                FROM car_models cm 
                INNER JOIN car_makes mk ON cm.make_id = mk.id 
                WHERE cm.is_active = 1 AND mk.is_active = 1 
                ORDER BY mk.name, cm.name
                LIMIT 100
            ");
            $models = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($models as $model) {
                $dbModels[] = [
                    'make' => strtolower($model['make_name']),
                    'model' => strtolower($model['model_name'])
                ];
            }
        } catch (Exception $e) {
            error_log("Error fetching makes/models for prioritization: " . $e->getMessage());
        }
        
        // Build make/model list for AI prompt
        $makeModelList = '';
        if (!empty($dbMakes)) {
            $makeModelList = "\n\nPRIORITY: Focus on these makes and models from our database first:\n";
            $makeModelList .= "Makes: " . implode(', ', array_slice($dbMakes, 0, 20)) . "\n";
            if (count($dbModels) > 0) {
                $makeModelList .= "Example combinations: ";
                $examples = [];
                foreach (array_slice($dbModels, 0, 10) as $model) {
                    $examples[] = $model['make'] . ' ' . $model['model'];
                }
                $makeModelList .= implode(', ', $examples);
            }
        }
        
        // Generate parts to learn about
        $messages = [
            [
                'role' => 'system',
                'content' => 'You are a helpful assistant that generates car parts information requests. Generate a list of ' . $toLearn . ' diverse car parts with make/model combinations that would be useful for car owners and mechanics. Each item should be a query about a specific car part for a specific make/model (e.g., "Toyota Corolla 2020 brake pads part number", "Honda Civic 2018 water pump compatibility"). Return only a JSON array of strings, one query per item. Focus on common parts: brake pads, filters, belts, pumps, sensors, etc.' . $makeModelList
            ],
            [
                'role' => 'user',
                'content' => 'Generate ' . $toLearn . ' car parts queries to learn about. ' . (!empty($dbMakes) ? 'Prioritize makes and models from our database: ' . implode(', ', array_slice($dbMakes, 0, 15)) : '')
            ]
        ];
        
        $response = callAILearningAPI($db, $provider, $messages);
        if (isset($response['error'])) {
            return ['success' => false, 'message' => $response['error']];
        }
        
        // Parse queries from response
        $content = $response['content'];
        $queries = [];
        
        // Try to extract JSON array
        if (preg_match('/\[.*\]/s', $content, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                $queries = $decoded;
            }
        }
        
        // Fallback: extract lines
        if (empty($queries)) {
            $lines = explode("\n", $content);
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                $line = preg_replace('/^[\d\s\.\-\*\•]+/', '', $line);
                $line = trim($line, '"\'');
                if (!empty($line) && strlen($line) > 10) {
                    $queries[] = $line;
                }
                if (count($queries) >= $toLearn) break;
            }
        }
        
        // Prioritize queries that match database makes/models
        $prioritizedQueries = [];
        $otherQueries = [];
        
        foreach ($queries as $query) {
            $queryLower = strtolower(trim($query));
            $isPrioritized = false;
            
            // Check if query contains a make from our database
            foreach ($dbMakes as $make) {
                if (strpos($queryLower, $make) !== false) {
                    $prioritizedQueries[] = $query;
                    $isPrioritized = true;
                    break;
                }
            }
            
            if (!$isPrioritized) {
                $otherQueries[] = $query;
            }
        }
        
        // Combine: prioritized first, then others
        $queries = array_merge($prioritizedQueries, $otherQueries);
        
        // Filter out queries that already exist
        $queriesToLearn = [];
        $queryHashes = [];
        foreach ($queries as $query) {
            if (count($queriesToLearn) >= $toLearn) break;
            
            $query = trim($query);
            if (empty($query)) continue;
            
            $queryHash = hash('sha256', strtolower(trim($query)));
            
            // Check if already exists
            if (queryExistsInCache($db, 'ai_parts_cache', $queryHash)) {
                continue;
            }
            
            $queriesToLearn[] = $query;
            $queryHashes[] = $queryHash;
        }
        
        if (empty($queriesToLearn)) {
            return [
                'success' => true,
                'learned' => 0,
                'requested' => $toLearn,
                'errors' => [],
                'learned_parts' => []
            ];
        }
        
        // Process in concurrent batches
        $batchSize = 10; // Process 10 parts concurrently
        $learned = 0;
        $errors = [];
        $learnedParts = [];
        
        $batches = array_chunk($queriesToLearn, $batchSize);
        $hashBatches = array_chunk($queryHashes, $batchSize);
        
        foreach ($batches as $batchIndex => $batch) {
            $hashBatch = $hashBatches[$batchIndex];
            
            // Prepare messages for batch
            $messagesBatch = [];
            foreach ($batch as $query) {
                $messagesBatch[] = [
                    [
                        'role' => 'system',
                        'content' => 'You are a knowledgeable car parts expert. Provide comprehensive information about car parts. IMPORTANT: You must include the OEM part number and price (in USD) for the part. Return the information in JSON format with these fields: part_name, part_number (OEM number), oem_number (same as part_number), price_usd (price in US dollars as a number), description, compatibility, specifications, cross_reference (array of alternative part numbers if available). If price is not available, set price_usd to null.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $query . ' Please provide detailed information including the OEM part number and price in USD. Return the response as a valid JSON object with fields: part_name, part_number, oem_number, price_usd, description, compatibility, specifications, cross_reference.'
                    ]
                ];
            }
            
            // Make concurrent API calls
            $responses = callAILearningAPIBatch($db, $provider, $messagesBatch);
            
            // Process results
            foreach ($batch as $idx => $query) {
                if ($learned >= $toLearn) break 2;
                
                $response = $responses[$idx] ?? null;
                if (!$response || isset($response['error'])) {
                    $errors[] = $response['error'] ?? 'Failed to learn part';
                    continue;
                }
                
                $queryHash = $hashBatch[$idx];
                $content = $response['content'];
                $sourcesJson = json_encode([['title' => 'AI Research - ' . ucfirst($provider), 'link' => null, 'snippet' => 'AI-generated parts research']]);
                
                // Extract make/model/part from query
                preg_match('/(\w+)\s+(\w+)\s+(\d{4})?\s*(.*)/i', $query, $matches);
                $makeName = $matches[1] ?? null;
                $modelName = $matches[2] ?? null;
                $year = !empty($matches[3]) ? (int)$matches[3] : null;
                $partQuery = $matches[4] ?? $query;
                $partName = trim($partQuery);
                if (empty($partName)) {
                    $partName = 'Part';
                }
                
                // Parse JSON response from AI to extract part number and price
                $partData = null;
                $partNumber = null;
                $oemNumber = null;
                $priceUsd = null;
                $description = null;
                $compatibility = null;
                $specifications = null;
                $crossReference = null;
                
                // Try to extract JSON from response
                if (preg_match('/\{.*\}/s', $content, $jsonMatches)) {
                    $partData = json_decode($jsonMatches[0], true);
                } else {
                    // Try parsing the entire content as JSON
                    $partData = json_decode($content, true);
                }
                
                if (is_array($partData)) {
                    $partNumber = $partData['part_number'] ?? $partData['oem_number'] ?? null;
                    $oemNumber = $partData['oem_number'] ?? $partData['part_number'] ?? null;
                    $priceUsd = isset($partData['price_usd']) ? (float)$partData['price_usd'] : null;
                    $description = $partData['description'] ?? null;
                    $compatibility = is_array($partData['compatibility'] ?? null) ? json_encode($partData['compatibility']) : ($partData['compatibility'] ?? null);
                    $specifications = is_array($partData['specifications'] ?? null) ? json_encode($partData['specifications']) : ($partData['specifications'] ?? null);
                    $crossReference = is_array($partData['cross_reference'] ?? null) ? json_encode($partData['cross_reference']) : null;
                    
                    // Update part_name if provided in JSON
                    if (!empty($partData['part_name'])) {
                        $partName = $partData['part_name'];
                    }
                }
                
                // If JSON parsing failed, try to extract part number and price from text
                if (empty($partNumber)) {
                    // Look for patterns like "Part Number: ABC123" or "OEM: XYZ789"
                    if (preg_match('/part\s*number[:\s]+([A-Z0-9\-]+)/i', $content, $pnMatch)) {
                        $partNumber = trim($pnMatch[1]);
                        $oemNumber = $partNumber;
                    } elseif (preg_match('/oem[:\s]+([A-Z0-9\-]+)/i', $content, $oemMatch)) {
                        $oemNumber = trim($oemMatch[1]);
                        $partNumber = $oemNumber;
                    }
                }
                
                if ($priceUsd === null) {
                    // Look for price patterns like "$29.99" or "Price: $45.50 USD"
                    if (preg_match('/\$(\d+\.?\d*)/', $content, $priceMatch)) {
                        $priceUsd = (float)$priceMatch[1];
                    } elseif (preg_match('/price[:\s]+\$?(\d+\.?\d*)/i', $content, $priceMatch2)) {
                        $priceUsd = (float)$priceMatch2[1];
                    }
                }
                
                // Use content as summary/description if no structured data
                $summary = $content;
                if (!empty($description)) {
                    $summary = $description;
                }
                
                // Insert into database
                try {
                    $stmt = $db->prepare("
                        INSERT INTO ai_parts_cache (
                            make_name, model_name, year, part_name, part_number, oem_number, price_usd,
                            description, compatibility, specifications, cross_reference,
                            query_hash, summary, sources_json,
                            created_at, updated_at
                        )
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                    ");
                    $stmt->execute([
                        $makeName,
                        $modelName,
                        $year,
                        $partName,
                        $partNumber,
                        $oemNumber,
                        $priceUsd,
                        $description,
                        $compatibility,
                        $specifications,
                        $crossReference,
                        $queryHash,
                        $summary,
                        $sourcesJson
                    ]);
                    
                    $learned++;
                    $learnedParts[] = $query;
                } catch (Exception $e) {
                    error_log("Error inserting parts cache: " . $e->getMessage());
                    $errors[] = 'Database error: ' . $e->getMessage();
                }
            }
        }
        
        return [
            'success' => true,
            'learned' => $learned,
            'requested' => $toLearn,
            'errors' => $errors,
            'learned_parts' => $learnedParts
        ];
        
    } catch (Exception $e) {
        error_log("Error learning parts cache topics: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Learn a single parts topic
 */
function learnSinglePartTopic($db, $query, $provider = 'openai') {
    try {
        $queryHash = hash('sha256', strtolower(trim($query)));
        
        // Check if already exists
        if (queryExistsInCache($db, 'ai_parts_cache', $queryHash)) {
            return ['success' => false, 'message' => 'Part query already exists in cache'];
        }
        
        // Extract make/model/part from query
        preg_match('/(\w+)\s+(\w+)\s+(\d{4})?\s*(.*)/i', $query, $matches);
        $makeName = $matches[1] ?? null;
        $modelName = $matches[2] ?? null;
        $year = !empty($matches[3]) ? (int)$matches[3] : null;
        $partQuery = $matches[4] ?? $query;
        
        // Generate comprehensive parts information
        $messages = [
            [
                'role' => 'system',
                'content' => 'You are a knowledgeable car parts expert. Provide comprehensive information about car parts including OEM part numbers, compatibility, specifications, cross-reference numbers, prices, and installation notes. Structure your response in a clear format with sections for part numbers, compatibility, specifications, and other relevant details.'
            ],
            [
                'role' => 'user',
                'content' => $query . ' Please provide detailed information including OEM part numbers, compatibility, specifications, cross-reference numbers, and any other relevant details.'
            ]
        ];
        
        $response = callAILearningAPI($db, $provider, $messages);
        if (isset($response['error'])) {
            return ['success' => false, 'message' => $response['error']];
        }
        
        $summary = $response['content'];
        $sourcesJson = json_encode([['title' => 'AI Research - ' . ucfirst($provider), 'link' => null, 'snippet' => 'AI-generated parts research']]);
        
        // Extract part name from query (first word after make/model/year)
        $partName = trim($partQuery);
        if (empty($partName)) {
            $partName = 'Part';
        }
        
        // Insert into database
        $stmt = $db->prepare("
            INSERT INTO ai_parts_cache (
                make_name, model_name, year, part_name, query_hash, summary, sources_json,
                created_at, updated_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([
            $makeName,
            $modelName,
            $year,
            $partName,
            $queryHash,
            $summary,
            $sourcesJson
        ]);
        
        return ['success' => true, 'id' => $db->lastInsertId()];
        
    } catch (Exception $e) {
        error_log("Error learning single part topic: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Learn from user query (called from ai-car-chat-api.php)
 */
function learnFromUserQuery($db, $query, $isPartsQuery = false, $provider = 'auto') {
    try {
        incrementLearningTelemetry($db, 'attempts');

        $settings = getAILearningSettings($db);
        $provider = $provider === 'auto' ? $settings['ai_provider'] : $provider;
        $provider = normalizeAIProvider($provider);
        
        if (!isAIProviderEnabledInSettings($settings, $provider)) {
            return ['success' => false, 'message' => getAIProviderLabel($provider) . ' is disabled'];
        }
        
        if ($isPartsQuery) {
            // Check daily limit
            $learnedToday = getTopicsLearnedToday($db, 'ai_parts_cache');
            if ($learnedToday >= $settings['parts_cache_limit']) {
                incrementLearningTelemetry($db, 'skipped_limit');
                return ['success' => false, 'message' => 'Daily parts learning limit reached'];
            }

            $result = learnSinglePartTopic($db, $query, $provider);
            if (!empty($result['success'])) {
                incrementLearningTelemetry($db, 'success');
            }
            return $result;
        } else {
            // Check daily limit
            $learnedToday = getTopicsLearnedToday($db, 'ai_web_cache');
            if ($learnedToday >= $settings['web_cache_limit']) {
                incrementLearningTelemetry($db, 'skipped_limit');
                return ['success' => false, 'message' => 'Daily web learning limit reached'];
            }

            $result = learnSingleWebTopic($db, $query, $provider);
            if (!empty($result['success'])) {
                incrementLearningTelemetry($db, 'success');
            }
            return $result;
        }
        
    } catch (Exception $e) {
        incrementLearningTelemetry($db, 'errors');
        error_log("Error learning from user query: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}
