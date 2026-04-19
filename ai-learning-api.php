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

    return $labels[$provider] ?? ucfirst((string)$provider);
}

function getAIProviderEndpointAndDefaultModel($provider) {
    $provider = normalizeAIProvider($provider);
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
            'default_model' => 'glm-4.7-flash'
        ]
    ];

    return $configs[$provider];
}

function normalizeAILearningModelName($provider, $modelName, $fallbackModel = '') {
    $provider = normalizeAIProvider($provider);
    $model = strtolower(trim((string)$modelName));

    if ($model === '') {
        return $fallbackModel !== '' ? $fallbackModel : getAIProviderEndpointAndDefaultModel($provider)['default_model'];
    }

    $aliases = [
        'glm' => [
            'glm-4-flash' => 'glm-4.7-flash',
            'glm4flash' => 'glm-4.7-flash',
            'glm-flash' => 'glm-4.7-flash',
            'glm-4-7-flash' => 'glm-4.7-flash',
            'glm4.7flash' => 'glm-4.7-flash',
            'glm 4.7 flash' => 'glm-4.7-flash',
            'glm5.5-turbo' => 'glm-5.5-turbo',
            'glm-5-5-turbo' => 'glm-5.5-turbo',
            'glm 5.5 turbo' => 'glm-5.5-turbo',
            'glm5.1' => 'glm-5.1',
            'glm-5-1' => 'glm-5.1',
            'glm 5.1' => 'glm-5.1'
        ]
    ];

    if (isset($aliases[$provider][$model])) {
        return $aliases[$provider][$model];
    }

    if ($provider === 'glm' && strpos($model, 'glm-') === 0) {
        return $model;
    }

    if ($provider === 'openai' && preg_match('/^(gpt-|o\d)/', $model)) {
        return $model;
    }

    if ($provider === 'deepseek' && strpos($model, 'deepseek-') === 0) {
        return $model;
    }

    if ($provider === 'qwen' && strpos($model, 'qwen-') === 0) {
        return $model;
    }

    return $fallbackModel !== '' ? $fallbackModel : getAIProviderEndpointAndDefaultModel($provider)['default_model'];
}

function getAILearningProviderFallbackModels($provider, $currentModel = '') {
    $provider = normalizeAIProvider($provider);
    $currentModel = strtolower(trim((string)$currentModel));

    $fallbacks = [
        'openai' => ['gpt-4o-mini'],
        'deepseek' => ['deepseek-chat'],
        'qwen' => ['qwen-plus', 'qwen-turbo'],
        'glm' => ['glm-4.7-flash', 'glm-4.7', 'glm-5.1']
    ];

    return array_values(array_filter($fallbacks[$provider] ?? [], function ($candidate) use ($currentModel) {
        $candidate = strtolower(trim((string)$candidate));
        return $candidate !== '' && $candidate !== $currentModel;
    }));
}

function extractAILearningProviderError($provider, $responseBody, $httpCode) {
    $provider = normalizeAIProvider($provider);
    $label = getAIProviderLabel($provider);
    $fallbackMessage = $label . ' API error: HTTP ' . (int)$httpCode;

    if (!is_string($responseBody) || trim($responseBody) === '') {
        return $fallbackMessage;
    }

    $decoded = json_decode($responseBody, true);
    if (!is_array($decoded)) {
        return $fallbackMessage;
    }

    $error = $decoded['error'] ?? [];
    if (is_array($error)) {
        $message = trim((string)($error['message'] ?? ''));
        $type = trim((string)($error['type'] ?? ''));
        $code = trim((string)($error['code'] ?? ''));

        if ($message !== '') {
            $details = array_values(array_filter([$type, $code], function ($value) {
                return $value !== '';
            }));
            return $label . ' API error: ' . $message . (!empty($details) ? ' (' . implode(', ', $details) . ')' : '');
        }
    } elseif (is_string($error) && trim($error) !== '') {
        return $label . ' API error: ' . trim($error);
    }

    $message = trim((string)($decoded['message'] ?? ''));
    if ($message !== '') {
        return $label . ' API error: ' . $message;
    }

    return $fallbackMessage;
}

function isAILearningMissingModelError($provider, $httpCode, $responseBody) {
    if ((int)$httpCode !== 400 || !is_string($responseBody) || trim($responseBody) === '') {
        return false;
    }

    $decoded = json_decode($responseBody, true);
    if (!is_array($decoded)) {
        return false;
    }

    $error = $decoded['error'] ?? [];
    $message = '';
    $code = '';

    if (is_array($error)) {
        $message = strtolower(trim((string)($error['message'] ?? '')));
        $code = strtolower(trim((string)($error['code'] ?? '')));
    } elseif (is_string($error)) {
        $message = strtolower(trim($error));
    }

    if ($message === '' && isset($decoded['message'])) {
        $message = strtolower(trim((string)$decoded['message']));
    }

    return strpos($message, 'model') !== false && (
        strpos($message, 'not found') !== false ||
        strpos($message, 'does not exist') !== false ||
        strpos($message, 'not exist') !== false ||
        strpos($message, '模型不存在') !== false ||
        $code === '1211' ||
        $code === 'model_not_found' ||
        $code === 'invalid_model'
    );
}

function buildAILearningRequestBody($provider, $model, $messages) {
    $provider = normalizeAIProvider($provider);
    $requestBody = [
        'model' => $model,
        'messages' => $messages,
        'temperature' => 0.7,
        'max_tokens' => 2000
    ];

    if ($provider === 'glm' && preg_match('/^glm-(?:4(?:\.7)?-)?flash(?:x)?(?:$|[-._:])/', $model) !== 1 && preg_match('/^glm-(4\.5|4\.6|4\.7|5\.1)(?:$|[-._:])/', $model) === 1) {
        $requestBody['thinking'] = ['type' => 'disabled'];
    }

    return $requestBody;
}

function resolveEffectiveAILearningProvider(array $settings, $requestedProvider = 'auto') {
    $requestedProvider = strtolower(trim((string)$requestedProvider));
    if ($requestedProvider !== '' && $requestedProvider !== 'auto') {
        return normalizeAIProvider($requestedProvider);
    }

    $learningProvider = strtolower(trim((string)($settings['ai_provider'] ?? 'auto')));
    if ($learningProvider !== '' && $learningProvider !== 'auto') {
        return normalizeAIProvider($learningProvider);
    }

    return normalizeAIProvider($settings['chat_ai_provider'] ?? 'openai');
}

function getLearningPriorityModelsFromDatabase($db, $limit = 100) {
    $models = [];

    try {
        $limit = max(1, min((int)$limit, 500));
        $stmt = $db->query("\n            SELECT DISTINCT mk.name AS make_name, cm.name AS model_name\n            FROM car_models cm\n            INNER JOIN car_makes mk ON cm.make_id = mk.id\n            WHERE cm.is_active = 1 AND mk.is_active = 1\n            ORDER BY mk.name, cm.name\n            LIMIT {$limit}\n        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $makeName = trim((string)($row['make_name'] ?? ''));
            $modelName = trim((string)($row['model_name'] ?? ''));
            if ($makeName === '' || $modelName === '') {
                continue;
            }

            $models[] = [
                'make' => $makeName,
                'model' => $modelName
            ];
        }
    } catch (Exception $e) {
        error_log('getLearningPriorityModelsFromDatabase error: ' . $e->getMessage());
    }

    return $models;
}

function buildIntentionalPartsQueriesFromDatabase(array $models, $limit = 500) {
    $partTemplates = [
        'brake pads part number',
        'brake disc OEM number',
        'brake shoes part number',
        'oil filter part number',
        'air filter part number',
        'cabin air filter part number',
        'spark plugs OEM number',
        'serpentine belt part number',
        'timing belt kit part number',
        'water pump OEM number',
        'fuel pump OEM number',
        'fuel filter part number',
        'radiator hose OEM number',
        'radiator fan motor part number',
        'thermostat housing OEM number',
        'wheel bearing part number',
        'wheel hub bearing part number',
        'shock absorber OEM number',
        'control arm OEM number',
        'ball joint part number',
        'cv joint OEM number',
        'alternator OEM number',
        'starter motor OEM number',
        'oxygen sensor OEM number',
        'engine mount OEM number',
        'clutch kit part number',
        'headlight bulb specification'
    ];

    $queries = [];
    $seen = [];
    $limit = max(1, min((int)$limit, 5000));

    foreach ($models as $row) {
        $makeName = trim((string)($row['make'] ?? ''));
        $modelName = trim((string)($row['model'] ?? ''));
        if ($makeName === '' || $modelName === '') {
            continue;
        }

        foreach ($partTemplates as $template) {
            $query = trim($makeName . ' ' . $modelName . ' ' . $template);
            $queryKey = strtolower($query);
            if (isset($seen[$queryKey])) {
                continue;
            }

            $seen[$queryKey] = true;
            $queries[] = $query;

            if (count($queries) >= $limit) {
                return $queries;
            }
        }
    }

    return $queries;
}

function executeAILearningAPIRequest($provider, $url, $apiKey, $messages, $model) {
    $requestBody = buildAILearningRequestBody($provider, $model, $messages);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody));
    curl_setopt($ch, CURLOPT_TIMEOUT, 90);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);

    $isLocalDev = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false ||
                   strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') !== false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !$isLocalDev);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, !$isLocalDev ? 2 : 0);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    if (function_exists('curl_close')) {
        @curl_close($ch);
    }

    return [
        'response' => $response,
        'http_code' => (int)$httpCode,
        'curl_error' => $error,
        'model' => $model
    ];
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
                'ai_provider' => 'glm',
                'chat_ai_provider' => 'glm',
                'web_cache_limit' => 20,
                'parts_cache_limit' => 500
            ];
        }

        $learningProvider = strtolower(trim((string)($settings['learning_ai_provider'] ?? 'auto')));
        if (!in_array($learningProvider, array_merge(['auto'], getSupportedAIProviders()), true)) {
            $learningProvider = 'auto';
        }
        $chatProvider = normalizeAIProvider($settings['ai_provider'] ?? 'openai');
        
        return [
            'openai_enabled' => (int)($settings['openai_enabled'] ?? 1),
            'deepseek_enabled' => (int)($settings['deepseek_enabled'] ?? 1),
            'qwen_enabled' => (int)($settings['qwen_enabled'] ?? 1),
            'glm_enabled' => (int)($settings['glm_enabled'] ?? 1),
            'ai_provider' => $learningProvider,
            'chat_ai_provider' => $chatProvider,
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
            'ai_provider' => 'glm',
            'chat_ai_provider' => 'glm',
            'web_cache_limit' => 20,
            'parts_cache_limit' => 500
        ];
    }
}

function isAIProviderEnabledInSettings(array $settings, $provider) {
    $provider = normalizeAIProvider($provider);
    return !empty($settings[$provider . '_enabled']);
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
    
    $model = normalizeAILearningModelName($provider, $model ?? $defaultModel, $defaultModel);
    $candidateModels = array_merge([$model], getAILearningProviderFallbackModels($provider, $model));

    foreach ($candidateModels as $candidateModel) {
        $mh = curl_multi_init();
        $handles = [];
        $results = [];
        $shouldRetryWithFallback = false;

        foreach ($requests as $index => $messages) {
            $requestBody = buildAILearningRequestBody($provider, $candidateModel, $messages);

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

        $running = null;
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh, 0.1);
        } while ($running > 0);

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
                if (isAILearningMissingModelError($provider, $httpCode, $response)) {
                    $shouldRetryWithFallback = true;
                }
                $results[$index] = ['error' => extractAILearningProviderError($provider, $response, $httpCode)];
            } else {
                $data = json_decode($response, true);
                if (isset($data['choices'][0]['message']['content'])) {
                    $results[$index] = [
                        'content' => $data['choices'][0]['message']['content'],
                        'model_used' => $candidateModel
                    ];
                } else {
                    $results[$index] = ['error' => 'Invalid API response'];
                }
            }
        }

        curl_multi_close($mh);

        if (!$shouldRetryWithFallback) {
            return $results;
        }
    }

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
    
    $model = normalizeAILearningModelName($provider, $model ?? $defaultModel, $defaultModel);
    $candidateModels = array_merge([$model], getAILearningProviderFallbackModels($provider, $model));
    
    // Increase execution time limit for API calls
    set_time_limit(120);

    foreach ($candidateModels as $candidateModel) {
        $result = executeAILearningAPIRequest($provider, $url, $apiKey, $messages, $candidateModel);

        if (!empty($result['curl_error'])) {
            return ['error' => 'cURL error: ' . $result['curl_error']];
        }

        if ((int)$result['http_code'] !== 200) {
            if (isAILearningMissingModelError($provider, $result['http_code'], $result['response'])) {
                continue;
            }

            return [
                'error' => extractAILearningProviderError($provider, $result['response'], $result['http_code']),
                'response' => $result['response']
            ];
        }

        $data = json_decode($result['response'], true);
        if (!isset($data['choices'][0]['message']['content'])) {
            return ['error' => 'Invalid API response'];
        }

        return [
            'content' => $data['choices'][0]['message']['content'],
            'model_used' => $candidateModel
        ];
    }

    return ['error' => getAIProviderLabel($provider) . ' API error: no supported model was accepted for learning requests'];
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
        $provider = resolveEffectiveAILearningProvider($settings, $provider);
        
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

        $learned = 0;
        $errors = [];
        $learnedTopics = [];

        $databaseSeedTarget = min($toLearn, max(10, (int)ceil($toLearn * 0.6)));
        $databaseSeedResult = learnWebCacheFromDatabaseCars($db, $databaseSeedTarget);
        if (!empty($databaseSeedResult['success'])) {
            $learned += (int)($databaseSeedResult['learned'] ?? 0);
            $learnedTopics = array_merge($learnedTopics, $databaseSeedResult['learned_topics'] ?? []);
            if (!empty($databaseSeedResult['errors'])) {
                $errors = array_merge($errors, $databaseSeedResult['errors']);
            }
        } elseif (!empty($databaseSeedResult['message'])) {
            $errors[] = $databaseSeedResult['message'];
        }

        $remainingToLearn = max(0, $toLearn - $learned);
        if ($remainingToLearn <= 0) {
            return [
                'success' => true,
                'mode' => 'database_intentional',
                'learned' => $learned,
                'requested' => $toLearn,
                'errors' => $errors,
                'learned_topics' => $learnedTopics
            ];
        }
        
        // Generate topics to learn
        $messages = [
            [
                'role' => 'system',
                'content' => 'You are a helpful assistant that generates educational car-related topics. Generate a list of ' . $remainingToLearn . ' diverse and useful car-related topics that would be helpful for car owners, buyers, and enthusiasts. Each topic should be a clear question or topic title. Return only a JSON array of strings, one topic per item. Topics should cover various aspects: maintenance, specifications, repairs, buying advice, technology, safety, etc. Avoid duplicate manufacturer specification topics because those are already being seeded directly from the MotorLink database.'
            ],
            [
                'role' => 'user',
                'content' => 'Generate ' . $remainingToLearn . ' car-related topics to learn about. Focus on maintenance, ownership, repairs, diagnostics, and safety topics that complement manufacturer specifications.'
            ]
        ];
        
        $response = callAILearningAPI($db, $provider, $messages);
        if (isset($response['error'])) {
            if ($learned > 0) {
                $errors[] = $response['error'];
                return [
                    'success' => true,
                    'mode' => 'database_intentional_partial',
                    'learned' => $learned,
                    'requested' => $toLearn,
                    'errors' => $errors,
                    'learned_topics' => $learnedTopics,
                    'message' => 'Deterministic database learning completed, but AI topic expansion failed: ' . $response['error']
                ];
            }

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
                if (count($topics) >= $remainingToLearn) break;
            }
        }
        
        // Filter out topics that already exist
        $topicsToLearn = [];
        $topicHashes = [];
        foreach ($topics as $topic) {
            if (count($topicsToLearn) >= $remainingToLearn) break;
            
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
                'learned' => $learned,
                'requested' => $toLearn,
                'errors' => $errors,
                'learned_topics' => $learnedTopics
            ];
        }
        
        // Prepare batch requests
        $batchSize = 10; // Process 10 topics concurrently
        
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

function detectVehicleSpecLearningTopic($topic) {
    $topicLower = strtolower(trim((string)$topic));
    if ($topicLower === '') {
        return false;
    }

    $specKeywords = [
        'spec', 'specs', 'specification', 'specifications', 'technical details',
        'engine', 'horsepower', 'power', 'torque', 'fuel economy', 'fuel consumption',
        'dimensions', 'length', 'width', 'height', 'wheelbase', 'ground clearance',
        'transmission', 'gearbox', 'drivetrain', 'drive type', 'seating capacity', 'boot space'
    ];

    foreach ($specKeywords as $keyword) {
        if (strpos($topicLower, $keyword) !== false) {
            return true;
        }
    }

    return preg_match('/\b(19|20)\d{2}\b/', $topicLower) === 1;
}

function buildSingleWebLearningMessages($topic) {
    if (detectVehicleSpecLearningTopic($topic)) {
        return [
            [
                'role' => 'system',
                'content' => 'You are a careful automotive research assistant. Provide a structured vehicle specification summary for information that may not exist in the local database. Use only stable, widely known public-source knowledge patterns. If a figure depends on trim, engine, market, or year, say so clearly instead of inventing certainty. Structure the answer with headings such as Overview, Powertrain, Efficiency, Dimensions and Capacity, and Ownership Notes.'
            ],
            [
                'role' => 'user',
                'content' => $topic . ' Please provide a concise but comprehensive vehicle specification summary, highlight where specs may vary by trim or market, and keep the wording reusable for a cached knowledge base.'
            ]
        ];
    }

    return [
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

function extractLearningVehicleContextFromQuery($db, $query) {
    $result = ['make' => null, 'model' => null, 'year' => null];
    $query = trim((string)$query);
    if ($query === '') {
        return $result;
    }

    $queryLower = strtolower($query);

    try {
        $makeStmt = $db->query("SELECT name FROM car_makes WHERE is_active = 1 ORDER BY CHAR_LENGTH(name) DESC, name ASC");
        $makes = $makeStmt ? $makeStmt->fetchAll(PDO::FETCH_COLUMN) : [];

        foreach ($makes as $make) {
            $make = trim((string)$make);
            if ($make !== '' && preg_match('/\\b' . preg_quote(strtolower($make), '/') . '\\b/i', $queryLower)) {
                $result['make'] = $make;
                break;
            }
        }

        if ($result['make']) {
            $makeIdStmt = $db->prepare("SELECT id FROM car_makes WHERE LOWER(name) = ? LIMIT 1");
            $makeIdStmt->execute([strtolower((string)$result['make'])]);
            $makeId = $makeIdStmt->fetchColumn();

            if ($makeId) {
                $modelStmt = $db->prepare("SELECT DISTINCT name FROM car_models WHERE is_active = 1 AND make_id = ? ORDER BY CHAR_LENGTH(name) DESC, name ASC");
                $modelStmt->execute([$makeId]);
                $models = $modelStmt->fetchAll(PDO::FETCH_COLUMN);
            } else {
                $modelStmt = $db->query("SELECT DISTINCT name FROM car_models WHERE is_active = 1 ORDER BY CHAR_LENGTH(name) DESC, name ASC");
                $models = $modelStmt ? $modelStmt->fetchAll(PDO::FETCH_COLUMN) : [];
            }
        } else {
            $modelStmt = $db->query("SELECT DISTINCT name FROM car_models WHERE is_active = 1 ORDER BY CHAR_LENGTH(name) DESC, name ASC");
            $models = $modelStmt ? $modelStmt->fetchAll(PDO::FETCH_COLUMN) : [];
        }

        foreach ($models as $model) {
            $model = trim((string)$model);
            if ($model !== '' && preg_match('/\\b' . preg_quote(strtolower($model), '/') . '\\b/i', $queryLower)) {
                $result['model'] = $model;
                break;
            }
        }
    } catch (Exception $e) {
        error_log('extractLearningVehicleContextFromQuery error: ' . $e->getMessage());
    }

    if (preg_match('/\b(19|20)\d{2}\b/', $query, $yearMatch)) {
        $result['year'] = (int)$yearMatch[0];
    }

    return $result;
}

function buildLearningPartNameFromQuery($query, array $vehicleContext) {
    $partName = trim((string)$query);

    if (!empty($vehicleContext['make'])) {
        $partName = preg_replace('/\\b' . preg_quote((string)$vehicleContext['make'], '/') . '\\b/i', ' ', $partName, 1);
    }
    if (!empty($vehicleContext['model'])) {
        $partName = preg_replace('/\\b' . preg_quote((string)$vehicleContext['model'], '/') . '\\b/i', ' ', $partName, 1);
    }

    $partName = preg_replace('/\b(19|20)\d{2}\b/', ' ', $partName, 1);
    $partName = trim(preg_replace('/\s+/', ' ', (string)$partName));

    return $partName !== '' ? $partName : trim((string)$query);
}

function buildSinglePartLearningMessages($query) {
    return [
        [
            'role' => 'system',
            'content' => 'You are a knowledgeable car parts expert. Provide comprehensive information about car parts using stable public-source knowledge patterns. IMPORTANT: Return valid JSON with these fields: part_name, part_number, oem_number, price_usd, description, compatibility, specifications, cross_reference. If an exact OEM or price can vary by engine, trim, or market, say so clearly in compatibility or description rather than inventing false certainty. Use null when exact numeric pricing is not available.'
        ],
        [
            'role' => 'user',
            'content' => $query . ' Please provide detailed information including OEM part number, compatibility, specifications, cross-reference numbers, and approximate USD pricing when known. Return only a valid JSON object.'
        ]
    ];
}

function normalizeLearningStructuredValue($value) {
    if (is_array($value)) {
        return json_encode($value);
    }

    $value = trim((string)$value);
    return $value !== '' ? $value : null;
}

function parseStructuredPartLearningResponse($content, $fallbackPartName = 'Part') {
    $parsed = [
        'part_name' => trim((string)$fallbackPartName) !== '' ? trim((string)$fallbackPartName) : 'Part',
        'part_number' => null,
        'oem_number' => null,
        'price_usd' => null,
        'description' => null,
        'compatibility' => null,
        'specifications' => null,
        'cross_reference' => null,
        'summary' => trim((string)$content)
    ];

    $partData = null;
    if (preg_match('/\{.*\}/s', (string)$content, $jsonMatches)) {
        $partData = json_decode($jsonMatches[0], true);
    } else {
        $partData = json_decode((string)$content, true);
    }

    if (is_array($partData)) {
        if (!empty($partData['part_name'])) {
            $parsed['part_name'] = trim((string)$partData['part_name']);
        }

        $parsed['part_number'] = normalizeLearningStructuredValue($partData['part_number'] ?? $partData['oem_number'] ?? null);
        $parsed['oem_number'] = normalizeLearningStructuredValue($partData['oem_number'] ?? $partData['part_number'] ?? null);
        $parsed['price_usd'] = isset($partData['price_usd']) && $partData['price_usd'] !== ''
            ? (float)$partData['price_usd']
            : null;
        $parsed['description'] = normalizeLearningStructuredValue($partData['description'] ?? null);
        $parsed['compatibility'] = normalizeLearningStructuredValue($partData['compatibility'] ?? null);
        $parsed['specifications'] = normalizeLearningStructuredValue($partData['specifications'] ?? null);
        $parsed['cross_reference'] = normalizeLearningStructuredValue($partData['cross_reference'] ?? null);
    }

    if (empty($parsed['part_number'])) {
        if (preg_match('/part\s*number[:\s]+([A-Z0-9\-]+)/i', (string)$content, $pnMatch)) {
            $parsed['part_number'] = trim((string)$pnMatch[1]);
            $parsed['oem_number'] = $parsed['part_number'];
        } elseif (preg_match('/oem[:\s]+([A-Z0-9\-]+)/i', (string)$content, $oemMatch)) {
            $parsed['oem_number'] = trim((string)$oemMatch[1]);
            $parsed['part_number'] = $parsed['oem_number'];
        }
    }

    if ($parsed['price_usd'] === null) {
        if (preg_match('/\$(\d+\.?\d*)/', (string)$content, $priceMatch)) {
            $parsed['price_usd'] = (float)$priceMatch[1];
        } elseif (preg_match('/price[:\s]+\$?(\d+\.?\d*)/i', (string)$content, $priceMatch2)) {
            $parsed['price_usd'] = (float)$priceMatch2[1];
        }
    }

    if (!empty($parsed['description'])) {
        $parsed['summary'] = $parsed['description'];
    }

    return $parsed;
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
        
        $messages = buildSingleWebLearningMessages($topic);
        
        $response = callAILearningAPI($db, $provider, $messages);
        if (isset($response['error'])) {
            return ['success' => false, 'message' => $response['error']];
        }
        
        $summary = trim((string)($response['content'] ?? ''));
        $sourcesJson = json_encode([[
            'title' => 'AI Research - ' . ucfirst($provider),
            'link' => null,
            'snippet' => detectVehicleSpecLearningTopic($topic)
                ? 'AI-generated vehicle specifications research'
                : 'AI-generated automotive knowledge'
        ]]);
        
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
        $provider = resolveEffectiveAILearningProvider($settings, $provider);

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

        $dbModels = getLearningPriorityModelsFromDatabase($db, 150);
        $dbMakes = [];
        foreach ($dbModels as $modelRow) {
            $makeLower = strtolower(trim((string)($modelRow['make'] ?? '')));
            if ($makeLower !== '' && !in_array($makeLower, $dbMakes, true)) {
                $dbMakes[] = $makeLower;
            }
        }

        $makeModelList = '';
        if (!empty($dbMakes)) {
            $makeModelList = "\n\nPRIORITY: Focus on these makes and models from our database first:\n";
            $makeModelList .= "Makes: " . implode(', ', array_slice($dbMakes, 0, 20)) . "\n";
            if (count($dbModels) > 0) {
                $makeModelList .= "Example combinations: ";
                $examples = [];
                foreach (array_slice($dbModels, 0, 10) as $model) {
                    $examples[] = strtolower($model['make']) . ' ' . strtolower($model['model']);
                }
                $makeModelList .= implode(', ', $examples);
            }
        }

        $queries = buildIntentionalPartsQueriesFromDatabase($dbModels, $toLearn * 2);

        if (empty($queries)) {
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

            $content = $response['content'];
            $queries = [];

            if (preg_match('/\[.*\]/s', $content, $matches)) {
                $decoded = json_decode($matches[0], true);
                if (is_array($decoded)) {
                    $queries = $decoded;
                }
            }

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
                    if (count($queries) >= $toLearn) {
                        break;
                    }
                }
            }
        }

        $prioritizedQueries = [];
        $otherQueries = [];

        foreach ($queries as $query) {
            $queryLower = strtolower(trim($query));
            $isPrioritized = false;

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

        $queries = array_merge($prioritizedQueries, $otherQueries);

        $queriesToLearn = [];
        $queryHashes = [];
        foreach ($queries as $query) {
            if (count($queriesToLearn) >= $toLearn) break;

            $query = trim($query);
            if (empty($query)) continue;

            $queryHash = hash('sha256', strtolower(trim($query)));
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

        $batchSize = 10;
        $learned = 0;
        $errors = [];
        $learnedParts = [];

        $batches = array_chunk($queriesToLearn, $batchSize);
        $hashBatches = array_chunk($queryHashes, $batchSize);

        foreach ($batches as $batchIndex => $batch) {
            $hashBatch = $hashBatches[$batchIndex];

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

            $responses = callAILearningAPIBatch($db, $provider, $messagesBatch);

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

                $vehicleContext = extractLearningVehicleContextFromQuery($db, $query);
                $makeName = $vehicleContext['make'] ?? null;
                $modelName = $vehicleContext['model'] ?? null;
                $year = $vehicleContext['year'] ?? null;
                $fallbackPartName = buildLearningPartNameFromQuery($query, $vehicleContext);
                $partPayload = parseStructuredPartLearningResponse($content, $fallbackPartName);
                $partName = $partPayload['part_name'];
                $partNumber = $partPayload['part_number'];
                $oemNumber = $partPayload['oem_number'];
                $priceUsd = $partPayload['price_usd'];
                $description = $partPayload['description'];
                $compatibility = $partPayload['compatibility'];
                $specifications = $partPayload['specifications'];
                $crossReference = $partPayload['cross_reference'];
                $summary = $partPayload['summary'];

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
        
        $vehicleContext = extractLearningVehicleContextFromQuery($db, $query);
        $makeName = $vehicleContext['make'] ?? null;
        $modelName = $vehicleContext['model'] ?? null;
        $year = $vehicleContext['year'] ?? null;
        $fallbackPartName = buildLearningPartNameFromQuery($query, $vehicleContext);

        $messages = buildSinglePartLearningMessages($query);
        
        $response = callAILearningAPI($db, $provider, $messages);
        if (isset($response['error'])) {
            return ['success' => false, 'message' => $response['error']];
        }
        
        $partPayload = parseStructuredPartLearningResponse($response['content'], $fallbackPartName);
        $summary = $partPayload['summary'];
        $sourcesJson = json_encode([['title' => 'AI Research - ' . ucfirst($provider), 'link' => null, 'snippet' => 'AI-generated parts research']]);
        
        // Insert into database
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
            $partPayload['part_name'],
            $partPayload['part_number'],
            $partPayload['oem_number'],
            $partPayload['price_usd'],
            $partPayload['description'],
            $partPayload['compatibility'],
            $partPayload['specifications'],
            $partPayload['cross_reference'],
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
        $provider = resolveEffectiveAILearningProvider($settings, $provider);
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
