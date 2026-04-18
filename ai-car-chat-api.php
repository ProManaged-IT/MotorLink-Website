<?php
/**
 * MotorLink AI Car Chat API
 * Separate API endpoint for AI-powered car assistance
 * Requires authentication
 */

// This file is included by proxy.php after api-common.php
// Function definition only - routing is handled in proxy.php

require_once __DIR__ . '/includes/runtime-site-config.php';

/**
 * Return the configured currency code for display in chat responses.
 */
function getChatCurrencyCode($db = null) {
    static $code = null;
    if ($code !== null) return $code;
    if ($db === null && function_exists('getDB')) {
        try { $db = getDB(); } catch (\Throwable $e) {}
    }
    if ($db) {
        $cfg = motorlink_get_site_runtime_config($db);
        $code = $cfg['currency_code'] ?? 'MWK';
    } else {
        $code = 'MWK';
    }
    return $code;
}

/**
 * Return the configured site name for chat responses.
 */
function getChatSiteName($db = null) {
    static $name = null;
    if ($name !== null) return $name;
    if ($db === null && function_exists('getDB')) {
        try { $db = getDB(); } catch (\Throwable $e) {}
    }
    if ($db) {
        $cfg = motorlink_get_site_runtime_config($db);
        $name = $cfg['site_name'] ?? 'MotorLink';
    } else {
        $name = 'MotorLink';
    }
    return $name;
}

/**
 * Load AI provider API key from database settings.
 * Keys are stored in site_settings and never hardcoded in source files.
 */
function getAIProviderApiKeyFromDB($provider = 'openai', $db = null) {
    static $cachedKeys = null;

    $provider = strtolower(trim((string)$provider));

    if ($db === null && function_exists('getDB')) {
        $db = getDB();
    }

    if (!$db) {
        return null;
    }

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
            error_log('Failed to load AI keys from database: ' . $e->getMessage());
        }
    }

    return $cachedKeys[$provider] ?? null;
}

function getSupportedAIChatProviders() {
    return ['openai', 'deepseek', 'qwen', 'glm'];
}

function normalizeAIChatProvider($provider, $fallback = 'openai') {
    $provider = strtolower(trim((string)$provider));
    return in_array($provider, getSupportedAIChatProviders(), true) ? $provider : $fallback;
}

function getAIChatProviderLabel($provider) {
    $labels = [
        'openai' => 'ChatGPT (OpenAI)',
        'deepseek' => 'DeepSeek',
        'qwen' => 'Qwen',
        'glm' => 'GLM'
    ];
    return $labels[$provider] ?? ucfirst($provider);
}

function getAIChatProviderConfig($provider) {
    $provider = normalizeAIChatProvider($provider);
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
    return $configs[$provider];
}

function normalizeAIChatModelName($provider, $modelName, $fallbackModel = '') {
    $provider = normalizeAIChatProvider($provider);
    $model = strtolower(trim((string)$modelName));

    if ($model === '') {
        return $fallbackModel !== '' ? $fallbackModel : getAIChatProviderConfig($provider)['default_model'];
    }

    $aliases = [
        'glm' => [
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

    // Allow forward-compatible provider model names while still normalizing obvious bad inputs.
    if ($provider === 'glm' && strpos($model, 'glm-') === 0) {
        return $model;
    }

    if ($provider === 'openai' && (strpos($model, 'gpt-') === 0 || strpos($model, 'o1') === 0 || strpos($model, 'o3') === 0)) {
        return $model;
    }

    if ($provider === 'deepseek' && strpos($model, 'deepseek-') === 0) {
        return $model;
    }

    if ($provider === 'qwen' && strpos($model, 'qwen-') === 0) {
        return $model;
    }

    return $fallbackModel !== '' ? $fallbackModel : getAIChatProviderConfig($provider)['default_model'];
}

function isAIChatModelErrorResponse($httpCode, $errorMessage, $errorType = null, $errorCode = null) {
    if ((int)$httpCode !== 400) {
        return false;
    }

    $message = strtolower((string)$errorMessage);
    $type = strtolower((string)$errorType);
    $code = strtolower((string)$errorCode);

    if (strpos($message, 'model') !== false) {
        return true;
    }

    return in_array($code, ['model_not_found', 'invalid_model', 'model_not_exist'], true)
        || in_array($type, ['invalid_model_error', 'model_error'], true);
}

function isAIChatProviderEnabled($settings, $provider) {
    $field = $provider . '_enabled';
    return (int)($settings[$field] ?? 1) === 1;
}

/**
 * Schedule non-blocking learning for automotive user queries.
 * Uses shutdown callback so chat response is never delayed.
 */
function scheduleContinuousLearningForQuery($db, $message) {
    $normalizedMessage = trim((string)$message);
    if ($normalizedMessage === '' || strlen($normalizedMessage) < 4) {
        return;
    }

    static $scheduled = [];
    $scheduleKey = hash('sha256', strtolower($normalizedMessage));
    if (isset($scheduled[$scheduleKey])) {
        return;
    }
    $scheduled[$scheduleKey] = true;

    register_shutdown_function(function() use ($db, $normalizedMessage) {
        try {
            require_once __DIR__ . '/ai-learning-api.php';

            $isPartsQuery = false;
            if (function_exists('detectPartsQuery')) {
                $isPartsQuery = detectPartsQuery($normalizedMessage);
            } else {
                $messageLower = strtolower($normalizedMessage);
                $partsKeywords = ['part number', 'oem number', 'compatibility', 'part for', 'parts for', 'spare part'];
                foreach ($partsKeywords as $keyword) {
                    if (strpos($messageLower, $keyword) !== false) {
                        $isPartsQuery = true;
                        break;
                    }
                }
            }

            @learnFromUserQuery($db, $normalizedMessage, $isPartsQuery, 'auto');
        } catch (Exception $e) {
            // Learning must never block or fail the main chat response.
        } catch (Error $e) {
            // Learning must never block or fail the main chat response.
        }
    });
}

/**
 * Handle AI Car Chat requests
 * Provides AI-powered assistance for car-related questions only
 * REQUIRES AUTHENTICATION - users must be logged in to use this feature
 */

/**
 * Ensure ai_chat_feedback table exists and upsert a feedback record.
 */
function handleAIFeedback($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('POST method required', 405);
    }

    // Require auth (getCurrentUser defined in api-common.php, already included by caller)
    if (!function_exists('getCurrentUser')) {
        sendError('Server configuration error', 500);
    }
    $user = getCurrentUser(true);
    if (!$user) {
        sendError('Authentication required', 401);
    }

    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    $feedback    = isset($data['feedback'])     ? trim((string)$data['feedback'])     : '';
    $userMsg     = isset($data['user_message']) ? trim((string)$data['user_message']) : '';
    $aiResponse  = isset($data['ai_response'])  ? trim((string)$data['ai_response'])  : '';

    if (!in_array($feedback, ['helpful', 'not-helpful'], true)) {
        sendError('Invalid feedback value', 400);
    }

    // Create table on first use (idempotent)
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS `ai_chat_feedback` (
            `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id`      INT UNSIGNED NOT NULL,
            `feedback`     ENUM('helpful','not-helpful') NOT NULL,
            `user_message` TEXT         NOT NULL,
            `ai_response`  MEDIUMTEXT   NOT NULL,
            `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_feedback_type` (`feedback`),
            INDEX `idx_created`       (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Exception $e) {
        error_log('ai_chat_feedback table create error: ' . $e->getMessage());
        // Non-fatal — still attempt insert below
    }

    if ($userMsg === '' || $aiResponse === '') {
        // No context to store; still acknowledge so UI doesn't error
        sendSuccess(['stored' => false]);
    }

    try {
        $stmt = $db->prepare(
            "INSERT INTO ai_chat_feedback (user_id, feedback, user_message, ai_response)
             VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$user['id'], $feedback, $userMsg, $aiResponse]);
        sendSuccess(['stored' => true]);
    } catch (Exception $e) {
        error_log('ai_chat_feedback insert error: ' . $e->getMessage());
        sendSuccess(['stored' => false]);  // Silent — feedback must never block UX
    }
}

/**
 * Load the most-used positive (helpful) Q&A pairs to inject as few-shot examples.
 * Returns up to $limit rows, preferring recently-rated and frequently-occurring queries.
 */
function loadPositiveFeedbackExamples($db, $limit = 4) {
    try {
        $stmt = $db->prepare(
            "SELECT user_message, ai_response
             FROM ai_chat_feedback
             WHERE feedback = 'helpful'
               AND LENGTH(user_message) BETWEEN 10 AND 300
               AND LENGTH(ai_response)  BETWEEN 30 AND 2000
             ORDER BY created_at DESC
             LIMIT ?"
        );
        $stmt->execute([(int)$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        // Table may not exist yet — silently return empty
        return [];
    }
}

function handleAICarChat($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('POST method required', 405);
    }

    // Authentication is already checked by requireAuth() in routing, but double-check
    $user = getCurrentUser(true);
    if (!$user) {
        sendError('Authentication required. Please log in to use MotorLink AI Assistant.', 401);
    }

    // Check if user's AI chat is disabled
    $restriction = checkAIChatUserRestriction($db, $user['id']);
    if ($restriction && $restriction['disabled']) {
        $reason = $restriction['reason'] ?: 'Your access to MotorLink AI Assistant has been temporarily disabled. Please contact support for assistance.';
        sendError($reason, 403);
        return;
    }

    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON decode error: " . json_last_error_msg());
            sendError('Invalid request format', 400);
            return;
        }
        
        if (empty($input['message'])) {
            sendError('Message is required', 400);
            return;
        }

        $message = trim($input['message']);
        $conversationHistory = $input['conversation_history'] ?? [];

        // Validate message length
        if (strlen($message) > 500) {
            sendError('Message is too long (maximum 500 characters)', 400);
            return;
        }

        // Always handle simple greetings deterministically so users get an immediate reply.
        if (preg_match('/^(hi|hello|hey|yo|good morning|good afternoon|good evening)[!. ]*$/i', $message)) {
            sendSuccess([
                'response' => "Hi! I can help you with car listings, car hire, dealers, garages, fuel prices, and vehicle specs. What would you like to find?"
            ]);
            return;
        }

        // Keep learning always on for valid automotive conversations.
        scheduleContinuousLearningForQuery($db, $message);

        // Load AI settings and select active provider
        $settings = getAIChatSettings($db);
        $provider = normalizeAIChatProvider($settings['ai_provider'] ?? 'openai');
        $providerConfig = getAIChatProviderConfig($provider);
        $enabled = (int)($settings['enabled'] ?? 1);
        $modelName = normalizeAIChatModelName(
            $provider,
            $settings['model_name'] ?? $providerConfig['default_model'],
            $providerConfig['default_model']
        );

        if (!$enabled) {
            sendError('AI chat feature is currently disabled. Please contact administrator.', 503);
            return;
        }

        // Enforce rate limits before any deterministic or provider-based response path.
        $rateLimitCheck = checkAIChatRateLimit($db, $user['id'], $settings);
        if (!$rateLimitCheck['allowed']) {
            sendError($rateLimitCheck['message'], 429);
            return;
        }

        $logDeterministicUsage = function() use ($db, $user, $message, $modelName) {
            logAIChatUsage($db, $user['id'], $message, 0, 0, $modelName, 0.0);
        };

        // Get user context (type, business info, etc.)
        $userContext = getUserContext($db, $user);
        
        // Check if user is asking about their own data (inventory, listings, fleet, garage, business)
        $userDataResult = detectAndHandleUserDataQuery($db, $message, $user, $userContext);
        if ($userDataResult !== false) {
            $logDeterministicUsage();
            sendSuccess($userDataResult);
            return;
        }
        
        // Check if user is asking to perform an action
        $actionResult = detectAndHandleAction($db, $message, $user, $userContext);
        if ($actionResult !== false) {
            $logDeterministicUsage();
            sendSuccess($actionResult);
            return;
        }

        // Resolve contextual location follow-ups like "What about Salima"
        // by reusing the previous car-search intent.
        $locationFollowUpMessage = buildLocationFollowUpSearchMessage($db, $message, $conversationHistory);
        if ($locationFollowUpMessage !== false) {
            $logDeterministicUsage();
            handleSearchQuery($db, $locationFollowUpMessage, $conversationHistory);
            return;
        }

        // Resolve contextual car-hire location follow-ups like
        // previous: "Looking for an SUV to hire in Lilongwe"
        // current: "What about Salima"
        $carHireFollowUpMessage = buildLocationFollowUpCarHireMessage($db, $message, $conversationHistory);
        if ($carHireFollowUpMessage !== false) {
            $logDeterministicUsage();
            handleCarHireQuery($db, $carHireFollowUpMessage, $conversationHistory);
            return;
        }

        // Resolve contextual car-hire comparative follow-ups like
        // previous: "Looking for a car hire in Blantyre"
        // current: "What is the cheapest"
        $carHireComparativeFollowUpMessage = buildCarHireComparativeFollowUpMessage($db, $message, $conversationHistory);
        if ($carHireComparativeFollowUpMessage !== false) {
            $logDeterministicUsage();
            handleCarHireQuery($db, $carHireComparativeFollowUpMessage, $conversationHistory);
            return;
        }

        // Resolve contextual contact follow-ups like
        // "Give me their contact number" after car-hire/dealer/garage results.
        $contactFollowUpMessage = buildContactFollowUpMessage($db, $message, $conversationHistory);
        if ($contactFollowUpMessage !== false) {
            $message = $contactFollowUpMessage;
        }

        // Resolve contextual garage location follow-ups like
        // previous: "Find a garage in Lilongwe"
        // current: "What about Salima"
        $garageFollowUpMessage = buildLocationFollowUpGarageMessage($db, $message, $conversationHistory);
        if ($garageFollowUpMessage !== false) {
            $logDeterministicUsage();
            handleGarageQuery($db, $garageFollowUpMessage, $conversationHistory);
            return;
        }

        // Resolve contextual dealer location follow-ups like
        // previous: "Find a dealer in Lilongwe"
        // current: "What about Salima"
        $dealerFollowUpMessage = buildLocationFollowUpDealerMessage($db, $message, $conversationHistory);
        if ($dealerFollowUpMessage !== false) {
            $logDeterministicUsage();
            handleDealerQuery($db, $dealerFollowUpMessage, $conversationHistory);
            return;
        }

        // Resolve short contextual automotive follow-ups (general car info)
        // so prompts like "What about Toyota Hilux" stay in automotive scope.
        $generalAutomotiveFollowUpMessage = buildGeneralAutomotiveFollowUpMessage($message, $conversationHistory);
        if ($generalAutomotiveFollowUpMessage !== false) {
            $message = $generalAutomotiveFollowUpMessage;
        }

        // AI-powered fallback intent resolution for natural language follow-ups that
        // deterministic keyword rules may miss. This uses configured provider tokens.
        $aiIntentMarkedInScope = false;
        $hasDeterministicIntent =
            detectCarHireQuery($message) ||
            detectDealerQuery($message) ||
            detectGarageQuery($message) ||
            detectFuelPriceQuery($message) ||
            detectSearchQuery($message) ||
            detectCarSpecQuery($message) ||
            detectCarRecommendationQuery($message) ||
            detectPartsQuery($message);

        $shouldUseAIIntent = !$hasDeterministicIntent && (
            isOutOfScopeQuery($message) ||
            detectFollowUpQuestion($message, $conversationHistory) ||
            str_word_count((string)$message) <= 10
        );

        if ($shouldUseAIIntent) {
            $aiIntent = resolveConversationalIntentWithAI(
                $db,
                $message,
                $conversationHistory,
                $settings,
                $provider,
                $providerConfig,
                $modelName
            );

            if (!empty($aiIntent)) {
                $rewrittenQuery = trim((string)($aiIntent['rewritten_query'] ?? ''));
                if ($rewrittenQuery !== '' && strlen($rewrittenQuery) <= 500) {
                    $message = $rewrittenQuery;
                }

                $resolvedIntent = strtolower(trim((string)($aiIntent['intent'] ?? '')));
                if (!empty($aiIntent['out_of_scope']) || $resolvedIntent === 'out_of_scope') {
                    $logDeterministicUsage();
                    sendSuccess([
                        'response' => buildOutOfScopeResponse()
                    ]);
                    return;
                }

                if ($resolvedIntent === 'car_hire') {
                    $logDeterministicUsage();
                    handleCarHireQuery($db, $message, $conversationHistory);
                    return;
                }

                if ($resolvedIntent === 'dealer') {
                    $logDeterministicUsage();
                    handleDealerQuery($db, $message, $conversationHistory);
                    return;
                }

                if ($resolvedIntent === 'garage') {
                    $logDeterministicUsage();
                    handleGarageQuery($db, $message, $conversationHistory);
                    return;
                }

                if ($resolvedIntent === 'listings') {
                    $logDeterministicUsage();
                    handleSearchQuery($db, $message, $conversationHistory);
                    return;
                }

                if ($resolvedIntent === 'fuel') {
                    $logDeterministicUsage();
                    handleFuelPriceQuery($db, $message, $conversationHistory);
                    return;
                }

                if ($resolvedIntent === 'spec') {
                    $logDeterministicUsage();
                    handleCarSpecQuery($db, $message, $conversationHistory, $userContext);
                    return;
                }

                if ($resolvedIntent === 'recommendation') {
                    $logDeterministicUsage();
                    handleCarRecommendationQuery($db, $message, $conversationHistory, $userContext);
                    return;
                }

                if ($resolvedIntent === 'general_automotive') {
                    $aiIntentMarkedInScope = true;
                }

                if ($resolvedIntent === 'general_knowledge') {
                    $aiIntentMarkedInScope = true;
                }
            }
        }

        // Hard scope gate: do not answer non-automotive / non-MotorLink requests.
        if (!$aiIntentMarkedInScope && isOutOfScopeQuery($message)) {
            $logDeterministicUsage();
            sendSuccess([
                'response' => buildOutOfScopeResponse()
            ]);
            return;
        }
        
        // Check query type with priority: car hire > garage > car listings
        // This ensures more specific queries are handled first
        
        // Check if user is asking about car hire (rental)
        $isCarHireQuery = detectCarHireQuery($message);
        
        if ($isCarHireQuery) {
            // Handle car hire query
            $logDeterministicUsage();
            handleCarHireQuery($db, $message, $conversationHistory);
            return; // Exit early after handling car hire query
        }
        
        // Check if user is asking about dealers
        $isDealerQuery = detectDealerQuery($message);
        
        if ($isDealerQuery) {
            // Handle dealer query
            $logDeterministicUsage();
            handleDealerQuery($db, $message, $conversationHistory);
            return; // Exit early after handling dealer query
        }
        
        // Check if user is asking about garages
        $isGarageQuery = detectGarageQuery($message);
        
        if ($isGarageQuery) {
            // Handle garage query
            $logDeterministicUsage();
            handleGarageQuery($db, $message, $conversationHistory);
            return; // Exit early after handling garage query
        }
        
        // Check if user is asking about gasoline/fuel prices
        $isFuelPriceQuery = detectFuelPriceQuery($message);
        
        if ($isFuelPriceQuery) {
            // Handle fuel price query
            $logDeterministicUsage();
            handleFuelPriceQuery($db, $message, $conversationHistory);
            return; // Exit early after handling fuel price query
        }
        
        // Check if user is asking to search for listings (for sale)
        $isSearchQuery = detectSearchQuery($message);
        
        if ($isSearchQuery) {
            // Handle search query
            $logDeterministicUsage();
            handleSearchQuery($db, $message, $conversationHistory);
            return; // Exit early after handling search
        }

        // Handle contextual search follow-ups like "what is the cheapest one?"
        // by reusing the latest search context from conversation history.
        $searchFollowUpMessage = buildSearchFollowUpMessage($message, $conversationHistory);
        if ($searchFollowUpMessage !== false) {
            $logDeterministicUsage();
            handleSearchQuery($db, $searchFollowUpMessage, $conversationHistory);
            return;
        }
        
        // Check if user is asking about car specifications
        $isCarSpecQuery = detectCarSpecQuery($message);
        
        if ($isCarSpecQuery) {
            // Handle car spec query with database and internet search
            $logDeterministicUsage();
            handleCarSpecQuery($db, $message, $conversationHistory, $userContext);
            return; // Exit early after handling car spec query
        }
        
        // Check if user is looking for car recommendations or searching for a car
        $isCarRecommendationQuery = detectCarRecommendationQuery($message);
        
        if ($isCarRecommendationQuery) {
            // Handle car recommendation/search query with intelligent matching
            $logDeterministicUsage();
            handleCarRecommendationQuery($db, $message, $conversationHistory, $userContext);
            return; // Exit early after handling recommendation query
        }

        // Before general AI response, try to query database for any car-related information
        // This ensures we always check database first before using AI knowledge
        $databaseInfo = queryGeneralCarInfoFromDatabase($db, $message);
        $databaseContext = "";
        if ($databaseInfo && !empty($databaseInfo['has_data'])) {
            // Check if we found cache data
            if (!empty($databaseInfo['cache_data']) && $databaseInfo['cache_data']['found']) {
                // Found in cache tables - use this information
                $cacheData = $databaseInfo['cache_data'];
                $databaseContext = "\n\nCACHED INFORMATION FROM DATABASE:\n{$cacheData['summary']}\n\n";
                $databaseContext .= "This information was retrieved from our learning cache. Use this cached information as the primary source for your response. You can enhance it with additional context if needed, but prioritize the cached information. Provide a clear, summarized overview.";
            } else {
                // We found relevant database info (from car_models, etc.), enhance the system prompt with it
                $databaseContext = "\n\nDATABASE CONTEXT:\n{$databaseInfo['context']}\n";
                $databaseContext .= "Remember: Always prioritize database data when available, and use AI research intelligently when database doesn't have the information.";
                
                // If we have alternatives, include them
                if (!empty($databaseInfo['alternatives'])) {
                    $databaseContext .= "\n\nALTERNATIVE OPTIONS IN DATABASE:\n";
                    foreach ($databaseInfo['alternatives'] as $alt) {
                        $databaseContext .= "- {$alt}\n";
                    }
                    $databaseContext .= "\nSuggest these alternatives if the exact query isn't found.";
                }
            }
        } else {
            $databaseContext = "\n\nDATABASE CHECK: No matching data found in MotorLink database (including cache tables) for this query. Use your AI knowledge, research capabilities, and web searches to provide a comprehensive, summarized overview. Always offer alternatives and similar options when appropriate.";
        }

        // Build context-aware system prompt
        $userType = $userContext['user_type'] ?? 'user';
        $businessType = $userContext['business_type'] ?? null;
        $listingsCount = $userContext['listings_count'] ?? 0;
        $userVehicles = $userContext['vehicles'] ?? [];
        $vehiclesCount = count($userVehicles);
        
        $contextInfo = "User Type: {$userType}";
        if ($businessType) {
            $contextInfo .= ", Business: {$businessType}";
            if (!empty($userContext['business_name'])) {
                $contextInfo .= " ({$userContext['business_name']})";
            }
        }
        if ($listingsCount > 0) {
            $contextInfo .= ", Listings: {$listingsCount}";
        }
        if ($vehiclesCount > 0) {
            $contextInfo .= ", Vehicles: {$vehiclesCount}";
            $vehiclesList = [];
            foreach ($userVehicles as $vehicle) {
                $vehicleInfo = "{$vehicle['make']} {$vehicle['model']}";
                if (!empty($vehicle['year'])) {
                    $vehicleInfo .= " ({$vehicle['year']})";
                }
                if ($vehicle['is_primary'] ?? 0) {
                    $vehicleInfo .= " [Primary]";
                }
                $vehiclesList[] = $vehicleInfo;
            }
            $contextInfo .= "\nUSER VEHICLES: " . implode(", ", $vehiclesList);
        }
        
        // Get base URL for generating links
        $serverHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        $isLocalhost = in_array($serverHost, ['localhost', '127.0.0.1']) || 
                       strpos($serverHost, 'localhost:') === 0 || 
                       strpos($serverHost, '127.0.0.1:') === 0 ||
                       preg_match('/^(192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $serverHost);
        $isProduction = !$isLocalhost && !empty($serverHost);
        $isLocalDev = $isLocalhost;
        
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $baseUrl = $protocol . '://' . $serverHost . '/';
        $siteConfig = motorlink_get_public_site_runtime_config($db, ['runtime_base_url' => $baseUrl]);
        $siteName = trim((string)($siteConfig['site_name'] ?? 'MotorLink'));
        $countryName = trim((string)($siteConfig['country_name'] ?? ''));
        $marketContextLabel = $countryName !== '' ? $countryName : 'the current market';
        $runtimeSiteUrl = rtrim((string)($siteConfig['site_url'] ?? $baseUrl), '/') . '/';
        
        // Get user location if available
        $userLocation = null;
        $userCity = null;
        try {
            $locationStmt = $db->prepare("
                SELECT u.city, u.address, loc.name as location_name, loc.region, loc.district
                FROM users u
                LEFT JOIN locations loc ON u.city = loc.name OR u.city LIKE CONCAT('%', loc.name, '%')
                WHERE u.id = ?
                LIMIT 1
            ");
            $locationStmt->execute([$user['id']]);
            $userLocation = $locationStmt->fetch(PDO::FETCH_ASSOC);
            if ($userLocation) {
                $userCity = $userLocation['location_name'] ?? $userLocation['city'] ?? null;
            }
        } catch (Exception $e) {
            error_log("Error fetching user location: " . $e->getMessage());
        }
        
        $locationContext = $userCity ? "\nUSER LOCATION: {$userCity}" : "";
        
        // System prompt - friendly, helpful, and comprehensive with intelligent fallback
        $systemPrompt = "Hey there! 👋 I'm {$siteName} AI Assistant, your friendly automotive expert here at {$siteName}! I'm here to help you with all things cars - from specs and maintenance to finding the perfect vehicle.

USER CONTEXT: {$contextInfo}{$locationContext}
BASE URL: {$baseUrl}{$databaseContext}

MOTORLINK DATABASE SCHEMA (CRITICAL KNOWLEDGE):
Our database contains comprehensive car data with the following structure:
- **car_makes**: 42+ manufacturers (Toyota, Honda, Nissan, Mazda, Mitsubishi, Suzuki, Mercedes-Benz, BMW, VW, Audi, Land Rover, Volvo, Subaru, Peugeot, Renault, Citroen, Fiat, Opel, MINI, Jaguar, Porsche, Alfa Romeo, Skoda, SEAT, etc.)
- **car_models**: 167+ models with COMPLETE specifications including:
  * Engine: size (liters), cylinders, horsepower (hp), torque (Nm)
  * Fuel: type (petrol/diesel/hybrid/electric), tank capacity (liters), consumption (urban/highway/combined L/100km)
  * Transmission: manual/automatic/CVT/semi-automatic/DCT
  * Dimensions: length/width/height/wheelbase (mm), weight (kg)
  * Performance: drive type (FWD/RWD/AWD/4WD), CO2 emissions (g/km)
  * Body: type (sedan/suv/hatchback/pickup/coupe/wagon/minivan/convertible/crossover/truck), doors, seating capacity
  * Production years: year_start and year_end (NULL if still in production)
- **car_listings**: Active vehicles for sale with prices, mileage, condition, seller info
- **user_vehicles**: User's personal vehicles with custom specs
- **garages**: Auto repair shops with services, locations, contact info
- **car_hire_companies**: Rental companies with fleet details
- **car_dealers**: Dealerships with inventory
- **fuel_prices**: Current fuel prices for {$marketContextLabel} (petrol, diesel, LPG, CNG where available)
- **locations**: Configured operating locations and coverage areas for {$marketContextLabel}

MY PERSONALITY:
- I'm warm, friendly, and approachable - like chatting with a knowledgeable friend
- I use conversational language and show genuine enthusiasm about cars
- I'm patient and happy to explain things clearly
- I celebrate when I can help you find exactly what you need! 🎉

WHAT I CAN DO FOR YOU:
1. **Car Specifications & Details** 🚗
    - I ALWAYS check our {$siteName} database FIRST for precise, verified data
   - If database has the info, I present it clearly and accurately with proper formatting
   - If database doesn't have it, I use my AI knowledge and web research to provide comprehensive answers
    - I clearly indicate data source: 'From {$siteName} database' vs 'Based on research'
   - I always offer alternatives and similar options when exact matches aren't found
   - I combine both sources to give you the most accurate, comprehensive answers

2. **Find Vehicles for Sale** 🔍
    - Search our listings with PRECISE matching: [View Listing]({$runtimeSiteUrl}car.html?id=ID)
   - I'll include seller info: 'Listed by [Dealer Name]' or 'Private seller'
   - If no exact matches, I offer intelligent alternatives and similar options
   - Help you compare options and make informed decisions

3. **Find Services** 🛠️
    - Search garages: [Garage]({$runtimeSiteUrl}garages.html?id=ID)
    - Search car hire companies: [Company]({$runtimeSiteUrl}car-hire-company.html?id=ID)
    - Search dealers: [Dealer]({$runtimeSiteUrl}showroom.html?dealer_id=ID)
   - If not in database, I provide general information and suggestions

4. **Your Personal Vehicles** 🚙
   - Answer questions about YOUR vehicles (from USER VEHICLES in context)
   - When you say 'my car' or 'my vehicle', I'll check your vehicles list
   - Provide detailed specs, maintenance tips, fuel consumption, comparisons
   - Help with fuel efficiency, engine specs, maintenance schedules

5. **General Car Advice** 💡
   - Maintenance tips and best practices
   - Buying and selling advice
   - Car comparisons and recommendations

HOW I ANSWER QUESTIONS:
1. **For car/vehicle/listing queries**: Check {$siteName} database FIRST → present results → if not found, use AI knowledge
2. **For general knowledge**: Answer directly and helpfully — I'm a broad knowledge assistant too
3. **For follow-ups**: Read conversation history carefully to maintain context
4. **Always include links**: When showing results, include clickable links: [Text]({$runtimeSiteUrl}page.html?id=ID)

LOGICAL THINKING & INTELLIGENT REASONING:
- **Family cars**: Prioritize 6-7+ seating capacity (minivans, 3-row SUVs like Toyota Fortuner, Honda Pilot, Nissan Pathfinder), safety features, spacious interiors, reliability. Think: parents + 2-4 children = need for space. NOT just any SUV - specifically those with 7+ seats.
- **Business/Executive cars**: Sedans with comfort, professional appearance (Mercedes E-Class, BMW 5 Series, Toyota Camry, Honda Accord), reliability, good fuel economy for long distances. Think: client meetings, professional image.
- **City/Urban cars**: Compact size for tight parking (hatchbacks like Toyota Yaris, Honda Fit, Suzuki Swift, VW Polo), excellent fuel efficiency (under 8L/100km), easy maneuverability. Think: narrow streets, parking challenges.
- **Off-road/Adventure**: 4WD capability (Toyota Prado, Land Cruiser, Nissan Patrol, Land Rover Defender), high ground clearance, rugged build, large fuel tanks. Think: rough terrain, rural areas.
- **Fuel efficiency**: Consider L/100km ratings - under 7 is excellent, 7-10 is good, 10-13 is average, 13+ is high consumption. Match to user's budget and usage.
- **Budget considerations**: Entry-level (Suzuki Alto, Toyota Vitz), mid-range (Toyota Corolla, Honda Civic), premium (Mercedes, BMW, Audi). Think about total cost of ownership.
- **Market context ({$marketContextLabel})**: Consider fuel availability, road conditions, local import mix, and service/parts availability when recommending vehicles.
- **Always think holistically**: What makes LOGICAL sense for the user's ACTUAL needs, not just keyword matching. Consider use case, budget, family size, driving conditions, fuel costs, maintenance availability.

COMPREHENSIVE CAR KNOWLEDGE (TEACHING MODE):
When users ask about car topics, I explain concepts clearly and educationally:

**Engine Types & How They Work:**
- **Petrol/Gasoline**: Spark ignition, lighter, smoother, better for short trips. Common in sedans/hatchbacks.
- **Diesel**: Compression ignition, more torque, better fuel economy for long distances, ideal for SUVs/trucks.
- **Hybrid**: Combines petrol engine + electric motor. Regenerative braking saves energy. Types: mild hybrid, full hybrid, plug-in hybrid (PHEV).
- **Electric (EV)**: Battery-powered, zero emissions, instant torque, lower running costs but charging infrastructure depends on the market.
- **Engine sizes**: 1.0-1.5L (city cars), 1.6-2.0L (family cars), 2.0-3.0L (SUVs/performance), 3.0L+ (trucks/luxury).
- **Cylinders**: 3-cyl (economy), 4-cyl (most common), 6-cyl (V6 power), 8-cyl (V8 performance).
- **Turbo vs NA**: Turbocharged engines provide more power from smaller displacement. NA (naturally aspirated) is simpler but less powerful per liter.

**Transmission Types Explained:**
- **Manual**: Driver controls gears via clutch pedal. More engaging, often more fuel efficient, cheaper to repair.
- **Automatic (AT)**: Torque converter shifts gears automatically. Convenient but uses more fuel.
- **CVT**: Continuously Variable Transmission - smooth, fuel efficient, no fixed gear ratios. Common in Toyotas, Hondas.
- **DCT/DSG**: Dual-clutch automatic - fast shifts like manual, efficient like automatic. Found in VW, Hyundai.
- **Semi-automatic**: Paddle shifters allow manual control of automatic gearbox.

**Drive Types & When to Use:**
- **FWD (Front-Wheel Drive)**: Engine powers front wheels. Good traction, fuel efficient, most sedans/hatchbacks.
- **RWD (Rear-Wheel Drive)**: Powers rear wheels. Better handling, common in sports cars, luxury sedans, trucks.
- **AWD (All-Wheel Drive)**: Power to all wheels automatically. Great for varied conditions, crossovers, SUVs.
- **4WD/4x4**: Selectable four-wheel drive. Best for off-road, can switch between 2WD and 4WD. Especially useful in rural or rough-road markets.
- **Diff lock**: Locks differential for maximum traction in mud/sand. Important for serious off-roading.

**Fuel Consumption Understanding:**
- **L/100km**: Liters per 100 kilometers - lower is better. Use this as the default efficiency standard unless the user asks for another format.
- **Urban/City**: Stop-start traffic uses more fuel (8-15 L/100km typical).
- **Highway**: Steady speed is most efficient (5-10 L/100km typical).
- **Combined**: Average of both cycles - what you'll actually experience.
- **Calculating costs**: (Distance ÷ 100) × Consumption × Fuel Price = Trip Cost

**Car Maintenance Essentials:**
- **Oil change**: Every 5,000-10,000km depending on oil type. Synthetic lasts longer.
- **Timing belt**: Replace every 80,000-120,000km - CRITICAL, can destroy engine if it breaks.
- **Brake pads**: Every 30,000-70,000km depending on driving style. Listen for squealing.
- **Tyres**: Replace when tread < 1.6mm. Rotate every 10,000km for even wear.
- **Coolant**: Flush every 2-3 years. Prevents overheating in hot climates and long-distance driving.
- **Battery**: 3-5 years lifespan. Test before rainy season starts.
- **Air filter**: Every 15,000-30,000km. Dusty roads require more frequent changes.

**Safety Features Explained:**
- **ABS**: Anti-lock Braking System - prevents wheel lockup during hard braking.
- **ESP/ESC**: Electronic Stability Control - prevents skidding and loss of control.
- **Airbags**: Front, side, curtain - more is better for family safety.
- **ISOFIX**: Secure child seat mounting points - essential for families.
- **Reverse camera/sensors**: Helps avoid accidents when parking.
- **Blind spot monitoring**: Alerts to vehicles in blind spots during lane changes.

**Car Buying Tips:**
- **Import duties**: Factor in applicable import duties and taxes on imported vehicles.
- **Driving side**: Check if the vehicle matches local driving-side requirements (LHD vs RHD).
- **Spare parts availability**: Toyota, Nissan, Honda parts are easiest to find locally.
- **Resale value**: Toyota holds value best, followed by Nissan and Honda.
- **Mileage considerations**: Under 100,000km is ideal, 100,000-150,000km is acceptable if well-maintained.
- **Service history**: Always ask for service records. No history = risk.
- **Pre-purchase inspection**: Get a mechanic to check before buying - worth the small fee.

CORE RULES:
1. For car/vehicle queries: Check MotorLink database FIRST, then use AI knowledge as fallback
2. For general knowledge queries: Answer directly using your training knowledge — be helpful
3. Always use **markdown formatting** in responses (bold, lists, headers)
4. Keep responses clear, structured, and under 400 words unless more detail is requested
5. When showing database results, indicate source. When using AI knowledge, say so clearly

RESPONSE FORMATTING:
- Use **bold** for key terms and section headers
- Use bullet points (- item) for lists of features, specs, options
- Use numbered lists (1. 2. 3.) for step-by-step instructions
- Present data in clean, scannable sections
- **Alternatives**: Present as 'Similar Options' or 'You might also consider'
- Use tables, lists, and clear formatting for better readability

MY STYLE:
- Be conversational and friendly - like we're chatting over coffee ☕
- Show enthusiasm when I can help you find something great
- Keep responses clear and concise (under 300 words unless you ask for more detail)
- Use emojis sparingly but naturally to add warmth
- Be helpful and supportive - I'm here to make your car journey easier!
- Present data professionally and accurately

REMEMBER (MANDATORY WORKFLOW):
1. **ALWAYS check database FIRST** for car/vehicle/listing queries - This is step 1, not optional
2. **ONLY if database doesn't have it** - Then use your broad knowledge as fallback
3. Database accuracy is CRITICAL - always verify and present precisely
4. Always offer alternatives and helpful suggestions
5. **GENERAL KNOWLEDGE**: You are also a helpful general assistant. If users ask non-automotive questions (math, science, history, coding, weather, etc.), answer them helpfully using your training knowledge. You are NOT restricted to only car topics.
6. **FOLLOW-UP UNDERSTANDING**: Always read the conversation history carefully. When a user says 'what about X' or 'and Y?', relate it to the previous messages. Maintain context across the conversation.
7. **FORMAT WITH MARKDOWN**: Use **bold** for emphasis, bullet points (- item) for lists, ### for section headers. This makes responses scannable and professional.
8. I'm always learning and improving to serve you better";

        // —— Inject proven high-quality Q&A pairs from user feedback ——
        $feedbackExamples = loadPositiveFeedbackExamples($db, 4);
        if ($feedbackExamples) {
            $examplesText = "\n\nLEARNED EXAMPLES (from users who found these responses helpful — study the style and accuracy):\n";
            foreach ($feedbackExamples as $i => $ex) {
                $n = $i + 1;
                $examplesText .= "\nExample {$n}:\n  User asked: " . $ex['user_message'] . "\n  Good response style: " . substr($ex['ai_response'], 0, 320) . (strlen($ex['ai_response']) > 320 ? '...' : '') . "\n";
            }
            $systemPrompt .= $examplesText;
        }

        // Build messages array for OpenAI API
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt]
        ];

        // Add conversation history (last 10 messages for context)
        // Enhanced: Better context understanding for follow-up questions
        $enhancedHistory = [];
        foreach ($conversationHistory as $historyItem) {
            if (isset($historyItem['role']) && isset($historyItem['content'])) {
                $enhancedHistory[] = [
                    'role' => $historyItem['role'],
                    'content' => $historyItem['content']
                ];
            }
        }
        
        // Detect if current message is a follow-up question
        $isFollowUp = detectFollowUpQuestion($message, $enhancedHistory);
        
        if ($isFollowUp && !empty($enhancedHistory)) {
            // Add context hint for follow-up questions
            $contextHint = "CONTEXT: The user is asking a follow-up question. ";
            $lastAIResponse = '';
            foreach (array_reverse($enhancedHistory) as $item) {
                if ($item['role'] === 'assistant') {
                    $lastAIResponse = $item['content'];
                    break;
                }
            }
            
            if ($lastAIResponse) {
                $contextHint .= "Previous conversation context: " . substr($lastAIResponse, 0, 200) . "... ";
                $contextHint .= "Answer the current question in relation to the previous conversation.";
                
                // Add context as a system message
                $messages[] = ['role' => 'system', 'content' => $contextHint];
            }
        }
        
        // Add conversation history
        $messages = array_merge($messages, $enhancedHistory);

        // Add current user message
        $messages[] = ['role' => 'user', 'content' => $message];

        if (!isAIChatProviderEnabled($settings, $provider)) {
            sendError(getAIChatProviderLabel($provider) . ' is currently disabled. Please contact administrator.', 503);
            return;
        }

        $providerApiKey = getAIProviderApiKeyFromDB($provider, $db);
        if (empty($providerApiKey)) {
            error_log(getAIChatProviderLabel($provider) . " API key not configured in site_settings.");
            sendError('AI service is not configured. Please contact support.', 503);
            return;
        }

        // Check if cURL is available
        if (!function_exists('curl_init')) {
            error_log("cURL extension not available for AI chat");
            sendError('AI service requires cURL extension. Please contact the administrator.', 503);
        }

        $maxTokens = (int)($settings['max_tokens_per_request'] ?? 1200);
        $temperature = (float)($settings['temperature'] ?? 0.7);
        
        // Build request body according to OpenAI API reference
        // Use settings from database (configurable in admin panel)
        $requestBody = [
            'model' => $modelName, // From admin settings
            'messages' => $messages, // Required: array of message objects
            'temperature' => $temperature, // From admin settings (0-2, controls randomness)
            'max_tokens' => $maxTokens, // From admin settings (1-4000, max response length)
            'top_p' => 0.95, // Higher for more natural responses
            'frequency_penalty' => 0.1, // Lower penalty for more natural flow
            'presence_penalty' => 0.1 // Lower penalty for more direct answers
        ];
        
        // Set provider auth headers
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $providerApiKey
        ];
        
        // SSL verification - enable in production, disable for local dev
        // Check multiple indicators for local development
        $serverHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        $serverAddr = $_SERVER['SERVER_ADDR'] ?? '';
        $isLocalDev = (
            strpos($serverHost, 'localhost') !== false || 
            strpos($serverHost, '127.0.0.1') !== false ||
            strpos($serverHost, '192.168.') !== false ||
            strpos($serverHost, '10.') !== false ||
            strpos($serverAddr, '127.0.0.1') !== false ||
            strpos($serverAddr, '::1') !== false ||
            (isset($_SERVER['REMOTE_ADDR']) && in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1']))
        );
        
        // For Windows development environments, always disable SSL verification
        // as they often lack proper CA certificates
        $isWindows = (PHP_OS_FAMILY === 'Windows');
        $disableSSL = $isLocalDev || $isWindows;
        
        $executeProviderRequest = function(array $payload) use ($providerConfig, $headers, $disableSSL) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $providerConfig['url']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !$disableSSL);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, !$disableSSL ? 2 : 0);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            $curlErrno = curl_errno($ch);
            curl_close($ch);

            return [
                'response' => $response,
                'httpCode' => $httpCode,
                'error' => $error,
                'curlErrno' => $curlErrno
            ];
        };

        $attempt = 0;
        while (true) {
            $attempt++;
            $result = $executeProviderRequest($requestBody);
            $response = $result['response'];
            $httpCode = (int)$result['httpCode'];
            $error = $result['error'];
            $curlErrno = (int)$result['curlErrno'];

            // Handle cURL errors
            if ($error || $curlErrno) {
                $errorMsg = $error ?: curl_strerror($curlErrno);
                error_log(getAIChatProviderLabel($provider) . " API cURL error (Code: $curlErrno): " . $errorMsg);
                sendError('AI service connection error. Please check your internet connection and try again.', 503);
            }

            // Handle HTTP errors from provider
            if ($httpCode !== 200) {
                $errorResponse = json_decode((string)$response, true);

                // Parse error response according to OpenAI-compatible format
                $errorMessage = 'Unknown error';
                $errorType = null;
                $errorCode = null;

                if (isset($errorResponse['error'])) {
                    $errorObj = $errorResponse['error'];
                    $errorMessage = $errorObj['message'] ?? 'Unknown error';
                    $errorType = $errorObj['type'] ?? null;
                    $errorCode = $errorObj['code'] ?? null;
                } else {
                    $errorMessage = substr((string)$response, 0, 500);
                }

                error_log(getAIChatProviderLabel($provider) . " API HTTP $httpCode (Type: $errorType, Code: $errorCode): $errorMessage");

                $isModelError = isAIChatModelErrorResponse($httpCode, $errorMessage, $errorType, $errorCode);
                $currentModel = (string)($requestBody['model'] ?? '');
                $defaultModel = (string)$providerConfig['default_model'];

                // Retry once with provider default model to recover from bad model settings.
                if ($isModelError && $attempt === 1 && $currentModel !== $defaultModel) {
                    error_log(getAIChatProviderLabel($provider) . " model '$currentModel' failed. Retrying with default '$defaultModel'.");
                    $requestBody['model'] = $defaultModel;
                    $modelName = $defaultModel;
                    continue;
                }

                // Handle specific error codes
                if ($httpCode === 401) {
                    sendError('AI service authentication failed. Please check API key configuration.', 503);
                } elseif ($httpCode === 429) {
                    sendError('AI provider rate limit or quota reached. Please try again later, or contact admin to check API credits.', 429);
                } elseif ($httpCode === 400) {
                    sendError('Invalid request: ' . (is_string($errorMessage) ? substr($errorMessage, 0, 200) : 'Bad request'), 400);
                } elseif ($httpCode === 500 || $httpCode === 502 || $httpCode === 503) {
                    sendError('AI service is temporarily unavailable. Please try again later.', 503);
                } else {
                    sendError('AI service error (HTTP ' . $httpCode . '): ' . (is_string($errorMessage) ? substr($errorMessage, 0, 200) : 'Unknown error'), 503);
                }
            } else {
                break;
            }
        }

        // Parse provider response structure
        $data = json_decode($response, true);
        
        if ($data === null || json_last_error() !== JSON_ERROR_NONE) {
            error_log(getAIChatProviderLabel($provider) . " API invalid JSON response: " . substr($response, 0, 500));
            sendError('Invalid response from AI service. Please try again.', 500);
        }

        // Validate response structure
        if (!isset($data['choices']) || !is_array($data['choices']) || empty($data['choices'])) {
            error_log(getAIChatProviderLabel($provider) . " API invalid response structure: " . json_encode($data));
            sendError('Invalid response structure from AI service. Please try again.', 500);
        }
        
        $firstChoice = $data['choices'][0];
        if (!isset($firstChoice['message']['content'])) {
            error_log(getAIChatProviderLabel($provider) . " API missing content in response: " . json_encode($firstChoice));
            sendError('No response content from AI service. Please try again.', 500);
        }

        $aiResponse = trim($firstChoice['message']['content']);
        
        // Validate response is not empty
        if (empty($aiResponse)) {
            error_log(getAIChatProviderLabel($provider) . " API empty response content");
            sendError('Empty response from AI service. Please try again.', 500);
        }

        // Track usage: Get token usage from response
        $tokensUsed = $data['usage']['total_tokens'] ?? 0;
        $responseLength = strlen($aiResponse);
        
        // Calculate cost estimate (gpt-4o-mini: $0.15/$0.60 per 1M tokens input/output)
        $inputTokens = $data['usage']['prompt_tokens'] ?? 0;
        $outputTokens = $data['usage']['completion_tokens'] ?? 0;
        $costEstimate = ($inputTokens / 1000000 * 0.15) + ($outputTokens / 1000000 * 0.60);
        
        // Log usage to database
        logAIChatUsage($db, $user['id'], $message, $responseLength, $tokensUsed, $modelName, $costEstimate);

        sendSuccess([
            'response' => $aiResponse
        ]);

    } catch (Exception $e) {
        error_log("handleAICarChat error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        sendError('I apologize, but I encountered an error processing your request. Please try again, or rephrase your question.', 500);
    }
}

/**
 * Detect if user message is asking about car hire (rental)
 */
function detectCarHireQuery($message) {
    $messageLower = strtolower($message);
    
    // FAST CHECK: Most specific patterns first for speed
    // Check for explicit "car hire" or "rental" patterns (highest priority)
    $specificPatterns = [
        '/car\s+hire/i',
        '/car\s+rental/i',
        '/rent\s+a\s+car/i',
        '/rental\s+car/i',
        '/hire\s+car/i',
        '/vehicle\s+hire/i',
        '/vehicle\s+rental/i',
        '/car\s+hire\s+company/i',
        '/rental\s+company/i',
        '/hire\s+service/i',
        '/rental\s+service/i'
    ];
    
    foreach ($specificPatterns as $pattern) {
        if (preg_match($pattern, $message)) {
            return true;
        }
    }
    
    // Check for "looking for car hire" or "need car hire" patterns
    if (preg_match('/(?:looking\s+for|need|want|find|search\s+for|show\s+me).*(?:car\s+hire|rental|to\s+hire|to\s+rent)/i', $message)) {
        return true;
    }
    
    // Check for "X seater" with hire/rental context
    if (preg_match('/(?:\d+\s*seater|\d+\s*seat).*(?:hire|rent|rental)/i', $message) || 
        preg_match('/(?:hire|rent|rental).*(?:\d+\s*seater|\d+\s*seat)/i', $message)) {
        return true;
    }
    
    // Only check generic "hire" or "rent" if they appear with car/vehicle context
    // This prevents false positives
    if (preg_match('/(?:car|vehicle|auto).*(?:hire|rent|rental)/i', $message) ||
        preg_match('/(?:hire|rent|rental).*(?:car|vehicle|auto)/i', $message)) {
        return true;
    }
    
    return false;
}

/**
 * Detect if user message is asking about car specifications
 */
function detectCarSpecQuery($message) {
    $messageLower = strtolower($message);
    
    // Keywords that indicate car spec queries
    $specKeywords = [
        'spec', 'specification', 'specs', 'specifications',
        'engine size', 'engine capacity', 'engine displacement',
        'fuel tank', 'fuel capacity', 'tank capacity',
        'transmission', 'gearbox', 'automatic', 'manual',
        'drivetrain', 'drive type', '4wd', 'awd', 'fwd', 'rwd',
        'horsepower', 'hp', 'torque', 'nm', 'kw',
        'fuel consumption', 'fuel economy', 'mpg', 'l/100km',
        'dimensions', 'length', 'width', 'height', 'wheelbase',
        'weight', 'curb weight', 'gross weight',
        'seating', 'seats', 'doors',
        'features', 'options', 'trim', 'variant',
        'what is the', 'tell me about', 'details about',
        'how much', 'how many', 'what size', 'what type',
        'compare', 'difference between', 'vs', 'versus'
    ];
    
    // Car model indicators
    $modelIndicators = [
        'hilux', 'fortuner', 'prado', 'landcruiser', 'corolla', 'camry', 'rav4',
        'navara', 'pathfinder', 'x-trail', 'sentra', 'altima',
        'ranger', 'everest', 'figo', 'ecosport',
        'crv', 'accord', 'civic', 'pilot',
        'x5', 'x3', 'x1', '3 series', '5 series', '7 series',
        'c class', 'e class', 's class', 'glc', 'gle', 'g class',
        'a4', 'a6', 'q5', 'q7', 'a3',
        'tucson', 'santa fe', 'ix35', 'elantra', 'sonata',
        'sportage', 'sorento', 'optima',
        'vitz', 'vios', 'yaris', 'aygo'
    ];
    
    // Check for spec keywords
    foreach ($specKeywords as $keyword) {
        if (strpos($messageLower, $keyword) !== false) {
            return true;
        }
    }
    
    // Check for car model + spec question pattern
    $hasModel = false;
    foreach ($modelIndicators as $model) {
        if (strpos($messageLower, $model) !== false) {
            $hasModel = true;
            break;
        }
    }
    
    // If has model and question words, likely a spec query
    if ($hasModel && preg_match('/(what|how|tell|show|give|find|search|spec|detail|info)/i', $message)) {
        return true;
    }
    
    // Pattern: "what is [car] [spec]" or "[car] [spec]"
    if (preg_match('/(?:what|tell|show|give).*(?:spec|engine|fuel|transmission|drivetrain|horsepower|torque)/i', $message)) {
        return true;
    }
    
    return false;
}

/**
 * Detect if user message is asking about car parts
 */
function detectPartsQuery($message) {
    $messageLower = strtolower($message);
    
    // Keywords that indicate parts queries
    $partsKeywords = [
        'part number', 'part no', 'oem', 'oem number', 'oem part',
        'brake pad', 'brake pads', 'brake disc', 'brake rotor',
        'water pump', 'timing belt', 'timing chain',
        'air filter', 'oil filter', 'fuel filter', 'cabin filter',
        'spark plug', 'spark plugs', 'ignition coil',
        'alternator', 'starter', 'battery',
        'shock absorber', 'struts', 'suspension',
        'headlight', 'taillight', 'bumper', 'fender',
        'windshield', 'windshield wiper', 'wiper blade',
        'tire', 'tyre', 'wheel', 'rim',
        'exhaust', 'muffler', 'catalytic converter',
        'radiator', 'cooling fan', 'thermostat',
        'transmission', 'clutch', 'flywheel',
        'crankshaft', 'camshaft', 'piston', 'cylinder head',
        'gasket', 'seal', 'bearing', 'bushing',
        'compatibility', 'cross reference', 'alternative part',
        'replace', 'replacement part', 'aftermarket part'
    ];
    
    // Check for parts keywords
    foreach ($partsKeywords as $keyword) {
        if (strpos($messageLower, $keyword) !== false) {
            return true;
        }
    }
    
    // Pattern: "[make] [model] [year] [part]" or "[part] for [make] [model]"
    if (preg_match('/(\w+\s+\w+)\s+(\d{4})?\s*(part|pad|pump|belt|filter|plug|coil|alternator|starter|battery)/i', $message)) {
        return true;
    }
    
    if (preg_match('/(part|pad|pump|belt|filter|plug|coil|alternator|starter|battery)\s+(?:for|of)\s+(\w+\s+\w+)/i', $message)) {
        return true;
    }
    
    return false;
}

/**
 * Detect if user is asking about fuel/gasoline prices
 */
function detectFuelPriceQuery($message) {
    $messageLower = strtolower($message);
    
    $fuelPriceKeywords = [
        'gasoline price', 'petrol price', 'fuel price', 'diesel price',
        'gas price', 'fuel cost', 'petrol cost', 'diesel cost',
        'current fuel price', 'current petrol price', 'current diesel price',
        'fuel prices', 'petrol prices', 'diesel prices', 'gas prices',
        'how much is fuel', 'how much is petrol', 'how much is diesel',
        'fuel rate', 'petrol rate', 'diesel rate',
        'price of fuel', 'price of petrol', 'price of diesel'
    ];
    
    foreach ($fuelPriceKeywords as $keyword) {
        if (strpos($messageLower, $keyword) !== false) {
            return true;
        }
    }
    
    return false;
}

/**
 * Handle fuel price queries
 */
function handleFuelPriceQuery($db, $message, $conversationHistory) {
    try {
        // Get fuel prices from database
        $stmt = $db->prepare("
            SELECT fuel_type, price_per_liter_mwk, price_per_liter_usd, 
                   currency, last_updated, date
            FROM fuel_prices 
            WHERE is_active = 1 AND date = CURDATE()
            ORDER BY fuel_type
        ");
        $stmt->execute();
        $prices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // If no prices for today, get most recent
        if (empty($prices)) {
            $stmt = $db->prepare("
                SELECT fuel_type, price_per_liter_mwk, price_per_liter_usd, 
                       currency, last_updated, date
                FROM fuel_prices 
                WHERE is_active = 1
                ORDER BY date DESC, fuel_type
                LIMIT 10
            ");
            $stmt->execute();
            $prices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // Get base URL
        $serverHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $baseUrl = $protocol . '://' . $serverHost . '/';
        
        // Build response
        if (empty($prices)) {
            $response = "I don't have current fuel prices in the database right now. Please check the [Journey Planner]({$baseUrl}car-database.html#journey-planner) for the latest fuel prices, or contact local fuel stations for current rates.";
        } else {
            $lastUpdated = !empty($prices[0]['last_updated']) ? date('F j, Y g:i A', strtotime($prices[0]['last_updated'])) : 'Recently';
            
            $response = "Here are the current fuel prices:\n\n";
            
            foreach ($prices as $price) {
                $fuelType = ucfirst($price['fuel_type']);
                $priceMWK = number_format($price['price_per_liter_mwk'], 2);
                $cc = getChatCurrencyCode($db);
                $response .= "⛽ **{$fuelType}**: {$cc} {$priceMWK} per liter";
                if (!empty($price['price_per_liter_usd'])) {
                    $priceUSD = number_format($price['price_per_liter_usd'], 2);
                    $response .= " (USD \${$priceUSD})";
                }
                $response .= "\n";
            }
            
            $response .= "\n📅 Last updated: {$lastUpdated}\n";
            $response .= "\n💡 Tip: Use the [Journey Planner]({$baseUrl}car-database.html#journey-planner) to calculate fuel costs for your trips!";
        }
        
        sendSuccess([
            'response' => $response,
            'fuel_prices' => $prices
        ]);
    } catch (Exception $e) {
        error_log("handleFuelPriceQuery error: " . $e->getMessage());
        sendError('I apologize, but I encountered an error while fetching fuel prices. Please try again!', 500);
    }
}

/**
 * Detect if user message is a search query (for buying cars)
 */
function detectSearchQuery($message) {
    // CRITICAL: Exclude car hire queries FIRST (before any other checks)
    // This ensures "looking for car hire" doesn't match as a search query
    if (detectCarHireQuery($message)) {
        return false;
    }
    
    // Exclude dealer queries
    if (detectDealerQuery($message)) {
        return false;
    }
    
    // Exclude garage queries
    if (detectGarageQuery($message)) {
        return false;
    }
    
    $messageLower = strtolower($message);

    // Treat concise filter-only queries like "SUV in Lilongwe" as listing searches.
    $hasLocationPhrase = preg_match('/\b(?:in|at|around|near)\s+[a-zA-Z][a-zA-Z\-\s]{2,30}\b/i', $message) === 1;
    $hasBodyTypeToken = preg_match('/\b(suv|sucv|sedan|hatchback|pickup|truck|wagon|minivan|van|crossover)\b/i', $messageLower) === 1;
    if ($hasLocationPhrase && $hasBodyTypeToken) {
        return true;
    }
    
    // FAST CHECK: Must have buying/selling intent for car listings
    // Car listings are ONLY for buying/selling, not for hire/rental
    $buyingKeywords = ['buy', 'purchase', 'for sale', 'selling', 'sell', 'price', 'cost', 'million', 'kwacha', 'own', 'looking for', 'i want', 'i need', 'find me', 'show me'];
    $hasBuyingIntent = false;
    foreach ($buyingKeywords as $keyword) {
        if (strpos($messageLower, $keyword) !== false) {
            $hasBuyingIntent = true;
            break;
        }
    }
    
    // Also check for specific car model/make mentions which imply buying intent
    $carModels = ['fortuner', 'prado', 'hilux', 'landcruiser', 'land cruiser', 'corolla', 'camry', 'rav4', 'harrier', 'vitz', 'axio', 'fielder', 'premio', 'allion', 'wish', 'noah', 'voxy', 'alphard', 'hiace', 'ranger', 'bt-50', 'cx-5', 'cx-3', 'demio', 'mazda3', 'mazda6', 'atenza', 'fit', 'civic', 'accord', 'crv', 'hrv', 'vezel', 'jazz', 'x-trail', 'qashqai', 'note', 'tiida', 'sunny', 'patrol', 'navara', 'np300', 'swift', 'alto', 'jimny', 'vitara', 'escudo', 'forester', 'outback', 'impreza', 'legacy', 'xv', 'benz', 'mercedes', 'bmw', 'audi', 'volkswagen', 'vw', 'golf', 'polo', 'passat', 'tiguan', 'defender', 'discovery', 'range rover', 'evoque', 'sport'];
    if (!$hasBuyingIntent) {
        foreach ($carModels as $model) {
            if (strpos($messageLower, $model) !== false) {
                $hasBuyingIntent = true;
                break;
            }
        }
    }
    
    // If no buying intent, it's NOT a car listing search
    if (!$hasBuyingIntent) {
        return false;
    }
    
    $searchKeywords = [
        'find', 'search', 'show me', 'look for', 'looking for', 'need', 'want',
        'available', 'list', 'display', 'get me', 'help me find', 'do you have',
        'are there', 'can you find', 'find me', 'show', 'see', 'browse'
    ];
    
    foreach ($searchKeywords as $keyword) {
        if (strpos($messageLower, $keyword) !== false) {
            // Additional check: should mention car-related terms
            $carTerms = ['car', 'vehicle', 'toyota', 'bmw', 'mercedes', 'honda', 'nissan', 'ford', 'mazda', 'suzuki', 'subaru', 'mitsubishi', 'volkswagen', 'vw', 'audi', 'land rover',
                        'hilux', 'prado', 'landcruiser', 'land cruiser', 'fortuner', 'rav4', 'harrier', 'vitz', 'axio', 'fielder', 'premio', 'allion', 'wish', 'noah', 'voxy', 'alphard', 'hiace',
                        'ranger', 'bt-50', 'cx-5', 'cx-3', 'demio', 'mazda3', 'atenza',
                        'fit', 'civic', 'accord', 'crv', 'hr-v', 'vezel', 'jazz',
                        'x-trail', 'qashqai', 'note', 'tiida', 'sunny', 'patrol', 'navara',
                        'swift', 'alto', 'jimny', 'vitara', 'escudo',
                        'forester', 'outback', 'impreza', 'legacy', 'xv',
                        'pajero', 'outlander', 'asx', 'triton', 'l200',
                        'golf', 'polo', 'passat', 'tiguan',
                        'defender', 'discovery', 'range rover', 'evoque',
                        'corolla', 'camry', 'prius', 'year', 'model', 'make'];
            
            foreach ($carTerms as $term) {
                if (strpos($messageLower, $term) !== false) {
                    // If it has buying intent or no rental keywords, it's a search query
                    if ($hasBuyingIntent || !preg_match('/(?:hire|rent|rental)/i', $messageLower)) {
                        return true;
                    }
                }
            }
        }
    }
    
    return false;
}

/**
 * Build an enriched search message for contextual follow-up queries.
 * Example: previous "available cars in Lilongwe" + current "which is cheapest?"
 */
function buildSearchFollowUpMessage($message, $conversationHistory) {
    if (detectPriceComparativeQuery($message) === false) {
        return false;
    }

    // Direct price-comparison searches are already handled by detectSearchQuery.
    if (detectSearchQuery($message)) {
        return false;
    }

    if (!is_array($conversationHistory) || empty($conversationHistory)) {
        return false;
    }

    $priorUserMessage = '';

    // Walk backward to find the latest user message likely containing search filters.
    for ($i = count($conversationHistory) - 1; $i >= 0; $i--) {
        $item = $conversationHistory[$i];
        if (!is_array($item) || ($item['role'] ?? '') !== 'user') {
            continue;
        }

        $content = trim((string)($item['content'] ?? ''));
        if ($content === '') {
            continue;
        }

        if (detectSearchQuery($content)) {
            $priorUserMessage = $content;
            break;
        }

        // Keep the latest non-empty user message as a fallback context.
        if ($priorUserMessage === '') {
            $priorUserMessage = $content;
        }
    }

    if ($priorUserMessage === '') {
        return false;
    }

    return trim($priorUserMessage . ' ' . $message);
}

/**
 * Build a contextual listing-search follow-up when user only changes location.
 * Example: previous "I am looking for an SUV in Lilongwe" + current "What about Salima"
 * => "I am looking for an SUV in Salima"
 */
function buildLocationFollowUpSearchMessage($db, $message, $conversationHistory) {
    $current = trim((string)$message);
    if ($current === '') {
        return false;
    }

    // If current message is already a full search query, no follow-up rewrite needed.
    if (detectSearchQuery($current)) {
        return false;
    }

    if (!is_array($conversationHistory) || empty($conversationHistory)) {
        return false;
    }

    $location = extractLocationMentionFromText($db, $current);
    if (empty($location)) {
        return false;
    }

    // Follow-up phrasing hints. Also allow very short location-only messages.
    $isLikelyFollowUp = preg_match('/\b(what about|how about|about|instead|then|and|try)\b/i', $current) === 1
        || str_word_count($current) <= 4;
    if (!$isLikelyFollowUp) {
        return false;
    }

    $priorSearchMessage = '';
    for ($i = count($conversationHistory) - 1; $i >= 0; $i--) {
        $item = $conversationHistory[$i];
        if (!is_array($item) || ($item['role'] ?? '') !== 'user') {
            continue;
        }

        $content = trim((string)($item['content'] ?? ''));
        if ($content === '') {
            continue;
        }

        if (detectSearchQuery($content)) {
            $priorSearchMessage = $content;
            break;
        }
    }

    if ($priorSearchMessage === '') {
        return false;
    }

    // Remove prior location from the old query, then inject new target location.
    $priorParams = simpleExtractParams($db, $priorSearchMessage);
    $base = $priorSearchMessage;

    if (!empty($priorParams['location'])) {
        $oldLoc = (string)$priorParams['location'];
        $base = preg_replace('/\b(in|at|around|near)\s+' . preg_quote($oldLoc, '/') . '\b/i', '', $base);
        $base = preg_replace('/\b' . preg_quote($oldLoc, '/') . '\b/i', '', $base);
    }

    $base = trim(preg_replace('/\s+/', ' ', (string)$base));
    if ($base === '') {
        $base = 'I am looking for a vehicle';
    }

    return trim($base . ' in ' . $location);
}

/**
 * Build a contextual car-hire follow-up when user only changes location.
 * Example: previous "Looking for an SUV to hire in Lilongwe" + current "What about Salima"
 * => "Looking for an SUV to hire in Salima"
 */
function buildLocationFollowUpCarHireMessage($db, $message, $conversationHistory) {
    $current = trim((string)$message);
    if ($current === '') {
        return false;
    }

    // If current message is already an explicit car-hire query, no rewrite needed.
    if (detectCarHireQuery($current)) {
        return false;
    }

    if (!is_array($conversationHistory) || empty($conversationHistory)) {
        return false;
    }

    $location = extractLocationMentionFromText($db, $current);
    if (empty($location)) {
        return false;
    }

    $isLikelyFollowUp = preg_match('/\b(what about|how about|about|instead|then|and|try)\b/i', $current) === 1
        || str_word_count($current) <= 4;
    if (!$isLikelyFollowUp) {
        return false;
    }

    $priorCarHireMessage = '';
    for ($i = count($conversationHistory) - 1; $i >= 0; $i--) {
        $item = $conversationHistory[$i];
        if (!is_array($item) || ($item['role'] ?? '') !== 'user') {
            continue;
        }

        $content = trim((string)($item['content'] ?? ''));
        if ($content === '') {
            continue;
        }

        if (detectCarHireQuery($content)) {
            $priorCarHireMessage = $content;
            break;
        }
    }

    if ($priorCarHireMessage === '') {
        return false;
    }

    $base = $priorCarHireMessage;
    $priorParams = extractCarHireSearchParams($priorCarHireMessage);
    if (!empty($priorParams['location'])) {
        $oldLoc = (string)$priorParams['location'];
        $base = preg_replace('/\b(in|at|around|near)\s+' . preg_quote($oldLoc, '/') . '\b/i', '', $base);
        $base = preg_replace('/\b' . preg_quote($oldLoc, '/') . '\b/i', '', $base);
    }

    $base = trim(preg_replace('/\s+/', ' ', (string)$base));
    if ($base === '') {
        $base = 'I am looking for a vehicle to hire';
    }

    return trim($base . ' in ' . $location);
}

/**
 * Build a contextual car-hire comparative follow-up.
 * Example: previous "Looking for a car hire in Blantyre" + current "What is the cheapest"
 * => "Looking for a car hire in Blantyre What is the cheapest"
 */
function buildCarHireComparativeFollowUpMessage($db, $message, $conversationHistory) {
    $current = trim((string)$message);
    if ($current === '') {
        return false;
    }

    if (detectPriceComparativeQuery($current) === false) {
        return false;
    }

    // If already explicit car-hire comparative query, no rewrite needed.
    if (detectCarHireQuery($current)) {
        return false;
    }

    if (!is_array($conversationHistory) || empty($conversationHistory)) {
        return false;
    }

    $priorCarHireMessage = '';
    $latestUserMessage = '';
    $sawCarHireAssistant = false;

    for ($i = count($conversationHistory) - 1; $i >= 0; $i--) {
        $item = $conversationHistory[$i];
        if (!is_array($item)) {
            continue;
        }

        $role = (string)($item['role'] ?? '');
        $content = trim((string)($item['content'] ?? ''));
        if ($content === '') {
            continue;
        }

        if ($role === 'assistant' && preg_match('/car\s+hire\s+compan/i', $content)) {
            $sawCarHireAssistant = true;
            continue;
        }

        if ($role !== 'user') {
            continue;
        }

        if ($latestUserMessage === '') {
            $latestUserMessage = $content;
        }

        if (detectCarHireQuery($content)) {
            $priorCarHireMessage = $content;
            break;
        }
    }

    if ($priorCarHireMessage === '' && $sawCarHireAssistant) {
        $location = extractLocationMentionFromText($db, $latestUserMessage);
        if (!empty($location)) {
            $priorCarHireMessage = 'Looking for a car hire in ' . $location;
        } else {
            $priorCarHireMessage = 'Looking for a car hire';
        }
    }

    if ($priorCarHireMessage === '') {
        return false;
    }

    return trim($priorCarHireMessage . ' ' . $current);
}

/**
 * Detect short contact-detail follow-up requests.
 */
function isContactInfoFollowUpQuery($message) {
    $text = strtolower(trim((string)$message));
    if ($text === '') {
        return false;
    }

    $hasContactWord = preg_match('/\b(contact|phone|number|call|whatsapp|reach)\b/i', $text) === 1;
    if (!$hasContactWord) {
        return false;
    }

    // Prefer short follow-ups or pronoun-based references like "their number".
    $hasReferenceWord = preg_match('/\b(their|them|those|these|that|it|him|her)\b/i', $text) === 1;
    return $hasReferenceWord || str_word_count($text) <= 8;
}

/**
 * Build a contextual contact follow-up query from prior user intent.
 * Example: previous "Looking for car hire in Lilongwe" + "Give me their contact number"
 * => "Looking for car hire in Lilongwe contact number"
 */
function buildContactFollowUpMessage($db, $message, $conversationHistory) {
    $current = trim((string)$message);
    if (!isContactInfoFollowUpQuery($current)) {
        return false;
    }

    if (!is_array($conversationHistory) || empty($conversationHistory)) {
        return false;
    }

    $latestUserMessage = '';
    $sawCarHireAssistant = false;
    $sawDealerAssistant = false;
    $sawGarageAssistant = false;

    for ($i = count($conversationHistory) - 1; $i >= 0; $i--) {
        $item = $conversationHistory[$i];
        if (!is_array($item)) {
            continue;
        }

        $role = (string)($item['role'] ?? '');
        $content = trim((string)($item['content'] ?? ''));
        if ($content === '') {
            continue;
        }

        if ($role === 'assistant') {
            if (preg_match('/car\s+hire\s+compan/i', $content)) {
                $sawCarHireAssistant = true;
            }
            if (preg_match('/\bdealer\b|\bshowroom\b/i', $content)) {
                $sawDealerAssistant = true;
            }
            if (preg_match('/\bgarage\b|\bworkshop\b/i', $content)) {
                $sawGarageAssistant = true;
            }
            continue;
        }

        if ($role !== 'user') {
            continue;
        }

        if ($latestUserMessage === '') {
            $latestUserMessage = $content;
        }

        if (detectCarHireQuery($content)) {
            return trim($content . ' contact number');
        }

        if (detectDealerQuery($content)) {
            return trim($content . ' contact number');
        }

        if (detectGarageQuery($content)) {
            return trim($content . ' contact number');
        }

        if (detectSearchQuery($content)) {
            return trim($content . ' seller contact number');
        }
    }

    // Fallback when assistant context exists but prior user intent message is noisy/misspelled.
    $location = extractLocationMentionFromText($db, $latestUserMessage);
    if ($sawCarHireAssistant) {
        return trim('show contact numbers for car hire companies' . (!empty($location) ? ' in ' . $location : ''));
    }
    if ($sawDealerAssistant) {
        return trim('show contact numbers for car dealers' . (!empty($location) ? ' in ' . $location : ''));
    }
    if ($sawGarageAssistant) {
        return trim('show contact numbers for garages' . (!empty($location) ? ' in ' . $location : ''));
    }

    return false;
}

/**
 * AI-assisted conversational intent resolver for ambiguous natural-language follow-ups.
 * Returns: ['intent' => string, 'rewritten_query' => string, 'confidence' => float, 'out_of_scope' => bool]
 */
function resolveConversationalIntentWithAI($db, $message, $conversationHistory, $settings, $provider, $providerConfig, $modelName) {
    if (!function_exists('curl_init')) {
        return [];
    }

    $providerApiKey = getAIProviderApiKeyFromDB($provider, $db);
    if (empty($providerApiKey)) {
        return [];
    }

    $recentHistory = [];
    if (is_array($conversationHistory) && !empty($conversationHistory)) {
        $slice = array_slice($conversationHistory, -8);
        foreach ($slice as $item) {
            if (!is_array($item)) {
                continue;
            }
            $role = strtolower(trim((string)($item['role'] ?? '')));
            $content = trim((string)($item['content'] ?? ''));
            if ($content === '' || ($role !== 'user' && $role !== 'assistant')) {
                continue;
            }
            $recentHistory[] = [
                'role' => $role,
                'content' => mb_substr($content, 0, 280)
            ];
        }
    }

    $intentPrompt = "Classify the user's latest message into one intent for an AI assistant on an automotive marketplace, and rewrite ambiguous follow-ups into a fully explicit query using conversation context.\n\n"
        . "Allowed intents:\n"
        . "- car_hire\n"
        . "- dealer\n"
        . "- garage\n"
        . "- listings\n"
        . "- fuel\n"
        . "- spec\n"
        . "- recommendation\n"
        . "- general_automotive\n"
        . "- general_knowledge (for non-automotive questions like math, science, history, weather, coding, etc.)\n"
        . "- out_of_scope (ONLY for harmful/inappropriate content)\n\n"
        . "Rules:\n"
        . "1. Use conversation context heavily for pronouns and short follow-ups (their, them, that one, cheapest, contact number).\n"
        . "2. Keep rewritten_query concise and faithful to user constraints (location, category, business type).\n"
        . "3. If the user is asking about automotive/MotorLink, do NOT classify as out_of_scope.\n"
        . "4. If the user asks a general knowledge question (not automotive), use general_knowledge intent — NOT out_of_scope.\n"
        . "5. Only use out_of_scope for harmful, explicit, or dangerous content.\n"
        . "6. Return STRICT JSON only with this schema:\n"
        . "{\"intent\":\"...\",\"rewritten_query\":\"...\",\"confidence\":0.0,\"out_of_scope\":false}\n\n"
        . "Conversation history JSON:\n"
        . json_encode($recentHistory, JSON_UNESCAPED_UNICODE)
        . "\n\nLatest user message:\n"
        . $message;

    $payload = [
        'model' => $modelName,
        'messages' => [
            ['role' => 'system', 'content' => 'You are a strict JSON intent resolver for an automotive assistant. Return valid JSON only.'],
            ['role' => 'user', 'content' => $intentPrompt]
        ],
        'temperature' => 0.1,
        'max_tokens' => 220
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $providerConfig['url']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $providerApiKey
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 18);

    $isWindows = (PHP_OS_FAMILY === 'Windows');
    $serverHost = $_SERVER['HTTP_HOST'] ?? '';
    $isLocalDev = (strpos($serverHost, 'localhost') !== false || strpos($serverHost, '127.0.0.1') !== false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !($isLocalDev || $isWindows));
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, ($isLocalDev || $isWindows) ? 0 : 2);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || empty($response)) {
        return [];
    }

    $apiData = json_decode($response, true);
    $rawContent = trim((string)($apiData['choices'][0]['message']['content'] ?? ''));
    if ($rawContent === '') {
        return [];
    }

    $jsonStr = preg_replace('/```json\s*/i', '', $rawContent);
    $jsonStr = preg_replace('/```\s*/', '', (string)$jsonStr);
    $jsonStr = trim((string)$jsonStr);

    if ($jsonStr === '' || $jsonStr[0] !== '{') {
        if (preg_match('/\{.*\}/s', $jsonStr, $matches)) {
            $jsonStr = $matches[0];
        }
    }

    $parsed = json_decode($jsonStr, true);
    if (!is_array($parsed)) {
        return [];
    }

    $intent = strtolower(trim((string)($parsed['intent'] ?? '')));
    $allowed = ['car_hire', 'dealer', 'garage', 'listings', 'fuel', 'spec', 'recommendation', 'general_automotive', 'general_knowledge', 'out_of_scope'];
    if (!in_array($intent, $allowed, true)) {
        return [];
    }

    $confidence = (float)($parsed['confidence'] ?? 0);
    if ($confidence > 0 && $confidence < 0.55) {
        return [];
    }

    return [
        'intent' => $intent,
        'rewritten_query' => trim((string)($parsed['rewritten_query'] ?? '')),
        'confidence' => $confidence,
        'out_of_scope' => (bool)($parsed['out_of_scope'] ?? false)
    ];
}

/**
 * Build a contextual garage follow-up when user only changes location.
 */
function buildLocationFollowUpGarageMessage($db, $message, $conversationHistory) {
    $current = trim((string)$message);
    if ($current === '') {
        return false;
    }

    if (detectGarageQuery($current)) {
        return false;
    }

    if (!is_array($conversationHistory) || empty($conversationHistory)) {
        return false;
    }

    $location = extractLocationMentionFromText($db, $current);
    if (empty($location)) {
        return false;
    }

    $isLikelyFollowUp = preg_match('/\b(what about|how about|about|instead|then|and|try)\b/i', $current) === 1
        || str_word_count($current) <= 4;
    if (!$isLikelyFollowUp) {
        return false;
    }

    $priorGarageMessage = '';
    for ($i = count($conversationHistory) - 1; $i >= 0; $i--) {
        $item = $conversationHistory[$i];
        if (!is_array($item) || ($item['role'] ?? '') !== 'user') {
            continue;
        }

        $content = trim((string)($item['content'] ?? ''));
        if ($content === '') {
            continue;
        }

        if (detectGarageQuery($content)) {
            $priorGarageMessage = $content;
            break;
        }
    }

    if ($priorGarageMessage === '') {
        return false;
    }

    $base = $priorGarageMessage;
    $priorParams = extractGarageSearchParams($priorGarageMessage);
    if (!empty($priorParams['location'])) {
        $oldLoc = (string)$priorParams['location'];
        $base = preg_replace('/\b(in|at|around|near)\s+' . preg_quote($oldLoc, '/') . '\b/i', '', $base);
        $base = preg_replace('/\b' . preg_quote($oldLoc, '/') . '\b/i', '', $base);
    }

    $base = trim(preg_replace('/\s+/', ' ', (string)$base));
    if ($base === '') {
        $base = 'I am looking for a garage';
    }

    return trim($base . ' in ' . $location);
}

/**
 * Build a contextual dealer follow-up when user only changes location.
 */
function buildLocationFollowUpDealerMessage($db, $message, $conversationHistory) {
    $current = trim((string)$message);
    if ($current === '') {
        return false;
    }

    if (detectDealerQuery($current)) {
        return false;
    }

    if (!is_array($conversationHistory) || empty($conversationHistory)) {
        return false;
    }

    $location = extractLocationMentionFromText($db, $current);
    if (empty($location)) {
        return false;
    }

    $isLikelyFollowUp = preg_match('/\b(what about|how about|about|instead|then|and|try)\b/i', $current) === 1
        || str_word_count($current) <= 4;
    if (!$isLikelyFollowUp) {
        return false;
    }

    $priorDealerMessage = '';
    for ($i = count($conversationHistory) - 1; $i >= 0; $i--) {
        $item = $conversationHistory[$i];
        if (!is_array($item) || ($item['role'] ?? '') !== 'user') {
            continue;
        }

        $content = trim((string)($item['content'] ?? ''));
        if ($content === '') {
            continue;
        }

        if (detectDealerQuery($content)) {
            $priorDealerMessage = $content;
            break;
        }
    }

    if ($priorDealerMessage === '') {
        return false;
    }

    $base = $priorDealerMessage;
    $priorParams = extractDealerSearchParams($db, $priorDealerMessage);
    if (!empty($priorParams['location'])) {
        $oldLoc = (string)$priorParams['location'];
        $base = preg_replace('/\b(in|at|around|near)\s+' . preg_quote($oldLoc, '/') . '\b/i', '', $base);
        $base = preg_replace('/\b' . preg_quote($oldLoc, '/') . '\b/i', '', $base);
    }

    $base = trim(preg_replace('/\s+/', ' ', (string)$base));
    if ($base === '') {
        $base = 'I am looking for a car dealer';
    }

    return trim($base . ' in ' . $location);
}

/**
 * Build a contextual follow-up for general automotive intents.
 * Example: previous "Tell me about Toyota Fortuner specs" + current "What about Toyota Hilux"
 */
function buildGeneralAutomotiveFollowUpMessage($message, $conversationHistory) {
    $current = trim((string)$message);
    if ($current === '') {
        return false;
    }

    // If already clearly in automotive scope, no rewrite needed.
    if (!isOutOfScopeQuery($current)) {
        return false;
    }

    if (!is_array($conversationHistory) || empty($conversationHistory)) {
        return false;
    }

    $isLikelyFollowUp = preg_match('/\b(what about|how about|about|instead|then|and|try|that one|this one|other one)\b/i', $current) === 1
        || str_word_count($current) <= 6;
    if (!$isLikelyFollowUp) {
        return false;
    }

    $priorAutomotiveMessage = '';
    for ($i = count($conversationHistory) - 1; $i >= 0; $i--) {
        $item = $conversationHistory[$i];
        if (!is_array($item) || ($item['role'] ?? '') !== 'user') {
            continue;
        }

        $content = trim((string)($item['content'] ?? ''));
        if ($content === '') {
            continue;
        }

        if (
            detectCarHireQuery($content) ||
            detectDealerQuery($content) ||
            detectGarageQuery($content) ||
            detectSearchQuery($content) ||
            detectCarSpecQuery($content) ||
            detectCarRecommendationQuery($content) ||
            detectFuelPriceQuery($content) ||
            detectPartsQuery($content)
        ) {
            $priorAutomotiveMessage = $content;
            break;
        }
    }

    if ($priorAutomotiveMessage === '') {
        return false;
    }

    return trim($priorAutomotiveMessage . ' ' . $current);
}

/**
 * Extract canonical location mention from free text (exact or fuzzy typo match).
 */
function extractLocationMentionFromText($db, $text) {
    $msg = strtolower(trim((string)$text));
    if ($msg === '') {
        return null;
    }

    try {
        $stmt = $db->query("SELECT name FROM locations ORDER BY name ASC");
        $locations = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($locations as $name) {
            $nameLower = strtolower((string)$name);
            if (preg_match('/\\b' . preg_quote($nameLower, '/') . '\\b/i', $msg)) {
                return (string)$name;
            }
        }

        // Fuzzy match for common misspellings in short follow-ups.
        $tokens = preg_split('/[^a-z]+/', $msg);
        if (!is_array($tokens)) {
            return null;
        }

        $best = null;
        $bestDistance = PHP_INT_MAX;
        foreach ($tokens as $token) {
            if ($token === '' || strlen($token) < 4) {
                continue;
            }
            foreach ($locations as $name) {
                $distance = levenshtein($token, strtolower((string)$name));
                if ($distance < $bestDistance) {
                    $bestDistance = $distance;
                    $best = (string)$name;
                }
            }
        }

        if ($best !== null && $bestDistance <= 2) {
            return $best;
        }
    } catch (Exception $e) {
        error_log('extractLocationMentionFromText error: ' . $e->getMessage());
    }

    return null;
}

/**
 * Handle search query - extract parameters and search listings
 */
function handleSearchQuery($db, $message, $conversationHistory) {
    try {
        // Deterministic-first extraction: use DB-backed parsing first.
        // Only call provider extraction when deterministic parsing cannot infer enough constraints.
        $searchParams = simpleExtractParams($db, $message);

        if (!hasMeaningfulSearchParams($searchParams)) {
            $aiExtractedParams = extractSearchParams($db, $message);
            if (!empty($aiExtractedParams)) {
                $searchParams = $aiExtractedParams;
            }
        }

        // Normalize fuzzy user input (typos, variants) into strict database-backed values.
        $searchParams = normalizeSearchParams($db, $searchParams, $message);
        
        // Build search query for listings
        $searchQuery = [];
        
        if (!empty($searchParams['make'])) {
            // Get make ID
            $makeStmt = $db->prepare("SELECT id FROM car_makes WHERE LOWER(name) LIKE ? LIMIT 1");
            $makeStmt->execute(['%' . strtolower($searchParams['make']) . '%']);
            $make = $makeStmt->fetch(PDO::FETCH_ASSOC);
            if ($make) {
                $searchQuery['make_id'] = $make['id'];
            }
        }
        
        if (!empty($searchParams['model'])) {
            // Get model ID (if make is also specified, use it)
            $modelQuery = "SELECT id FROM car_models WHERE LOWER(name) LIKE ?";
            $modelParams = ['%' . strtolower($searchParams['model']) . '%'];
            
            if (!empty($searchQuery['make_id'])) {
                $modelQuery .= " AND make_id = ?";
                $modelParams[] = $searchQuery['make_id'];
            }
            
            $modelStmt = $db->prepare($modelQuery . " LIMIT 1");
            $modelStmt->execute($modelParams);
            $model = $modelStmt->fetch(PDO::FETCH_ASSOC);
            if ($model) {
                $searchQuery['model_id'] = $model['id'];
            }
        }
        
        if (!empty($searchParams['max_price'])) {
            $searchQuery['max_price'] = $searchParams['max_price'];
        }
        
        if (!empty($searchParams['min_price'])) {
            $searchQuery['min_price'] = $searchParams['min_price'];
        }
        
        if (!empty($searchParams['min_year'])) {
            $searchQuery['min_year'] = $searchParams['min_year'];
        }
        
        if (!empty($searchParams['max_year'])) {
            $searchQuery['max_year'] = $searchParams['max_year'];
        }
        
        if (!empty($searchParams['min_mileage'])) {
            $searchQuery['min_mileage'] = $searchParams['min_mileage'];
        }
        
        if (!empty($searchParams['max_mileage'])) {
            $searchQuery['max_mileage'] = $searchParams['max_mileage'];
        }
        
        if (!empty($searchParams['body_type'])) {
            $searchQuery['category'] = $searchParams['body_type'];
        }
        
        if (!empty($searchParams['color'])) {
            $searchQuery['color'] = $searchParams['color'];
        }
        
        if (!empty($searchParams['fuel_type'])) {
            $searchQuery['fuel_type'] = $searchParams['fuel_type'];
        }
        
        if (!empty($searchParams['transmission'])) {
            $searchQuery['transmission'] = $searchParams['transmission'];
        }
        
        if (!empty($searchParams['seats'])) {
            $searchQuery['seats'] = (int)$searchParams['seats'];
        }
        
        if (!empty($searchParams['doors'])) {
            $searchQuery['doors'] = (int)$searchParams['doors'];
        }
        
        if (!empty($searchParams['location'])) {
            // Normalize location name (capitalize first letter)
            $location = ucfirst(strtolower(trim($searchParams['location'])));
            $searchQuery['location'] = $location;
            
            // Also try to get location ID for more precise matching
            $locStmt = $db->prepare("SELECT id FROM locations WHERE LOWER(name) = ? OR LOWER(name) LIKE ? LIMIT 1");
            $locLower = strtolower($location);
            $locStmt->execute([$locLower, '%' . $locLower . '%']);
            $locationData = $locStmt->fetch(PDO::FETCH_ASSOC);
            if ($locationData) {
                $searchQuery['location_id'] = $locationData['id'];
            }
        }
        
        if (!empty($searchParams['drivetrain'])) {
            $searchQuery['drivetrain'] = $searchParams['drivetrain'];
        }
        
        if (!empty($searchParams['condition'])) {
            $searchQuery['condition'] = $searchParams['condition'];
        }
        
        // Detect price-based comparative queries (cheapest, most expensive, best value)
        // First check if AI extraction found it, otherwise use direct detection
        if (!empty($searchParams['price_comparison'])) {
            $searchQuery['sort_by_price'] = $searchParams['price_comparison'];
        } else {
            $priceComparison = detectPriceComparativeQuery($message);
            if ($priceComparison) {
                $searchQuery['sort_by_price'] = $priceComparison;
            }
        }
        
        // Debug: Log search query (only in development)
        $isLocalDev = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false || 
                       strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') !== false);
        if ($isLocalDev) {
            error_log("Search query built: " . json_encode($searchQuery));
        }
        
        // Search listings using the existing getListings function logic
        $listings = searchListings($db, $searchQuery);
        
        // Debug: Log results count (only in development)
        if ($isLocalDev) {
            error_log("Search results count: " . count($listings));
        }
        
        // Get base URL for generating links
        $serverHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        $isLocalhost = in_array($serverHost, ['localhost', '127.0.0.1']) || 
                       strpos($serverHost, 'localhost:') === 0 || 
                       strpos($serverHost, '127.0.0.1:') === 0 ||
                       preg_match('/^(192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $serverHost);
        $isProduction = !$isLocalhost && !empty($serverHost);
        $isLocalDev = $isLocalhost;
        
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $baseUrl = $protocol . '://' . $serverHost . '/';
        
        // Detect if this is a comparative query
        $isPriceComparison = !empty($searchQuery['sort_by_price']);
        $isMostQuery = detectComparativeQuery($message) === 'most' || detectComparativeQuery($message) === 'largest' || detectComparativeQuery($message) === 'biggest';
        
        // Format response with links - CLINICAL and PRECISE
        if (empty($listings)) {
            // Build alternative search suggestions
            $alternatives = [];
            $hasMake = !empty($searchQuery['make_id']);
            $hasModel = !empty($searchQuery['model_id']);
            $hasLocation = !empty($searchQuery['location']);

            // Strict location behavior: if the user asked for a location, never broaden to other locations.
            if ($hasLocation) {
                $locationName = !empty($searchQuery['location']) ? $searchQuery['location'] : (!empty($searchParams['location']) ? $searchParams['location'] : 'that location');
                $response = "I couldn't find any vehicles matching your criteria in {$locationName}. ";
                $response .= "I won't show vehicles from other locations unless you ask me to broaden the search. ";
                $response .= "You can try a nearby location, adjust make/model, or remove one filter.";

                sendSuccess([
                    'response' => $response,
                    'search_results' => [],
                    'total_results' => 0
                ]);
                return;
            }
            
            // Try alternatives: remove location first, then model, then make
            if ($hasLocation) {
                // Try without location
                $altQuery = $searchQuery;
                unset($altQuery['location'], $altQuery['location_id']);
                $altListings = searchListings($db, $altQuery);
                if (!empty($altListings)) {
                    $makeName = !empty($searchParams['make']) ? $searchParams['make'] : '';
                    $modelName = !empty($searchParams['model']) ? $searchParams['model'] : 'vehicles';
                    $locationName = !empty($searchParams['location']) ? $searchParams['location'] : 'that location';
                    $alternatives[] = [
                        'type' => 'without_location',
                        'listings' => array_slice($altListings, 0, 3),
                        'message' => "I couldn't find any {$makeName} {$modelName} in {$locationName}, but here are some available elsewhere:"
                    ];
                }
            }
            
            if ($hasModel && empty($alternatives)) {
                // Try same make, different model
                $altQuery = $searchQuery;
                unset($altQuery['model_id'], $altQuery['location'], $altQuery['location_id']);
                $altListings = searchListings($db, $altQuery);
                if (!empty($altListings)) {
                    $modelName = !empty($searchParams['model']) ? $searchParams['model'] : 'that model';
                    $locationName = !empty($searchParams['location']) ? "in {$searchParams['location']}" : 'in your area';
                    $makeName = !empty($searchParams['make']) ? $searchParams['make'] : '';
                    $alternatives[] = [
                        'type' => 'different_model',
                        'listings' => array_slice($altListings, 0, 3),
                        'message' => "I couldn't find {$modelName} {$locationName}, but here are other {$makeName} vehicles:"
                    ];
                }
            }
            
            if ($hasMake && empty($alternatives)) {
                // Try different make (but keep location if specified)
                $altQuery = $searchQuery;
                unset($altQuery['make_id'], $altQuery['model_id']);
                $altListings = searchListings($db, $altQuery);
                if (!empty($altListings)) {
                    $makeName = !empty($searchParams['make']) ? $searchParams['make'] : '';
                    $modelName = !empty($searchParams['model']) ? $searchParams['model'] : '';
                    $locationName = !empty($searchParams['location']) ? "in {$searchParams['location']}" : 'in your area';
                    $alternatives[] = [
                        'type' => 'different_make',
                        'listings' => array_slice($altListings, 0, 3),
                        'message' => "I couldn't find {$makeName} {$modelName} {$locationName}, but here are similar vehicles:"
                    ];
                }
            }
            
            if (!empty($alternatives)) {
                $alt = $alternatives[0];
                $response = $alt['message'] . "\n\n";
                $listings = $alt['listings'];
            } else {
                // No alternatives found - be helpful but don't show all listings
                $response = "I couldn't find any vehicles matching your exact criteria. ";
                $suggestions = [];
                if ($hasLocation) {
                    $suggestions[] = "Try searching without the location filter";
                }
                if ($hasModel) {
                    $suggestions[] = "Try searching for other models";
                }
                if ($hasMake) {
                    $suggestions[] = "Try searching for other makes";
                }
                if (!empty($suggestions)) {
                    $response .= "Suggestions: " . implode(", ", $suggestions) . ". ";
                }
                $response .= "[Browse all listings]({$baseUrl}index.html) to see what's available.";
                
                // Send response without listings
                sendSuccess([
                    'response' => $response,
                    'search_results' => [],
                    'total_results' => 0
                ]);
                return;
            }
        }
        
        if (!empty($listings)) {
            // For price comparison queries (cheapest, most expensive), return only the top result
            if ($isPriceComparison && !empty($listings)) {
                $listing = $listings[0];
                $price = isset($listing['price']) ? number_format($listing['price']) : 'Price on request';
                $listingUrl = $baseUrl . "car.html?id=" . $listing['id'];
                
                $comparisonType = $searchQuery['sort_by_price'];
                if ($comparisonType === 'cheapest') {
                    $response = "The cheapest " . (!empty($listing['model_name']) ? $listing['model_name'] : 'vehicle') . " I found is:\n\n";
                } elseif ($comparisonType === 'most_expensive') {
                    $response = "The most expensive " . (!empty($listing['model_name']) ? $listing['model_name'] : 'vehicle') . " I found is:\n\n";
                } else {
                    $response = "The best value " . (!empty($listing['model_name']) ? $listing['model_name'] : 'vehicle') . " I found is:\n\n";
                }
                
                $response .= "[{$listing['make_name']} {$listing['model_name']} ({$listing['year']}) - " . getChatCurrencyCode($db) . " {$price}]({$listingUrl}) - Reference #{$listing['id']}\n";
                
                // Add seller information if available
                if (!empty($listing['seller_name'])) {
                    $sellerLabel = (!empty($listing['seller_type']) && $listing['seller_type'] !== 'user') ? 'Listed by' : 'Seller';
                    $response .= "  👤 {$sellerLabel}: {$listing['seller_name']}\n";
                }
                
                // Add detailed specs
                $specs = [];
                if (!empty($listing['exterior_color'])) {
                    $specs[] = "🎨 {$listing['exterior_color']}";
                }
                if (!empty($listing['location_name'])) {
                    $specs[] = "📍 {$listing['location_name']}";
                }
                if (!empty($listing['mileage'])) {
                    $specs[] = "📊 " . number_format($listing['mileage']) . " km";
                }
                if (!empty($listing['seats'])) {
                    $specs[] = "💺 {$listing['seats']} seats";
                }
                if (!empty($listing['fuel_type'])) {
                    $specs[] = "⛽ " . ucfirst($listing['fuel_type']);
                }
                if (!empty($listing['transmission'])) {
                    $specs[] = "⚙️ " . ucfirst($listing['transmission']);
                }
                
                if (!empty($specs)) {
                    $response .= "  " . implode(" • ", $specs) . "\n";
                }
            } else {
                // Limit to 5 results maximum for clinical, focused responses
                $limitedListings = array_slice($listings, 0, 5);
                $count = count($listings);
                $shownCount = count($limitedListings);
                
                $response = "Found {$count} vehicle" . ($count > 1 ? 's' : '') . " matching your criteria";
                if ($count > $shownCount) {
                    $response .= " (showing {$shownCount}):";
                } else {
                    $response .= ":";
                }
                $response .= "\n\n";
                
                // Show results with detailed specs
                foreach ($limitedListings as $listing) {
                    $price = isset($listing['price']) ? number_format($listing['price']) : 'Price on request';
                    $listingUrl = $baseUrl . "car.html?id=" . $listing['id'];
                    $response .= "• [{$listing['make_name']} {$listing['model_name']} ({$listing['year']}) - " . getChatCurrencyCode($db) . " {$price}]({$listingUrl}) - Reference #{$listing['id']}\n";
                    
                    // Add seller information if available
                    if (!empty($listing['seller_name'])) {
                        $sellerLabel = (!empty($listing['seller_type']) && $listing['seller_type'] !== 'user') ? 'Listed by' : 'Seller';
                        $response .= "  👤 {$sellerLabel}: {$listing['seller_name']}\n";
                    }
                    
                    // Add detailed specs
                    $specs = [];
                    if (!empty($listing['exterior_color'])) {
                        $specs[] = "🎨 {$listing['exterior_color']}";
                    }
                    if (!empty($listing['location_name'])) {
                        $specs[] = "📍 {$listing['location_name']}";
                    }
                    if (!empty($listing['mileage'])) {
                        $specs[] = "📊 " . number_format($listing['mileage']) . " km";
                    }
                    if (!empty($listing['seats'])) {
                        $specs[] = "💺 {$listing['seats']} seats";
                    }
                    if (!empty($listing['fuel_type'])) {
                        $specs[] = "⛽ " . ucfirst($listing['fuel_type']);
                    }
                    if (!empty($listing['transmission'])) {
                        $specs[] = "⚙️ " . ucfirst($listing['transmission']);
                    }
                    
                    if (!empty($specs)) {
                        $response .= "  " . implode(" • ", $specs) . "\n";
                    }
                    $response .= "\n";
                }
                
                if ($count > $shownCount) {
                    // Build search URL with filters
                    $searchParams = [];
                    if (!empty($searchQuery['make_id'])) {
                        $searchParams[] = "make=" . $searchQuery['make_id'];
                    }
                    if (!empty($searchQuery['model_id'])) {
                        $searchParams[] = "model=" . $searchQuery['model_id'];
                    }
                    if (!empty($searchQuery['max_price'])) {
                        $searchParams[] = "max_price=" . $searchQuery['max_price'];
                    }
                    if (!empty($searchQuery['location_id'])) {
                        $searchParams[] = "location=" . $searchQuery['location_id'];
                    } elseif (!empty($searchQuery['location'])) {
                        $searchParams[] = "location=" . urlencode($searchQuery['location']);
                    }
                    $searchUrl = $baseUrl . "index.html" . (!empty($searchParams) ? "?" . implode("&", $searchParams) : "");
                    $response .= "\n\n[View all {$count} results on website →]({$searchUrl})";
                }
            }
        }
        
        // Only return the listings we actually showed (max 5 for clinical responses)
        $returnedListings = array_slice($listings, 0, 5);
        
        sendSuccess([
            'response' => $response,
            'search_results' => $returnedListings, // Return only what we showed (max 5)
            'total_results' => count($listings), // Total matching results
            'base_url' => $baseUrl
        ]);
        
    } catch (Exception $e) {
        error_log("handleSearchQuery error: " . $e->getMessage());
        error_log("handleSearchQuery trace: " . $e->getTraceAsString());
        // Fallback to regular AI response
        sendError('Search failed: ' . $e->getMessage() . '. Please try rephrasing your query.', 500);
    }
}

/**
 * Determine if the message is out of chatbot scope.
 * Scope: automotive + MotorLink features/services/data.
 */
function isOutOfScopeQuery($message) {
    $msg = strtolower(trim((string)$message));
    if ($msg === '') {
        return false;
    }

    // Block only clearly harmful/inappropriate content
    $blockedPatterns = [
        '/\b(hack|exploit|malware|phishing|ddos)\b/i',
        '/\b(porn|xxx|nude|sex\s*chat|erotic)\b/i',
        '/\b(bomb|weapon|how\s+to\s+kill|terrorism)\b/i',
        '/\b(drug\s+deal|illegal\s+drug|cocaine|heroin|meth)\b/i',
    ];

    foreach ($blockedPatterns as $pattern) {
        if (preg_match($pattern, $msg)) {
            return true;
        }
    }

    // Allow everything else — the AI is helpful for general knowledge too
    return false;
}

function buildOutOfScopeResponse() {
    return "I'm sorry, I can't help with that type of request. I'm here to help with cars, vehicles, and general knowledge questions. How can I assist you today?";
}

/**
 * Handle car specification queries
 * Queries both database and internet for comprehensive answers
 */
function handleCarSpecQuery($db, $message, $conversationHistory, $userContext) {
    try {
        // Extract car make and model from message (using database)
        $extractedInfo = extractCarInfoFromMessage($db, $message);
        $contextualInfo = resolveCarSpecContextFromConversation($db, $message, $conversationHistory, $extractedInfo);
        $makeName = $contextualInfo['make'] ?? null;
        $modelName = $contextualInfo['model'] ?? null;
        $year = $contextualInfo['year'] ?? null;
        
        // Query database for car specs
        $dbSpecs = queryCarSpecsFromDatabase($db, $makeName, $modelName, $year);
        
        // Check cache tables first
        $cacheResult = queryCacheTables($db, $message);
        $hasCacheData = $cacheResult['found'];
        
        // Query internet for additional specs (using OpenAI's knowledge) if not in cache
        $internetSpecs = null;
        
        if (!$hasCacheData) {
            $internetSpecs = queryCarSpecsFromInternet($message, $makeName, $modelName, $year);
        } else {
            // Use cached data
            $internetSpecs = $cacheResult['summary'];
        }
        
        // Build enhanced context for AI
        $specContext = "CAR SPECIFICATION QUERY DETECTED\n\n";
        
        if ($dbSpecs && !empty($dbSpecs)) {
            $specContext .= "DATABASE RESULTS (from MotorLink database):\n";
            foreach ($dbSpecs as $spec) {
                $specContext .= "- Make: {$spec['make_name']}, Model: {$spec['name']}";
                if ($spec['year_start']) $specContext .= ", Years: {$spec['year_start']}-{$spec['year_end']}";
                if ($spec['engine_size_liters']) $specContext .= ", Engine: {$spec['engine_size_liters']}L";
                if ($spec['fuel_type']) $specContext .= ", Fuel: {$spec['fuel_type']}";
                if ($spec['transmission_type']) $specContext .= ", Transmission: {$spec['transmission_type']}";
                if ($spec['drive_type']) $specContext .= ", Drivetrain: {$spec['drive_type']}";
                if ($spec['fuel_tank_capacity_liters']) $specContext .= ", Fuel Tank: {$spec['fuel_tank_capacity_liters']}L";
                if ($spec['horsepower_hp']) $specContext .= ", Horsepower: {$spec['horsepower_hp']} HP";
                if ($spec['torque_nm']) $specContext .= ", Torque: {$spec['torque_nm']} Nm";
                if ($spec['fuel_consumption_liters_per_100km']) $specContext .= ", Fuel Consumption: {$spec['fuel_consumption_liters_per_100km']} L/100km";
                $specContext .= "\n";
            }
            $specContext .= "\n";
        } else {
            $specContext .= "DATABASE RESULTS: No matching specifications found in MotorLink database.\n\n";
        }
        
        if ($internetSpecs) {
            $specContext .= "INTERNET RESEARCH:\n{$internetSpecs}\n\n";
        }
        
        // If no database results, try to find similar/alternative models
        $alternatives = [];
        if (empty($dbSpecs) && ($makeName || $modelName)) {
            try {
                $altQuery = "
                    SELECT DISTINCT
                        cm.name as model_name,
                        mk.name as make_name,
                        COUNT(*) as variant_count
                    FROM car_models cm
                    INNER JOIN car_makes mk ON cm.make_id = mk.id
                    WHERE cm.is_active = 1
                ";
                $altParams = [];
                $altConditions = [];
                
                if ($makeName) {
                    $altConditions[] = "LOWER(mk.name) LIKE ?";
                    $altParams[] = '%' . strtolower($makeName) . '%';
                }
                
                if ($modelName && !$makeName) {
                    // If only model specified, find similar models
                    $altConditions[] = "LOWER(cm.name) LIKE ?";
                    $altParams[] = '%' . strtolower($modelName) . '%';
                }
                
                if (!empty($altConditions)) {
                    $altQuery .= " AND " . implode(" AND ", $altConditions);
                }
                
                $altQuery .= " GROUP BY cm.name, mk.name ORDER BY variant_count DESC LIMIT 5";
                
                $altStmt = $db->prepare($altQuery);
                $altStmt->execute($altParams);
                $alternatives = $altStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                error_log("Error finding alternatives: " . $e->getMessage());
            }
        }
        
        if (!empty($alternatives)) {
            $specContext .= "ALTERNATIVE OPTIONS (similar vehicles in database):\n";
            foreach ($alternatives as $alt) {
                $specContext .= "- {$alt['make_name']} {$alt['model_name']} ({$alt['variant_count']} variant" . ($alt['variant_count'] > 1 ? 's' : '') . ")\n";
            }
            $specContext .= "\n";
        }
        
        $specContext .= "INSTRUCTIONS:\n";
        $specContext .= "- Provide a friendly, comprehensive answer about the car specifications\n";
        $specContext .= "- If database has info, present it PRECISELY and mention it comes from MotorLink's database\n";
        $specContext .= "- If database doesn't have it, use your AI knowledge and research to provide comprehensive information\n";
        $specContext .= "- Clearly indicate data source: \"From MotorLink database\" vs \"Based on research\"\n";
        $specContext .= "- If alternatives are available, suggest them: \"While we don't have that exact model, we do have...\"\n";
        $specContext .= "- Be enthusiastic and helpful - celebrate finding the information!\n";
        $specContext .= "- If multiple variants exist, explain the differences clearly in a structured format\n";
        $specContext .= "- Use conversational, friendly language but be precise with data\n";
        $specContext .= "- Format specifications clearly (use tables or lists for better readability)\n";
        
        // Get user context
        $user = getCurrentUser(true);
        $userType = $userContext['user_type'] ?? 'user';
        $contextInfo = "User Type: {$userType}";
        
        // Get base URL
        $serverHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $baseUrl = $protocol . '://' . $serverHost . '/';
        
        // Enhanced system prompt for spec queries
        $systemPrompt = "Hey there! 👋 I'm MotorLink AI Assistant, your friendly automotive expert!

{$contextInfo}
BASE URL: {$baseUrl}

{$specContext}

I'm here to help you with car specifications! I've checked both our MotorLink database and the internet to give you the most comprehensive answer possible. Let me share what I found! 🚗✨";
        
        // Build messages array
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt]
        ];
        
        // Add conversation history
        foreach ($conversationHistory as $historyItem) {
            if (isset($historyItem['role']) && isset($historyItem['content'])) {
                $messages[] = [
                    'role' => $historyItem['role'],
                    'content' => $historyItem['content']
                ];
            }
        }
        
        // Add current user message
        $messages[] = ['role' => 'user', 'content' => $message];
        
        // Call OpenAI API with enhanced context
        $response = callOpenAIAPIForSpecs($db, $user, $messages);
        
        if ($response) {
            sendSuccess($response);
        } else {
            // Provider fallback: return deterministic database/cache summary instead of hard failure.
            $fallbackResponse = buildCarSpecFallbackResponse($message, $dbSpecs, $cacheResult, $alternatives);
            sendSuccess([
                'response' => $fallbackResponse,
                'source' => 'database_fallback'
            ]);
        }
    } catch (Exception $e) {
        error_log("handleCarSpecQuery error: " . $e->getMessage());
        sendError('I apologize, but I encountered an error while searching for car specifications. Please try again!', 500);
    }
}

/**
 * Query cache tables (ai_web_cache and ai_parts_cache) for relevant information
 */
function queryCacheTables($db, $message) {
    $result = [
        'found' => false,
        'summary' => null,
        'sources' => null,
        'cache_type' => null
    ];
    
    try {
        $queryHash = hash('sha256', strtolower(trim($message)));
        $messageLower = strtolower($message);
        
        // Check if it's a parts query - require ai-learning-api.php for detectPartsQuery
        $isPartsQuery = false;
        if (function_exists('detectPartsQuery')) {
            $isPartsQuery = detectPartsQuery($message);
        } else {
            // Simple detection if function not available
            $partsKeywords = ['part number', 'oem number', 'compatibility', 'part for', 'parts for'];
            foreach ($partsKeywords as $keyword) {
                if (strpos($messageLower, $keyword) !== false) {
                    $isPartsQuery = true;
                    break;
                }
            }
        }
        
        if ($isPartsQuery) {
            // Check ai_parts_cache first
            $stmt = $db->prepare("SELECT summary, sources_json FROM ai_parts_cache WHERE query_hash = ? ORDER BY created_at DESC LIMIT 1");
            $stmt->execute([$queryHash]);
            $cacheEntry = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($cacheEntry) {
                $result['found'] = true;
                $result['summary'] = $cacheEntry['summary'];
                $result['sources'] = json_decode($cacheEntry['sources_json'] ?? '[]', true);
                $result['cache_type'] = 'parts';
                return $result;
            }
        }
        
        // Check ai_web_cache (general car topics)
        $stmt = $db->prepare("SELECT summary, sources_json FROM ai_web_cache WHERE query_hash = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$queryHash]);
        $cacheEntry = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($cacheEntry) {
            $result['found'] = true;
            $result['summary'] = $cacheEntry['summary'];
            $result['sources'] = json_decode($cacheEntry['sources_json'] ?? '[]', true);
            $result['cache_type'] = 'web';
            return $result;
        }
        
        // Try fuzzy match on query_text for web cache
        $stmt = $db->prepare("SELECT summary, sources_json FROM ai_web_cache WHERE LOWER(query_text) LIKE ? ORDER BY created_at DESC LIMIT 3");
        $searchTerm = '%' . $messageLower . '%';
        $stmt->execute([$searchTerm]);
        $cacheEntries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($cacheEntries)) {
            // Use the most relevant one (first result)
            $result['found'] = true;
            $result['summary'] = $cacheEntries[0]['summary'];
            $result['sources'] = json_decode($cacheEntries[0]['sources_json'] ?? '[]', true);
            $result['cache_type'] = 'web';
            return $result;
        }
        
    } catch (Exception $e) {
        error_log("queryCacheTables error: " . $e->getMessage());
    }
    
    return $result;
}

/**
 * Query general car information from database for any car-related query
 * Returns context and alternatives to enhance AI responses
 * NOW ALSO CHECKS CACHE TABLES FIRST
 */
function queryGeneralCarInfoFromDatabase($db, $message) {
    $result = [
        'has_data' => false,
        'context' => '',
        'alternatives' => [],
        'cache_data' => null
    ];
    
    try {
        // FIRST: Check cache tables (ai_web_cache, ai_parts_cache)
        $cacheResult = queryCacheTables($db, $message);
        if ($cacheResult['found']) {
            $result['has_data'] = true;
            $result['cache_data'] = $cacheResult;
            $result['context'] = "CACHED INFORMATION FROM DATABASE:\n" . $cacheResult['summary'];
            return $result;
        }
        
        $messageLower = strtolower($message);
        
        // Extract make and model from message
        $extractedInfo = extractCarInfoFromMessage($db, $message);
        $makeName = $extractedInfo['make'] ?? null;
        $modelName = $extractedInfo['model'] ?? null;
        
        if ($makeName || $modelName) {
            // Query database for matching cars
            $query = "
                SELECT DISTINCT
                    mk.name as make_name,
                    cm.name as model_name,
                    COUNT(*) as variant_count,
                    MIN(cm.year_start) as min_year,
                    MAX(cm.year_end) as max_year
                FROM car_models cm
                INNER JOIN car_makes mk ON cm.make_id = mk.id
                WHERE cm.is_active = 1 AND mk.is_active = 1
            ";
            $params = [];
            $conditions = [];
            
            if ($makeName) {
                $conditions[] = "LOWER(mk.name) = ?";
                $params[] = strtolower($makeName);
            }
            
            if ($modelName) {
                $conditions[] = "LOWER(cm.name) = ?";
                $params[] = strtolower($modelName);
            }
            
            if (!empty($conditions)) {
                $query .= " AND " . implode(" AND ", $conditions);
            }
            
            $query .= " GROUP BY mk.name, cm.name ORDER BY variant_count DESC LIMIT 10";
            
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($matches)) {
                $result['has_data'] = true;
                $result['context'] = "DATABASE MATCHES FOUND:\n";
                foreach ($matches as $match) {
                    $result['context'] .= "- {$match['make_name']} {$match['model_name']}";
                    if ($match['min_year'] && $match['max_year']) {
                        $result['context'] .= " (Years: {$match['min_year']}-{$match['max_year']})";
                    }
                    $result['context'] .= " - {$match['variant_count']} variant" . ($match['variant_count'] > 1 ? 's' : '') . "\n";
                }
                
                // Get detailed specs for the first match
                if (count($matches) > 0) {
                    $firstMatch = $matches[0];
                    $specQuery = "
                        SELECT 
                            engine_size_liters, fuel_type, transmission_type, drive_type,
                            fuel_tank_capacity_liters, horsepower_hp, torque_nm,
                            fuel_consumption_liters_per_100km
                        FROM car_models
                        WHERE make_id = (SELECT id FROM car_makes WHERE LOWER(name) = ? LIMIT 1)
                        AND LOWER(name) = ?
                        AND is_active = 1
                        LIMIT 5
                    ";
                    $specStmt = $db->prepare($specQuery);
                    $specStmt->execute([strtolower($firstMatch['make_name']), strtolower($firstMatch['model_name'])]);
                    $specs = $specStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (!empty($specs)) {
                        $result['context'] .= "\nSAMPLE SPECIFICATIONS:\n";
                        foreach ($specs as $spec) {
                            $specLine = "- ";
                            if ($spec['engine_size_liters']) $specLine .= "Engine: {$spec['engine_size_liters']}L, ";
                            if ($spec['fuel_type']) $specLine .= "Fuel: {$spec['fuel_type']}, ";
                            if ($spec['transmission_type']) $specLine .= "Transmission: {$spec['transmission_type']}, ";
                            if ($spec['drive_type']) $specLine .= "Drivetrain: {$spec['drive_type']}";
                            $result['context'] .= rtrim($specLine, ', ') . "\n";
                        }
                    }
                }
            } else {
                // No exact matches, find alternatives
                $altQuery = "
                    SELECT DISTINCT
                        mk.name as make_name,
                        cm.name as model_name,
                        COUNT(*) as variant_count
                    FROM car_models cm
                    INNER JOIN car_makes mk ON cm.make_id = mk.id
                    WHERE cm.is_active = 1 AND mk.is_active = 1
                ";
                $altParams = [];
                $altConditions = [];
                
                if ($makeName) {
                    // Find similar makes
                    $altConditions[] = "LOWER(mk.name) LIKE ?";
                    $altParams[] = '%' . strtolower($makeName) . '%';
                }
                
                if ($modelName) {
                    // Find similar models
                    $altConditions[] = "LOWER(cm.name) LIKE ?";
                    $altParams[] = '%' . strtolower($modelName) . '%';
                }
                
                if (!empty($altConditions)) {
                    $altQuery .= " AND (" . implode(" OR ", $altConditions) . ")";
                }
                
                $altQuery .= " GROUP BY mk.name, cm.name ORDER BY variant_count DESC LIMIT 5";
                
                $altStmt = $db->prepare($altQuery);
                $altStmt->execute($altParams);
                $alternatives = $altStmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (!empty($alternatives)) {
                    foreach ($alternatives as $alt) {
                        $result['alternatives'][] = "{$alt['make_name']} {$alt['model_name']} ({$alt['variant_count']} variant" . ($alt['variant_count'] > 1 ? 's' : '') . ")";
                    }
                }
            }
        }
    } catch (Exception $e) {
        error_log("queryGeneralCarInfoFromDatabase error: " . $e->getMessage());
    }
    
    return $result;
}

/**
 * Extract car make, model, and year from user message - uses database for makes/models
 */
function extractCarInfoFromMessage($db, $message) {
    $result = ['make' => null, 'model' => null, 'year' => null];
    
    $messageLower = strtolower($message);
    
    try {
        // Extract make from database
        $makeStmt = $db->query("SELECT LOWER(name) as make_name FROM car_makes WHERE is_active = 1 ORDER BY name ASC");
        $makes = $makeStmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($makes as $make) {
            if (preg_match('/\b' . preg_quote($make, '/') . '\b/i', $messageLower)) {
                $result['make'] = ucfirst($make);
                break;
            }
        }
        
        // Extract model from database (prefer models from the same make if make was found)
        if ($result['make']) {
            $makeIdStmt = $db->prepare("SELECT id FROM car_makes WHERE LOWER(name) = ? LIMIT 1");
            $makeIdStmt->execute([strtolower($result['make'])]);
            $makeData = $makeIdStmt->fetch(PDO::FETCH_ASSOC);
            if ($makeData) {
                $modelStmt = $db->prepare("SELECT DISTINCT LOWER(name) as model_name FROM car_models WHERE is_active = 1 AND make_id = ? ORDER BY name ASC");
                $modelStmt->execute([$makeData['id']]);
                $models = $modelStmt->fetchAll(PDO::FETCH_COLUMN);
            } else {
                $modelStmt = $db->query("SELECT DISTINCT LOWER(name) as model_name FROM car_models WHERE is_active = 1 ORDER BY name ASC");
                $models = $modelStmt->fetchAll(PDO::FETCH_COLUMN);
            }
        } else {
            $modelStmt = $db->query("SELECT DISTINCT LOWER(name) as model_name FROM car_models WHERE is_active = 1 ORDER BY name ASC");
            $models = $modelStmt->fetchAll(PDO::FETCH_COLUMN);
        }
        
        foreach ($models as $model) {
            if (preg_match('/\b' . preg_quote($model, '/') . '\b/i', $messageLower)) {
                $result['model'] = ucfirst($model);
                break;
            }
        }
    } catch (Exception $e) {
        error_log("extractCarInfoFromMessage error: " . $e->getMessage());
    }
    
    // Extract year (4-digit year)
    if (preg_match('/\b(19|20)\d{2}\b/', $message, $yearMatch)) {
        $result['year'] = (int)$yearMatch[0];
    }
    
    return $result;
}

/**
 * Resolve missing car spec context (make/model/year) from recent conversation.
 * This allows follow-up queries like "its engine capacity" to keep referring
 * to the previously discussed vehicle.
 */
function resolveCarSpecContextFromConversation($db, $message, $conversationHistory, $currentInfo) {
    $resolved = [
        'make' => $currentInfo['make'] ?? null,
        'model' => $currentInfo['model'] ?? null,
        'year' => $currentInfo['year'] ?? null
    ];

    // If current message already has all key fields we need, use it directly.
    if (!empty($resolved['make']) && !empty($resolved['model'])) {
        return $resolved;
    }

    if (!is_array($conversationHistory) || empty($conversationHistory)) {
        return $resolved;
    }

    for ($i = count($conversationHistory) - 1; $i >= 0; $i--) {
        $item = $conversationHistory[$i];
        if (!is_array($item) || empty($item['content'])) {
            continue;
        }

        $candidate = extractCarInfoFromMessage($db, (string)$item['content']);

        if (empty($resolved['make']) && !empty($candidate['make'])) {
            $resolved['make'] = $candidate['make'];
        }
        if (empty($resolved['model']) && !empty($candidate['model'])) {
            $resolved['model'] = $candidate['model'];
        }
        if (empty($resolved['year']) && !empty($candidate['year'])) {
            $resolved['year'] = $candidate['year'];
        }

        if (!empty($resolved['make']) && !empty($resolved['model'])) {
            break;
        }
    }

    return $resolved;
}

/**
 * Query car specifications from database
 */
function queryCarSpecsFromDatabase($db, $makeName, $modelName, $year = null) {
    try {
        // Never return broad generic model catalogs when no vehicle context is known.
        if (empty($makeName) && empty($modelName) && empty($year)) {
            return [];
        }

        $query = "
            SELECT 
                cm.*,
                mk.name as make_name
            FROM car_models cm
            INNER JOIN car_makes mk ON cm.make_id = mk.id
            WHERE cm.is_active = 1
        ";
        
        $params = [];
        $conditions = [];
        
        if ($makeName) {
            $conditions[] = "LOWER(mk.name) LIKE ?";
            $params[] = '%' . strtolower($makeName) . '%';
        }
        
        if ($modelName) {
            $conditions[] = "LOWER(cm.name) LIKE ?";
            $params[] = '%' . strtolower($modelName) . '%';
        }
        
        if ($year) {
            $conditions[] = "(cm.year_start IS NULL OR cm.year_start <= ?) AND (cm.year_end IS NULL OR cm.year_end >= ?)";
            $params[] = $year;
            $params[] = $year;
        }
        
        if (!empty($conditions)) {
            $query .= " AND " . implode(" AND ", $conditions);
        }
        
        $query .= " ORDER BY mk.name, cm.name, cm.engine_size_liters LIMIT 20";
        
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $results;
    } catch (Exception $e) {
        error_log("queryCarSpecsFromDatabase error: " . $e->getMessage());
        return null;
    }
}

/**
 * Query car specifications from internet using OpenAI's knowledge
 * Note: This uses OpenAI's training data which includes web information
 * For true web search, you would need to integrate with a search API like Google Custom Search
 */
function queryCarSpecsFromInternet($message, $makeName, $modelName, $year = null) {
    // Build a focused query for internet research
    $searchQuery = "";
    
    if ($makeName && $modelName) {
        $searchQuery = "{$makeName} {$modelName}";
        if ($year) {
            $searchQuery .= " {$year}";
        }
        $searchQuery .= " specifications technical details";
    } else {
        $searchQuery = $message . " car specifications technical details";
    }
    
    // Return context that will be used in the AI prompt
    // The AI model (GPT-4o) has knowledge from its training data which includes web information
    // For real-time web search, you would need to integrate with Google Custom Search API or similar
    return "Please use your knowledge (including information from your training data which includes web sources) to provide comprehensive specifications for: {$searchQuery}. Include details about engine, transmission, fuel economy, dimensions, features, and any other relevant technical specifications.";
}

/**
 * Call OpenAI API for car spec queries (extracted for reuse)
 */
function callOpenAIAPIForSpecs($db, $user, $messages) {
    // Get settings
    $settings = getAIChatSettings($db);
    $provider = normalizeAIChatProvider($settings['ai_provider'] ?? 'openai');
    $providerConfig = getAIChatProviderConfig($provider);

    if (!isAIChatProviderEnabled($settings, $provider)) {
        error_log(getAIChatProviderLabel($provider) . " is disabled in AI chat settings");
        return null;
    }

    $providerApiKey = getAIProviderApiKeyFromDB($provider, $db);
    if (empty($providerApiKey)) {
        error_log(getAIChatProviderLabel($provider) . " API key not configured");
        return null;
    }

    $modelName = trim((string)($settings['model_name'] ?? $providerConfig['default_model']));
    if ($modelName === '') {
        $modelName = $providerConfig['default_model'];
    }
    $maxTokens = (int)($settings['max_tokens_per_request'] ?? 1200);
    $temperature = (float)($settings['temperature'] ?? 0.7);
    $enabled = (int)($settings['enabled'] ?? 1);
    
    if (!$enabled) {
        return null;
    }
    
    // Check rate limits
    $rateLimitCheck = checkAIChatRateLimit($db, $user['id'], $settings);
    if (!$rateLimitCheck['allowed']) {
        sendError($rateLimitCheck['message'], 429);
        return null;
    }
    
    // Build request
    $requestBody = [
        'model' => $modelName,
        'messages' => $messages,
        'temperature' => $temperature,
        'max_tokens' => $maxTokens,
        'top_p' => 0.95,
        'frequency_penalty' => 0.1,
        'presence_penalty' => 0.1
    ];
    
    // Make API call
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $providerConfig['url']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $providerApiKey
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody));
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    
    // Disable SSL for Windows/localhost development
    $isWindows = (PHP_OS_FAMILY === 'Windows');
    $serverHost = $_SERVER['HTTP_HOST'] ?? '';
    $isLocalDev = (strpos($serverHost, 'localhost') !== false || strpos($serverHost, '127.0.0.1') !== false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !($isLocalDev || $isWindows));
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, ($isLocalDev || $isWindows) ? 0 : 2);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error || $httpCode !== 200) {
        error_log(getAIChatProviderLabel($provider) . " API error: " . ($error ?: "HTTP $httpCode"));
        return null;
    }
    
    $responseData = json_decode($response, true);
    
    if (!isset($responseData['choices'][0]['message']['content'])) {
        return null;
    }
    
    $aiResponse = $responseData['choices'][0]['message']['content'];
    $tokensUsed = $responseData['usage']['total_tokens'] ?? 0;
    
    // Log usage
    logAIChatUsage($db, $user['id'], $messages[count($messages) - 1]['content'], 
                   strlen($aiResponse), $tokensUsed, $modelName, 0);
    
    return [
        'response' => $aiResponse,
        'tokens_used' => $tokensUsed
    ];
}

/**
 * Detect if user message is asking for car recommendations or searching for a car
 */
function detectCarRecommendationQuery($message) {
    $messageLower = strtolower($message);
    
    // Keywords that indicate recommendation/search intent
    $recommendationKeywords = [
        'looking for', 'i need', 'i want', 'find me', 'help me find',
        'recommend', 'suggest', 'what car', 'which car', 'best car',
        'family car', 'suv', 'sedan', 'hatchback', 'pickup', 'truck',
        'budget', 'affordable', 'cheap', 'expensive', 'luxury',
        'fuel efficient', 'economical', 'good on fuel',
        'reliable', 'safe', 'safety', 'secure',
        'spacious', 'roomy', 'big', 'small', 'compact',
        'seats', 'seater', '7 seater', '6 seater', '5 seater',
        'for family', 'family vehicle', 'family suv',
        'daily driver', 'commute', 'city car', 'highway',
        'off road', '4x4', 'all wheel drive', 'awd',
        'automatic', 'manual', 'transmission'
    ];
    
    // Patterns that indicate recommendation intent
    $recommendationPatterns = [
        '/looking for (a|an|the)?/i',
        '/i (am|need|want) (a|an|the)?/i',
        '/find me (a|an|the)?/i',
        '/help me (find|choose|pick)/i',
        '/what (car|vehicle|suv|sedan)/i',
        '/which (car|vehicle|suv|sedan)/i',
        '/best (car|vehicle|suv|sedan)/i',
        '/recommend (a|an|the)?/i',
        '/suggest (a|an|the)?/i',
        '/(\d+)\s*seater/i',
        '/family (car|vehicle|suv)/i'
    ];
    
    // Check for keywords
    foreach ($recommendationKeywords as $keyword) {
        if (strpos($messageLower, $keyword) !== false) {
            return true;
        }
    }
    
    // Check for patterns
    foreach ($recommendationPatterns as $pattern) {
        if (preg_match($pattern, $message)) {
            return true;
        }
    }
    
    return false;
}

/**
 * Handle car recommendation/search queries with intelligent matching
 */
function handleCarRecommendationQuery($db, $message, $conversationHistory, $userContext) {
    try {
        // Extract requirements from message (with database support)
        $requirements = extractCarRequirements($db, $message);
        
        // Query database for matching cars
        $matchingCars = searchCarsByRequirements($db, $requirements);
        
        // Get base URL
        $serverHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $baseUrl = $protocol . '://' . $serverHost . '/';
        
        // Build context for AI with logical reasoning
        $recommendationContext = "CAR RECOMMENDATION/SEARCH QUERY DETECTED\n\n";
        $recommendationContext .= "THINK LOGICALLY: Consider what makes sense for the user's needs. Family cars need adequate seating (6-7 seats), safety features, and spacious interiors. Business cars need comfort and reliability. City cars need fuel efficiency.\n\n";
        $recommendationContext .= "USER REQUIREMENTS:\n";
        
        if (!empty($requirements['seats'])) {
            $recommendationContext .= "- Seating: {$requirements['seats']} seats\n";
        }
        if (!empty($requirements['body_type'])) {
            $recommendationContext .= "- Body Type: {$requirements['body_type']}\n";
        }
        if (!empty($requirements['make'])) {
            $recommendationContext .= "- Preferred Make: {$requirements['make']}\n";
        }
        if (!empty($requirements['model'])) {
            $recommendationContext .= "- Preferred Model: {$requirements['model']}\n";
        }
        if (!empty($requirements['max_price'])) {
            $recommendationContext .= "- Max Budget: " . getChatCurrencyCode($db) . " " . number_format($requirements['max_price']) . "\n";
        }
        if (!empty($requirements['fuel_type'])) {
            $recommendationContext .= "- Fuel Type: {$requirements['fuel_type']}\n";
        }
        if (!empty($requirements['transmission'])) {
            $recommendationContext .= "- Transmission: {$requirements['transmission']}\n";
        }
        if (!empty($requirements['purpose'])) {
            $recommendationContext .= "- Purpose: {$requirements['purpose']}\n";
        }
        if (!empty($requirements['location'])) {
            $recommendationContext .= "- Location: {$requirements['location']}\n";
        }
        if (!empty($requirements['features'])) {
            $recommendationContext .= "- Features: " . implode(", ", $requirements['features']) . "\n";
        }
        
        $recommendationContext .= "\n";
        
        if ($matchingCars && count($matchingCars) > 0) {
            $recommendationContext .= "MATCHING CARS FOUND IN DATABASE (" . count($matchingCars) . " results):\n";
            foreach (array_slice($matchingCars, 0, 10) as $car) {
                $recommendationContext .= "- {$car['make_name']} {$car['model_name']}";
                if ($car['year']) $recommendationContext .= " ({$car['year']})";
                if ($car['price']) $recommendationContext .= " - " . getChatCurrencyCode($db) . " " . number_format($car['price']);
                if ($car['seats']) $recommendationContext .= " - {$car['seats']} seats";
                if ($car['body_type']) $recommendationContext .= " - {$car['body_type']}";
                $recommendationContext .= " - [View Listing]({$baseUrl}car.html?id={$car['id']})\n";
            }
            $recommendationContext .= "\n";
        } else {
            $recommendationContext .= "MATCHING CARS: No exact matches found, but I can provide general recommendations.\n\n";
        }
        
        // Determine if we need to ask clarifying questions
        $needsClarification = needsClarification($requirements);
        $clarificationQuestions = [];
        
        if ($needsClarification) {
            $clarificationQuestions = generateClarificationQuestions($requirements);
            $recommendationContext .= "CLARIFICATION NEEDED:\n";
            $recommendationContext .= "The user's requirements are not fully specified. Consider asking:\n";
            foreach ($clarificationQuestions as $question) {
                $recommendationContext .= "- {$question}\n";
            }
            $recommendationContext .= "\n";
        }
        
        $recommendationContext .= "INSTRUCTIONS:\n";
        if ($needsClarification && count($matchingCars) == 0) {
            $recommendationContext .= "- Ask 2-3 friendly clarifying questions to better understand their needs\n";
            $recommendationContext .= "- Be conversational and helpful - like a friendly car expert\n";
            $recommendationContext .= "- Show enthusiasm about helping them find the perfect car\n";
        } else if (count($matchingCars) > 0) {
            $recommendationContext .= "- Provide a friendly, enthusiastic response with specific car recommendations\n";
            $recommendationContext .= "- Mention the matching cars from our database with details\n";
            $recommendationContext .= "- Include clickable links to view listings: [Car Name]({$baseUrl}car.html?id=ID)\n";
            $recommendationContext .= "- Explain why these cars match their requirements\n";
            $recommendationContext .= "- If they need more options, suggest refining the search\n";
        } else {
            $recommendationContext .= "- Provide general recommendations based on their stated needs\n";
            $recommendationContext .= "- Suggest popular options that match their criteria\n";
            $recommendationContext .= "- Be helpful and encouraging\n";
        }
        $recommendationContext .= "- Use friendly, conversational language\n";
        $recommendationContext .= "- Show genuine enthusiasm about helping them\n";
        
        // Get user context
        $user = getCurrentUser(true);
        $userType = $userContext['user_type'] ?? 'user';
        $contextInfo = "User Type: {$userType}";
        
        // Enhanced system prompt for recommendation queries with logical thinking
        $systemPrompt = "Hey there! 👋 I'm MotorLink AI Assistant, your friendly automotive expert!

{$contextInfo}
BASE URL: {$baseUrl}

{$recommendationContext}

THINK LOGICALLY: 
- When users ask for \"family cars\", think about seating capacity (6-7 seats for parents + children), safety features, spacious interiors, and reliability. Consider SUVs as they're popular family choices.
- Consider what makes practical sense for the use case - not just matching keywords, but understanding actual needs.
- Look at the database results and think about which cars logically fit the user's stated or implied requirements.

I'm here to help you find the perfect car! I've analyzed your requirements and searched our database. Let me help you find exactly what you're looking for! 🚗✨";
        
        // Build messages array
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt]
        ];
        
        // Add conversation history
        foreach ($conversationHistory as $historyItem) {
            if (isset($historyItem['role']) && isset($historyItem['content'])) {
                $messages[] = [
                    'role' => $historyItem['role'],
                    'content' => $historyItem['content']
                ];
            }
        }
        
        // Add current user message
        $messages[] = ['role' => 'user', 'content' => $message];
        
        // Call OpenAI API
        $response = callOpenAIAPIForSpecs($db, $user, $messages);
        
        if ($response) {
            // Add search results if available
            if (count($matchingCars) > 0) {
                $response['search_results'] = array_slice($matchingCars, 0, 10);
                $response['total_results'] = count($matchingCars);
            }
            sendSuccess($response);
        } else {
            // Provider fallback: still answer from deterministic DB results.
            $fallbackResponse = buildCarRecommendationFallbackResponse($requirements, $matchingCars, $clarificationQuestions, $needsClarification, $baseUrl);
            sendSuccess([
                'response' => $fallbackResponse,
                'search_results' => array_slice($matchingCars, 0, 10),
                'total_results' => count($matchingCars),
                'source' => 'database_fallback'
            ]);
        }
    } catch (Exception $e) {
        error_log("handleCarRecommendationQuery error: " . $e->getMessage());
        sendError('I apologize, but I encountered an error while searching for cars. Please try again!', 500);
    }
}

/**
 * Extract car requirements from user message - uses AI for better understanding
 */
function extractCarRequirements($db, $message) {
    $requirements = [];
    $messageLower = strtolower($message);
    
    // Use AI to extract requirements for better English understanding
    $aiExtracted = extractCarRequirementsWithAI($db, $message);
    if (!empty($aiExtracted)) {
        $requirements = array_merge($requirements, $aiExtracted);
    }
    
    // Fallback to pattern matching
    // Extract seating capacity
    if (empty($requirements['seats'])) {
        if (preg_match('/(\d+)\s*seater/i', $message, $matches)) {
            $requirements['seats'] = (int)$matches[1];
        } elseif (preg_match('/(\d+)\s*seats/i', $message, $matches)) {
            $requirements['seats'] = (int)$matches[1];
        } elseif (strpos($messageLower, 'family car') !== false || strpos($messageLower, 'family vehicle') !== false || strpos($messageLower, 'looking for a family car') !== false || strpos($messageLower, 'family') !== false) {
            // Think logically: Family cars need 6-7 seats (for parents + 2-3+ children), prefer SUVs for space and safety
            $requirements['seats'] = 6; // Minimum 6 for family (6 or 7 seater) - typical family size
            $requirements['purpose'] = 'family';
            if (empty($requirements['body_type'])) {
                $requirements['body_type'] = 'suv'; // Families prefer SUVs for space, safety, and versatility
            }
            // Add logical features for family cars
            if (empty($requirements['features'])) {
                $requirements['features'] = [];
            }
            if (!in_array('safe', $requirements['features']) && !in_array('safety', $requirements['features'])) {
                $requirements['features'][] = 'safe';
            }
            if (!in_array('spacious', $requirements['features'])) {
                $requirements['features'][] = 'spacious';
            }
        }
    }
    
    // Extract body type
    if (empty($requirements['body_type'])) {
        $bodyTypes = ['suv', 'sedan', 'hatchback', 'pickup', 'truck', 'wagon', 'coupe', 'convertible', 'minivan', 'van'];
        foreach ($bodyTypes as $type) {
            if (preg_match('/\b' . preg_quote($type, '/') . '\b/i', $messageLower)) {
                $requirements['body_type'] = $type;
                break;
            }
        }
    }
    
    // Extract make from database
    if (empty($requirements['make'])) {
        try {
            $makeStmt = $db->query("SELECT LOWER(name) as make_name FROM car_makes WHERE is_active = 1 ORDER BY name ASC");
            $makes = $makeStmt->fetchAll(PDO::FETCH_COLUMN);
            foreach ($makes as $make) {
                if (preg_match('/\b' . preg_quote($make, '/') . '\b/i', $messageLower)) {
                    $requirements['make'] = ucfirst($make);
                    break;
                }
            }
        } catch (Exception $e) {
            error_log("Error extracting make: " . $e->getMessage());
        }
    }
    
    // Extract model from database
    if (empty($requirements['model'])) {
        try {
            $modelQuery = "SELECT DISTINCT LOWER(name) as model_name FROM car_models WHERE is_active = 1 ORDER BY name ASC";
            if (!empty($requirements['make'])) {
                $makeIdStmt = $db->prepare("SELECT id FROM car_makes WHERE LOWER(name) = ? LIMIT 1");
                $makeIdStmt->execute([strtolower($requirements['make'])]);
                $makeData = $makeIdStmt->fetch(PDO::FETCH_ASSOC);
                if ($makeData) {
                    $modelQuery = "SELECT DISTINCT LOWER(name) as model_name FROM car_models WHERE is_active = 1 AND make_id = ? ORDER BY name ASC";
                    $modelStmt = $db->prepare($modelQuery);
                    $modelStmt->execute([$makeData['id']]);
                    $models = $modelStmt->fetchAll(PDO::FETCH_COLUMN);
                } else {
                    $modelStmt = $db->query($modelQuery);
                    $models = $modelStmt->fetchAll(PDO::FETCH_COLUMN);
                }
            } else {
                $modelStmt = $db->query($modelQuery);
                $models = $modelStmt->fetchAll(PDO::FETCH_COLUMN);
            }
            
            foreach ($models as $model) {
                if (preg_match('/\b' . preg_quote($model, '/') . '\b/i', $messageLower)) {
                    $requirements['model'] = ucfirst($model);
                    break;
                }
            }
        } catch (Exception $e) {
            error_log("Error extracting model: " . $e->getMessage());
        }
    }
    
    // Extract budget
    if (preg_match('/(?:budget|max|maximum|under|below|less than|up to)\s*(?:of|is)?\s*([\d,]+)\s*(?:million|mwk|kwacha|mk)/i', $message, $matches)) {
        $amount = str_replace(',', '', $matches[1]);
        if (strpos($messageLower, 'million') !== false) {
            $requirements['max_price'] = (float)$amount * 1000000;
        } else {
            $requirements['max_price'] = (float)$amount;
        }
    } elseif (preg_match('/([\d,]+)\s*(?:million|mwk|kwacha|mk)/i', $message, $matches)) {
        $amount = str_replace(',', '', $matches[1]);
        if (strpos($messageLower, 'million') !== false) {
            $requirements['max_price'] = (float)$amount * 1000000;
        } else {
            $requirements['max_price'] = (float)$amount;
        }
    }
    
    // Extract fuel type
    if (strpos($messageLower, 'petrol') !== false || strpos($messageLower, 'gasoline') !== false) {
        $requirements['fuel_type'] = 'petrol';
    } elseif (strpos($messageLower, 'diesel') !== false) {
        $requirements['fuel_type'] = 'diesel';
    } elseif (strpos($messageLower, 'electric') !== false || strpos($messageLower, 'ev') !== false) {
        $requirements['fuel_type'] = 'electric';
    } elseif (strpos($messageLower, 'hybrid') !== false) {
        $requirements['fuel_type'] = 'hybrid';
    }
    
    // Extract transmission
    if (strpos($messageLower, 'automatic') !== false || strpos($messageLower, 'auto') !== false) {
        $requirements['transmission'] = 'automatic';
    } elseif (strpos($messageLower, 'manual') !== false) {
        $requirements['transmission'] = 'manual';
    }
    
    // Extract purpose/usage
    if (strpos($messageLower, 'family') !== false) {
        $requirements['purpose'] = 'family';
    } elseif (strpos($messageLower, 'business') !== false || strpos($messageLower, 'commercial') !== false) {
        $requirements['purpose'] = 'business';
    } elseif (strpos($messageLower, 'off road') !== false || strpos($messageLower, '4x4') !== false) {
        $requirements['purpose'] = 'off-road';
    } elseif (strpos($messageLower, 'city') !== false || strpos($messageLower, 'commute') !== false) {
        $requirements['purpose'] = 'city';
    }
    
    // Extract features
    $requirements['features'] = [];
    if (strpos($messageLower, 'fuel efficient') !== false || strpos($messageLower, 'economical') !== false) {
        $requirements['features'][] = 'fuel efficient';
    }
    if (strpos($messageLower, 'safe') !== false || strpos($messageLower, 'safety') !== false) {
        $requirements['features'][] = 'safe';
    }
    if (strpos($messageLower, 'spacious') !== false || strpos($messageLower, 'roomy') !== false) {
        $requirements['features'][] = 'spacious';
    }
    if (strpos($messageLower, 'luxury') !== false) {
        $requirements['features'][] = 'luxury';
    }
    if (strpos($messageLower, 'reliable') !== false) {
        $requirements['features'][] = 'reliable';
    }

    // Extract location from canonical locations table for strict location filtering.
    if (empty($requirements['location'])) {
        try {
            $locStmt = $db->query("SELECT id, name FROM locations ORDER BY name ASC");
            $locations = $locStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($locations as $loc) {
                $locNameLower = strtolower((string)$loc['name']);
                if (preg_match('/\\b' . preg_quote($locNameLower, '/') . '\\b/i', $messageLower)) {
                    $requirements['location'] = $loc['name'];
                    $requirements['location_id'] = (int)$loc['id'];
                    break;
                }
            }
        } catch (Exception $e) {
            error_log("Error extracting recommendation location: " . $e->getMessage());
        }
    }

    // Fuzzy fallback for typos like "lilonwe".
    if (empty($requirements['location'])) {
        $inferredLocation = inferLocationFromMessage($message);
        if (!empty($inferredLocation)) {
            $resolvedLocation = resolveClosestLocationName($db, $inferredLocation);
            if (!empty($resolvedLocation)) {
                $requirements['location'] = $resolvedLocation;
            }
        }
    }

    if (!empty($requirements['location']) && empty($requirements['location_id'])) {
        try {
            $locIdStmt = $db->prepare("SELECT id FROM locations WHERE LOWER(name) = ? LIMIT 1");
            $locIdStmt->execute([strtolower(trim((string)$requirements['location']))]);
            $locId = $locIdStmt->fetch(PDO::FETCH_ASSOC);
            if (!empty($locId['id'])) {
                $requirements['location_id'] = (int)$locId['id'];
            }
        } catch (Exception $e) {
            error_log("Error resolving recommendation location_id: " . $e->getMessage());
        }
    }
    
    return $requirements;
}

/**
 * Extract car requirements using AI for better English understanding
 */
function extractCarRequirementsWithAI($db, $message) {
    $settings = getAIChatSettings($db);
    $provider = normalizeAIChatProvider($settings['ai_provider'] ?? 'openai');
    $providerConfig = getAIChatProviderConfig($provider);
    $providerApiKey = getAIProviderApiKeyFromDB($provider, $db);

    if (empty($providerApiKey)) {
        return [];
    }

    $modelName = trim((string)($settings['model_name'] ?? $providerConfig['default_model']));
    if ($modelName === '') {
        $modelName = $providerConfig['default_model'];
    }
    
    $extractionPrompt = "Extract car search requirements from this user query. Think logically about what the user needs. Return ONLY a JSON object:
{
  \"seats\": number (e.g., 5, 6, 7, 8) or null,
  \"body_type\": \"SUV, Sedan, Hatchback, Pickup, Truck, Wagon, Minivan, Van\" or null,
  \"make\": \"brand name\" or null,
  \"model\": \"model name\" or null,
  \"max_price\": number or null,
    \"fuel_type\": \"petrol, diesel, hybrid, electric\" or null,
    \"transmission\": \"manual, automatic\" or null,
    \"purpose\": \"family, business, city, off-road\" or null,
    \"location\": \"Exact location (e.g., city or district name)\" or null,
    \"features\": [\"safety\", \"spacious\", \"fuel efficient\", \"reliable\"] or null
}

THINK LOGICALLY:
1. \"family car\" or \"looking for a family car\" → Think: families need 6-7 seats (for parents + 2-3 kids), prefer SUVs for space and safety, need safety features, spacious interior. Set seats: 6-7, body_type: \"SUV\", purpose: \"family\", features: [\"safety\", \"spacious\"]
2. \"7 seater\" or \"7 seats\" → seats: 7
3. \"SUV\" → body_type: \"SUV\"
4. Think about what makes sense: Large families need more seats, business use might need sedan/luxury, city driving needs fuel efficiency
5. Consider implicit needs: Family = safety + space + seats, Business = comfort + reliability, City = fuel efficiency + compact
6. Extract ONLY what is explicitly mentioned or clearly implied through logical reasoning
7. For price: Convert 'million' to number (e.g., '5 million' = 5000000)

User query: {$message}
Return ONLY the JSON object, no other text:";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $providerConfig['url']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $providerApiKey
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'model' => $modelName,
        'messages' => [
            ['role' => 'system', 'content' => 'You are a precise JSON extraction assistant. Extract ONLY the exact requirements mentioned. Return only valid JSON, no other text.'],
            ['role' => 'user', 'content' => $extractionPrompt]
        ],
        'temperature' => 0.1,
        'max_tokens' => 200
    ]));
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    
    // Disable SSL for Windows/localhost development
    $isWindows = (PHP_OS_FAMILY === 'Windows');
    $serverHost = $_SERVER['HTTP_HOST'] ?? '';
    $isLocalDev = (strpos($serverHost, 'localhost') !== false || strpos($serverHost, '127.0.0.1') !== false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !($isLocalDev || $isWindows));
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, ($isLocalDev || $isWindows) ? 0 : 2);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        if (isset($data['choices'][0]['message']['content'])) {
            $jsonStr = trim($data['choices'][0]['message']['content']);
            $jsonStr = preg_replace('/```json\n?/', '', $jsonStr);
            $jsonStr = preg_replace('/```\n?/', '', $jsonStr);
            $params = json_decode($jsonStr, true);
            if ($params) {
                return $params;
            }
        }
    }
    
    return [];
}

/**
 * Search cars in database based on requirements - STRICT filtering
 * Returns ONLY cars that match ALL specified requirements
 */
function searchCarsByRequirements($db, $requirements) {
    try {
        // CRITICAL: If no requirements specified, return empty (don't show all cars)
        $hasAnyRequirement = !empty($requirements['seats']) || 
                            !empty($requirements['body_type']) || 
                            !empty($requirements['make']) || 
                            !empty($requirements['model']) || 
                            !empty($requirements['max_price']) || 
                            !empty($requirements['fuel_type']) || 
                            !empty($requirements['transmission']) ||
                            !empty($requirements['purpose']) ||
                            !empty($requirements['location']) ||
                            !empty($requirements['location_id']);
        
        if (!$hasAnyRequirement) {
            // No requirements = no results (don't show all cars)
            return [];
        }
        
        $query = "
            SELECT l.*, m.name as make_name, mo.name as model_name, mo.body_type,
                   loc.name as location_name, l.exterior_color, l.mileage, l.seats, 
                   l.fuel_type, l.transmission, l.drivetrain, l.condition_type,
                   u.user_type as seller_type, u.business_id,
                   COALESCE(d.business_name, g.name, ch.business_name, u.full_name) as seller_name,
                   (SELECT filename FROM car_listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) as featured_image
            FROM car_listings l
            INNER JOIN car_makes m ON l.make_id = m.id
            INNER JOIN car_models mo ON l.model_id = mo.id
            INNER JOIN locations loc ON l.location_id = loc.id
            LEFT JOIN users u ON l.user_id = u.id
            LEFT JOIN car_dealers d ON u.user_type = 'dealer' AND u.business_id = d.id
            LEFT JOIN garages g ON u.user_type = 'garage' AND u.business_id = g.id
            LEFT JOIN car_hire_companies ch ON u.user_type = 'car_hire' AND u.business_id = ch.id
            WHERE l.status = 'active' AND l.approval_status = 'approved'
        ";
        
        $params = [];
        $conditions = [];
        
        // Seating capacity - STRICT: must match exactly or be >= specified
        if (!empty($requirements['seats'])) {
            // For family cars (6-7 seats), allow 6 or 7
            if (!empty($requirements['purpose']) && $requirements['purpose'] === 'family') {
                $conditions[] = "(l.seats >= ? AND l.seats <= 8)";
                $params[] = $requirements['seats'];
            } else {
                $conditions[] = "l.seats >= ?";
                $params[] = $requirements['seats'];
            }
        }
        
        // Body type - EXACT match (not LIKE)
        if (!empty($requirements['body_type'])) {
            $conditions[] = "LOWER(mo.body_type) = ?";
            $params[] = strtolower($requirements['body_type']);
        }
        
        // Make - EXACT match (not LIKE)
        if (!empty($requirements['make'])) {
            $conditions[] = "LOWER(m.name) = ?";
            $params[] = strtolower($requirements['make']);
        }
        
        // Model - EXACT match (not LIKE)
        if (!empty($requirements['model'])) {
            $conditions[] = "LOWER(mo.name) = ?";
            $params[] = strtolower($requirements['model']);
        }
        
        // Max price
        if (!empty($requirements['max_price'])) {
            $conditions[] = "l.price <= ?";
            $params[] = $requirements['max_price'];
        }
        
        // Fuel type - EXACT match
        if (!empty($requirements['fuel_type'])) {
            $conditions[] = "LOWER(l.fuel_type) = ?";
            $params[] = strtolower($requirements['fuel_type']);
        }
        
        // Transmission - EXACT match
        if (!empty($requirements['transmission'])) {
            $conditions[] = "LOWER(l.transmission) = ?";
            $params[] = strtolower($requirements['transmission']);
        }

        // Location - STRICT exact filtering.
        if (!empty($requirements['location_id'])) {
            $conditions[] = "l.location_id = ?";
            $params[] = (int)$requirements['location_id'];
        } elseif (!empty($requirements['location'])) {
            $conditions[] = "LOWER(loc.name) = ?";
            $params[] = strtolower(trim((string)$requirements['location']));
        }
        
        // Purpose-based filtering
        if (!empty($requirements['purpose'])) {
            if ($requirements['purpose'] === 'family') {
                // Family cars: 6-7 seats, usually SUV
                if (empty($requirements['seats'])) {
                    $conditions[] = "(l.seats >= 6 AND l.seats <= 8)";
                }
                if (empty($requirements['body_type'])) {
                    $conditions[] = "LOWER(mo.body_type) IN ('suv', 'minivan', 'wagon')";
                }
            } elseif ($requirements['purpose'] === 'business') {
                // Business: usually sedans or pickups
                if (empty($requirements['body_type'])) {
                    $conditions[] = "LOWER(mo.body_type) IN ('sedan', 'pickup')";
                }
            } elseif ($requirements['purpose'] === 'off-road') {
                // Off-road: 4WD/AWD, usually SUV or pickup
                $conditions[] = "LOWER(l.drivetrain) IN ('4wd', 'awd')";
                if (empty($requirements['body_type'])) {
                    $conditions[] = "LOWER(mo.body_type) IN ('suv', 'pickup')";
                }
            }
        }
        
        // CRITICAL: Must have at least one condition
        if (empty($conditions)) {
            return []; // No conditions = no results
        }
        
        $query .= " AND " . implode(" AND ", $conditions);
        $query .= " ORDER BY l.is_featured DESC, l.created_at DESC LIMIT 50";
        
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("searchCarsByRequirements error: " . $e->getMessage());
        return [];
    }
}

/**
 * Determine if clarification is needed
 */
function needsClarification($requirements) {
    // Need clarification if key requirements are missing
    $hasSeats = !empty($requirements['seats']);
    $hasBodyType = !empty($requirements['body_type']);
    $hasBudget = !empty($requirements['max_price']);
    $hasPurpose = !empty($requirements['purpose']);
    
    // If they mentioned "family car" but no specific seats, that's okay
    if (!empty($requirements['purpose']) && $requirements['purpose'] === 'family') {
        return false; // Family car implies 6-7 seats, so we have enough info
    }
    
    // Need clarification if no seats, no body type, and no specific make/model
    if (!$hasSeats && !$hasBodyType && empty($requirements['make']) && empty($requirements['model'])) {
        return true;
    }
    
    return false;
}

/**
 * Detect if a message is a follow-up question
 */
function detectFollowUpQuestion($message, $conversationHistory) {
    if (empty($conversationHistory)) {
        return false;
    }
    
    $messageLower = strtolower($message);
    
    // Follow-up indicators
    $followUpKeywords = [
        'what about', 'how about', 'what is', 'what are', 'tell me more',
        'and', 'also', 'what', 'how', 'why', 'when', 'where',
        'show me', 'give me', 'can you', 'could you',
        'more', 'other', 'another', 'different', 'else',
        'cheaper', 'expensive', 'better', 'best', 'worst',
        'this one', 'that one', 'these', 'those',
        'it', 'they', 'them', 'its', 'their'
    ];
    
    // Check if message is very short (likely a follow-up)
    if (strlen(trim($message)) < 30) {
        foreach ($followUpKeywords as $keyword) {
            if (strpos($messageLower, $keyword) !== false) {
                return true;
            }
        }
    }
    
    // Check for pronouns that reference previous conversation
    $pronouns = ['it', 'they', 'them', 'this', 'that', 'these', 'those', 'its', 'their'];
    foreach ($pronouns as $pronoun) {
        if (preg_match('/\b' . $pronoun . '\b/i', $message)) {
            return true;
        }
    }
    
    // Check for comparative questions
    if (preg_match('/(cheaper|expensive|better|best|worst|more|less|different|other|another)/i', $message)) {
        return true;
    }
    
    return false;
}

/**
 * Generate clarifying questions based on missing requirements
 */
function generateClarificationQuestions($requirements) {
    $questions = [];
    
    if (empty($requirements['seats']) && empty($requirements['purpose'])) {
        $questions[] = "How many seats do you need? (e.g., 5, 6, or 7 seater)";
    }
    
    if (empty($requirements['body_type'])) {
        $questions[] = "What type of vehicle are you looking for? (SUV, Sedan, Pickup, etc.)";
    }
    
    if (empty($requirements['max_price'])) {
        $questions[] = "What's your budget range? (e.g., under 10 million)";
    }
    
    if (empty($requirements['purpose']) && empty($requirements['seats'])) {
        $questions[] = "What will you primarily use the car for? (Family, Business, City driving, etc.)";
    }
    
    return array_slice($questions, 0, 3); // Max 3 questions
}

/**
 * Extract search parameters from user message using AI
 */
function extractSearchParams($db, $message) {
    $settings = getAIChatSettings($db);
    $provider = normalizeAIChatProvider($settings['ai_provider'] ?? 'openai');
    $providerConfig = getAIChatProviderConfig($provider);
    $providerApiKey = getAIProviderApiKeyFromDB($provider, $db);

    if (empty($providerApiKey)) {
        error_log(getAIChatProviderLabel($provider) . " API key not configured in extractSearchParams");
        return [];
    }

    $modelName = trim((string)($settings['model_name'] ?? $providerConfig['default_model']));
    if ($modelName === '') {
        $modelName = $providerConfig['default_model'];
    }
    
    $extractionPrompt = "Extract EXACT car search parameters from this user query. Return ONLY a JSON object with these fields (use null if not found):
{
  \"make\": \"EXACT brand name like Toyota, BMW, Honda, Nissan, etc. Extract ONLY if explicitly mentioned\",
  \"model\": \"EXACT model name like Hilux, Corolla, X5, Civic, etc. Extract ONLY if explicitly mentioned\",
  \"min_price\": number or null,
  \"max_price\": number or null,
  \"min_year\": number or null,
  \"max_year\": number or null,
  \"min_mileage\": number or null,
  \"max_mileage\": number or null,
  \"body_type\": \"SUV, Sedan, Hatchback, Pickup, Van, etc\" or null,
  \"color\": \"exterior color like red, white, black, blue, silver, gray, etc\" or null,
  \"fuel_type\": \"petrol, diesel, hybrid, electric, lpg\" or null,
  \"transmission\": \"manual, automatic, cvt, semi-automatic\" or null,
  \"seats\": number (e.g., 5, 7, 8) or null,
  \"doors\": number (e.g., 2, 4, 5) or null,
  \"location\": \"EXACT city name like Blantyre, Lilongwe, Mzuzu, Zomba, etc. Extract ONLY if explicitly mentioned\",
  \"drivetrain\": \"fwd, rwd, awd, 4wd\" or null,
  \"condition\": \"excellent, very_good, good, fair, poor\" or null,
  \"price_comparison\": \"cheapest, most_expensive, best_value\" or null
}

CRITICAL EXTRACTION RULES:
1. Make and Model: If user says \"toyota hilux\", extract make=\"Toyota\" AND model=\"Hilux\". If user says \"hilux\", extract ONLY model=\"Hilux\" (make=null).
2. Location: If user says \"in blantyre\" or \"blantyre\", extract location=\"Blantyre\". Be precise with city names.
3. Extract ALL mentioned specifications. Do NOT add specifications that are NOT mentioned.
4. For price: Convert 'million' to actual number (e.g., '5 million' = 5000000, '10 million' = 10000000).
5. For price_comparison: \"cheapest\"=\"cheapest\", \"most expensive\"=\"most_expensive\", \"best value\"=\"best_value\".

Examples:
- \"Looking for a toyota hilux in blantyre\" → {\"make\":\"Toyota\",\"model\":\"Hilux\",\"location\":\"Blantyre\"}
- \"Show me red corolla\" → {\"make\":null,\"model\":\"Corolla\",\"color\":\"red\"}
- \"Hilux under 10 million\" → {\"make\":null,\"model\":\"Hilux\",\"max_price\":10000000}

User query: {$message}

Return ONLY the JSON object, no other text:";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $providerConfig['url']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $providerApiKey
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'model' => $modelName,
        'messages' => [
            ['role' => 'system', 'content' => 'You are a precise JSON extraction assistant. Extract ONLY the exact specifications mentioned by the user. Do NOT add anything that was not explicitly stated. Return only valid JSON, no other text.'],
            ['role' => 'user', 'content' => $extractionPrompt]
        ],
        'temperature' => 0.1, // Lower temperature for more precise extraction
        'max_tokens' => 300
    ]));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    // Disable SSL for Windows/localhost development
    $isWindows = (PHP_OS_FAMILY === 'Windows');
    $serverHost = $_SERVER['HTTP_HOST'] ?? '';
    $isLocalDev = (strpos($serverHost, 'localhost') !== false || strpos($serverHost, '127.0.0.1') !== false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !($isLocalDev || $isWindows));
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, ($isLocalDev || $isWindows) ? 0 : 2);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        if (isset($data['choices'][0]['message']['content'])) {
            $jsonStr = trim($data['choices'][0]['message']['content']);
            // Remove markdown code blocks if present
            $jsonStr = preg_replace('/```json\n?/', '', $jsonStr);
            $jsonStr = preg_replace('/```\n?/', '', $jsonStr);
            $params = json_decode($jsonStr, true);
            if ($params) {
                // Debug logging (only in development)
                $isLocalDev = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false || 
                               strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') !== false);
                if ($isLocalDev) {
                    error_log("Extracted search params: " . json_encode($params));
                }
                return $params;
            }
        }
    }
    
    // Fallback: simple keyword extraction (needs $db parameter)
    // We need to get $db from the calling function, but since this is a fallback,
    // we'll return empty and let the calling function handle it
    return [];
}

/**
 * Simple parameter extraction fallback - uses database for makes/models/locations
 */
function simpleExtractParams($db, $message) {
    $params = [];
    $messageLower = strtolower($message);
    
    try {
        // Extract make from database
        $stmt = $db->query("SELECT LOWER(name) as make_name FROM car_makes WHERE is_active = 1 ORDER BY name ASC");
        $makes = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($makes as $make) {
            if (preg_match('/\b' . preg_quote($make, '/') . '\b/i', $messageLower)) {
                $params['make'] = ucfirst($make);
                break;
            }
        }
        
        // Extract model from database
        $modelQuery = "SELECT DISTINCT LOWER(name) as model_name FROM car_models WHERE is_active = 1 ORDER BY name ASC";
        if (!empty($params['make'])) {
            // If make is found, filter models by that make
            $makeStmt = $db->prepare("SELECT id FROM car_makes WHERE LOWER(name) = ? LIMIT 1");
            $makeStmt->execute([$params['make']]);
            $makeData = $makeStmt->fetch(PDO::FETCH_ASSOC);
            if ($makeData) {
                $modelQuery = "SELECT DISTINCT LOWER(name) as model_name FROM car_models WHERE is_active = 1 AND make_id = ? ORDER BY name ASC";
                $modelStmt = $db->prepare($modelQuery);
                $modelStmt->execute([$makeData['id']]);
                $models = $modelStmt->fetchAll(PDO::FETCH_COLUMN);
            } else {
                $modelStmt = $db->query($modelQuery);
                $models = $modelStmt->fetchAll(PDO::FETCH_COLUMN);
            }
        } else {
            $modelStmt = $db->query($modelQuery);
            $models = $modelStmt->fetchAll(PDO::FETCH_COLUMN);
        }
        
        foreach ($models as $model) {
            if (preg_match('/\b' . preg_quote($model, '/') . '\b/i', $messageLower)) {
                $params['model'] = ucfirst($model);
                break;
            }
        }
        
        // Extract location from database
        $locStmt = $db->query("SELECT LOWER(name) as location_name FROM locations ORDER BY name ASC");
        $locations = $locStmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($locations as $location) {
            if (preg_match('/\b' . preg_quote($location, '/') . '\b/i', $messageLower)) {
                $params['location'] = ucfirst($location);
                break;
            }
        }
    } catch (Exception $e) {
        error_log("simpleExtractParams database error: " . $e->getMessage());
    }
    
    // Extract color (these are standard, not database-dependent)
    $colors = ['red', 'white', 'black', 'blue', 'silver', 'gray', 'grey', 'green', 'yellow', 'orange', 'brown', 'beige', 'gold', 'maroon'];
    foreach ($colors as $color) {
        if (preg_match('/\b' . preg_quote($color, '/') . '\b/i', $messageLower)) {
            $params['color'] = $color;
            break;
        }
    }
    
    // Extract price
    if (preg_match('/(?:under|below|less than|max|maximum)\s*(?:mwk|kwacha)?\s*(\d+(?:[.,]\d+)?)\s*(?:million|m|k)?/i', $message, $matches)) {
        $price = floatval(str_replace([',', '.'], '', $matches[1]));
        if (strpos($messageLower, 'million') !== false || strpos($messageLower, ' m') !== false) {
            $params['max_price'] = $price * 1000000;
        } else {
            $params['max_price'] = $price;
        }
    }
    
    if (preg_match('/(?:over|above|more than|min|minimum|from)\s*(?:mwk|kwacha)?\s*(\d+(?:[.,]\d+)?)\s*(?:million|m|k)?/i', $message, $matches)) {
        $price = floatval(str_replace([',', '.'], '', $matches[1]));
        if (strpos($messageLower, 'million') !== false || strpos($messageLower, ' m') !== false) {
            $params['min_price'] = $price * 1000000;
        } else {
            $params['min_price'] = $price;
        }
    }
    
    // Extract mileage
    if (preg_match('/(?:under|below|less than|max)\s*(\d+(?:[.,]\d+)?)\s*(?:km|kilometer|mile|miles|k)/i', $message, $matches)) {
        $mileage = floatval(str_replace([',', '.'], '', $matches[1]));
        $params['max_mileage'] = (int)$mileage;
    }
    
    // Extract seats
    if (preg_match('/(\d+)\s*(?:seat|seater|passenger)/i', $message, $matches)) {
        $params['seats'] = (int)$matches[1];
    }
    
    // Extract fuel type
    if (preg_match('/\bdiesel\b/i', $messageLower)) {
        $params['fuel_type'] = 'diesel';
    } elseif (preg_match('/\bpetrol\b|\bgasoline\b/i', $messageLower)) {
        $params['fuel_type'] = 'petrol';
    } elseif (preg_match('/\bhybrid\b/i', $messageLower)) {
        $params['fuel_type'] = 'hybrid';
    } elseif (preg_match('/\belectric\b/i', $messageLower)) {
        $params['fuel_type'] = 'electric';
    }
    
    // Extract transmission
    if (preg_match('/\bautomatic\b|\bauto\b/i', $messageLower)) {
        $params['transmission'] = 'automatic';
    } elseif (preg_match('/\bmanual\b/i', $messageLower)) {
        $params['transmission'] = 'manual';
    }

    // Dynamically infer comparative intent (e.g., cheapest/most expensive)
    // instead of relying on fixed command phrases.
    $comparativeIntent = inferComparativeIntent($message);
    if (($comparativeIntent['metric'] ?? null) === 'price' && !empty($comparativeIntent['sort_by_price'])) {
        $params['price_comparison'] = $comparativeIntent['sort_by_price'];
    }
    
    return $params;
}

/**
 * Normalize user search params using typo-tolerant matching for strict filters.
 */
function normalizeSearchParams($db, $searchParams, $message) {
    if (!is_array($searchParams)) {
        $searchParams = [];
    }

    $searchParams = normalizeBodyTypeParam($searchParams, $message);

    // If location is missing from extraction, try to infer from phrasing like "in lilonwe".
    if (empty($searchParams['location'])) {
        $inferredLocation = inferLocationFromMessage($message);
        if (!empty($inferredLocation)) {
            $searchParams['location'] = $inferredLocation;
        }
    }

    // Resolve fuzzy/typo location to canonical locations table value.
    if (!empty($searchParams['location'])) {
        $resolvedLocation = resolveClosestLocationName($db, $searchParams['location']);
        if (!empty($resolvedLocation)) {
            $searchParams['location'] = $resolvedLocation;
        }
    }

    return $searchParams;
}

function normalizeBodyTypeParam($searchParams, $message) {
    if (!is_array($searchParams)) {
        $searchParams = [];
    }

    if (!empty($searchParams['body_type'])) {
        $normalized = strtolower(trim((string)$searchParams['body_type']));
        $searchParams['body_type'] = normalizeBodyTypeToken($normalized) ?: $normalized;
        return $searchParams;
    }

    $msg = strtolower((string)$message);

    // Prefer explicit body-type mentions in the raw text first.
    if (preg_match('/\b(suv|sucv|crossover|sedan|hatchback|pickup|truck|wagon|coupe|convertible|minivan|van)\b/i', $msg, $exactMatch)) {
        $direct = normalizeBodyTypeToken((string)$exactMatch[1]);
        if (!empty($direct)) {
            $searchParams['body_type'] = $direct;
            return $searchParams;
        }
    }

    $tokens = preg_split('/[^a-z0-9]+/', $msg);
    if (!is_array($tokens)) {
        return $searchParams;
    }

    foreach ($tokens as $token) {
        if ($token === '') {
            continue;
        }

        // Ignore very short tokens to avoid false positives like "an" -> "van".
        if (strlen($token) < 3) {
            continue;
        }

        $normalized = normalizeBodyTypeToken($token);
        if (!empty($normalized)) {
            $searchParams['body_type'] = $normalized;
            break;
        }
    }

    return $searchParams;
}

function normalizeBodyTypeToken($token) {
    $token = strtolower(trim((string)$token));
    if ($token === '') {
        return null;
    }

    if (strlen($token) < 3) {
        return null;
    }

    $map = [
        'suv' => 'suv',
        'sucv' => 'suv',
        'suv\'s' => 'suv',
        'crossover' => 'crossover',
        'sedan' => 'sedan',
        'hatchback' => 'hatchback',
        'pickup' => 'pickup',
        'truck' => 'truck',
        'wagon' => 'wagon',
        'coupe' => 'coupe',
        'convertible' => 'convertible',
        'minivan' => 'minivan',
        'van' => 'van'
    ];

    if (isset($map[$token])) {
        return $map[$token];
    }

    $known = ['suv', 'crossover', 'sedan', 'hatchback', 'pickup', 'truck', 'wagon', 'coupe', 'convertible', 'minivan', 'van'];
    $best = null;
    $bestDistance = PHP_INT_MAX;

    foreach ($known as $candidate) {
        $distance = levenshtein($token, $candidate);
        if ($distance < $bestDistance) {
            $bestDistance = $distance;
            $best = $candidate;
        }
    }

    // Fuzzy correction only for sufficiently long tokens.
    if (strlen($token) < 4) {
        return null;
    }

    return ($bestDistance <= 1) ? $best : null;
}

function inferLocationFromMessage($message) {
    $msg = trim((string)$message);
    if ($msg === '') {
        return null;
    }

    if (preg_match('/\b(?:in|at|around|near)\s+([a-zA-Z][a-zA-Z\-]*(?:\s+[a-zA-Z][a-zA-Z\-]*){0,2})(?=\s+(?:for|with|under|below|but|and|or|that|which|who|where)\b|[?.!,]|$)/i', $msg, $matches)) {
        return trim($matches[1]);
    }

    return null;
}

/**
 * Resolve a free-text location (including common typos) to a canonical locations.name value.
 */
function resolveClosestLocationName($db, $rawLocation) {
    $raw = strtolower(trim((string)$rawLocation));
    if ($raw === '') {
        return null;
    }

    try {
        // First: exact match.
        $stmt = $db->prepare("SELECT name FROM locations WHERE LOWER(name) = ? LIMIT 1");
        $stmt->execute([$raw]);
        $exact = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!empty($exact['name'])) {
            return $exact['name'];
        }

        // Then: closest edit-distance match across known locations.
        $allStmt = $db->query("SELECT name FROM locations ORDER BY name ASC");
        $all = $allStmt->fetchAll(PDO::FETCH_COLUMN);
        if (empty($all)) {
            return null;
        }

        $best = null;
        $bestDistance = PHP_INT_MAX;

        foreach ($all as $name) {
            $nameLower = strtolower((string)$name);
            $distance = levenshtein($raw, $nameLower);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $name;
            }
        }

        // Accept only close matches to avoid incorrect location mapping.
        if ($best !== null && $bestDistance <= 2) {
            return $best;
        }
    } catch (Exception $e) {
        error_log("resolveClosestLocationName error: " . $e->getMessage());
    }

    return null;
}

function hasMeaningfulSearchParams($params) {
    if (!is_array($params) || empty($params)) {
        return false;
    }

    $keys = [
        'make', 'model', 'min_price', 'max_price', 'min_year', 'max_year',
        'min_mileage', 'max_mileage', 'body_type', 'color', 'fuel_type',
        'transmission', 'seats', 'doors', 'location', 'drivetrain',
        'condition', 'price_comparison'
    ];

    foreach ($keys as $key) {
        if (isset($params[$key]) && $params[$key] !== null && $params[$key] !== '') {
            return true;
        }
    }

    return false;
}

function buildCarSpecFallbackResponse($message, $dbSpecs, $cacheResult, $alternatives) {
    $response = "I checked MotorLink data for your request: \"{$message}\".\n\n";

    if (!empty($dbSpecs)) {
        $response .= "From MotorLink database:\n";
        $count = 0;
        foreach ($dbSpecs as $spec) {
            if ($count >= 5) break;
            $line = "- {$spec['make_name']} {$spec['name']}";
            if (!empty($spec['year_start']) || !empty($spec['year_end'])) {
                $line .= " ({$spec['year_start']}-{$spec['year_end']})";
            }
            if (!empty($spec['engine_size_liters'])) $line .= ", {$spec['engine_size_liters']}L";
            if (!empty($spec['fuel_type'])) $line .= ", " . ucfirst($spec['fuel_type']);
            if (!empty($spec['transmission_type'])) $line .= ", " . ucfirst($spec['transmission_type']);
            $response .= $line . "\n";
            $count++;
        }
    } elseif (!empty($cacheResult['found']) && !empty($cacheResult['summary'])) {
        $response .= "From cached knowledge:\n" . trim((string)$cacheResult['summary']) . "\n";
    } else {
        $response .= "I couldn't find exact specs in local data right now.\n";
    }

    if (!empty($alternatives)) {
        $response .= "\nClosest alternatives in MotorLink:\n";
        foreach (array_slice($alternatives, 0, 5) as $alt) {
            $response .= "- {$alt['make_name']} {$alt['model_name']} ({$alt['variant_count']} variant" . ((int)$alt['variant_count'] > 1 ? 's' : '') . ")\n";
        }
    }

    $response .= "\nI can refine this further if you share a specific year or trim.";
    return $response;
}

function buildCarRecommendationFallbackResponse($requirements, $matchingCars, $clarificationQuestions, $needsClarification, $baseUrl) {
    if (!empty($matchingCars)) {
        $count = count($matchingCars);
        $lines = ["I found {$count} matching vehicle" . ($count > 1 ? 's' : '') . " from MotorLink listings:", ""];

        foreach (array_slice($matchingCars, 0, 5) as $car) {
            $price = isset($car['price']) ? number_format((float)$car['price']) : 'Price on request';
            $lines[] = "- {$car['make_name']} {$car['model_name']} ({$car['year']}) - " . getChatCurrencyCode() . " {$price} [View]({$baseUrl}car.html?id={$car['id']})";
        }

        if ($count > 5) {
            $lines[] = "";
            $lines[] = "I can narrow this down by budget, fuel type, or transmission if you want.";
        }

        return implode("\n", $lines);
    }

    $lines = ["I couldn't find exact matches in current MotorLink listings."];

    if ($needsClarification && !empty($clarificationQuestions)) {
        $lines[] = "Please help me narrow it down:";
        foreach (array_slice($clarificationQuestions, 0, 3) as $q) {
            $lines[] = "- {$q}";
        }
    } else {
        $lines[] = "Try adding make/model, budget, location, or fuel type.";
    }

    return implode("\n", $lines);
}

/**
 * Check if a business is currently open based on business hours text
 * Supports formats like:
 * - "Mon-Fri: 8:00 AM - 5:00 PM, Sat: 9:00 AM - 1:00 PM, Sun: Closed"
 * - "Open 24/7"
 * - "Mon-Sat: 8:00 AM - 5:30 PM, Sun: Closed"
 */
function isBusinessOpenNow($businessHours, $operatingHours = null) {
    if (empty($businessHours) && empty($operatingHours)) {
        return null; // Unknown
    }
    
    $hoursText = !empty($operatingHours) ? $operatingHours : $businessHours;
    if (empty($hoursText)) {
        return null;
    }
    
    $hoursText = trim($hoursText);
    
    // Check for 24/7
    if (preg_match('/24\s*\/\s*7|open\s*24|always\s*open/i', $hoursText)) {
        return true;
    }
    
    // Get current day and time
    $currentDay = strtolower(date('l')); // Monday, Tuesday, etc.
    $currentTime = time();
    $currentHour = (int)date('G');
    $currentMinute = (int)date('i');
    $currentTimeMinutes = $currentHour * 60 + $currentMinute;
    
    // Dynamic day mapping - no hard-coding
    $dayNames = ['monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6, 'sunday' => 0];
    $dayAbbrevs = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 0];
    
    $currentDayNum = date('w'); // 0 (Sunday) to 6 (Saturday)
    $currentDayAbbrevs = [];
    if (isset($dayNames[$currentDay])) {
        $currentDayAbbrevs[] = substr($currentDay, 0, 3);
    }
    $currentDayAbbrevs[] = $currentDay;
    
    // Parse hours text
    // Split by commas first
    $parts = preg_split('/,\s*/', $hoursText);
    
    foreach ($parts as $part) {
        $part = trim($part);
        
        // Check if closed
        if (preg_match('/closed|close/i', $part)) {
            continue;
        }
        
        // Match day ranges like "Mon-Fri:" or "Mon-Sat:"
        if (preg_match('/(Mon|Tue|Wed|Thu|Fri|Sat|Sun)(?:-(Mon|Tue|Wed|Thu|Fri|Sat|Sun))?\s*:/i', $part, $dayMatch)) {
            $startDay = strtolower($dayMatch[1]);
            $endDay = strtolower($dayMatch[2] ?? $dayMatch[1]);
            
            // Check if current day is in range
            $startDayNum = $dayAbbrevs[$startDay] ?? null;
            $endDayNum = $dayAbbrevs[$endDay] ?? null;
            
            if ($startDayNum !== null && $endDayNum !== null) {
                // Handle week wrap-around (e.g., Sat-Sun)
                if ($startDayNum > $endDayNum) {
                    $inRange = ($currentDayNum >= $startDayNum) || ($currentDayNum <= $endDayNum);
                } else {
                    $inRange = ($currentDayNum >= $startDayNum) && ($currentDayNum <= $endDayNum);
                }
                
                if ($inRange) {
                    // Extract time range
                    if (preg_match('/(\d{1,2})\s*:\s*(\d{2})\s*(AM|PM)\s*-\s*(\d{1,2})\s*:\s*(\d{2})\s*(AM|PM)/i', $part, $timeMatch)) {
                        $openHour = (int)$timeMatch[1];
                        $openMinute = (int)$timeMatch[2];
                        $openAmPm = strtoupper($timeMatch[3]);
                        $closeHour = (int)$timeMatch[4];
                        $closeMinute = (int)$timeMatch[5];
                        $closeAmPm = strtoupper($timeMatch[6]);
                        
                        // Convert to 24-hour format
                        if ($openAmPm === 'PM' && $openHour !== 12) {
                            $openHour += 12;
                        } elseif ($openAmPm === 'AM' && $openHour === 12) {
                            $openHour = 0;
                        }
                        
                        if ($closeAmPm === 'PM' && $closeHour !== 12) {
                            $closeHour += 12;
                        } elseif ($closeAmPm === 'AM' && $closeHour === 12) {
                            $closeHour = 0;
                        }
                        
                        $openMinutes = $openHour * 60 + $openMinute;
                        $closeMinutes = $closeHour * 60 + $closeMinute;
                        
                        // Check if current time is within range
                        if ($currentTimeMinutes >= $openMinutes && $currentTimeMinutes <= $closeMinutes) {
                            return true;
                        }
                    }
                }
            }
        }
    }
    
    return false;
}

/**
 * Extract vehicle model from query using database (dynamic, not hard-coded)
 */
function extractVehicleModelFromQuery($db, $message) {
    $messageLower = strtolower($message);
    
    try {
        // Get all active models from database
        $stmt = $db->query("
            SELECT DISTINCT LOWER(name) as model_name 
            FROM car_models 
            WHERE is_active = 1 
            ORDER BY name ASC
        ");
        $models = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Search for model names in the message
        foreach ($models as $model) {
            // Use word boundary to match whole words only
            if (preg_match('/\b' . preg_quote($model, '/') . '\b/i', $messageLower)) {
                return $model;
            }
        }
        
        return null;
    } catch (Exception $e) {
        error_log("extractVehicleModelFromQuery error: " . $e->getMessage());
        return null;
    }
}

/**
 * Detect "open now" query
 */
function detectOpenNowQuery($message) {
    $messageLower = strtolower($message);
    $openNowKeywords = ['open now', 'open today', 'currently open', 'is open', 'are they open', 'opened', 'available now'];
    
    foreach ($openNowKeywords as $keyword) {
        if (strpos($messageLower, $keyword) !== false) {
            return true;
        }
    }
    
    return false;
}

/**
 * Search listings based on parameters - STRICT filtering
 */
function searchListings($db, $searchQuery) {
    $whereConditions = ["l.status = 'active'", "l.approval_status = 'approved'"];
    $params = [];
    
    if (!empty($searchQuery['make_id'])) {
        $whereConditions[] = "l.make_id = ?";
        $params[] = $searchQuery['make_id'];
        $hasSpecificFilters = true;
    }
    
    if (!empty($searchQuery['model_id'])) {
        $whereConditions[] = "l.model_id = ?";
        $params[] = $searchQuery['model_id'];
        $hasSpecificFilters = true;
    }
    
    if (!empty($searchQuery['max_price'])) {
        $whereConditions[] = "l.price <= ?";
        $params[] = $searchQuery['max_price'];
    }
    
    if (!empty($searchQuery['min_price'])) {
        $whereConditions[] = "l.price >= ?";
        $params[] = $searchQuery['min_price'];
    }
    
    if (!empty($searchQuery['min_year'])) {
        $whereConditions[] = "l.year >= ?";
        $params[] = $searchQuery['min_year'];
    }
    
    if (!empty($searchQuery['max_year'])) {
        $whereConditions[] = "l.year <= ?";
        $params[] = $searchQuery['max_year'];
    }
    
    if (!empty($searchQuery['min_mileage'])) {
        $whereConditions[] = "l.mileage >= ?";
        $params[] = $searchQuery['min_mileage'];
    }
    
    if (!empty($searchQuery['max_mileage'])) {
        $whereConditions[] = "l.mileage <= ?";
        $params[] = $searchQuery['max_mileage'];
    }
    
    if (!empty($searchQuery['category'])) {
        $whereConditions[] = "mo.body_type = ?";
        $params[] = $searchQuery['category'];
    }
    
    // Color filter (exterior_color)
    if (!empty($searchQuery['color'])) {
        $whereConditions[] = "LOWER(l.exterior_color) LIKE ?";
        $params[] = '%' . strtolower($searchQuery['color']) . '%';
    }
    
    // Fuel type filter
    if (!empty($searchQuery['fuel_type'])) {
        $whereConditions[] = "LOWER(l.fuel_type) = ?";
        $params[] = strtolower($searchQuery['fuel_type']);
    }
    
    // Transmission filter
    if (!empty($searchQuery['transmission'])) {
        $whereConditions[] = "LOWER(l.transmission) = ?";
        $params[] = strtolower($searchQuery['transmission']);
    }
    
    // Seats filter
    if (!empty($searchQuery['seats'])) {
        $whereConditions[] = "l.seats = ?";
        $params[] = $searchQuery['seats'];
    }
    
    // Doors filter
    if (!empty($searchQuery['doors'])) {
        $whereConditions[] = "l.doors = ?";
        $params[] = $searchQuery['doors'];
    }
    
    // Location filter - STRICT matching (EXACT match required)
    if (!empty($searchQuery['location_id'])) {
        // Use location ID for precise matching
        $whereConditions[] = "l.location_id = ?";
        $params[] = $searchQuery['location_id'];
        $hasSpecificFilters = true;
    } elseif (!empty($searchQuery['location'])) {
        // EXACT name matching only - no LIKE for strict filtering
        $whereConditions[] = "LOWER(loc.name) = ?";
        $locationLower = strtolower($searchQuery['location']);
        $params[] = $locationLower;
        $hasSpecificFilters = true;
    }
    
    // Drivetrain filter
    if (!empty($searchQuery['drivetrain'])) {
        $whereConditions[] = "LOWER(l.drivetrain) = ?";
        $params[] = strtolower($searchQuery['drivetrain']);
    }
    
    // Condition filter
    if (!empty($searchQuery['condition'])) {
        $whereConditions[] = "LOWER(l.condition_type) = ?";
        $params[] = strtolower($searchQuery['condition']);
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    // Determine sort order based on query type
    $sortByPrice = $searchQuery['sort_by_price'] ?? null;
    
    if ($sortByPrice === 'cheapest') {
        // Sort by price ascending (cheapest first)
        $orderBy = "l.price ASC, l.listing_type DESC, l.is_featured DESC";
    } elseif ($sortByPrice === 'most_expensive') {
        // Sort by price descending (most expensive first)
        $orderBy = "l.price DESC, l.listing_type DESC, l.is_featured DESC";
    } elseif ($sortByPrice === 'best_value') {
        // Sort by price ascending but prioritize featured (best value)
        $orderBy = "l.price ASC, l.listing_type DESC, l.is_featured DESC";
    } else {
        // Default sort: listing type, featured, then date
        $orderBy = "l.listing_type DESC, l.is_featured DESC, l.created_at DESC";
    }
    
    $stmt = $db->prepare("
        SELECT l.*, m.name as make_name, mo.name as model_name, mo.body_type,
               loc.name as location_name, l.exterior_color, l.mileage, l.seats, 
               l.fuel_type, l.transmission, l.drivetrain, l.condition_type,
               u.user_type as seller_type, u.business_id,
               COALESCE(d.business_name, g.name, ch.business_name, u.full_name) as seller_name
        FROM car_listings l
        INNER JOIN car_makes m ON l.make_id = m.id
        INNER JOIN car_models mo ON l.model_id = mo.id
        INNER JOIN locations loc ON l.location_id = loc.id
        LEFT JOIN users u ON l.user_id = u.id
        LEFT JOIN car_dealers d ON u.user_type = 'dealer' AND u.business_id = d.id
        LEFT JOIN garages g ON u.user_type = 'garage' AND u.business_id = g.id
        LEFT JOIN car_hire_companies ch ON u.user_type = 'car_hire' AND u.business_id = ch.id
        WHERE {$whereClause}
        ORDER BY {$orderBy}
        LIMIT 50
    ");
    
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get user context information (type, business info, listings count, etc.)
 */
function getUserContext($db, $user) {
    $context = [
        'user_type' => $user['type'] ?? 'user',
        'business_type' => null,
        'business_name' => null,
        'listings_count' => 0,
        'vehicles' => []
    ];
    
    try {
        $userId = $user['id'];
        $userType = $user['type'] ?? 'user';
        
        // Get listings count
        $stmt = $db->prepare("SELECT COUNT(*) FROM car_listings WHERE user_id = ?");
        $stmt->execute([$userId]);
        $context['listings_count'] = (int)$stmt->fetchColumn();
        
        // Get user vehicles
        try {
            $stmt = $db->prepare("
                SELECT 
                    id, make, model, year, fuel_type, engine_size_liters, 
                    transmission, body_type, fuel_consumption_liters_per_100km,
                    fuel_tank_capacity_liters, vin, is_primary
                FROM user_vehicles 
                WHERE user_id = ?
                ORDER BY is_primary DESC, created_at DESC
            ");
            $stmt->execute([$userId]);
            $context['vehicles'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("getUserContext - vehicles error: " . $e->getMessage());
            $context['vehicles'] = [];
        }
        
        // Get business info based on user type
        if ($userType === 'dealer') {
            $stmt = $db->prepare("SELECT business_name FROM car_dealers WHERE user_id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $dealer = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($dealer) {
                $context['business_type'] = 'dealer';
                $context['business_name'] = $dealer['business_name'] ?? null;
            }
        } elseif ($userType === 'garage') {
            $stmt = $db->prepare("SELECT name FROM garages WHERE user_id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $garage = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($garage) {
                $context['business_type'] = 'garage';
                $context['business_name'] = $garage['name'] ?? null;
            }
        } elseif ($userType === 'car_hire') {
            $stmt = $db->prepare("SELECT business_name FROM car_hire_companies WHERE user_id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $company = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($company) {
                $context['business_type'] = 'car_hire';
                $context['business_name'] = $company['business_name'] ?? null;
            }
        }
    } catch (Exception $e) {
        error_log("getUserContext error: " . $e->getMessage());
        // Return default context on error
    }
    
    return $context;
}

/**
 * Detect and handle user actions (change price, delete listing, etc.)
 * Returns action result if action was detected and handled, false otherwise
 */
function detectAndHandleAction($db, $message, $user, $userContext) {
    $messageLower = strtolower($message);
    $userId = $user['id'];

    $serverHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $baseUrl = $protocol . '://' . $serverHost . '/';
    
    // ========================================================================
    // SMART PRICE UPDATE - Enhanced with listing identification
    // ========================================================================
    if (preg_match('/(?:change|update|modify|set|edit).*price.*(?:to|is|at|for)\s*(?:mwk|kwacha)?\s*(\d+(?:[.,]\d+)?)\s*(?:million|m|k)?/i', $message, $matches)) {
        $price = floatval(str_replace([',', '.'], '', $matches[1]));
        if (strpos($messageLower, 'million') !== false || strpos($messageLower, ' m') !== false) {
            $price = $price * 1000000;
        }
        
        // Try to identify the listing from message
        $listing = identifyListingFromMessage($db, $userId, $message);
        
        if ($listing) {
            // Update price directly with verification
            try {
                // Get current price for verification
                $checkStmt = $db->prepare("SELECT price FROM car_listings WHERE id = ? AND user_id = ?");
                $checkStmt->execute([$listing['id'], $userId]);
                $currentListing = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$currentListing) {
                    return [
                        'response' => "❌ Listing not found or you don't have permission to update it.",
                        'action_detected' => 'update_price',
                        'success' => false
                    ];
                }
                
                $oldPrice = $currentListing['price'];
                
                // Update price - ensure we're updating the correct listing
                $stmt = $db->prepare("UPDATE car_listings SET price = ?, updated_at = NOW() WHERE id = ? AND user_id = ?");
                $result = $stmt->execute([$price, $listing['id'], $userId]);
                
                if (!$result) {
                    $errorInfo = $stmt->errorInfo();
                    error_log("Price update execute failed for listing ID: {$listing['id']}, user ID: {$userId}. Error: " . json_encode($errorInfo));
                    return [
                        'response' => "❌ Failed to update price. Database error occurred. Please try again or contact support.",
                        'action_detected' => 'update_price',
                        'success' => false
                    ];
                }
                
                // Check if any rows were affected
                $rowsAffected = $stmt->rowCount();
                
                if ($rowsAffected === 0) {
                    error_log("Price update: No rows affected for listing ID: {$listing['id']}, user ID: {$userId}. Possible permission issue or listing doesn't exist.");
                    return [
                        'response' => "❌ Price update failed. No changes were made. Please verify you own listing #{$listing['id']} and try again.",
                        'action_detected' => 'update_price',
                        'success' => false
                    ];
                }
                
                // Verify the update was successful by reading back the price
                $verifyStmt = $db->prepare("SELECT price FROM car_listings WHERE id = ? AND user_id = ?");
                $verifyStmt->execute([$listing['id'], $userId]);
                $updatedListing = $verifyStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($updatedListing && abs($updatedListing['price'] - $price) < 0.01) {
                    // Update successful
                    return [
                        'response' => "✅ Price updated successfully! Your {$listing['make_name']} {$listing['model_name']} ({$listing['year']}) - **Reference #{$listing['id']}** is now priced at " . getChatCurrencyCode($db) . " " . number_format($price) . " (was " . getChatCurrencyCode($db) . " " . number_format($oldPrice) . "). [View listing]({$listing['url']})",
                        'action_detected' => 'update_price',
                        'success' => true,
                        'listing_id' => $listing['id'],
                        'old_price' => $oldPrice,
                        'new_price' => $price
                    ];
                } else {
                    $actualPrice = $updatedListing ? $updatedListing['price'] : 'unknown';
                    error_log("Price update verification failed - price mismatch. Expected: {$price}, Got: {$actualPrice} for listing ID: {$listing['id']}");
                    return [
                        'response' => "❌ Price update verification failed. Expected " . getChatCurrencyCode($db) . " " . number_format($price) . " but got " . getChatCurrencyCode($db) . " " . number_format($actualPrice) . ". Please check the listing or try again.",
                        'action_detected' => 'update_price',
                        'success' => false
                    ];
                }
            } catch (PDOException $e) {
                error_log("Update price PDO error: " . $e->getMessage() . " | Code: " . $e->getCode());
                return [
                    'response' => "❌ Database error while updating price. Please try again or contact support.",
                    'action_detected' => 'update_price',
                    'success' => false
                ];
            } catch (Exception $e) {
                error_log("Update price error: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
                return [
                    'response' => "❌ Failed to update price: " . $e->getMessage() . ". Please try again or contact support.",
                    'action_detected' => 'update_price',
                    'success' => false
                ];
            }
        } else {
            // Show user's listings to help them choose
            $listings = getUserListingsForSelection($db, $userId);
            if (empty($listings)) {
                return [
                    'response' => "You don't have any listings to update. [Create a listing]({$baseUrl}sell.html) first.",
                    'action_detected' => 'update_price',
                    'requires_clarification' => true
                ];
            }
            
            $response = "Which listing would you like to update? I found " . count($listings) . " of your listings:\n\n";
            foreach (array_slice($listings, 0, 5) as $l) {
                $response .= "• [{$l['make_name']} {$l['model_name']} ({$l['year']}) - " . getChatCurrencyCode($db) . " " . number_format($l['price']) . "]({$l['url']}) - **Reference #{$l['id']}**\n";
            }
            $response .= "\nPlease specify which one (e.g., 'update listing #{$listings[0]['id']} price to 5 million' or 'update the Toyota Hilux price to 5 million').";
            
            return [
                'response' => $response,
                'action_detected' => 'update_price',
                'listings' => $listings,
                'requires_clarification' => true
            ];
        }
    }
    
    // ========================================================================
    // SMART DELETE LISTING - Enhanced with confirmation
    // ========================================================================
    if (preg_match('/(?:delete|remove|take down|deactivate).*(?:listing|car|vehicle)/i', $message)) {
        $listing = identifyListingFromMessage($db, $userId, $message);
        
        if ($listing) {
            // Check if user confirmed (look for confirmation words)
            $confirmed = preg_match('/(?:yes|confirm|sure|ok|okay|proceed|do it)/i', $message);
            
            if (!$confirmed && !preg_match('/(?:delete|remove).*(?:listing|car|vehicle).*(?:#|number|id)\s*(\d+)/i', $message)) {
                return [
                    'response' => "⚠️ Are you sure you want to delete your {$listing['make_name']} {$listing['model_name']} ({$listing['year']}) listing? This action cannot be undone.\n\nType 'yes, delete it' to confirm, or specify a different listing.",
                    'action_detected' => 'delete_listing',
                    'listing_id' => $listing['id'],
                    'requires_confirmation' => true
                ];
            }
            
            try {
                // Soft delete by setting status to inactive
                $stmt = $db->prepare("UPDATE car_listings SET status = 'inactive', updated_at = NOW() WHERE id = ? AND user_id = ?");
                $stmt->execute([$listing['id'], $userId]);
                
                return [
                    'response' => "✅ Listing deleted successfully. Your {$listing['make_name']} {$listing['model_name']} has been removed from the marketplace.",
                    'action_detected' => 'delete_listing',
                    'success' => true,
                    'listing_id' => $listing['id']
                ];
            } catch (Exception $e) {
                error_log("Delete listing error: " . $e->getMessage());
                return [
                    'response' => "❌ Failed to delete listing. Please try again or contact support.",
                    'action_detected' => 'delete_listing',
                    'success' => false
                ];
            }
        } else {
            // Show listings for selection
            $listings = getUserListingsForSelection($db, $userId);
            if (empty($listings)) {
                return [
                    'response' => "You don't have any listings to delete.",
                    'action_detected' => 'delete_listing',
                    'requires_clarification' => true
                ];
            }
            
            $response = "Which listing would you like to delete? I found " . count($listings) . " of your listings:\n\n";
            foreach (array_slice($listings, 0, 5) as $l) {
                $response .= "• [{$l['make_name']} {$l['model_name']} ({$l['year']})]({$l['url']}) - **Reference #{$l['id']}**\n";
            }
            $response .= "\nPlease specify which one (e.g., 'delete listing #{$listings[0]['id']}' or 'delete my Toyota Hilux').";
            
            return [
                'response' => $response,
                'action_detected' => 'delete_listing',
                'listings' => $listings,
                'requires_clarification' => true
            ];
        }
    }
    
    // ========================================================================
    // MARK AS SOLD
    // ========================================================================
    if (preg_match('/(?:mark|set|update).*(?:as|to).*(?:sold|sale.*complete|sold.*out)/i', $message)) {
        $listing = identifyListingFromMessage($db, $userId, $message);
        
        if ($listing) {
            try {
                $stmt = $db->prepare("UPDATE car_listings SET status = 'sold', updated_at = NOW() WHERE id = ? AND user_id = ?");
                $stmt->execute([$listing['id'], $userId]);
                
                return [
                    'response' => "✅ Congratulations! Your {$listing['make_name']} {$listing['model_name']} has been marked as sold. [View listing]({$listing['url']})",
                    'action_detected' => 'mark_sold',
                    'success' => true,
                    'listing_id' => $listing['id']
                ];
            } catch (Exception $e) {
                error_log("Mark as sold error: " . $e->getMessage());
                return [
                    'response' => "❌ Failed to mark listing as sold. Please try again.",
                    'action_detected' => 'mark_sold',
                    'success' => false
                ];
            }
        }
    }
    
    // ========================================================================
    // SMART ANALYTICS & INSIGHTS
    // ========================================================================
    if (preg_match('/(?:show|tell|give|what).*(?:analytics|stats|statistics|performance|insights|metrics|how.*doing|how.*performing)/i', $message)) {
        return handleAnalyticsQuery($db, $userId, $userContext);
    }
    
    // ========================================================================
    // PRICE SUGGESTIONS
    // ========================================================================
    if (preg_match('/(?:suggest|recommend|what.*should|what.*price|price.*suggestion|market.*price)/i', $message)) {
        $listing = identifyListingFromMessage($db, $userId, $message);
        if ($listing) {
            return handlePriceSuggestion($db, $listing);
        }
    }
    
    // ========================================================================
    // PROACTIVE SUGGESTIONS - Only for listing/business suggestions, NOT car recommendations
    // ========================================================================
    // CRITICAL: Must explicitly ask for suggestions about THEIR listings/business, not general car recommendations
    if (preg_match('/(?:suggestions|tips|advice).*(?:for|about|on).*(?:my|our).*(?:listing|listings|business|sales|inventory)/i', $message) ||
        preg_match('/(?:what|how).*(?:should|can|could).*(?:i|we).*(?:do|improve).*(?:my|our).*(?:listing|listings|business|sales)/i', $message) ||
        preg_match('/(?:give|show|tell).*(?:me|us).*(?:suggestions|tips|advice).*(?:my|our)/i', $message)) {
        return handleProactiveSuggestions($db, $userId, $userContext);
    }
    
    // ========================================================================
    // CREATE LISTING ASSISTANCE
    // ========================================================================
    if (preg_match('/(?:create|add|post|new|list|sell).*(?:listing|car|vehicle|ad)/i', $message)) {
        $serverHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        $isLocalhost = in_array($serverHost, ['localhost', '127.0.0.1']) || 
                       strpos($serverHost, 'localhost:') === 0 || 
                       strpos($serverHost, '127.0.0.1:') === 0 ||
                       preg_match('/^(192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $serverHost);
        $isProduction = !$isLocalhost && !empty($serverHost);
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $baseUrl = $protocol . '://' . $serverHost . '/';
        
        return [
            'response' => "I can help you create a new listing! [Click here to create a listing]({$baseUrl}sell.html)\n\n**Tips for a great listing:**\n• Add multiple high-quality photos\n• Write a detailed description\n• Set a competitive price\n• Include all vehicle details\n\nWould you like me to help you with pricing suggestions or listing optimization?",
            'action_detected' => 'create_listing',
            'create_url' => $baseUrl . 'sell.html'
        ];
    }
    
    // ========================================================================
    // BULK OPERATIONS
    // ========================================================================
    if (preg_match('/(?:update|change|modify).*all.*(?:price|prices)/i', $message)) {
        // Extract price change percentage or amount
        if (preg_match('/(?:by|increase|decrease|reduce).*(\d+)\s*(?:%|percent)/i', $message, $matches)) {
            $percentage = (float)$matches[1];
            return handleBulkPriceUpdate($db, $userId, $percentage, true);
        } elseif (preg_match('/(?:by|increase|decrease|reduce).*(?:mwk|kwacha)?\s*(\d+(?:[.,]\d+)?)\s*(?:million|m|k)?/i', $message, $matches)) {
            $amount = floatval(str_replace([',', '.'], '', $matches[1]));
            if (strpos($messageLower, 'million') !== false || strpos($messageLower, ' m') !== false) {
                $amount = $amount * 1000000;
            }
            return handleBulkPriceUpdate($db, $userId, $amount, false);
        }
    }
    
    // ========================================================================
    // QUICK ACTIONS - Status changes
    // ========================================================================
    if (preg_match('/(?:activate|enable|make.*active).*(?:all|my).*(?:listing|listings)/i', $message)) {
        return handleBulkStatusUpdate($db, $userId, 'active');
    }
    
    if (preg_match('/(?:deactivate|disable|make.*inactive).*(?:all|my).*(?:listing|listings)/i', $message)) {
        return handleBulkStatusUpdate($db, $userId, 'inactive');
    }
    
    // ========================================================================
    // SMART SEARCH WITHIN USER'S LISTINGS
    // ========================================================================
    if (preg_match('/(?:find|search|show).*(?:my|in my).*(?:listing|car|vehicle)/i', $message)) {
        return handleUserListingSearch($db, $userId, $message);
    }
    
    // ========================================================================
    // CAR HIRE SPECIFIC OPERATIONS
    // ========================================================================
    if ($userContext['business_type'] === 'car_hire') {
        // Update fleet vehicle availability
        if (preg_match('/(?:mark|set|make).*(?:vehicle|car|fleet).*(?:available|unavailable|rented|out)/i', $message)) {
            return handleFleetAvailabilityUpdate($db, $userId, $message);
        }
        
        // Update fleet vehicle rates
        if (preg_match('/(?:change|update|set).*(?:fleet|vehicle|car).*(?:rate|price|daily).*(?:to|is|at)\s*(?:mwk|kwacha)?\s*(\d+(?:[.,]\d+)?)/i', $message, $matches)) {
            $rate = floatval(str_replace([',', '.'], '', $matches[1]));
            return handleFleetRateUpdate($db, $userId, $message, $rate);
        }
    }
    
    // ========================================================================
    // GARAGE SPECIFIC OPERATIONS
    // ========================================================================
    if ($userContext['business_type'] === 'garage') {
        // Update garage services
        if (preg_match('/(?:add|remove|update).*(?:service|services)/i', $message)) {
            $serverHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
            $isLocalhost = in_array($serverHost, ['localhost', '127.0.0.1']) || 
                           strpos($serverHost, 'localhost:') === 0 || 
                           strpos($serverHost, '127.0.0.1:') === 0 ||
                           preg_match('/^(192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $serverHost);
            $isProduction = !$isLocalhost && !empty($serverHost);
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $baseUrl = $protocol . '://' . $serverHost . '/';
            return [
                'response' => "I can help you manage your garage services. Please specify which service to add or remove, or [manage services in your dashboard]({$baseUrl}garage-dashboard.html#services).",
                'action_detected' => 'update_garage_services',
                'requires_clarification' => true
            ];
        }
        
        // Update operating hours
        if (preg_match('/(?:change|update|set).*(?:hours|operating|schedule).*(?:to|is|as)\s*(.+)/i', $message, $matches)) {
            $hours = trim($matches[1]);
            return handleGarageHoursUpdate($db, $userId, $hours);
        }
    }
    
    // ========================================================================
    // VIEW LISTINGS - Enhanced with analytics
    // ========================================================================
    if (preg_match('/(?:show|list|view|display|see).*(?:my|your).*(?:listing|car|vehicle)/i', $message)) {
        try {
            $userId = $user['id'];
            $stmt = $db->prepare("
                SELECT l.id, l.year, l.price, l.status, l.approval_status,
                       m.name as make_name, mo.name as model_name,
                       loc.name as location_name
            FROM car_listings l
            INNER JOIN car_makes m ON l.make_id = m.id
            INNER JOIN car_models mo ON l.model_id = mo.id
            LEFT JOIN locations loc ON l.location_id = loc.id
            WHERE l.user_id = ?
            ORDER BY l.created_at DESC
            LIMIT 10
            ");
            $stmt->execute([$userId]);
            $listings = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($listings)) {
                $serverHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
                $isLocalhost = in_array($serverHost, ['localhost', '127.0.0.1']) || 
                               strpos($serverHost, 'localhost:') === 0 || 
                               strpos($serverHost, '127.0.0.1:') === 0 ||
                               preg_match('/^(192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $serverHost);
                $isProduction = !$isLocalhost && !empty($serverHost);
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $baseUrl = $protocol . '://' . $serverHost . '/';
                return [
                    'response' => "You don't have any listings yet. Would you like help creating one? [Create a listing]({$baseUrl}sell.html)",
                    'action_detected' => 'view_listings',
                    'listings' => []
                ];
            }
            
            // Get base URL
            $serverHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
            $isLocalhost = in_array($serverHost, ['localhost', '127.0.0.1']) || 
                           strpos($serverHost, 'localhost:') === 0 || 
                           strpos($serverHost, '127.0.0.1:') === 0 ||
                           preg_match('/^(192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $serverHost);
            $isProduction = !$isLocalhost && !empty($serverHost);
            
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $baseUrl = $protocol . '://' . $serverHost . '/';
            
            // Calculate stats
            $total = count($listings);
            $active = count(array_filter($listings, fn($l) => $l['status'] === 'active'));
            $pending = count(array_filter($listings, fn($l) => $l['approval_status'] === 'pending'));
            
            $response = "📋 **Your Listings** ({$total} total, {$active} active";
            if ($pending > 0) {
                $response .= ", {$pending} pending";
            }
            $response .= "):\n\n";
            
            foreach ($listings as $listing) {
                $price = isset($listing['price']) ? number_format($listing['price']) : 'Price on request';
                $listingUrl = $baseUrl . "car.html?id=" . $listing['id'];
                $statusIcon = $listing['status'] === 'active' ? '✅' : ($listing['approval_status'] === 'pending' ? '⏳' : '❌');
                $response .= "{$statusIcon} [{$listing['make_name']} {$listing['model_name']} ({$listing['year']}) - " . getChatCurrencyCode($db) . " {$price}]({$listingUrl}) - **Reference #{$listing['id']}**";
                if ($listing['location_name']) {
                    $response .= "\n   📍 {$listing['location_name']}";
                }
                $response .= "\n";
            }
            
            if ($pending > 0) {
                $response .= "\n💡 You have {$pending} listing(s) pending approval. Make sure all information is complete!";
            }
            
            return [
                'response' => $response,
                'action_detected' => 'view_listings',
                'listings' => $listings,
                'base_url' => $baseUrl,
                'stats' => ['total' => $total, 'active' => $active, 'pending' => $pending]
            ];
        } catch (Exception $e) {
            error_log("detectAndHandleAction view_listings error: " . $e->getMessage());
            return false; // Fall through to regular AI response
        }
    }
    
    // No action detected
    return false;
}

/**
 * Detect if user message is about dealers
 */
function detectDealerQuery($message) {
    $dealerKeywords = [
        'dealer', 'dealers', 'showroom', 'showrooms', 'car dealer', 'car dealers',
        'car showroom', 'car showrooms', 'automotive dealer', 'automotive dealers',
        'car seller', 'car sellers', 'used car dealer', 'new car dealer',
        'car dealership', 'dealerships', 'find a dealer', 'looking for dealer',
        'need a dealer', 'car dealer near', 'showroom near', 'dealer in',
        'dealer at', 'showroom in', 'showroom at', 'which dealer',
        'closest dealer', 'nearest dealer', 'dealer near me'
    ];
    
    $messageLower = strtolower($message);
    
    foreach ($dealerKeywords as $keyword) {
        if (strpos($messageLower, $keyword) !== false) {
            return true;
        }
    }
    
    return false;
}

/**
 * Detect if user message is asking about garages
 */
function detectGarageQuery($message) {
    $messageLower = strtolower($message);
    
    // First, exclude general knowledge questions (how/why/what is questions about concepts)
    // These should NOT trigger garage queries
    $generalKnowledgePatterns = [
        '/^how (does|do|can|will|should|would)/i',
        '/^why (does|do|is|are|will)/i',
        '/^what (is|are|does|do|was|were)/i',
        '/explain (how|why|what)/i',
        '/tell me (how|why|what)/i'
    ];
    
    foreach ($generalKnowledgePatterns as $pattern) {
        if (preg_match($pattern, $messageLower)) {
            // If it's a general knowledge question, check if it's specifically about finding a garage
            // Only return true if it explicitly asks to find/locate a garage
            if (preg_match('/(find|locate|where is|which|what garage|garage open|garage near)/i', $messageLower)) {
                // Explicit garage search query
                break; // Continue to check garage-specific keywords
            } else {
                // General knowledge question - don't trigger garage query
                return false;
            }
        }
    }
    
    // Specific garage-related queries that should trigger garage search
    $garageSpecificKeywords = [
        'what garage', 'which garage', 'find a garage', 'find garage', 'locate garage',
        'garage open', 'garages open', 'open garage', 'garage near', 'garage nearby',
        'garage closest', 'garage nearest', 'nearest garage', 'closest garage',
        'garage that offers', 'garage offering', 'garages that offer',
        'where can i get', 'where can i find a garage', 'where to find garage',
        'need a garage', 'looking for a garage', 'search for garage'
    ];
    
    // Check for specific garage query patterns
    foreach ($garageSpecificKeywords as $keyword) {
        if (strpos($messageLower, $keyword) !== false) {
            return true;
        }
    }
    
    // Also check for service queries that are looking for garages (not general knowledge)
    // But only if they're asking WHERE to find them, not HOW they work
    $serviceKeywords = [
        'brake service', 'oil change', 'engine repair', 'ac repair',
        'body work', 'painting', 'tire service', 'battery replacement',
        'diagnostics', 'towing service', 'breakdown service', 'emergency repair'
    ];
    
    // Only trigger if it's asking WHERE to find the service, not HOW it works
    if (preg_match('/(where|which|what|find|locate|near|open).*(garage|workshop|mechanic)/i', $messageLower)) {
        foreach ($serviceKeywords as $service) {
            if (strpos($messageLower, $service) !== false) {
                return true;
            }
        }
    }
    
    return false;
}

/**
 * Extract dealer search parameters from user message
 */
function extractDealerSearchParams($db, $message) {
    $params = [];
    $messageLower = strtolower($message);
    
    // Detect proximity queries (closest, nearest, near me, nearby)
    $proximityKeywords = ['closest', 'nearest', 'near me', 'nearby', 'close to me', 'near'];
    foreach ($proximityKeywords as $keyword) {
        if (strpos($messageLower, $keyword) !== false) {
            $params['proximity'] = true;
            break;
        }
    }
    
    // Detect "open now" queries
    if (detectOpenNowQuery($message)) {
        $params['open_now'] = true;
    }
    
    // Extract vehicle model (e.g., "navara", "hilux") - using database
    $vehicleModel = extractVehicleModelFromQuery($db, $message);
    if ($vehicleModel) {
        $params['vehicle_model'] = $vehicleModel;
    }
    
    // Extract location
    $locations = ['blantyre', 'lilongwe', 'mzuzu', 'zomba', 'mangochi', 'salima', 'kasungu', 'mulanje'];
    foreach ($locations as $location) {
        if (strpos($messageLower, $location) !== false) {
            $params['location'] = ucfirst($location);
            break;
        }
    }
    
    // Extract specialization (from specialization JSON field)
    $specializations = ['toyota', 'honda', 'bmw', 'mercedes', 'nissan', 'ford', 'luxury', 'european', 'japanese', 'german', 'american'];
    foreach ($specializations as $specialization) {
        if (strpos($messageLower, $specialization) !== false) {
            $params['specialization'] = $specialization;
            break;
        }
    }
    
    return $params;
}

/**
 * Search dealers based on parameters
 */
function searchDealers($db, $searchParams, $userLocation = null) {
    try {
        $statusFilter = $searchParams['status'] ?? 'active';
        $whereConditions = ["d.status = ?"];
        $params = [$statusFilter];
        
        $isLocalDev = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false || 
                       strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') !== false);
        
        // Location filter - case-insensitive matching
        if (!empty($searchParams['location'])) {
            $locationSearch = strtolower(trim($searchParams['location']));
            $whereConditions[] = "(LOWER(loc.name) LIKE ? OR LOWER(loc.district) LIKE ? OR LOWER(loc.region) LIKE ?)";
            $locationPattern = '%' . $locationSearch . '%';
            $params[] = $locationPattern;
            $params[] = $locationPattern;
            $params[] = $locationPattern;
        } elseif (!empty($searchParams['proximity']) && $userLocation && !empty($userLocation['location_name'])) {
            if (!empty($userLocation['location_name'])) {
                $whereConditions[] = "loc.name LIKE ?";
                $params[] = '%' . $userLocation['location_name'] . '%';
            }
        }
        
        // Specialization filter
        if (!empty($searchParams['specialization'])) {
            $whereConditions[] = "d.specialization LIKE ?";
            $specSearch = '%' . strtolower($searchParams['specialization']) . '%';
            $params[] = $specSearch;
        }
        
        $whereClause = implode(' AND ', $whereConditions);
        
        // Include location information and business_hours
        $stmt = $db->prepare("
            SELECT d.*, loc.name as location_name, loc.region, loc.district, d.business_hours,
                   (SELECT COUNT(*) 
                    FROM car_listings cl 
                    INNER JOIN users u ON cl.user_id = u.id 
                    WHERE u.business_id = d.id AND u.user_type = 'dealer' 
                    AND cl.status = 'active' AND cl.approval_status = 'approved') as total_cars
            FROM car_dealers d
            INNER JOIN locations loc ON d.location_id = loc.id
            WHERE {$whereClause}
            ORDER BY d.featured DESC, d.verified DESC, d.certified DESC, d.business_name ASC
            LIMIT 50
        ");
        $stmt->execute($params);
        $dealers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Filter by vehicle model if specified (check dealer's listings)
        if (!empty($searchParams['vehicle_model'])) {
            $vehicleModel = strtolower($searchParams['vehicle_model']);
            $filteredDealers = [];
            foreach ($dealers as $dealer) {
                // Check if dealer has listings with this model
                $modelStmt = $db->prepare("
                    SELECT COUNT(*) 
                    FROM car_listings cl 
                    INNER JOIN users u ON cl.user_id = u.id 
                    INNER JOIN car_models cm ON cl.model_id = cm.id
                    WHERE u.business_id = ? AND u.user_type = 'dealer' 
                    AND cl.status = 'active' AND cl.approval_status = 'approved'
                    AND LOWER(cm.name) LIKE ?
                ");
                $modelStmt->execute([$dealer['id'], '%' . $vehicleModel . '%']);
                $modelCount = $modelStmt->fetchColumn();
                if ($modelCount > 0) {
                    $filteredDealers[] = $dealer;
                }
            }
            $dealers = $filteredDealers;
        }
        
        // Filter by "open now" if specified
        if (!empty($searchParams['open_now'])) {
            $openDealers = [];
            foreach ($dealers as $dealer) {
                if (isBusinessOpenNow($dealer['business_hours'] ?? null)) {
                    $openDealers[] = $dealer;
                }
            }
            $dealers = $openDealers;
        }
        
        // Note: Distance calculation removed as locations table doesn't have latitude/longitude columns
        // Proximity queries will sort by featured/verified status only
        
        return $dealers;
        
    } catch (Exception $e) {
        error_log("searchDealers error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        return [];
    }
}

/**
 * Handle dealer query - search and provide dealer information
 */
function handleDealerQuery($db, $message, $conversationHistory) {
    try {
        // Get user info for location context
        $user = getCurrentUser(true);
        $userLocation = null;
        if ($user) {
            try {
                $locationStmt = $db->prepare("
                    SELECT u.city, u.address, loc.name as location_name, loc.region, loc.district
                    FROM users u
                    LEFT JOIN locations loc ON u.city = loc.name OR u.city LIKE CONCAT('%', loc.name, '%')
                    WHERE u.id = ?
                    LIMIT 1
                ");
                $locationStmt->execute([$user['id']]);
                $userLocation = $locationStmt->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                error_log("Error fetching user location in handleDealerQuery: " . $e->getMessage());
            }
        }
        
        // Extract search parameters from message
        $searchParams = extractDealerSearchParams($db, $message);
        
        // If proximity query and user has location, add to search params
        if (!empty($searchParams['proximity']) && $userLocation && !empty($userLocation['location_name'])) {
            $searchParams['user_location'] = $userLocation['location_name'];
        }
        
        // Search dealers
        $dealers = searchDealers($db, $searchParams, $userLocation);
        
        // Get base URL
        $serverHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        $isLocalhost = in_array($serverHost, ['localhost', '127.0.0.1']) || 
                       strpos($serverHost, 'localhost:') === 0 || 
                       strpos($serverHost, '127.0.0.1:') === 0 ||
                       preg_match('/^(192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $serverHost);
        $isProduction = !$isLocalhost && !empty($serverHost);
        $isLocalDev = $isLocalhost;
        
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $baseUrl = $protocol . '://' . $serverHost . '/';
        
        // Detect query type
        $isProximityQuery = !empty($searchParams['proximity']);
        $isMostQuery = detectComparativeQuery($message) === 'most' || detectComparativeQuery($message) === 'largest' || detectComparativeQuery($message) === 'biggest';
        
        // Format response
        if (empty($dealers)) {
            $response = "I couldn't find any dealers in the database matching your search. ";
            $response .= "Try [browsing all dealers]({$baseUrl}dealers.html) on the website, or contact support if you believe this is an error.";
        } else {
            // For proximity queries (closest, nearest), return only the closest result
            if ($isProximityQuery && !empty($dealers)) {
                $dealer = $dealers[0];
                $dealerUrl = $baseUrl . "showroom.html?dealer_id=" . $dealer['id'];
                $response = "The closest dealer to you is [{$dealer['business_name']}]({$dealerUrl})";
                
                if (!empty($dealer['location_name'])) {
                    $response .= " in {$dealer['location_name']}";
                }
                
                if (!empty($dealer['distance'])) {
                    $response .= " (approximately " . round($dealer['distance'], 1) . " km away)";
                }
                
                $response .= ".\n\n";
                
                if (!empty($dealer['phone'])) {
                    $response .= "📞 {$dealer['phone']}\n";
                }
                
                if (!empty($dealer['total_cars'])) {
                    $response .= "🚗 {$dealer['total_cars']} car" . ($dealer['total_cars'] != 1 ? 's' : '') . " available\n";
                }
            }
            // For "most" queries, return only the top result
            else if ($isMostQuery && !empty($dealers)) {
                $dealer = $dealers[0];
                $dealerUrl = $baseUrl . "showroom.html?dealer_id=" . $dealer['id'];
                $response = "[{$dealer['business_name']}]({$dealerUrl})";
                
                if (!empty($dealer['location_name'])) {
                    $response .= "\n📍 {$dealer['location_name']}";
                }
                
                if (!empty($dealer['phone'])) {
                    $response .= "\n📞 {$dealer['phone']}";
                }
                
                if (!empty($dealer['total_cars'])) {
                    $response .= "\n🚗 {$dealer['total_cars']} car" . ($dealer['total_cars'] != 1 ? 's' : '') . " available";
                }
            } else {
                // Regular query - show top 2-3 most relevant results
                $count = count($dealers);
                $displayCount = min(3, $count);
                $response = "Here are the {$displayCount} most relevant dealer" . ($displayCount > 1 ? 's' : '') . ":\n\n";
                
                foreach (array_slice($dealers, 0, $displayCount) as $dealer) {
                    $dealerUrl = $baseUrl . "showroom.html?dealer_id=" . $dealer['id'];
                    $response .= "• [{$dealer['business_name']}]({$dealerUrl})\n";
                    
                    if (!empty($dealer['location_name'])) {
                        $response .= "  📍 {$dealer['location_name']}\n";
                    }
                    
                    if (!empty($dealer['phone'])) {
                        $response .= "  📞 {$dealer['phone']}\n";
                    }
                    
                    if (!empty($dealer['total_cars'])) {
                        $response .= "  🚗 {$dealer['total_cars']} car" . ($dealer['total_cars'] != 1 ? 's' : '') . " available\n";
                    }
                    
                    $response .= "\n";
                }
                
                if ($count > $displayCount) {
                    $response .= "And " . ($count - $displayCount) . " more. [View all]({$baseUrl}dealers.html)";
                }
            }
        }
        
        sendSuccess([
            'response' => $response,
            'dealers' => array_slice($dealers, 0, 10),
            'total_results' => count($dealers),
            'base_url' => $baseUrl
        ]);
        
    } catch (Exception $e) {
        error_log("handleDealerQuery error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        sendError('Dealer search failed. Please try rephrasing your query.', 500);
    }
}

/**
 * Handle garage query - search and provide garage information
 */
function handleGarageQuery($db, $message, $conversationHistory) {
    try {
        // Get user info for location context
        $user = getCurrentUser(true);
        $userLocation = null;
        if ($user) {
            try {
                $locationStmt = $db->prepare("
                    SELECT u.city, u.address, loc.name as location_name, loc.region, loc.district
                    FROM users u
                    LEFT JOIN locations loc ON u.city = loc.name OR u.city LIKE CONCAT('%', loc.name, '%')
                    WHERE u.id = ?
                    LIMIT 1
                ");
                $locationStmt->execute([$user['id']]);
                $userLocation = $locationStmt->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                error_log("Error fetching user location in handleGarageQuery: " . $e->getMessage());
            }
        }
        
        // Extract search parameters from message
        $searchParams = extractGarageSearchParams($message);
        
        // If proximity query and user has location, add to search params
        if (!empty($searchParams['proximity']) && $userLocation && !empty($userLocation['location_name'])) {
            $searchParams['user_location'] = $userLocation['location_name'];
        }
        
        // Search garages
        $garages = searchGarages($db, $searchParams, $userLocation);
        
        // Get base URL
        $serverHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        $isLocalhost = in_array($serverHost, ['localhost', '127.0.0.1']) || 
                       strpos($serverHost, 'localhost:') === 0 || 
                       strpos($serverHost, '127.0.0.1:') === 0 ||
                       preg_match('/^(192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $serverHost);
        $isProduction = !$isLocalhost && !empty($serverHost);
        
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $baseUrl = $protocol . '://' . $serverHost . '/';
        
        // Detect query type
        $isProximityQuery = !empty($searchParams['proximity']);
        $isMostQuery = detectComparativeQuery($message) === 'most' || detectComparativeQuery($message) === 'largest' || detectComparativeQuery($message) === 'biggest';
        
        // Format response
        if (empty($garages)) {
            // If location was specified, try with different statuses but keep location
            if (!empty($searchParams['location'])) {
                // First try with pending_approval status but same location
                $retryParams = $searchParams;
                $retryParams['status'] = 'pending_approval';
                $garages = searchGarages($db, $retryParams, $userLocation);
                
                // If still no results, try without status filter but keep location
                if (empty($garages)) {
                    $retryParams = $searchParams;
                    unset($retryParams['status']);
                    // Try to get any garage in that location regardless of status
                    try {
                        $locationSearch = strtolower(trim($searchParams['location']));
                        $locationPattern = '%' . $locationSearch . '%';
                        $testStmt = $db->prepare("
                            SELECT COUNT(*) as count 
                            FROM garages g
                            INNER JOIN locations loc ON g.location_id = loc.id
                            WHERE (LOWER(loc.name) LIKE ? OR LOWER(loc.district) LIKE ? OR LOWER(loc.region) LIKE ?)
                        ");
                        $testStmt->execute([$locationPattern, $locationPattern, $locationPattern]);
                        $locationCount = $testStmt->fetch(PDO::FETCH_ASSOC);
                        if ($isLocalhost) {
                            error_log("Garages in location '{$searchParams['location']}': " . $locationCount['count']);
                        }
                    } catch (Exception $e) {
                        if ($isLocalhost) {
                            error_log("Error checking location count: " . $e->getMessage());
                        }
                    }
                }
            }
            
            // If still no results and location was specified, try without location but keep other filters
            if (empty($garages) && !empty($searchParams['location'])) {
                $retryParams = $searchParams;
                unset($retryParams['location']);
                $retryParams['status'] = 'active';
                $garages = searchGarages($db, $retryParams, $userLocation);
            }
            
            // If still no results, try with minimal filters
            if (empty($garages)) {
                if (!empty($searchParams['service']) || !empty($searchParams['specialization'])) {
                    // Retry with minimal filters - just active status
                    $broadSearchParams = ['status' => 'active'];
                    if (!empty($searchParams['proximity'])) {
                        $broadSearchParams['proximity'] = true;
                    }
                    $garages = searchGarages($db, $broadSearchParams, $userLocation);
                }
            }
            
            // If still no results, try with pending_approval status as well
            if (empty($garages)) {
                $broadSearchParams = ['status' => 'active'];
                $garages = searchGarages($db, $broadSearchParams, null);
                
                // If still empty, try without status filter to see if there are ANY garages
                if (empty($garages)) {
                    // Try to get count of all garages for debugging
                    try {
                        $countStmt = $db->query("SELECT status, COUNT(*) as count FROM garages GROUP BY status");
                        $statusCounts = $countStmt->fetchAll(PDO::FETCH_ASSOC);
                        if ($isLocalhost) {
                            error_log("Garage status counts: " . json_encode($statusCounts));
                        }
                    } catch (Exception $e) {
                        if ($isLocalhost) {
                            error_log("Error getting garage counts: " . $e->getMessage());
                        }
                    }
                    
                    // Try with pending_approval status
                    $broadSearchParams = ['status' => 'pending_approval'];
                    $garages = searchGarages($db, $broadSearchParams, null);
                }
            }
            
            if (empty($garages)) {
                $response = "I couldn't find any garages in the database matching your search. ";
                $response .= "Try [browsing all garages]({$baseUrl}garages.html) on the website, or contact support if you believe this is an error.";
            } else {
                $response = "I found some garages:\n\n";
                // Continue with the garage listing below
            }
        }
        
        if (!empty($garages)) {
            // For proximity queries (closest, nearest), return only the closest result
            if ($isProximityQuery && !empty($garages)) {
                $garage = $garages[0];
                $garageUrl = $baseUrl . "garages.html?id=" . $garage['id'];
                $response = "The closest garage to you is [{$garage['name']}]({$garageUrl})";
                
                if (!empty($garage['location_name'])) {
                    $response .= " in {$garage['location_name']}";
                }
                
                if (!empty($garage['distance'])) {
                    $response .= " (approximately " . round($garage['distance'], 1) . " km away)";
                }
                
                $response .= ".\n\n";
                
                if (!empty($garage['phone'])) {
                    $response .= "📞 {$garage['phone']}\n";
                }
                
                if (!empty($garage['services_list'])) {
                    $services = is_array($garage['services_list']) ? $garage['services_list'] : json_decode($garage['services_list'], true);
                    if (is_array($services) && !empty($services)) {
                        $response .= "🔧 Services: " . implode(', ', array_slice($services, 0, 5));
                        if (count($services) > 5) {
                            $response .= " and more";
                        }
                    }
                }
            }
            // For "most" queries, return only the top result
            else if ($isMostQuery && !empty($garages)) {
                $garage = $garages[0];
                $garageUrl = $baseUrl . "garages.html?id=" . $garage['id'];
                $response = "[{$garage['name']}]({$garageUrl})";
                
                if (!empty($garage['location_name'])) {
                    $response .= "\n📍 {$garage['location_name']}";
                }
                
                if (!empty($garage['phone'])) {
                    $response .= "\n📞 {$garage['phone']}";
                }
                
                if (!empty($garage['services_list'])) {
                    $services = is_array($garage['services_list']) ? $garage['services_list'] : json_decode($garage['services_list'], true);
                    if (is_array($services) && !empty($services)) {
                        $response .= "\n🔧 " . implode(', ', array_slice($services, 0, 5));
                    }
                }
            } else {
                // Regular query - show top 2-3 most relevant results
                $count = count($garages);
                $displayCount = min(2, $count); // Show max 2 for better focus
                $response = "Here are the {$displayCount} most relevant garage" . ($displayCount > 1 ? 's' : '') . ":\n\n";
                
                foreach (array_slice($garages, 0, $displayCount) as $garage) {
                    $garageUrl = $baseUrl . "garages.html?id=" . $garage['id'];
                    $response .= "• [{$garage['name']}]({$garageUrl})\n";
                    
                    if (!empty($garage['location_name'])) {
                        $response .= "  📍 {$garage['location_name']}\n";
                    }
                    
                    if (!empty($garage['phone'])) {
                        $response .= "  📞 {$garage['phone']}\n";
                    }
                    
                    // Show if open now (if open_now query was made)
                    if (!empty($searchParams['open_now'])) {
                        $isOpen = isBusinessOpenNow($garage['business_hours'] ?? null, $garage['operating_hours'] ?? null);
                        if ($isOpen !== null) {
                            $response .= "  " . ($isOpen ? "🟢 Open now" : "🔴 Closed now") . "\n";
                        }
                    }
                    
                    $response .= "\n";
                }
                
                if ($count > $displayCount) {
                    $response .= "And " . ($count - $displayCount) . " more. [View all]({$baseUrl}garages.html)";
                }
            }
        }
        
        sendSuccess([
            'response' => $response,
            'garages' => array_slice($garages, 0, 10),
            'total_results' => count($garages),
            'base_url' => $baseUrl
        ]);
        
    } catch (Exception $e) {
        error_log("handleGarageQuery error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        sendError('Garage search failed. Please try rephrasing your query.', 500);
    }
}

/**
 * Extract garage search parameters from user message
 */
function extractGarageSearchParams($message) {
    $params = [];
    $messageLower = strtolower($message);
    
    // Detect proximity queries (closest, nearest, near me, nearby)
    $proximityKeywords = ['closest', 'nearest', 'near me', 'nearby', 'close to me', 'near'];
    foreach ($proximityKeywords as $keyword) {
        if (strpos($messageLower, $keyword) !== false) {
            $params['proximity'] = true;
            break;
        }
    }
    
    // Extract location
    $locations = ['blantyre', 'lilongwe', 'mzuzu', 'zomba', 'mangochi', 'salima', 'kasungu', 'mulanje'];
    foreach ($locations as $location) {
        if (strpos($messageLower, $location) !== false) {
            $params['location'] = $location;
            break;
        }
    }
    
    // Extract service type - Improved precision with multiple keyword matching
    $services = [
        // Multi-keyword patterns (more specific first)
        'brake service' => 'Brake Service',
        'brake repair' => 'Brake Service',
        'brake replacement' => 'Brake Service',
        'oil change' => 'Oil Change',
        'oil service' => 'Oil Change',
        'engine repair' => 'Engine Repair',
        'engine service' => 'Engine Repair',
        'engine diagnostic' => 'Engine Diagnostics',
        'ac repair' => 'AC Repair',
        'air conditioning' => 'AC Repair',
        'ac service' => 'AC Repair',
        'transmission service' => 'Transmission Service',
        'transmission repair' => 'Transmission Service',
        'transmission' => 'Transmission Service',
        'electrical repair' => 'Electrical Repair',
        'electrical service' => 'Electrical Repair',
        'electrical' => 'Electrical Repair',
        'body work' => 'Body Work',
        'body repair' => 'Body Work',
        'painting' => 'Painting',
        'car painting' => 'Painting',
        'tire service' => 'Tire Service',
        'tire repair' => 'Tire Service',
        'tire replacement' => 'Tire Service',
        'tire' => 'Tire Service',
        'tyre' => 'Tire Service',
        'battery replacement' => 'Battery Replacement',
        'battery' => 'Battery Replacement',
        'diagnostics' => 'Engine Diagnostics',
        'engine diagnostic' => 'Engine Diagnostics',
        'towing service' => 'Towing Service',
        'towing' => 'Towing Service',
        'recovery service' => 'Breakdown Recovery',
        'breakdown recovery' => 'Breakdown Recovery',
        'recovery' => 'Breakdown Recovery',
        'emergency service' => 'Emergency Services',
        'emergency repair' => 'Emergency Services',
        'emergency' => 'Emergency Services',
        'wheel alignment' => 'Wheel Alignment',
        'alignment' => 'Wheel Alignment',
        'suspension' => 'Suspension Repair',
        'suspension repair' => 'Suspension Repair',
        'exhaust' => 'Exhaust Repair',
        'exhaust repair' => 'Exhaust Repair',
        'clutch' => 'Clutch Repair',
        'clutch repair' => 'Clutch Repair',
        'radiator' => 'Radiator Repair',
        'radiator repair' => 'Radiator Repair',
        'windshield' => 'Windshield Repair',
        'windshield repair' => 'Windshield Repair',
        'windscreen' => 'Windshield Repair'
    ];
    
    // Try multi-word matches first (more specific)
    $foundService = false;
    foreach ($services as $keyword => $service) {
        // Check if the keyword appears as a whole phrase (more precise)
        if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/i', $messageLower)) {
            $params['service'] = $service;
            $foundService = true;
            break;
        }
    }
    
    // If no multi-word match found, try single keywords (fallback)
    if (!$foundService) {
        $singleKeywords = [
            'brake' => 'Brake Service',
            'oil' => 'Oil Change',
            'engine' => 'Engine Repair',
            'ac' => 'AC Repair',
            'electrical' => 'Electrical Repair',
            'body' => 'Body Work',
            'paint' => 'Painting',
            'tire' => 'Tire Service',
            'tyre' => 'Tire Service',
            'battery' => 'Battery Replacement',
            'diagnostic' => 'Engine Diagnostics',
            'tow' => 'Towing Service',
            'recover' => 'Breakdown Recovery',
            'emergency' => 'Emergency Services'
        ];
        
        foreach ($singleKeywords as $keyword => $service) {
            if (strpos($messageLower, $keyword) !== false) {
                $params['service'] = $service;
                break;
            }
        }
    }
    
    // Extract specialization
    $specializations = ['toyota', 'honda', 'bmw', 'mercedes', 'nissan', 'ford', 'luxury', 'european', 'japanese'];
    foreach ($specializations as $specialization) {
        if (strpos($messageLower, $specialization) !== false) {
            $params['specialization'] = $specialization;
            break;
        }
    }
    
    // Detect "open now" queries
    if (detectOpenNowQuery($message)) {
        $params['open_now'] = true;
    }
    
    return $params;
}

/**
 * Calculate distance between two coordinates (Haversine formula)
 */
function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    if (empty($lat1) || empty($lon1) || empty($lat2) || empty($lon2)) {
        return null;
    }
    
    $earthRadius = 6371; // Earth's radius in kilometers
    
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    
    $a = sin($dLat/2) * sin($dLat/2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon/2) * sin($dLon/2);
    
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    $distance = $earthRadius * $c;
    
    return $distance;
}

/**
 * Search garages based on parameters
 */
function searchGarages($db, $searchParams, $userLocation = null) {
    try {
        // Start with flexible status filter - try active first, but can fallback
        // Garage statuses: 'active', 'pending_approval', 'suspended'
        $statusFilter = $searchParams['status'] ?? 'active';
        $whereConditions = ["g.status = ?"];
        $params = [$statusFilter];
        
        // Debug: Log the search parameters (only in development)
        $isLocalDev = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false || 
                       strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') !== false);
        if ($isLocalDev) {
            error_log("searchGarages called with params: " . json_encode($searchParams) . ", statusFilter: " . $statusFilter);
        }
    
    // Location filter - make it flexible and case-insensitive
    // Only filter by location if a specific location is requested
    if (!empty($searchParams['location'])) {
        // Use case-insensitive matching and also try district/region
        // MySQL LIKE is case-insensitive by default, but we'll use LOWER for safety
        $locationSearch = strtolower(trim($searchParams['location']));
        $whereConditions[] = "(LOWER(loc.name) LIKE ? OR LOWER(loc.district) LIKE ? OR LOWER(loc.region) LIKE ?)";
        $locationPattern = '%' . $locationSearch . '%';
        $params[] = $locationPattern;
        $params[] = $locationPattern;
        $params[] = $locationPattern;
        
        // Debug logging
        if ($isLocalDev) {
            error_log("Location filter applied: " . $locationSearch . " -> Pattern: " . $locationPattern);
        }
    } elseif (!empty($searchParams['proximity']) && $userLocation && !empty($userLocation['location_name'])) {
        // For proximity queries, prioritize user's location but don't make it mandatory
        // We'll sort by distance later, so we can include all garages if needed
        // Only filter by location if we have a specific location match
        if (!empty($userLocation['location_name'])) {
            $whereConditions[] = "loc.name LIKE ?";
            $params[] = '%' . $userLocation['location_name'] . '%';
        }
    }
    
    // Service filter
    if (!empty($searchParams['service'])) {
        $whereConditions[] = "(g.services LIKE ? OR g.emergency_services LIKE ?)";
        $serviceSearch = '%' . $searchParams['service'] . '%';
        $params[] = $serviceSearch;
        $params[] = $serviceSearch;
    }
    
    // Specialization filter
    if (!empty($searchParams['specialization'])) {
        $whereConditions[] = "(g.specialization LIKE ? OR g.specializes_in_cars LIKE ?)";
        $specSearch = '%' . $searchParams['specialization'] . '%';
        $params[] = $specSearch;
        $params[] = $specSearch;
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    // Order by featured/verified status
    $orderBy = "g.featured DESC, g.verified DESC, g.certified DESC, g.name ASC";
    
    // Use INNER JOIN like getGarages() in api.php for consistency
    // This ensures we only get garages with valid locations
    try {
        $stmt = $db->prepare("
            SELECT g.*, loc.name as location_name, loc.region, loc.district, g.business_hours, g.operating_hours
            FROM garages g
            INNER JOIN locations loc ON g.location_id = loc.id
            WHERE {$whereClause}
            ORDER BY {$orderBy}
            LIMIT 20
        ");
        $stmt->execute($params);
        $garages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Filter by "open now" if specified
        if (!empty($searchParams['open_now'])) {
            $openGarages = [];
            foreach ($garages as $garage) {
                if (isBusinessOpenNow($garage['business_hours'] ?? null, $garage['operating_hours'] ?? null)) {
                    $openGarages[] = $garage;
                }
            }
            $garages = $openGarages;
        }
    } catch (PDOException $e) {
        error_log("SQL error in searchGarages: " . $e->getMessage());
        error_log("SQL: SELECT g.*, loc.name as location_name, loc.region, loc.district FROM garages g INNER JOIN locations loc ON g.location_id = loc.id WHERE {$whereClause} ORDER BY {$orderBy}");
        error_log("Params: " . print_r($params, true));
        throw $e;
    }
    
    // Note: Distance calculation removed as locations table doesn't have latitude/longitude columns
    
    // Parse JSON fields safely
    foreach ($garages as &$garage) {
        try {
            if (!empty($garage['services'])) {
                if (is_string($garage['services'])) {
                    $decoded = json_decode($garage['services'], true);
                    $garage['services_list'] = (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [];
                } else {
                    $garage['services_list'] = is_array($garage['services']) ? $garage['services'] : [];
                }
            } else {
                $garage['services_list'] = [];
            }
            
            if (!empty($garage['emergency_services'])) {
                if (is_string($garage['emergency_services'])) {
                    $decoded = json_decode($garage['emergency_services'], true);
                    $garage['emergency_services_list'] = (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [];
                } else {
                    $garage['emergency_services_list'] = is_array($garage['emergency_services']) ? $garage['emergency_services'] : [];
                }
            } else {
                $garage['emergency_services_list'] = [];
            }
            
            if (!empty($garage['specialization'])) {
                if (is_string($garage['specialization'])) {
                    $decoded = json_decode($garage['specialization'], true);
                    $garage['specialization_list'] = (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [];
                } else {
                    $garage['specialization_list'] = is_array($garage['specialization']) ? $garage['specialization'] : [];
                }
            } else {
                $garage['specialization_list'] = [];
            }
        } catch (Exception $e) {
            error_log("Error parsing JSON fields for garage: " . $e->getMessage());
            // Set defaults on error
            $garage['services_list'] = $garage['services_list'] ?? [];
            $garage['emergency_services_list'] = $garage['emergency_services_list'] ?? [];
            $garage['specialization_list'] = $garage['specialization_list'] ?? [];
        }
    }
    unset($garage); // Important: unset reference after loop
    
    // Debug: Log results (only in development)
    $isLocalDev = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false || 
                   strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') !== false);
    if ($isLocalDev && empty($garages)) {
        // Test query to see if there are ANY garages in the database
        try {
            $testStmt = $db->query("SELECT status, COUNT(*) as count FROM garages GROUP BY status");
            $testResults = $testStmt->fetchAll(PDO::FETCH_ASSOC);
            error_log("Garage status breakdown: " . json_encode($testResults));
        } catch (Exception $testE) {
            error_log("Test query failed: " . $testE->getMessage());
        }
    }
    
    return $garages;
    } catch (Exception $e) {
        error_log("searchGarages error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        // Return empty array on error to prevent 500
        return [];
    }
}

/**
 * Handle car hire query - search and provide car hire information
 */
/**
 * Detect if query is asking for "most", "best", "largest", etc.
 */
function detectComparativeQuery($message) {
    $messageLower = strtolower($message);
    $comparativeKeywords = ['most', 'best', 'largest', 'biggest', 'top', 'highest', 'greatest', 'maximum', 'max'];
    
    foreach ($comparativeKeywords as $keyword) {
        if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/', $messageLower)) {
            return $keyword;
        }
    }
    
    return false;
}

/**
 * Infer comparative intent from natural language using metric + direction logic.
 * Returns:
 * - metric: inferred target metric (price|mileage|year|seats|null)
 * - direction: asc|desc|null
 * - sort_by_price: cheapest|most_expensive|best_value|null (for pricing intents)
 */
function inferComparativeIntent($message) {
    $text = strtolower(trim((string)$message));
    if ($text === '') {
        return ['metric' => null, 'direction' => null, 'sort_by_price' => null];
    }

    $normalized = preg_replace('/[^a-z0-9\s]/', ' ', $text);
    $tokens = preg_split('/\s+/', trim($normalized));
    $tokens = array_values(array_filter($tokens, function($t) { return $t !== ''; }));

    $hasBestValuePhrase = preg_match('/\bbest\s+value\b|\bvalue\s+for\s+money\b|\bbest\s+deal\b/', $text) === 1;

    $lowSignals = ['low', 'least', 'min', 'minimum', 'cheap', 'afford', 'budget'];
    $highSignals = ['high', 'most', 'max', 'maximum', 'expens', 'prici', 'costl', 'premium', 'luxury'];

    $metricSignals = [
        'price' => ['price', 'cost', 'value', 'amount', 'budget', 'mwk', 'kwacha', 'million', 'cheap', 'expens', 'prici', 'costl', 'afford'],
        'mileage' => ['mileage', 'km', 'kilometer', 'kilometre', 'odometer'],
        'year' => ['year', 'newest', 'oldest', 'latest', 'recent'],
        'seats' => ['seat', 'seater', 'passenger']
    ];

    $metricScores = ['price' => 0, 'mileage' => 0, 'year' => 0, 'seats' => 0];
    foreach ($tokens as $token) {
        foreach ($metricSignals as $metric => $signals) {
            foreach ($signals as $signal) {
                if (strpos($token, $signal) !== false) {
                    $metricScores[$metric]++;
                    break;
                }
            }
        }
    }

    $metric = null;
    $maxScore = 0;
    foreach ($metricScores as $candidate => $score) {
        if ($score > $maxScore) {
            $metric = $candidate;
            $maxScore = $score;
        }
    }

    // If no explicit metric but this looks like a comparative car follow-up,
    // default to price (what users typically mean by "which one is the best/cheapest").
    $isComparativeForm = preg_match('/\b(most|least|lowest|highest|best|worst|minimum|maximum)\b/', $text) === 1;
    if ($metric === null && $isComparativeForm) {
        $metric = 'price';
    }

    $lowScore = 0;
    $highScore = 0;
    foreach ($tokens as $token) {
        foreach ($lowSignals as $signal) {
            if (strpos($token, $signal) !== false) {
                $lowScore++;
                break;
            }
        }
        foreach ($highSignals as $signal) {
            if (strpos($token, $signal) !== false) {
                $highScore++;
                break;
            }
        }
    }

    $direction = null;
    if ($lowScore > $highScore) {
        $direction = 'asc';
    } elseif ($highScore > $lowScore) {
        $direction = 'desc';
    }

    $sortByPrice = null;
    if ($metric === 'price') {
        if ($hasBestValuePhrase) {
            $sortByPrice = 'best_value';
        } elseif ($direction === 'asc') {
            $sortByPrice = 'cheapest';
        } elseif ($direction === 'desc') {
            $sortByPrice = 'most_expensive';
        }
    }

    return [
        'metric' => $metric,
        'direction' => $direction,
        'sort_by_price' => $sortByPrice
    ];
}

/**
 * Detect price-based comparative queries (cheapest, most expensive, etc.)
 */
function detectPriceComparativeQuery($message) {
    $intent = inferComparativeIntent($message);
    if (($intent['metric'] ?? null) !== 'price') {
        return false;
    }

    return $intent['sort_by_price'] ?? false;
}

function handleCarHireQuery($db, $message, $conversationHistory) {
    try {
        // Get user info for location context
        $user = getCurrentUser(true);
        $userLocation = null;
        if ($user) {
            try {
                $locationStmt = $db->prepare("
                    SELECT u.city, u.address, loc.name as location_name, loc.region, loc.district
                    FROM users u
                    LEFT JOIN locations loc ON u.city = loc.name OR u.city LIKE CONCAT('%', loc.name, '%')
                    WHERE u.id = ?
                    LIMIT 1
                ");
                $locationStmt->execute([$user['id']]);
                $userLocation = $locationStmt->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                error_log("Error fetching user location in handleCarHireQuery: " . $e->getMessage());
            }
        }
        
        // Extract search parameters from message
        $searchParams = extractCarHireSearchParams($message);
        
        // Detect if this is a "most" query (e.g., "which car hire has most cars")
        $isComparativeQuery = detectComparativeQuery($message);
        $isMostQuery = ($isComparativeQuery === 'most' || $isComparativeQuery === 'largest' || $isComparativeQuery === 'biggest');
        $priceComparison = detectPriceComparativeQuery($message);
        $isPriceComparison = !empty($priceComparison);
        $isProximityQuery = !empty($searchParams['proximity']);
        
        // If it's a "most" query, sort by vehicle count
        if ($isMostQuery) {
            $searchParams['sort_by'] = 'vehicle_count';
        }

        if ($isPriceComparison) {
            $searchParams['price_comparison'] = $priceComparison;
        }
        
        // If proximity query and user has location, add to search params
        if ($isProximityQuery && $userLocation && !empty($userLocation['location_name'])) {
            $searchParams['user_location'] = $userLocation['location_name'];
        }
        
        // Search car hire companies and their fleet
        $results = searchCarHire($db, $searchParams, $userLocation);
        
        // Get base URL
        $serverHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        $isLocalhost = in_array($serverHost, ['localhost', '127.0.0.1']) || 
                       strpos($serverHost, 'localhost:') === 0 || 
                       strpos($serverHost, '127.0.0.1:') === 0 ||
                       preg_match('/^(192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $serverHost);
        $isProduction = !$isLocalhost && !empty($serverHost);
        
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $baseUrl = $protocol . '://' . $serverHost . '/';
        
        // Format response
        if (empty($results['companies'])) {
            $response = "I couldn't find any car hire companies matching your search. ";
            $response .= "Try [browsing all car hire companies]({$baseUrl}car-hire.html) on the website.";
        } else {
            // For proximity queries (closest, nearest), return only the closest result
            if ($isProximityQuery && !empty($results['companies'])) {
                $company = $results['companies'][0];
                $companyUrl = $baseUrl . "car-hire-company.html?id=" . $company['id'];
                $response = "The closest car hire company to you is [{$company['business_name']}]({$companyUrl})";
                
                if (!empty($company['location_name'])) {
                    $response .= " in {$company['location_name']}";
                }
                
                if (!empty($company['distance'])) {
                    $response .= " (approximately " . round($company['distance'], 1) . " km away)";
                }
                
                $response .= ".\n\n";
                
                if (!empty($company['phone'])) {
                    $response .= "📞 {$company['phone']}\n";
                }
                
                if (!empty($company['total_vehicles'])) {
                    $response .= "🚗 {$company['total_vehicles']} vehicle" . ($company['total_vehicles'] != 1 ? 's' : '') . " available\n";
                }
                
                if (!empty($company['daily_rate_from'])) {
                    $response .= "💰 Starting from: " . getChatCurrencyCode($db) . " " . number_format($company['daily_rate_from']) . "/day\n";
                }
                
                // Show matching vehicle details if available
                if (!empty($company['matching_vehicles']) && count($company['matching_vehicles']) > 0) {
                    $response .= "\nAvailable vehicles:\n";
                    foreach (array_slice($company['matching_vehicles'], 0, 3) as $vehicle) {
                        $response .= "• ";
                        if (!empty($vehicle['make_name'])) $response .= $vehicle['make_name'] . " ";
                        if (!empty($vehicle['model_name'])) $response .= $vehicle['model_name'];
                        if (!empty($vehicle['seats'])) $response .= " ({$vehicle['seats']} seats)";
                        if (!empty($vehicle['daily_rate'])) $response .= " - " . getChatCurrencyCode($db) . " " . number_format($vehicle['daily_rate']) . "/day";
                        $response .= "\n";
                    }
                }
            } elseif ($isMostQuery && !empty($results['companies'])) {
                $company = $results['companies'][0];
                $companyUrl = $baseUrl . "car-hire-company.html?id=" . $company['id'];
                $vehicleCount = $company['total_vehicles'] ?? 0;
                
                $response = "[{$company['business_name']}]({$companyUrl}) has the most vehicles with {$vehicleCount} car" . ($vehicleCount != 1 ? 's' : '') . ".\n\n";
                
                if (!empty($company['location_name'])) {
                    $response .= "📍 Location: {$company['location_name']}\n";
                }
                
                if (!empty($company['phone'])) {
                    $response .= "📞 Phone: {$company['phone']}\n";
                }
                
                if (!empty($company['daily_rate_from'])) {
                    $response .= "💰 Starting from: " . getChatCurrencyCode($db) . " " . number_format($company['daily_rate_from']) . "/day\n";
                }
            } elseif ($isPriceComparison && !empty($results['companies'])) {
                $company = $results['companies'][0];
                $companyUrl = $baseUrl . "car-hire-company.html?id=" . $company['id'];
                $label = $priceComparison === 'most_expensive' ? 'most expensive' : 'cheapest';

                $response = "The {$label} car hire option I found is [{$company['business_name']}]({$companyUrl})";
                if (!empty($company['location_name'])) {
                    $response .= " in {$company['location_name']}";
                }
                $response .= ".\n\n";

                if (!empty($company['daily_rate_from'])) {
                    $response .= "💰 From " . getChatCurrencyCode($db) . " " . number_format($company['daily_rate_from']) . "/day\n";
                }
                if (!empty($company['phone'])) {
                    $response .= "📞 {$company['phone']}\n";
                }
                if (!empty($company['total_vehicles'])) {
                    $response .= "🚗 {$company['total_vehicles']} vehicle" . ($company['total_vehicles'] != 1 ? 's' : '') . " available\n";
                }
            } else {
                // Regular query - show top 3 results (reduced from 5 for conciseness)
                $count = count($results['companies']);
                $response = "Found {$count} car hire compan" . ($count > 1 ? 'ies' : 'y') . ":\n\n";
                
                foreach (array_slice($results['companies'], 0, 3) as $company) {
                    $companyUrl = $baseUrl . "car-hire-company.html?id=" . $company['id'];
                    $response .= "• [{$company['business_name']}]({$companyUrl})\n";
                    
                    if (!empty($company['location_name'])) {
                        $response .= "  📍 {$company['location_name']}\n";
                    }

                    if (!empty($company['phone'])) {
                        $response .= "  📞 {$company['phone']}\n";
                    }
                    
                    if (!empty($company['total_vehicles'])) {
                        $response .= "  🚗 {$company['total_vehicles']} vehicle" . ($company['total_vehicles'] != 1 ? 's' : '') . "\n";
                    }
                    
                    if (!empty($company['daily_rate_from'])) {
                        $response .= "  💰 From " . getChatCurrencyCode($db) . " " . number_format($company['daily_rate_from']) . "/day\n";
                    }
                    
                    // Show matching vehicle details if available
                    if (!empty($company['matching_vehicles']) && count($company['matching_vehicles']) > 0) {
                        $vehicle = $company['matching_vehicles'][0];
                        $specs = [];
                        if (!empty($vehicle['seats'])) {
                            $specs[] = "{$vehicle['seats']} seats";
                        }
                        if (!empty($vehicle['fuel_type'])) {
                            $specs[] = ucfirst($vehicle['fuel_type']);
                        }
                        if (!empty($vehicle['transmission'])) {
                            $specs[] = ucfirst($vehicle['transmission']);
                        }
                        if (!empty($specs)) {
                            $response .= "  ⚙️ " . implode(" • ", $specs) . "\n";
                        }
                    }
                    
                    $response .= "\n";
                }
                
                if ($count > 3) {
                    $response .= "And " . ($count - 3) . " more. [View all]({$baseUrl}car-hire.html)";
                }
            }
        }
        
        sendSuccess([
            'response' => $response,
            'car_hire_companies' => array_slice($results['companies'], 0, 10),
            'total_results' => count($results['companies']),
            'base_url' => $baseUrl
        ]);
        
    } catch (Exception $e) {
        error_log("handleCarHireQuery error: " . $e->getMessage());
        sendError('Car hire search failed. Please try rephrasing your query.', 500);
    }
}

/**
 * Extract car hire search parameters from user message
 */
function extractCarHireSearchParams($message) {
    $params = [];
    $messageLower = strtolower($message);
    
    // Detect proximity queries (closest, nearest, near me, nearby)
    $proximityKeywords = ['closest', 'nearest', 'near me', 'nearby', 'close to me', 'near'];
    foreach ($proximityKeywords as $keyword) {
        if (strpos($messageLower, $keyword) !== false) {
            $params['proximity'] = true;
            break;
        }
    }
    
    // Extract location (but don't override proximity)
    $locations = ['blantyre', 'lilongwe', 'mzuzu', 'zomba', 'mangochi', 'salima', 'kasungu', 'mulanje'];
    foreach ($locations as $location) {
        if (strpos($messageLower, $location) !== false) {
            $params['location'] = $location;
            break;
        }
    }
    
    // Extract vehicle make/model
    $makes = ['toyota', 'honda', 'bmw', 'mercedes', 'nissan', 'ford', 'mazda', 'volkswagen', 'audi', 'hyundai', 'kia'];
    $models = ['hilux', 'prado', 'landcruiser', 'corolla', 'camry', 'ranger', 'x5', 'x3', 'c-class', 'e-class'];
    
    foreach ($makes as $make) {
        if (strpos($messageLower, $make) !== false) {
            $params['make'] = $make;
            break;
        }
    }
    
    foreach ($models as $model) {
        if (strpos($messageLower, $model) !== false) {
            $params['model'] = $model;
            break;
        }
    }
    
    // Extract vehicle type
    $vehicleTypes = ['suv', 'sedan', 'hatchback', 'pickup', 'truck', 'luxury', 'economy', 'van', 'minibus', 'bus'];
    foreach ($vehicleTypes as $type) {
        if (strpos($messageLower, $type) !== false) {
            $params['vehicle_type'] = $type;
            break;
        }
    }
    
    // Extract seats (e.g., "van with 8 seats", "7 seater")
    if (preg_match('/(\d+)\s*(?:seat|seater|passenger)/i', $message, $matches)) {
        $params['seats'] = (int)$matches[1];
    } elseif (preg_match('/(?:with|has|having)\s*(\d+)\s*(?:seat|seater)/i', $message, $matches)) {
        $params['seats'] = (int)$matches[1];
    }
    
    // Extract price range
    if (preg_match('/(?:under|below|less than|max|maximum|up to)\s*(?:mwk|kwacha)?\s*(\d+(?:[.,]\d+)?)\s*(?:million|m|k)?/i', $message, $matches)) {
        $price = floatval(str_replace([',', '.'], '', $matches[1]));
        if (strpos($messageLower, 'million') !== false || strpos($messageLower, ' m') !== false) {
            $params['max_daily_rate'] = $price * 1000000;
        } else {
            $params['max_daily_rate'] = $price;
        }
    }
    
    if (preg_match('/(?:over|above|more than|min|minimum|from)\s*(?:mwk|kwacha)?\s*(\d+(?:[.,]\d+)?)\s*(?:million|m|k)?/i', $message, $matches)) {
        $price = floatval(str_replace([',', '.'], '', $matches[1]));
        if (strpos($messageLower, 'million') !== false || strpos($messageLower, ' m') !== false) {
            $params['min_daily_rate'] = $price * 1000000;
        } else {
            $params['min_daily_rate'] = $price;
        }
    }
    
    // Extract fuel type
    $fuelTypes = ['petrol', 'diesel', 'hybrid', 'electric', 'lpg'];
    foreach ($fuelTypes as $fuel) {
        if (strpos($messageLower, $fuel) !== false) {
            $params['fuel_type'] = $fuel;
            break;
        }
    }
    
    // Extract transmission
    if (strpos($messageLower, 'automatic') !== false || strpos($messageLower, 'auto') !== false) {
        $params['transmission'] = 'automatic';
    } elseif (strpos($messageLower, 'manual') !== false) {
        $params['transmission'] = 'manual';
    }

    $priceComparison = detectPriceComparativeQuery($message);
    if (!empty($priceComparison)) {
        $params['price_comparison'] = $priceComparison;
    }
    
    return $params;
}

/**
 * Search car hire companies and their fleet based on parameters
 */
function searchCarHire($db, $searchParams, $userLocation = null) {
    $whereConditions = ["ch.status = 'active'"];
    $params = [];
    
    // Location filter - case-insensitive
    if (!empty($searchParams['location'])) {
        $locationSearch = strtolower(trim($searchParams['location']));
        $whereConditions[] = "(LOWER(loc.name) LIKE ? OR LOWER(loc.district) LIKE ? OR LOWER(loc.region) LIKE ?)";
        $locationPattern = '%' . $locationSearch . '%';
        $params[] = $locationPattern;
        $params[] = $locationPattern;
        $params[] = $locationPattern;
    } elseif (!empty($searchParams['proximity']) && $userLocation && !empty($userLocation['location_name'])) {
        // For proximity queries, prioritize user's location but don't make it mandatory
        $locationSearch = strtolower(trim($userLocation['location_name']));
        $whereConditions[] = "(LOWER(loc.name) LIKE ? OR LOWER(loc.district) LIKE ? OR LOWER(loc.region) LIKE ?)";
        $locationPattern = '%' . $locationSearch . '%';
        $params[] = $locationPattern;
        $params[] = $locationPattern;
        $params[] = $locationPattern;
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    // Determine sort order
    $sortBy = $searchParams['sort_by'] ?? 'default';
    $isProximityQuery = !empty($searchParams['proximity']);
    
    if ($sortBy === 'vehicle_count') {
        // Sort by vehicle count (for "most" queries)
        $orderBy = "vehicle_count DESC, ch.featured DESC, ch.verified DESC, ch.certified DESC";
    } else {
        // Default sort: featured, verified, certified, then name
        $orderBy = "ch.featured DESC, ch.verified DESC, ch.certified DESC, ch.business_name ASC";
    }
    
    // Get car hire companies with vehicle count and location information
    $stmt = $db->prepare("
        SELECT ch.*, loc.name as location_name, loc.region, loc.district,
               COUNT(f.id) as vehicle_count
        FROM car_hire_companies ch
        INNER JOIN locations loc ON ch.location_id = loc.id
        LEFT JOIN car_hire_fleet f ON ch.id = f.company_id AND f.is_active = 1
        WHERE {$whereClause}
        GROUP BY ch.id
        ORDER BY {$orderBy}
        LIMIT 50
    ");
    
    $stmt->execute($params);
    $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Note: Distance calculation removed as locations table doesn't have latitude/longitude columns
    
    // For each company, check if they have matching vehicles
    foreach ($companies as &$company) {
        $company['matching_vehicles'] = [];
        $company['total_vehicles'] = 0;
        
        // Search fleet for matching vehicles
        $fleetConditions = ["f.company_id = ?", "f.is_active = 1", "f.is_available = 1"];
        $fleetParams = [$company['id']];
        
        // Make filter
        if (!empty($searchParams['make'])) {
            $makeStmt = $db->prepare("SELECT id FROM car_makes WHERE LOWER(name) LIKE ? LIMIT 1");
            $makeStmt->execute(['%' . strtolower($searchParams['make']) . '%']);
            $make = $makeStmt->fetch(PDO::FETCH_ASSOC);
            if ($make) {
                $fleetConditions[] = "f.make_id = ?";
                $fleetParams[] = $make['id'];
            }
        }
        
        // Model filter
        if (!empty($searchParams['model'])) {
            $modelStmt = $db->prepare("SELECT id FROM car_models WHERE LOWER(name) LIKE ? LIMIT 1");
            $modelStmt->execute(['%' . strtolower($searchParams['model']) . '%']);
            $model = $modelStmt->fetch(PDO::FETCH_ASSOC);
            if ($model) {
                $fleetConditions[] = "f.model_id = ?";
                $fleetParams[] = $model['id'];
            }
        }
        
        // Vehicle type filter
        if (!empty($searchParams['vehicle_type'])) {
            $fleetConditions[] = "mo.body_type LIKE ?";
            $fleetParams[] = '%' . $searchParams['vehicle_type'] . '%';
        }
        
        // Seats filter
        if (!empty($searchParams['seats'])) {
            $fleetConditions[] = "f.seats = ?";
            $fleetParams[] = (int)$searchParams['seats'];
        }
        
        // Price range filters
        if (!empty($searchParams['min_daily_rate'])) {
            $fleetConditions[] = "f.daily_rate >= ?";
            $fleetParams[] = $searchParams['min_daily_rate'];
        }
        
        if (!empty($searchParams['max_daily_rate'])) {
            $fleetConditions[] = "f.daily_rate <= ?";
            $fleetParams[] = $searchParams['max_daily_rate'];
        }
        
        // Fuel type filter
        if (!empty($searchParams['fuel_type'])) {
            $fleetConditions[] = "LOWER(f.fuel_type) = ?";
            $fleetParams[] = strtolower($searchParams['fuel_type']);
        }
        
        // Transmission filter
        if (!empty($searchParams['transmission'])) {
            $fleetConditions[] = "LOWER(f.transmission) = ?";
            $fleetParams[] = strtolower($searchParams['transmission']);
        }
        
        $fleetWhereClause = implode(' AND ', $fleetConditions);
        
        $fleetStmt = $db->prepare("
            SELECT f.*, m.name as make_name, mo.name as model_name, mo.body_type, f.seats, f.fuel_type, f.transmission
            FROM car_hire_fleet f
            INNER JOIN car_makes m ON f.make_id = m.id
            INNER JOIN car_models mo ON f.model_id = mo.id
            WHERE {$fleetWhereClause}
            ORDER BY f.daily_rate ASC
            LIMIT 10
        ");
        
        $fleetStmt->execute($fleetParams);
        $company['matching_vehicles'] = $fleetStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get total vehicle count (use from query if available, otherwise fetch)
        // Note: vehicle_count is already calculated in the SQL query above
        if (isset($company['vehicle_count']) && $company['vehicle_count'] > 0) {
            $company['total_vehicles'] = (int)$company['vehicle_count'];
        } else {
            // Fallback: fetch if not in query result
            $countStmt = $db->prepare("SELECT COUNT(*) FROM car_hire_fleet WHERE company_id = ? AND is_active = 1");
            $countStmt->execute([$company['id']]);
            $company['total_vehicles'] = (int)$countStmt->fetchColumn();
        }
        
        // Calculate daily rate from
        if (!empty($company['matching_vehicles'])) {
            $rates = array_column($company['matching_vehicles'], 'daily_rate');
            $rates = array_filter($rates);
            if (!empty($rates)) {
                $company['daily_rate_from'] = min($rates);
            }
        }
    }
    
    // Filter companies to only show those with matching vehicles if vehicle filters were specified
    if (!empty($searchParams['make']) || !empty($searchParams['model']) || !empty($searchParams['vehicle_type']) || 
        !empty($searchParams['seats']) || !empty($searchParams['fuel_type']) || !empty($searchParams['transmission']) ||
        !empty($searchParams['min_daily_rate']) || !empty($searchParams['max_daily_rate'])) {
        $companies = array_filter($companies, function($company) {
            return !empty($company['matching_vehicles']);
        });
        $companies = array_values($companies); // Re-index array
    }

    // Apply price-comparison ordering after calculating daily_rate_from values.
    if (!empty($searchParams['price_comparison'])) {
        $comparison = (string)$searchParams['price_comparison'];
        usort($companies, function($a, $b) use ($comparison) {
            $aRate = isset($a['daily_rate_from']) ? (float)$a['daily_rate_from'] : INF;
            $bRate = isset($b['daily_rate_from']) ? (float)$b['daily_rate_from'] : INF;

            if ($comparison === 'most_expensive') {
                return $bRate <=> $aRate;
            }

            // Default to cheapest and best_value behaving like ascending price.
            return $aRate <=> $bRate;
        });
    }
    
    return ['companies' => $companies];
}

/**
 * Detect and handle user data queries (inventory, listings, fleet, garage, business details)
 * Returns result if query was detected and handled, false otherwise
 */
function detectAndHandleUserDataQuery($db, $message, $user, $userContext) {
    $messageLower = strtolower($message);
    $userId = $user['id'];
    $userType = $user['type'] ?? 'user';
    $businessType = $userContext['business_type'] ?? null;
    
    // Get base URL
    $serverHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
    $isLocalhost = in_array($serverHost, ['localhost', '127.0.0.1']) || 
                   strpos($serverHost, 'localhost:') === 0 || 
                   strpos($serverHost, '127.0.0.1:') === 0 ||
                   preg_match('/^(192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $serverHost);
    $isProduction = !$isLocalhost && !empty($serverHost);
    
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $baseUrl = $protocol . '://' . $serverHost . '/';
    
    // Detect inventory/listings queries
    if (preg_match('/(?:what|show|list|view|display|see|tell me about).*(?:my|our).*(?:inventory|listings|cars|vehicles|stock)/i', $message)) {
        return handleUserListingsQuery($db, $userId, $baseUrl);
    }
    
    // Detect fleet queries (for car hire companies)
    if ($businessType === 'car_hire' && preg_match('/(?:what|show|list|view|display|see|tell me about).*(?:my|our).*(?:fleet|vehicles|cars|rental.*vehicles)/i', $message)) {
        return handleUserFleetQuery($db, $userId, $baseUrl);
    }
    
    // Detect garage queries (for garage owners)
    if ($businessType === 'garage' && preg_match('/(?:what|show|tell me about|view|see).*(?:my|our).*(?:garage|workshop|business)/i', $message)) {
        return handleUserGarageQuery($db, $userId, $baseUrl);
    }
    
    // Detect business details queries
    if (preg_match('/(?:what|show|tell me about|view|see|change|update|modify).*(?:my|our).*(?:business|company|dealer|showroom|details|information|profile)/i', $message)) {
        return handleUserBusinessQuery($db, $userId, $userType, $businessType, $baseUrl, $messageLower);
    }
    
    // Detect dashboard queries
    if (preg_match('/(?:go to|open|show|view|access).*(?:dashboard|my.*dashboard)/i', $message)) {
        $dashboardUrl = getDashboardUrl($userType, $baseUrl);
        return [
            'response' => "I can help you access your dashboard. [Open your dashboard]({$dashboardUrl}) to manage your account, listings, and business information.",
            'dashboard_url' => $dashboardUrl
        ];
    }
    
    // Detect user vehicle queries (my car, my vehicle, information about my car)
    $userVehicles = $userContext['vehicles'] ?? [];
    if (!empty($userVehicles) && preg_match('/(?:what|tell me|show|give me|information).*(?:about|on).*(?:my|the).*(?:car|vehicle|auto|auto mobile)/i', $messageLower)) {
        return handleUserVehicleQuery($db, $userId, $userVehicles, $baseUrl, $messageLower);
    }
    
    return false;
}

/**
 * Handle user personal vehicle query from user_vehicles context.
 */
function handleUserVehicleQuery($db, $userId, $userVehicles, $baseUrl, $messageLower) {
    if (empty($userVehicles)) {
        return [
            'response' => "I couldn't find any saved vehicles on your account yet. [Add a vehicle in your profile]({$baseUrl}profile.html).",
            'vehicles' => [],
            'total' => 0
        ];
    }

    $selectedVehicle = $userVehicles[0];
    foreach ($userVehicles as $vehicle) {
        $make = strtolower((string)($vehicle['make'] ?? ''));
        $model = strtolower((string)($vehicle['model'] ?? ''));
        $year = (string)($vehicle['year'] ?? '');
        if (($make && strpos($messageLower, $make) !== false) ||
            ($model && strpos($messageLower, $model) !== false) ||
            ($year && strpos($messageLower, $year) !== false)) {
            $selectedVehicle = $vehicle;
            break;
        }
    }

    $isSpecificSpecQuery = preg_match('/fuel|consumption|tank|range|engine|transmission|body|vin/i', $messageLower);
    if ($isSpecificSpecQuery) {
        $bits = [];
        $title = trim(($selectedVehicle['year'] ?? '') . ' ' . ($selectedVehicle['make'] ?? '') . ' ' . ($selectedVehicle['model'] ?? ''));
        if (!empty($selectedVehicle['engine_size_liters'])) {
            $bits[] = "Engine: {$selectedVehicle['engine_size_liters']}L";
        }
        if (!empty($selectedVehicle['fuel_type'])) {
            $bits[] = "Fuel: {$selectedVehicle['fuel_type']}";
        }
        if (!empty($selectedVehicle['fuel_consumption_liters_per_100km'])) {
            $bits[] = "Consumption: {$selectedVehicle['fuel_consumption_liters_per_100km']} L/100km";
        }
        if (!empty($selectedVehicle['fuel_tank_capacity_liters'])) {
            $bits[] = "Tank: {$selectedVehicle['fuel_tank_capacity_liters']}L";
        }
        if (!empty($selectedVehicle['transmission'])) {
            $bits[] = "Transmission: {$selectedVehicle['transmission']}";
        }
        if (!empty($selectedVehicle['body_type'])) {
            $bits[] = "Body: {$selectedVehicle['body_type']}";
        }
        if (!empty($selectedVehicle['vin'])) {
            $bits[] = "VIN: {$selectedVehicle['vin']}";
        }

        if (empty($bits)) {
            return [
                'response' => "I found your vehicle ({$title}), but there are no detailed specs saved yet. [Update your profile]({$baseUrl}profile.html) to add specs.",
                'vehicle' => $selectedVehicle
            ];
        }

        return [
            'response' => "Here are the saved details for your {$title}:\n\n• " . implode("\n• ", $bits),
            'vehicle' => $selectedVehicle
        ];
    }

    if (count($userVehicles) === 1) {
        $v = $userVehicles[0];
        $title = trim(($v['year'] ?? '') . ' ' . ($v['make'] ?? '') . ' ' . ($v['model'] ?? ''));
        return [
            'response' => "You currently have one saved vehicle: **{$title}**. Ask me for specs like fuel use, tank size, transmission, or VIN.",
            'vehicle' => $v,
            'total' => 1
        ];
    }

    $response = "You have **" . count($userVehicles) . " saved vehicles**:\n\n";
    foreach (array_slice($userVehicles, 0, 5) as $vehicle) {
        $title = trim(($vehicle['year'] ?? '') . ' ' . ($vehicle['make'] ?? '') . ' ' . ($vehicle['model'] ?? ''));
        $response .= "• {$title}\n";
    }
    if (count($userVehicles) > 5) {
        $response .= "\n...and " . (count($userVehicles) - 5) . " more.";
    }
    $response .= "\n\nTell me which one you want details about (for example: 'tell me about my Toyota Hilux').";

    return [
        'response' => $response,
        'vehicles' => $userVehicles,
        'total' => count($userVehicles)
    ];
}

/**
 * Handle user listings/inventory query
 */
function handleUserListingsQuery($db, $userId, $baseUrl) {
    try {
        $stmt = $db->prepare("
            SELECT l.id, l.year, l.price, l.status, l.approval_status, 
                   m.name as make_name, mo.name as model_name,
                   loc.name as location_name
            FROM car_listings l
            INNER JOIN car_makes m ON l.make_id = m.id
            INNER JOIN car_models mo ON l.model_id = mo.id
            LEFT JOIN locations loc ON l.location_id = loc.id
            WHERE l.user_id = ?
            ORDER BY l.created_at DESC
            LIMIT 20
        ");
        $stmt->execute([$userId]);
        $listings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($listings)) {
            return [
                'response' => "You don't have any listings yet. Would you like help creating one? [Create a listing]({$baseUrl}sell.html)",
                'listings' => [],
                'total' => 0
            ];
        }
        
        $total = count($listings);
        $active = count(array_filter($listings, fn($l) => $l['status'] === 'active'));
        $pending = count(array_filter($listings, fn($l) => $l['approval_status'] === 'pending'));
        $approved = count(array_filter($listings, fn($l) => $l['approval_status'] === 'approved'));
        
        $response = "You have **{$total} listing" . ($total > 1 ? 's' : '') . "** in your inventory:\n\n";
        $response .= "• **{$active}** active\n";
        $response .= "• **{$approved}** approved\n";
        if ($pending > 0) {
            $response .= "• **{$pending}** pending approval\n";
        }
        $response .= "\n**Your listings:**\n\n";
        
        foreach (array_slice($listings, 0, 10) as $listing) {
            $listingUrl = $baseUrl . "car.html?id=" . $listing['id'];
            $price = number_format($listing['price']);
            $status = $listing['status'] === 'active' ? '✅' : '⏸️';
            $approval = $listing['approval_status'] === 'approved' ? '' : ($listing['approval_status'] === 'pending' ? ' ⏳ Pending' : ' ❌ Rejected');
            
            $response .= "• {$status} [{$listing['make_name']} {$listing['model_name']} ({$listing['year']})]({$listingUrl}) - " . getChatCurrencyCode($db) . " {$price} - **Reference #{$listing['id']}**{$approval}\n";
        }
        
        if ($total > 10) {
            $response .= "\nAnd " . ($total - 10) . " more. ";
        }
        
        $response .= "[View all listings]({$baseUrl}my-listings.html) | [Manage listings]({$baseUrl}my-listings.html)";
        
        return [
            'response' => $response,
            'listings' => $listings,
            'total' => $total,
            'stats' => [
                'active' => $active,
                'approved' => $approved,
                'pending' => $pending
            ]
        ];
    } catch (Exception $e) {
        error_log("handleUserListingsQuery error: " . $e->getMessage());
        return [
            'response' => "I encountered an error retrieving your listings. Please try again or [view your listings directly]({$baseUrl}my-listings.html)."
        ];
    }
}

/**
 * Handle user fleet query (for car hire companies)
 */
function handleUserFleetQuery($db, $userId, $baseUrl) {
    try {
        // Get car hire company ID
        $stmt = $db->prepare("SELECT id FROM car_hire_companies WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$company) {
            return [
                'response' => "You don't have a car hire company registered. Please set up your car hire business first."
            ];
        }
        
        $companyId = $company['id'];
        
        // Get fleet
        $stmt = $db->prepare("
            SELECT f.id, f.year, f.daily_rate, f.is_available, f.is_active,
                   m.name as make_name, mo.name as model_name
            FROM car_hire_fleet f
            INNER JOIN car_makes m ON f.make_id = m.id
            INNER JOIN car_models mo ON f.model_id = mo.id
            WHERE f.company_id = ?
            ORDER BY f.is_available DESC, f.daily_rate ASC
        ");
        $stmt->execute([$companyId]);
        $fleet = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($fleet)) {
            return [
                'response' => "Your fleet is empty. [Add vehicles to your fleet]({$baseUrl}car-hire-dashboard.html#fleet) to start renting them out."
            ];
        }
        
        $total = count($fleet);
        $available = count(array_filter($fleet, fn($v) => $v['is_available'] == 1 && $v['is_active'] == 1));
        $unavailable = $total - $available;
        
        $response = "You have **{$total} vehicle" . ($total > 1 ? 's' : '') . "** in your fleet:\n\n";
        $response .= "• **{$available}** available for rent\n";
        if ($unavailable > 0) {
            $response .= "• **{$unavailable}** currently unavailable\n";
        }
        $response .= "\n**Your fleet:**\n\n";
        
        foreach (array_slice($fleet, 0, 10) as $vehicle) {
            $status = ($vehicle['is_available'] == 1 && $vehicle['is_active'] == 1) ? '✅ Available' : '⏸️ Unavailable';
            $rate = $vehicle['daily_rate'] ? getChatCurrencyCode($db) . ' ' . number_format($vehicle['daily_rate']) . '/day' : 'Rate not set';
            
            $response .= "• {$status} - {$vehicle['make_name']} {$vehicle['model_name']}";
            if ($vehicle['year']) {
                $response .= " ({$vehicle['year']})";
            }
            $response .= " - {$rate}\n";
        }
        
        if ($total > 10) {
            $response .= "\nAnd " . ($total - 10) . " more. ";
        }
        
        $response .= "[Manage your fleet]({$baseUrl}car-hire-dashboard.html#fleet)";
        
        return [
            'response' => $response,
            'fleet' => $fleet,
            'total' => $total,
            'available' => $available,
            'unavailable' => $unavailable
        ];
    } catch (Exception $e) {
        error_log("handleUserFleetQuery error: " . $e->getMessage());
        return [
            'response' => "I encountered an error retrieving your fleet. Please try again or [manage your fleet directly]({$baseUrl}car-hire-dashboard.html#fleet)."
        ];
    }
}

/**
 * Handle user garage query (for garage owners)
 */
function handleUserGarageQuery($db, $userId, $baseUrl) {
    try {
        $stmt = $db->prepare("
            SELECT g.*, loc.name as location_name
            FROM garages g
            LEFT JOIN locations loc ON g.location_id = loc.id
            WHERE g.user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $garage = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$garage) {
            return [
                'response' => "You don't have a garage registered. Please set up your garage business first."
            ];
        }
        
        $response = "**Your Garage Information:**\n\n";
        $response .= "**Name:** {$garage['name']}\n";
        
        if (!empty($garage['location_name'])) {
            $response .= "**Location:** {$garage['location_name']}\n";
        }
        
        if (!empty($garage['phone'])) {
            $response .= "**Phone:** {$garage['phone']}\n";
        }
        
        if (!empty($garage['email'])) {
            $response .= "**Email:** {$garage['email']}\n";
        }
        
        if (!empty($garage['services'])) {
            $services = is_string($garage['services']) ? json_decode($garage['services'], true) : $garage['services'];
            if (is_array($services) && !empty($services)) {
                $response .= "**Services:** " . implode(', ', array_slice($services, 0, 5));
                if (count($services) > 5) {
                    $response .= " and " . (count($services) - 5) . " more";
                }
                $response .= "\n";
            }
        }
        
        if (!empty($garage['operating_hours'])) {
            $response .= "**Operating Hours:** {$garage['operating_hours']}\n";
        }
        
        $response .= "\n[Manage your garage]({$baseUrl}garage-dashboard.html)";
        
        return [
            'response' => $response,
            'garage' => $garage
        ];
    } catch (Exception $e) {
        error_log("handleUserGarageQuery error: " . $e->getMessage());
        return [
            'response' => "I encountered an error retrieving your garage information. Please try again or [view your garage dashboard directly]({$baseUrl}garage-dashboard.html)."
        ];
    }
}

/**
 * Handle user business query (dealer/showroom/car hire business details)
 */
function handleUserBusinessQuery($db, $userId, $userType, $businessType, $baseUrl, $messageLower) {
    try {
        // Check if user wants to change/update business details
        $isUpdateQuery = preg_match('/(?:change|update|modify|edit|set).*(?:business|company|dealer|showroom|details|information|phone|email|location|address)/i', $messageLower);
        
        if ($isUpdateQuery) {
            // Extract what they want to update
            $updateField = null;
            $updateValue = null;
            
            // Phone update
            if (preg_match('/(?:phone|contact|number).*(?:to|is|as)\s*([\d\s\+\-\(\)]+)/i', $messageLower, $matches)) {
                $updateField = 'phone';
                $updateValue = preg_replace('/[^\d\+]/', '', $matches[1]);
            }
            
            // Email update
            if (preg_match('/(?:email|e-mail).*(?:to|is|as)\s*([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/i', $messageLower, $matches)) {
                $updateField = 'email';
                $updateValue = $matches[1];
            }
            
            if ($updateField && $updateValue) {
                return handleBusinessFieldUpdate($db, $userId, $userType, $businessType, $updateField, $updateValue, $baseUrl);
            }
            $dashboardUrl = getDashboardUrl($userType, $baseUrl);
            return [
                'response' => "I can help you update your business details. [Go to your dashboard]({$dashboardUrl}) to edit your business information, contact details, and other settings.",
                'dashboard_url' => $dashboardUrl,
                'action' => 'update_business'
            ];
        }
        
        // Get business information
        if ($businessType === 'dealer' || $userType === 'dealer') {
            $stmt = $db->prepare("
                SELECT d.*, loc.name as location_name
                FROM car_dealers d
                LEFT JOIN locations loc ON d.location_id = loc.id
                WHERE d.user_id = ?
                LIMIT 1
            ");
            $stmt->execute([$userId]);
            $business = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$business) {
                return [
                    'response' => "You don't have a dealer/showroom registered. Please set up your business first."
                ];
            }
            
            $response = "**Your Dealer/Showroom Information:**\n\n";
            $response .= "**Business Name:** {$business['business_name']}\n";
            
            if (!empty($business['location_name'])) {
                $response .= "**Location:** {$business['location_name']}\n";
            }
            
            if (!empty($business['phone'])) {
                $response .= "**Phone:** {$business['phone']}\n";
            }
            
            if (!empty($business['email'])) {
                $response .= "**Email:** {$business['email']}\n";
            }
            
            
            $response .= "\n[Manage your business details]({$baseUrl}dealer-dashboard.html#business-info)";
            
            return [
                'response' => $response,
                'business' => $business
            ];
            
        } elseif ($businessType === 'car_hire' || $userType === 'car_hire') {
            $stmt = $db->prepare("
                SELECT ch.*, loc.name as location_name
                FROM car_hire_companies ch
                LEFT JOIN locations loc ON ch.location_id = loc.id
                WHERE ch.user_id = ?
                LIMIT 1
            ");
            $stmt->execute([$userId]);
            $business = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$business) {
                return [
                    'response' => "You don't have a car hire company registered. Please set up your business first."
                ];
            }
            
            $response = "**Your Car Hire Company Information:**\n\n";
            $response .= "**Business Name:** {$business['business_name']}\n";
            
            if (!empty($business['location_name'])) {
                $response .= "**Location:** {$business['location_name']}\n";
            }
            
            if (!empty($business['phone'])) {
                $response .= "**Phone:** {$business['phone']}\n";
            }
            
            if (!empty($business['email'])) {
                $response .= "**Email:** {$business['email']}\n";
            }
            
            $response .= "\n[Manage your business details]({$baseUrl}car-hire-dashboard.html#company-info)";
            
            return [
                'response' => $response,
                'business' => $business
            ];
        } else {
            return [
                'response' => "You don't have a business account. If you're a dealer, garage owner, or car hire company, please register your business first."
            ];
        }
    } catch (Exception $e) {
        error_log("handleUserBusinessQuery error: " . $e->getMessage());
        $dashboardUrl = getDashboardUrl($userType, $baseUrl);
        return [
            'response' => "I encountered an error retrieving your business information. Please try again or [view your dashboard directly]({$dashboardUrl})."
        ];
    }
}

/**
 * Get dashboard URL based on user type
 */
function getDashboardUrl($userType, $baseUrl) {
    switch ($userType) {
        case 'dealer':
            return $baseUrl . 'dealer-dashboard.html';
        case 'garage':
            return $baseUrl . 'garage-dashboard.html';
        case 'car_hire':
            return $baseUrl . 'car-hire-dashboard.html';
        default:
            return $baseUrl . 'profile.html';
    }
}

/**
 * Identify listing from user message using make/model/year or ID
 * Enhanced with fuzzy matching and confidence scoring
 */
function identifyListingFromMessage($db, $userId, $message) {
    // Try to extract listing ID first (most precise) - handles "listing #123", "#123", "reference 123", "ref 123", etc.
    if (preg_match('/(?:#|number|id|listing|listing\s*#|reference|ref)\s*(\d+)/i', $message, $matches)) {
        $listingId = (int)$matches[1];
        $stmt = $db->prepare("
            SELECT l.*, m.name as make_name, mo.name as model_name
            FROM car_listings l
            INNER JOIN car_makes m ON l.make_id = m.id
            INNER JOIN car_models mo ON l.model_id = mo.id
            WHERE l.id = ? AND l.user_id = ?
        ");
        $stmt->execute([$listingId, $userId]);
        $listing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($listing) {
            $serverHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
            $isLocalhost = in_array($serverHost, ['localhost', '127.0.0.1']) || 
                           strpos($serverHost, 'localhost:') === 0 || 
                           strpos($serverHost, '127.0.0.1:') === 0 ||
                           preg_match('/^(192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $serverHost);
            $isProduction = !$isLocalhost && !empty($serverHost);
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $baseUrl = $protocol . '://' . $serverHost . '/';
            $listing['url'] = $baseUrl . "car.html?id=" . $listing['id'];
            return $listing;
        }
    }
    
    // Get all makes and models from database for better matching
    $stmt = $db->query("SELECT id, LOWER(name) as name_lower FROM car_makes");
    $allMakes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $db->query("SELECT id, make_id, LOWER(name) as name_lower FROM car_models");
    $allModels = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $messageLower = strtolower(trim($message));
    $messageWords = preg_split('/\s+/', $messageLower);
    
    $makeId = null;
    $modelId = null;
    $year = null;
    $confidence = 0;
    
    // Extract make with fuzzy matching
    foreach ($allMakes as $make) {
        $makeName = $make['name_lower'];
        // Exact match
        if (strpos($messageLower, $makeName) !== false) {
            $makeId = $make['id'];
            $confidence += 3;
            break;
        }
        // Partial match (for compound names like "land rover")
        foreach ($messageWords as $word) {
            if (strlen($word) > 3 && strpos($makeName, $word) !== false) {
                $makeId = $make['id'];
                $confidence += 2;
                break;
            }
        }
        if ($makeId) break;
    }
    
    // Extract model with fuzzy matching (prefer models from identified make)
    $candidateModels = $makeId 
        ? array_filter($allModels, fn($m) => $m['make_id'] == $makeId)
        : $allModels;
    
    foreach ($candidateModels as $model) {
        $modelName = $model['name_lower'];
        // Exact match
        if (strpos($messageLower, $modelName) !== false) {
            $modelId = $model['id'];
            $confidence += 3;
            break;
        }
        // Partial match
        foreach ($messageWords as $word) {
            if (strlen($word) > 3 && strpos($modelName, $word) !== false) {
                $modelId = $model['id'];
                $confidence += 2;
                break;
            }
        }
        if ($modelId) break;
    }
    
    // Extract year with better pattern matching
    if (preg_match('/\b(19|20)\d{2}\b/', $message, $yearMatches)) {
        $year = (int)$yearMatches[0];
        if ($year >= 1950 && $year <= date('Y') + 1) {
            $confidence += 2;
        }
    }
    
    // Extract price as additional identifier
    $price = null;
    if (preg_match('/(?:price|cost|priced|worth).*(?:mwk|kwacha)?\s*(\d+(?:[.,]\d+)?)\s*(?:million|m|k)?/i', $message, $priceMatches)) {
        $price = floatval(str_replace([',', '.'], '', $priceMatches[1]));
        if (strpos($messageLower, 'million') !== false || strpos($messageLower, ' m') !== false) {
            $price = $price * 1000000;
        }
        $confidence += 1;
    }
    
    // Build query with confidence threshold
    if ($confidence >= 2) {
        $where = ["l.user_id = ?"];
        $params = [$userId];
        
        if ($makeId) {
            $where[] = "l.make_id = ?";
            $params[] = $makeId;
        }
        if ($modelId) {
            $where[] = "l.model_id = ?";
            $params[] = $modelId;
        }
        if ($year) {
            $where[] = "l.year = ?";
            $params[] = $year;
        }
        if ($price) {
            // Match price within 10% range
            $where[] = "l.price BETWEEN ? AND ?";
            $params[] = $price * 0.9;
            $params[] = $price * 1.1;
        }
        
        if (count($where) > 1) {
            $stmt = $db->prepare("
                SELECT l.*, m.name as make_name, mo.name as model_name,
                       ABS(l.year - ?) as year_diff,
                       ABS(l.price - ?) as price_diff
                FROM car_listings l
                INNER JOIN car_makes m ON l.make_id = m.id
                INNER JOIN car_models mo ON l.model_id = mo.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY 
                    CASE WHEN l.make_id = ? THEN 0 ELSE 1 END,
                    CASE WHEN l.model_id = ? THEN 0 ELSE 1 END,
                    year_diff ASC,
                    price_diff ASC,
                    l.created_at DESC
                LIMIT 1
            ");
            $orderParams = [$year ?: 2000, $price ?: 0, $makeId ?: 0, $modelId ?: 0];
            $stmt->execute(array_merge($params, $orderParams));
            $listing = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($listing) {
                $serverHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
                $isLocalhost = in_array($serverHost, ['localhost', '127.0.0.1']) || 
                               strpos($serverHost, 'localhost:') === 0 || 
                               strpos($serverHost, '127.0.0.1:') === 0 ||
                               preg_match('/^(192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $serverHost);
                $isProduction = !$isLocalhost && !empty($serverHost);
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $baseUrl = $protocol . '://' . $serverHost . '/';
                $listing['url'] = $baseUrl . "car.html?id=" . $listing['id'];
                return $listing;
            }
        }
    }
    
    return null;
}

/**
 * Get user listings for selection
 */
function getUserListingsForSelection($db, $userId) {
    $stmt = $db->prepare("
        SELECT l.id, l.year, l.price, m.name as make_name, mo.name as model_name
        FROM car_listings l
        INNER JOIN car_makes m ON l.make_id = m.id
        INNER JOIN car_models mo ON l.model_id = mo.id
        WHERE l.user_id = ? AND l.status = 'active'
        ORDER BY l.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$userId]);
    $listings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $serverHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
    $isLocalhost = in_array($serverHost, ['localhost', '127.0.0.1']) || 
                   strpos($serverHost, 'localhost:') === 0 || 
                   strpos($serverHost, '127.0.0.1:') === 0 ||
                   preg_match('/^(192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $serverHost);
    $isProduction = !$isLocalhost && !empty($serverHost);
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $baseUrl = $protocol . '://' . $serverHost . '/';
    
    foreach ($listings as &$listing) {
        $listing['url'] = $baseUrl . "car.html?id=" . $listing['id'];
    }
    
    return $listings;
}

/**
 * Handle analytics and insights query
 */
function handleAnalyticsQuery($db, $userId, $userContext) {
    try {
        // Get comprehensive stats
        $stats = [];
        
        // Listings stats
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN approval_status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN approval_status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'sold' THEN 1 ELSE 0 END) as sold,
                AVG(price) as avg_price,
                MIN(price) as min_price,
                MAX(price) as max_price
            FROM car_listings
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        $stats['listings'] = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Views/engagement (check if table exists)
        try {
            $stmt = $db->prepare("
                SELECT COUNT(*) as total_views
                FROM car_listing_views
                WHERE listing_id IN (SELECT id FROM car_listings WHERE user_id = ?)
            ");
            $stmt->execute([$userId]);
            $views = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['views'] = $views['total_views'] ?? 0;
        } catch (Exception $e) {
            // Table might not exist, skip views
            $stats['views'] = 0;
        }
        
        // Recent activity
        $stmt = $db->prepare("
            SELECT COUNT(*) as recent_listings
            FROM car_listings
            WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $stmt->execute([$userId]);
        $recent = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['recent'] = $recent['recent_listings'] ?? 0;
        
        // Build response
        $response = "📊 **Your Analytics & Insights:**\n\n";
        $response .= "**Listings Overview:**\n";
        $response .= "• Total: **{$stats['listings']['total']}** listings\n";
        $response .= "• Active: **{$stats['listings']['active']}**\n";
        $response .= "• Approved: **{$stats['listings']['approved']}**\n";
        if ($stats['listings']['pending'] > 0) {
            $response .= "• Pending: **{$stats['listings']['pending']}** ⏳\n";
        }
        if ($stats['listings']['sold'] > 0) {
            $response .= "• Sold: **{$stats['listings']['sold']}** ✅\n";
        }
        
        if ($stats['listings']['avg_price']) {
            $response .= "\n**Pricing Insights:**\n";
            $response .= "• Average price: **" . getChatCurrencyCode($db) . " " . number_format($stats['listings']['avg_price']) . "**\n";
            $response .= "• Price range: **" . getChatCurrencyCode($db) . " " . number_format($stats['listings']['min_price']) . "** - **" . getChatCurrencyCode($db) . " " . number_format($stats['listings']['max_price']) . "**\n";
        }
        
        if ($stats['views'] > 0) {
            $response .= "\n**Engagement:**\n";
            $response .= "• Total views: **{$stats['views']}** 👁️\n";
        }
        
        if ($stats['recent'] > 0) {
            $response .= "\n**Recent Activity:**\n";
            $response .= "• New listings (last 30 days): **{$stats['recent']}** 📈\n";
        }
        
        // Add suggestions
        if ($stats['listings']['pending'] > 0) {
            $response .= "\n💡 **Suggestion:** You have {$stats['listings']['pending']} listing(s) pending approval. Make sure all information is complete!";
        }
        
        if ($stats['listings']['active'] > 0 && $stats['views'] == 0) {
            $response .= "\n💡 **Suggestion:** Consider adding more photos or improving your listing descriptions to increase visibility.";
        }
        
        return [
            'response' => $response,
            'action_detected' => 'analytics',
            'stats' => $stats
        ];
    } catch (Exception $e) {
        error_log("Analytics query error: " . $e->getMessage());
        return false;
    }
}

/**
 * Handle price suggestion based on market data
 */
function handlePriceSuggestion($db, $listing) {
    try {
        // Get similar listings from database
        $stmt = $db->prepare("
            SELECT AVG(price) as avg_price, MIN(price) as min_price, MAX(price) as max_price, COUNT(*) as count
            FROM car_listings
            WHERE make_id = ? AND model_id = ? AND year BETWEEN ? AND ? 
                  AND status = 'active' AND approval_status = 'approved'
                  AND id != ?
        ");
        $yearRange = [$listing['year'] - 2, $listing['year'] + 2];
        $stmt->execute([$listing['make_id'], $listing['model_id'], $yearRange[0], $yearRange[1], $listing['id']]);
        $marketData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($marketData && $marketData['count'] > 0) {
            $currentPrice = $listing['price'] ?? 0;
            $marketAvg = $marketData['avg_price'];
            $suggestion = '';
            
            if ($currentPrice > $marketAvg * 1.15) {
                $cc = getChatCurrencyCode($db);
                $suggestion = "Your price is **" . round((($currentPrice - $marketAvg) / $marketAvg) * 100) . "% higher** than the market average. Consider reducing to **{$cc} " . number_format($marketAvg) . "** for faster sale.";
            } elseif ($currentPrice < $marketAvg * 0.85) {
                $cc = getChatCurrencyCode($db);
                $suggestion = "Your price is **" . round((($marketAvg - $currentPrice) / $marketAvg) * 100) . "% lower** than the market average. You might be able to increase it to **{$cc} " . number_format($marketAvg) . "**.";
            } else {
                $cc = getChatCurrencyCode($db);
                $suggestion = "Your price is competitive! Market average is **{$cc} " . number_format($marketAvg) . "**.";
            }
            
            $cc = getChatCurrencyCode($db);
            $response = "\ud83d\udcb0 **Price Analysis for {$listing['make_name']} {$listing['model_name']} ({$listing['year']}):**\n\n";
            $response .= "\u2022 Your price: **{$cc} " . number_format($currentPrice) . "**\n";
            $response .= "\u2022 Market average: **{$cc} " . number_format($marketAvg) . "**\n";
            $response .= "\u2022 Market range: **{$cc} " . number_format($marketData['min_price']) . "** - **{$cc} " . number_format($marketData['max_price']) . "**\n";
            $response .= "• Based on **{$marketData['count']}** similar listings\n\n";
            $response .= $suggestion;
            
            return [
                'response' => $response,
                'action_detected' => 'price_suggestion',
                'market_data' => $marketData,
                'current_price' => $currentPrice
            ];
        } else {
            return [
                'response' => "I couldn't find enough similar listings in the market to provide a price suggestion. Your current price is **" . getChatCurrencyCode($db) . " " . number_format($listing['price']) . "**.",
                'action_detected' => 'price_suggestion',
                'insufficient_data' => true
            ];
        }
    } catch (Exception $e) {
        error_log("Price suggestion error: " . $e->getMessage());
        return false;
    }
}

/**
 * Handle proactive suggestions based on user context
 */
function handleProactiveSuggestions($db, $userId, $userContext) {
    try {
        $suggestions = [];
        
        // Check for pending listings
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM car_listings WHERE user_id = ? AND approval_status = 'pending'");
        $stmt->execute([$userId]);
        $pending = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($pending['count'] > 0) {
            $suggestions[] = "You have **{$pending['count']}** listing(s) pending approval. Make sure all photos and details are complete!";
        }
        
        // Check for old listings (no views or old)
        $stmt = $db->prepare("
            SELECT COUNT(*) as count
            FROM car_listings
            WHERE user_id = ? AND status = 'active' 
                  AND created_at < DATE_SUB(NOW(), INTERVAL 60 DAY)
        ");
        $stmt->execute([$userId]);
        $old = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($old['count'] > 0) {
            $suggestions[] = "You have **{$old['count']}** listing(s) older than 60 days. Consider updating photos or reducing prices to attract more buyers.";
        }
        
        // Check for listings without photos
        $stmt = $db->prepare("
            SELECT COUNT(*) as count
            FROM car_listings l
            LEFT JOIN car_listing_images i ON l.id = i.listing_id
            WHERE l.user_id = ? AND l.status = 'active' AND i.id IS NULL
        ");
        $stmt->execute([$userId]);
        $noPhotos = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($noPhotos['count'] > 0) {
            $suggestions[] = "You have **{$noPhotos['count']}** listing(s) without photos. Adding photos can increase views by up to 10x!";
        }
        
        // Check for high-priced listings
        $stmt = $db->prepare("
            SELECT COUNT(*) as count, AVG(price) as avg_price
            FROM car_listings
            WHERE user_id = ? AND status = 'active'
        ");
        $stmt->execute([$userId]);
        $priceData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($priceData['count'] > 0) {
            $stmt2 = $db->prepare("
                SELECT AVG(price) as market_avg
                FROM car_listings
                WHERE status = 'active' AND approval_status = 'approved'
            ");
            $stmt2->execute();
            $marketAvg = $stmt2->fetch(PDO::FETCH_ASSOC);
            
            if ($marketAvg['market_avg'] && $priceData['avg_price'] > $marketAvg['market_avg'] * 1.2) {
                $suggestions[] = "Your average listing price is higher than market average. Consider competitive pricing for faster sales.";
            }
        }
        
        if (empty($suggestions)) {
            return [
                'response' => "🎉 Great job! Your listings look good. Keep adding quality photos and detailed descriptions to maximize visibility.",
                'action_detected' => 'suggestions',
                'suggestions' => []
            ];
        }
        
        $response = "💡 **Personalized Suggestions for You:**\n\n";
        foreach ($suggestions as $i => $suggestion) {
            $response .= ($i + 1) . ". " . $suggestion . "\n\n";
        }
        
        return [
            'response' => $response,
            'action_detected' => 'suggestions',
            'suggestions' => $suggestions
        ];
    } catch (Exception $e) {
        error_log("Proactive suggestions error: " . $e->getMessage());
        return false;
    }
}

/**
 * Handle bulk price update (percentage or fixed amount)
 */
function handleBulkPriceUpdate($db, $userId, $value, $isPercentage) {
    try {
        // Get all active listings
        $stmt = $db->prepare("
            SELECT id, price, m.name as make_name, mo.name as model_name, year
            FROM car_listings l
            INNER JOIN car_makes m ON l.make_id = m.id
            INNER JOIN car_models mo ON l.model_id = mo.id
            WHERE user_id = ? AND status = 'active'
        ");
        $stmt->execute([$userId]);
        $listings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($listings)) {
            return [
                'response' => "You don't have any active listings to update.",
                'action_detected' => 'bulk_price_update',
                'success' => false
            ];
        }
        
        $updated = 0;
        $updates = [];
        
        foreach ($listings as $listing) {
            $oldPrice = $listing['price'];
            $newPrice = $isPercentage 
                ? $oldPrice * (1 + ($value / 100))
                : ($oldPrice + $value);
            
            // Ensure price doesn't go negative
            if ($newPrice < 0) {
                $newPrice = 0;
            }
            
            $updateStmt = $db->prepare("UPDATE car_listings SET price = ?, updated_at = NOW() WHERE id = ?");
            $updateStmt->execute([$newPrice, $listing['id']]);
            $updated++;
            
            $updates[] = [
                'listing' => "{$listing['make_name']} {$listing['model_name']} ({$listing['year']})",
                'old_price' => $oldPrice,
                'new_price' => $newPrice
            ];
        }
        
        $cc = getChatCurrencyCode($db);
        $changeType = $isPercentage ? ($value > 0 ? "increased by {$value}%" : "decreased by " . abs($value) . "%") : ($value > 0 ? "increased by {$cc} " . number_format($value) : "decreased by {$cc} " . number_format(abs($value)));
        
        $response = "✅ **Bulk Price Update Complete!**\n\n";
        $response .= "Updated **{$updated}** listing(s) - prices {$changeType}:\n\n";
        
        foreach (array_slice($updates, 0, 5) as $update) {
            $response .= "• {$update['listing']}: " . getChatCurrencyCode($db) . " " . number_format($update['old_price']) . " → " . getChatCurrencyCode($db) . " " . number_format($update['new_price']) . "\n";
        }
        
        if (count($updates) > 5) {
            $response .= "\nAnd " . (count($updates) - 5) . " more...";
        }
        
        return [
            'response' => $response,
            'action_detected' => 'bulk_price_update',
            'success' => true,
            'updated_count' => $updated,
            'updates' => $updates
        ];
    } catch (Exception $e) {
        error_log("Bulk price update error: " . $e->getMessage());
        return [
            'response' => "❌ Failed to update prices. Please try again or contact support.",
            'action_detected' => 'bulk_price_update',
            'success' => false
        ];
    }
}

/**
 * Handle bulk status update
 */
function handleBulkStatusUpdate($db, $userId, $status) {
    try {
        $stmt = $db->prepare("UPDATE car_listings SET status = ?, updated_at = NOW() WHERE user_id = ? AND status != ?");
        $stmt->execute([$status, $userId, $status]);
        $updated = $stmt->rowCount();
        
        $statusText = $status === 'active' ? 'activated' : 'deactivated';
        
        return [
            'response' => "✅ Successfully {$statusText} **{$updated}** listing(s).",
            'action_detected' => 'bulk_status_update',
            'success' => true,
            'updated_count' => $updated,
            'new_status' => $status
        ];
    } catch (Exception $e) {
        error_log("Bulk status update error: " . $e->getMessage());
        return [
            'response' => "❌ Failed to update listing status. Please try again.",
            'action_detected' => 'bulk_status_update',
            'success' => false
        ];
    }
}

/**
 * Handle search within user's own listings
 */
function handleUserListingSearch($db, $userId, $message) {
    try {
        // Extract search terms
        $messageLower = strtolower($message);
        $searchTerms = [];
        
        // Extract make
        $makes = ['toyota', 'bmw', 'mercedes', 'honda', 'nissan', 'ford', 'mazda', 'volkswagen', 'audi'];
        foreach ($makes as $make) {
            if (strpos($messageLower, $make) !== false) {
                $searchTerms['make'] = $make;
                break;
            }
        }
        
        // Extract model
        $models = ['hilux', 'prado', 'landcruiser', 'corolla', 'camry', 'ranger', 'x5', 'x3'];
        foreach ($models as $model) {
            if (strpos($messageLower, $model) !== false) {
                $searchTerms['model'] = $model;
                break;
            }
        }
        
        // Extract year
        if (preg_match('/\b(19|20)\d{2}\b/', $message, $yearMatches)) {
            $searchTerms['year'] = (int)$yearMatches[0];
        }
        
        // Extract price range
        if (preg_match('/(?:under|below|less than|max)\s*(?:mwk|kwacha)?\s*(\d+(?:[.,]\d+)?)\s*(?:million|m|k)?/i', $message, $matches)) {
            $price = floatval(str_replace([',', '.'], '', $matches[1]));
            if (strpos($messageLower, 'million') !== false || strpos($messageLower, ' m') !== false) {
                $searchTerms['max_price'] = $price * 1000000;
            } else {
                $searchTerms['max_price'] = $price;
            }
        }
        
        // Build query
        $where = ["l.user_id = ?"];
        $params = [$userId];
        
        if (!empty($searchTerms['make'])) {
            $stmt = $db->prepare("SELECT id FROM car_makes WHERE LOWER(name) LIKE ? LIMIT 1");
            $stmt->execute(['%' . $searchTerms['make'] . '%']);
            $make = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($make) {
                $where[] = "l.make_id = ?";
                $params[] = $make['id'];
            }
        }
        
        if (!empty($searchTerms['model'])) {
            $stmt = $db->prepare("SELECT id FROM car_models WHERE LOWER(name) LIKE ?" . (!empty($make) ? " AND make_id = ?" : "") . " LIMIT 1");
            $modelParams = ['%' . $searchTerms['model'] . '%'];
            if (!empty($make)) $modelParams[] = $make['id'];
            $stmt->execute($modelParams);
            $model = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($model) {
                $where[] = "l.model_id = ?";
                $params[] = $model['id'];
            }
        }
        
        if (!empty($searchTerms['year'])) {
            $where[] = "l.year = ?";
            $params[] = $searchTerms['year'];
        }
        
        if (!empty($searchTerms['max_price'])) {
            $where[] = "l.price <= ?";
            $params[] = $searchTerms['max_price'];
        }
        
        $stmt = $db->prepare("
            SELECT l.id, l.year, l.price, m.name as make_name, mo.name as model_name
            FROM car_listings l
            INNER JOIN car_makes m ON l.make_id = m.id
            INNER JOIN car_models mo ON l.model_id = mo.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY l.created_at DESC
        ");
        $stmt->execute($params);
        $listings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $serverHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        $isLocalhost = in_array($serverHost, ['localhost', '127.0.0.1']) || 
                       strpos($serverHost, 'localhost:') === 0 || 
                       strpos($serverHost, '127.0.0.1:') === 0 ||
                       preg_match('/^(192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $serverHost);
        $isProduction = !$isLocalhost && !empty($serverHost);
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $baseUrl = $protocol . '://' . $serverHost . '/';
        
        if (empty($listings)) {
            return [
                'response' => "I couldn't find any of your listings matching that criteria. Try different search terms or [view all your listings]({$baseUrl}my-listings.html).",
                'action_detected' => 'user_listing_search',
                'listings' => []
            ];
        }
        
        $response = "🔍 **Found " . count($listings) . " of your listing(s):**\n\n";
        foreach ($listings as $listing) {
            $listingUrl = $baseUrl . "car.html?id=" . $listing['id'];
            $response .= "• [{$listing['make_name']} {$listing['model_name']} ({$listing['year']}) - " . getChatCurrencyCode($db) . " " . number_format($listing['price']) . "]({$listingUrl}) - **Reference #{$listing['id']}**\n";
        }
        
        return [
            'response' => $response,
            'action_detected' => 'user_listing_search',
            'listings' => $listings,
            'search_terms' => $searchTerms
        ];
    } catch (Exception $e) {
        error_log("User listing search error: " . $e->getMessage());
        return false;
    }
}

/**
 * Handle fleet availability update for car hire companies
 */
function handleFleetAvailabilityUpdate($db, $userId, $message) {
    try {
        // Get company ID
        $stmt = $db->prepare("SELECT id FROM car_hire_companies WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$company) {
            return [
                'response' => "You don't have a car hire company registered.",
                'action_detected' => 'fleet_availability',
                'success' => false
            ];
        }
        
        $messageLower = strtolower($message);
        $isAvailable = (strpos($messageLower, 'available') !== false || strpos($messageLower, 'make available') !== false);
        $isUnavailable = (strpos($messageLower, 'unavailable') !== false || strpos($messageLower, 'rented') !== false || strpos($messageLower, 'out') !== false);
        
        // Try to identify specific vehicle
        $vehicle = identifyFleetVehicleFromMessage($db, $company['id'], $message);
        
        if ($vehicle) {
            $newStatus = $isAvailable ? 1 : 0;
            $stmt = $db->prepare("UPDATE car_hire_fleet SET is_available = ?, updated_at = NOW() WHERE id = ? AND company_id = ?");
            $stmt->execute([$newStatus, $vehicle['id'], $company['id']]);
            
            $statusText = $isAvailable ? 'available' : 'unavailable';
            return [
                'response' => "✅ {$vehicle['make_name']} {$vehicle['model_name']} marked as **{$statusText}** for rental.",
                'action_detected' => 'fleet_availability',
                'success' => true,
                'vehicle_id' => $vehicle['id'],
                'is_available' => $newStatus
            ];
        } else {
            // Show fleet for selection
            $serverHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
            $isLocalhost = in_array($serverHost, ['localhost', '127.0.0.1']) || 
                           strpos($serverHost, 'localhost:') === 0 || 
                           strpos($serverHost, '127.0.0.1:') === 0 ||
                           preg_match('/^(192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $serverHost);
            $isProduction = !$isLocalhost && !empty($serverHost);
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $baseUrl = $protocol . '://' . $serverHost . '/';
            
            $stmt = $db->prepare("
                SELECT f.id, f.year, m.name as make_name, mo.name as model_name, f.is_available
                FROM car_hire_fleet f
                INNER JOIN car_makes m ON f.make_id = m.id
                INNER JOIN car_models mo ON f.model_id = mo.id
                WHERE f.company_id = ? AND f.is_active = 1
                ORDER BY f.is_available DESC
                LIMIT 10
            ");
            $stmt->execute([$company['id']]);
            $fleet = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($fleet)) {
                return [
                    'response' => "Your fleet is empty. [Add vehicles to your fleet]({$baseUrl}car-hire-dashboard.html#fleet) first.",
                    'action_detected' => 'fleet_availability',
                    'success' => false
                ];
            }
            
            $response = "Which vehicle would you like to update? Your fleet:\n\n";
            foreach ($fleet as $v) {
                $status = $v['is_available'] ? '✅ Available' : '⏸️ Unavailable';
                $response .= "• {$status} - {$v['make_name']} {$v['model_name']}";
                if ($v['year']) $response .= " ({$v['year']})";
                $response .= "\n";
            }
            $response .= "\nPlease specify which vehicle (e.g., 'mark Toyota Hilux as available').";
            
            return [
                'response' => $response,
                'action_detected' => 'fleet_availability',
                'fleet' => $fleet,
                'requires_clarification' => true
            ];
        }
    } catch (Exception $e) {
        error_log("Fleet availability update error: " . $e->getMessage());
        return [
            'response' => "❌ Failed to update fleet availability. Please try again.",
            'action_detected' => 'fleet_availability',
            'success' => false
        ];
    }
}

/**
 * Identify fleet vehicle from message
 */
function identifyFleetVehicleFromMessage($db, $companyId, $message) {
    $messageLower = strtolower($message);
    $makes = ['toyota', 'bmw', 'mercedes', 'honda', 'nissan', 'ford', 'mazda'];
    $models = ['hilux', 'prado', 'landcruiser', 'corolla', 'camry', 'ranger'];
    
    $makeId = null;
    $modelId = null;
    
    foreach ($makes as $make) {
        if (strpos($messageLower, $make) !== false) {
            $stmt = $db->prepare("SELECT id FROM car_makes WHERE LOWER(name) LIKE ? LIMIT 1");
            $stmt->execute(['%' . $make . '%']);
            $makeRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($makeRow) {
                $makeId = $makeRow['id'];
                break;
            }
        }
    }
    
    foreach ($models as $model) {
        if (strpos($messageLower, $model) !== false) {
            $stmt = $db->prepare("SELECT id FROM car_models WHERE LOWER(name) LIKE ?" . ($makeId ? " AND make_id = ?" : "") . " LIMIT 1");
            $params = ['%' . $model . '%'];
            if ($makeId) $params[] = $makeId;
            $stmt->execute($params);
            $modelRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($modelRow) {
                $modelId = $modelRow['id'];
                break;
            }
        }
    }
    
    if ($makeId || $modelId) {
        $where = ["f.company_id = ?", "f.is_active = 1"];
        $params = [$companyId];
        
        if ($makeId) {
            $where[] = "f.make_id = ?";
            $params[] = $makeId;
        }
        if ($modelId) {
            $where[] = "f.model_id = ?";
            $params[] = $modelId;
        }
        
        $stmt = $db->prepare("
            SELECT f.*, m.name as make_name, mo.name as model_name
            FROM car_hire_fleet f
            INNER JOIN car_makes m ON f.make_id = m.id
            INNER JOIN car_models mo ON f.model_id = mo.id
            WHERE " . implode(' AND ', $where) . "
            LIMIT 1
        ");
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    return null;
}

/**
 * Handle fleet rate update
 */
function handleFleetRateUpdate($db, $userId, $message, $rate) {
    try {
        $stmt = $db->prepare("SELECT id FROM car_hire_companies WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$company) {
            return [
                'response' => "You don't have a car hire company registered.",
                'action_detected' => 'fleet_rate',
                'success' => false
            ];
        }
        
        $vehicle = identifyFleetVehicleFromMessage($db, $company['id'], $message);
        
        if ($vehicle) {
            $stmt = $db->prepare("UPDATE car_hire_fleet SET daily_rate = ?, updated_at = NOW() WHERE id = ? AND company_id = ?");
            $stmt->execute([$rate, $vehicle['id'], $company['id']]);
            
            return [
                'response' => "✅ Daily rate updated! {$vehicle['make_name']} {$vehicle['model_name']} is now **" . getChatCurrencyCode($db) . " " . number_format($rate) . "/day**.",
                'action_detected' => 'fleet_rate',
                'success' => true,
                'vehicle_id' => $vehicle['id'],
                'new_rate' => $rate
            ];
        } else {
            return [
                'response' => "Please specify which vehicle to update (e.g., 'change Toyota Hilux rate to 50000').",
                'action_detected' => 'fleet_rate',
                'requires_clarification' => true
            ];
        }
    } catch (Exception $e) {
        error_log("Fleet rate update error: " . $e->getMessage());
        return [
            'response' => "❌ Failed to update rate. Please try again.",
            'action_detected' => 'fleet_rate',
            'success' => false
        ];
    }
}

/**
 * Handle garage hours update
 */
function handleGarageHoursUpdate($db, $userId, $hours) {
    try {
        $serverHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        $isLocalhost = in_array($serverHost, ['localhost', '127.0.0.1']) || 
                       strpos($serverHost, 'localhost:') === 0 || 
                       strpos($serverHost, '127.0.0.1:') === 0 ||
                       preg_match('/^(192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $serverHost);
        $isProduction = !$isLocalhost && !empty($serverHost);
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $baseUrl = $protocol . '://' . $serverHost . '/';
        
        $stmt = $db->prepare("UPDATE garages SET operating_hours = ?, updated_at = NOW() WHERE user_id = ?");
        $stmt->execute([$hours, $userId]);
        
        return [
            'response' => "✅ Operating hours updated to **{$hours}**! [View your garage]({$baseUrl}garage-dashboard.html)",
            'action_detected' => 'update_garage_hours',
            'success' => true,
            'new_hours' => $hours
        ];
    } catch (Exception $e) {
        error_log("Garage hours update error: " . $e->getMessage());
        return [
            'response' => "❌ Failed to update operating hours. Please try again.",
            'action_detected' => 'update_garage_hours',
            'success' => false
        ];
    }
}

/**
 * Handle business field update (phone, email, etc.)
 */
function handleBusinessFieldUpdate($db, $userId, $userType, $businessType, $field, $value, $baseUrl) {
    try {
        $table = '';
        $idField = '';
        
        if ($businessType === 'dealer') {
            $table = 'car_dealers';
            $idField = 'user_id';
        } elseif ($businessType === 'garage') {
            $table = 'garages';
            $idField = 'user_id';
        } elseif ($businessType === 'car_hire') {
            $table = 'car_hire_companies';
            $idField = 'user_id';
        } else {
            return [
                'response' => "You don't have a business profile to update. Please set up your business first.",
                'action_detected' => 'update_business',
                'success' => false
            ];
        }
        
        // Validate field name
        $allowedFields = ['phone', 'email', 'location_id'];
        if (!in_array($field, $allowedFields)) {
            return [
                'response' => "I can help you update your business {$field}, but please use the [dashboard]({$baseUrl}" . getDashboardUrl($userType, '') . ") for more complex updates.",
                'action_detected' => 'update_business',
                'requires_dashboard' => true
            ];
        }
        
        // Update the field
        $stmt = $db->prepare("UPDATE {$table} SET {$field} = ?, updated_at = NOW() WHERE {$idField} = ?");
        $stmt->execute([$value, $userId]);
        
        $fieldName = ucfirst($field);
        
        return [
            'response' => "✅ {$fieldName} updated successfully to **{$value}**! [View your business profile]({$baseUrl}" . getDashboardUrl($userType, '') . ")",
            'action_detected' => 'update_business',
            'success' => true,
            'field' => $field,
            'new_value' => $value
        ];
    } catch (Exception $e) {
        error_log("Business field update error: " . $e->getMessage());
        return [
            'response' => "❌ Failed to update {$field}. Please try again or use the [dashboard]({$baseUrl}" . getDashboardUrl($userType, '') . ")",
            'action_detected' => 'update_business',
            'success' => false
        ];
    }
}

/**
 * Get AI Chat settings from database
 */
function getAIChatSettings($db) {
    try {
        // Always fetch fresh settings from database (no caching)
        $stmt = $db->prepare("SELECT * FROM ai_chat_settings WHERE id = 1");
        $stmt->execute();
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$settings) {
            // Return defaults if no settings exist
            $defaults = [
                'ai_provider' => 'openai',
                'model_name' => 'gpt-4o',
                'openai_enabled' => 1,
                'deepseek_enabled' => 1,
                'qwen_enabled' => 1,
                'glm_enabled' => 1,
                'max_tokens_per_request' => 1200,
                'temperature' => 0.7,
                'requests_per_day' => 50,
                'requests_per_hour' => 10,
                'enabled' => 1
            ];
            error_log("AI Chat Settings: No settings found in database, using defaults");
            return $defaults;
        }
        
        // Ensure all fields are properly typed
        $settings['ai_provider'] = normalizeAIChatProvider($settings['ai_provider'] ?? 'openai');
        $settings['model_name'] = $settings['model_name'] ?? 'gpt-4o-mini';
        $settings['openai_enabled'] = (int)($settings['openai_enabled'] ?? 1);
        $settings['deepseek_enabled'] = (int)($settings['deepseek_enabled'] ?? 1);
        $settings['qwen_enabled'] = (int)($settings['qwen_enabled'] ?? 1);
        $settings['glm_enabled'] = (int)($settings['glm_enabled'] ?? 1);
        $settings['max_tokens_per_request'] = (int)($settings['max_tokens_per_request'] ?? 1200);
        $settings['temperature'] = (float)($settings['temperature'] ?? 0.7);
        $settings['requests_per_day'] = (int)($settings['requests_per_day'] ?? 50);
        $settings['requests_per_hour'] = (int)($settings['requests_per_hour'] ?? 10);
        $settings['enabled'] = (int)($settings['enabled'] ?? 1);
        
        // Debug log to verify settings are being read correctly
        error_log("AI Chat Settings loaded: provider={$settings['ai_provider']}, hourly_limit={$settings['requests_per_hour']}, daily_limit={$settings['requests_per_day']}, model={$settings['model_name']}");
        
        return $settings;
    } catch (Exception $e) {
        error_log("Error getting AI chat settings: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        // Return safe defaults on error
        return [
            'ai_provider' => 'openai',
            'model_name' => 'gpt-4o',
            'openai_enabled' => 1,
            'deepseek_enabled' => 1,
            'qwen_enabled' => 1,
            'glm_enabled' => 1,
            'max_tokens_per_request' => 1200,
            'temperature' => 0.7,
            'requests_per_day' => 50,
            'requests_per_hour' => 10,
            'enabled' => 1
        ];
    }
}

/**
 * Check if user's AI chat is restricted/disabled
 */
function checkAIChatUserRestriction($db, $userId) {
    try {
        $stmt = $db->prepare("
            SELECT disabled, reason, disabled_at, disabled_by
            FROM ai_chat_user_restrictions
            WHERE user_id = ? AND disabled = 1
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $restriction = $stmt->fetch(PDO::FETCH_ASSOC);
        return $restriction ?: null;
    } catch (Exception $e) {
        error_log("Error checking AI chat user restriction: " . $e->getMessage());
        // If table doesn't exist, return null (no restriction)
        return null;
    }
}

/**
 * Check if user has exceeded rate limits
 */
function checkAIChatRateLimit($db, $userId, $settings) {
    try {
        // Ensure settings are properly typed
        $requestsPerDay = (int)($settings['requests_per_day'] ?? 50);
        $requestsPerHour = (int)($settings['requests_per_hour'] ?? 10);
        
        // Debug log to verify settings are being used
        error_log("Rate limit check for user {$userId}: daily_limit={$requestsPerDay}, hourly_limit={$requestsPerHour}");
        
        // Check daily limit
        $stmt = $db->prepare("
            SELECT COUNT(*) as count 
            FROM ai_chat_usage 
            WHERE user_id = ? 
            AND DATE(created_at) = CURDATE()
        ");
        $stmt->execute([$userId]);
        $dailyUsage = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($dailyUsage && (int)$dailyUsage['count'] >= $requestsPerDay) {
            return [
                'allowed' => false,
                'message' => "You've reached your daily limit of {$requestsPerDay} AI chat requests. Please try again tomorrow."
            ];
        }
        
        // Check hourly limit
        $stmt = $db->prepare("
            SELECT COUNT(*) as count 
            FROM ai_chat_usage 
            WHERE user_id = ? 
            AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $stmt->execute([$userId]);
        $hourlyUsage = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($hourlyUsage && (int)$hourlyUsage['count'] >= $requestsPerHour) {
            // Get the time when the oldest request in the last hour was made
            $stmt = $db->prepare("
                SELECT MIN(created_at) as oldest_request
                FROM ai_chat_usage 
                WHERE user_id = ? 
                AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ");
            $stmt->execute([$userId]);
            $oldestRequest = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $waitTime = '';
            if ($oldestRequest && $oldestRequest['oldest_request']) {
                $oldestTime = strtotime($oldestRequest['oldest_request']);
                $nextAvailable = $oldestTime + 3600; // Add 1 hour
                $minutesLeft = max(1, ceil(($nextAvailable - time()) / 60));
                $waitTime = " You can try again in approximately {$minutesLeft} minute" . ($minutesLeft != 1 ? 's' : '') . ".";
            }
            
            return [
                'allowed' => false,
                'message' => "You've reached your hourly limit of {$requestsPerHour} AI chat requests.{$waitTime}"
            ];
        }
        
        return ['allowed' => true];
    } catch (Exception $e) {
        error_log("Error checking rate limit: " . $e->getMessage());
        // Allow on error to prevent blocking users
        return ['allowed' => true];
    }
}

/**
 * Get user's daily AI chat usage and remaining requests
 */
function getUserAIChatUsageRemaining($db, $userId) {
    try {
        $settings = getAIChatSettings($db);
        $requestsPerDay = (int)($settings['requests_per_day'] ?? 50);
        
        // Get today's usage count
        $stmt = $db->prepare("
            SELECT COUNT(*) as count 
            FROM ai_chat_usage 
            WHERE user_id = ? 
            AND DATE(created_at) = CURDATE()
        ");
        $stmt->execute([$userId]);
        $dailyUsage = $stmt->fetch(PDO::FETCH_ASSOC);
        $usedToday = (int)($dailyUsage['count'] ?? 0);
        
        $remaining = max(0, $requestsPerDay - $usedToday);
        
        return [
            'used_today' => $usedToday,
            'daily_limit' => $requestsPerDay,
            'remaining' => $remaining,
            'percentage_used' => $requestsPerDay > 0 ? round(($usedToday / $requestsPerDay) * 100, 1) : 0
        ];
    } catch (Exception $e) {
        error_log("Error getting user AI chat usage: " . $e->getMessage());
        return [
            'used_today' => 0,
            'daily_limit' => 50,
            'remaining' => 50,
            'percentage_used' => 0
        ];
    }
}

/**
 * Log AI chat usage to database
 */
function logAIChatUsage($db, $userId, $message, $responseLength, $tokensUsed, $modelUsed, $costEstimate) {
    try {
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        $stmt = $db->prepare("
            INSERT INTO ai_chat_usage 
            (user_id, message, response_length, tokens_used, model_used, cost_estimate, ip_address, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $userId,
            substr($message, 0, 1000), // Limit message length
            $responseLength,
            $tokensUsed,
            $modelUsed,
            $costEstimate,
            $ipAddress,
            $userAgent
        ]);
    } catch (Exception $e) {
        error_log("Error logging AI chat usage: " . $e->getMessage());
        // Don't throw - logging failure shouldn't break the chat
    }
}
