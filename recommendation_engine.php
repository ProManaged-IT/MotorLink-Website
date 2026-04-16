<?php
/**
 * MotorLink AI Recommendation Engine
 * Learns from user preferences + browsing history for both logged-in and guest sessions.
 */

if (!defined('MOTORLINK_CONSTANTS_ONLY')) {
    define('MOTORLINK_CONSTANTS_ONLY', true);
}
require_once __DIR__ . '/api.php';

class RecommendationEngine {
    private $db;
    private $userId;
    private $sessionId;

    public function __construct($db, $userId = null, $sessionId = null) {
        $this->db = $db;
        $this->userId = $userId ? (int)$userId : null;
        $this->sessionId = $sessionId ? trim((string)$sessionId) : null;
    }

    /**
     * Get personalized recommendations based on browsing behavior and saved preferences.
     */
    public function getPersonalizedRecommendations($limit = 10, $excludeListingId = null) {
        $limit = max(1, min(30, (int)$limit));
        $excludeListingId = $excludeListingId ? (int)$excludeListingId : null;

        $preferences = $this->analyzeUserPreferences();
        $collaborative = $this->collaborativeFiltering();
        $content = $this->contentBasedFiltering($preferences, max($limit * 3, 24));

        $recommendations = $this->mergeAndRank($collaborative, $content, $preferences);

        if ($excludeListingId) {
            $recommendations = array_values(array_filter($recommendations, function ($item) use ($excludeListingId) {
                return (int)($item['id'] ?? 0) !== $excludeListingId;
            }));
        }

        if (empty($recommendations)) {
            $recommendations = $this->getTrendingCars(max($limit * 2, 12), $excludeListingId);
        }

        return array_slice($recommendations, 0, $limit);
    }

    /**
     * Analyze user preferences from behavior + stored preference snapshots.
     */
    private function analyzeUserPreferences() {
        $defaults = $this->getDefaultPreferences();
        $historyPrefs = $this->loadHistoryPreferences();
        $storedPrefs = $this->loadStoredPreferences();

        $defaults['avg_price'] = $this->pickNumericPreference($historyPrefs['avg_price'] ?? null, $storedPrefs['avg_price'] ?? null, $defaults['avg_price']);
        $defaults['avg_year'] = $this->pickNumericPreference($historyPrefs['avg_year'] ?? null, $storedPrefs['avg_year'] ?? null, $defaults['avg_year']);
        $defaults['avg_mileage'] = $this->pickNumericPreference($historyPrefs['avg_mileage'] ?? null, $storedPrefs['avg_mileage'] ?? null, $defaults['avg_mileage']);

        $defaults['preferred_makes'] = $this->mergePreferenceLists(
            $defaults['preferred_makes'],
            $historyPrefs['preferred_makes'] ?? [],
            $storedPrefs['preferred_makes'] ?? []
        );

        $defaults['preferred_body_types'] = $this->mergePreferenceLists(
            $defaults['preferred_body_types'],
            $historyPrefs['preferred_body_types'] ?? [],
            $storedPrefs['preferred_body_types'] ?? []
        );

        $defaults['interaction_score'] = $this->calculateInteractionScore();

        return $defaults;
    }

    /**
     * Preference averages and dominant attributes from recent views.
     */
    private function loadHistoryPreferences() {
        $identity = null;
        $param = null;
        $historyTable = null;

        if ($this->userId) {
            $identity = 'user_id';
            $param = $this->userId;
            $historyTable = 'viewing_history';
        } elseif ($this->sessionId) {
            $identity = 'session_id';
            $param = $this->sessionId;
            $historyTable = 'guest_viewing_history';
        } else {
            return [];
        }

        try {
            $sql = "
                SELECT
                    AVG(cl.price) AS avg_price,
                    AVG(cl.year) AS avg_year,
                    AVG(cl.mileage) AS avg_mileage,
                    GROUP_CONCAT(DISTINCT cm.name) AS preferred_makes,
                    GROUP_CONCAT(DISTINCT cmo.body_type) AS preferred_body_types,
                    COUNT(DISTINCT vh.listing_id) AS total_views
                FROM {$historyTable} vh
                JOIN car_listings cl ON vh.listing_id = cl.id
                JOIN car_makes cm ON cl.make_id = cm.id
                JOIN car_models cmo ON cl.model_id = cmo.id
                WHERE vh.{$identity} = ?
                AND vh.last_viewed > DATE_SUB(NOW(), INTERVAL 45 DAY)
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$param]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                return [];
            }

            return [
                'avg_price' => !empty($row['avg_price']) ? (float)$row['avg_price'] : null,
                'avg_year' => !empty($row['avg_year']) ? (float)$row['avg_year'] : null,
                'avg_mileage' => !empty($row['avg_mileage']) ? (float)$row['avg_mileage'] : null,
                'preferred_makes' => $this->normalizePreferenceList($row['preferred_makes'] ?? ''),
                'preferred_body_types' => $this->normalizePreferenceList($row['preferred_body_types'] ?? '')
            ];
        } catch (Exception $e) {
            error_log('Recommendation history preference query failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Pull client-snapshotted preferences (sent by frontend) for better cold-start quality.
     */
    private function loadStoredPreferences() {
        try {
            if ($this->userId) {
                $stmt = $this->db->prepare("SELECT preferences FROM user_preferences WHERE user_id = ? LIMIT 1");
                $stmt->execute([$this->userId]);
            } elseif ($this->sessionId) {
                $stmt = $this->db->prepare("SELECT preferences FROM guest_preferences WHERE session_id = ? LIMIT 1");
                $stmt->execute([$this->sessionId]);
            } else {
                return [];
            }

            $raw = $stmt->fetchColumn();
            if (!$raw) {
                return [];
            }

            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                return [];
            }

            $stored = [
                'preferred_makes' => $this->normalizePreferenceList($decoded['preferred_makes'] ?? ($decoded['makes'] ?? [])),
                'preferred_body_types' => $this->normalizePreferenceList($decoded['preferred_body_types'] ?? ($decoded['body_types'] ?? [])),
                'avg_price' => null,
                'avg_year' => null,
                'avg_mileage' => null
            ];

            if (!empty($decoded['price_range']['avg'])) {
                $stored['avg_price'] = (float)$decoded['price_range']['avg'];
            } elseif (!empty($decoded['avgPrice'])) {
                $stored['avg_price'] = (float)$decoded['avgPrice'];
            }

            if (!empty($decoded['year_range']['avg'])) {
                $stored['avg_year'] = (float)$decoded['year_range']['avg'];
            } elseif (!empty($decoded['avgYear'])) {
                $stored['avg_year'] = (float)$decoded['avgYear'];
            }

            if (!empty($decoded['mileage_range']['avg'])) {
                $stored['avg_mileage'] = (float)$decoded['mileage_range']['avg'];
            }

            return $stored;
        } catch (Exception $e) {
            error_log('Recommendation stored preference query failed: ' . $e->getMessage());
            return [];
        }
    }

    private function pickNumericPreference($primary, $secondary, $fallback) {
        if (is_numeric($primary) && (float)$primary > 0) {
            return (float)$primary;
        }

        if (is_numeric($secondary) && (float)$secondary > 0) {
            return (float)$secondary;
        }

        return (float)$fallback;
    }

    private function mergePreferenceLists(...$lists) {
        $counts = [];

        foreach ($lists as $list) {
            foreach ($this->normalizePreferenceList($list) as $item) {
                if ($item === '') {
                    continue;
                }

                if (!isset($counts[$item])) {
                    $counts[$item] = 0;
                }
                $counts[$item]++;
            }
        }

        arsort($counts);
        return array_slice(array_keys($counts), 0, 8);
    }

    private function normalizePreferenceList($value) {
        if (is_string($value)) {
            $parts = array_map('trim', explode(',', $value));
            $parts = array_values(array_filter($parts, function ($item) {
                return $item !== '';
            }));
            return array_slice($parts, 0, 8);
        }

        if (!is_array($value)) {
            return [];
        }

        if (array_values($value) === $value) {
            $normalized = array_values(array_filter(array_map('trim', $value), function ($item) {
                return $item !== '';
            }));
            return array_slice($normalized, 0, 8);
        }

        arsort($value);
        $keys = array_map('trim', array_keys($value));
        $keys = array_values(array_filter($keys, function ($item) {
            return $item !== '';
        }));
        return array_slice($keys, 0, 8);
    }

    /**
     * Interaction score from explicit user actions.
     */
    private function calculateInteractionScore() {
        if (!$this->userId) {
            return 0;
        }

        try {
            $sql = "
                SELECT
                    SUM(CASE
                        WHEN action_type = 'view' THEN 1
                        WHEN action_type = 'favorite' THEN 3
                        WHEN action_type = 'inquiry' THEN 5
                        WHEN action_type = 'share' THEN 2
                        ELSE 0
                    END) AS score
                FROM user_interactions
                WHERE user_id = ?
                AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$this->userId]);
            return (float)($stmt->fetchColumn() ?: 0);
        } catch (Exception $e) {
            // Table may not be available in all environments.
            return 0;
        }
    }

    /**
     * Collaborative filtering for logged-in users.
     */
    private function collaborativeFiltering() {
        if (!$this->userId) {
            return [];
        }

        try {
            $sql = "
                SELECT
                    other.user_id,
                    COUNT(DISTINCT other.listing_id) AS common_views,
                    GROUP_CONCAT(DISTINCT recommended.listing_id) AS recommendations
                FROM viewing_history vh
                JOIN viewing_history other ON vh.listing_id = other.listing_id
                JOIN viewing_history recommended ON other.user_id = recommended.user_id
                LEFT JOIN viewing_history already_seen
                    ON already_seen.listing_id = recommended.listing_id
                    AND already_seen.user_id = ?
                WHERE vh.user_id = ?
                AND other.user_id != ?
                AND already_seen.id IS NULL
                GROUP BY other.user_id
                HAVING common_views >= 2
                ORDER BY common_views DESC
                LIMIT 12
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$this->userId, $this->userId, $this->userId]);

            $recommendationMap = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (empty($row['recommendations'])) {
                    continue;
                }

                $listingIds = array_filter(array_map('trim', explode(',', $row['recommendations'])));
                foreach ($listingIds as $id) {
                    $key = (int)$id;
                    if (!isset($recommendationMap[$key])) {
                        $recommendationMap[$key] = 0;
                    }
                    $recommendationMap[$key] += (int)$row['common_views'];
                }
            }

            if (empty($recommendationMap)) {
                return [];
            }

            $details = $this->fetchListingDetails(array_keys($recommendationMap));
            foreach ($details as &$item) {
                $id = (int)($item['id'] ?? 0);
                $item['_collab_weight'] = (float)($recommendationMap[$id] ?? 0);
            }
            unset($item);

            return $details;
        } catch (Exception $e) {
            error_log('Collaborative filtering failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Content-based filtering from preferences.
     */
    private function contentBasedFiltering($preferences, $limit = 24) {
        $limit = max(8, min(60, (int)$limit));

        $avgPrice = max(1, (float)($preferences['avg_price'] ?? 5000000));
        $avgYear = max(1990, (float)($preferences['avg_year'] ?? 2018));
        $avgMileage = max(1, (float)($preferences['avg_mileage'] ?? 50000));

        $preferredMakes = $this->normalizePreferenceList($preferences['preferred_makes'] ?? []);
        $preferredBodyTypes = $this->normalizePreferenceList($preferences['preferred_body_types'] ?? []);

        $scoreSql = "
            ABS(COALESCE(cl.price, ?) - ?) / ? * 0.36 +
            ABS(COALESCE(cl.year, ?) - ?) / 12 * 0.22 +
            ABS(COALESCE(cl.mileage, ?) - ?) / ? * 0.18
        ";

        $params = [
            $avgPrice, $avgPrice, $avgPrice,
            $avgYear, $avgYear,
            $avgMileage, $avgMileage, $avgMileage
        ];

        if (!empty($preferredMakes)) {
            $placeholders = implode(',', array_fill(0, count($preferredMakes), '?'));
            $scoreSql .= " + CASE WHEN cm.name IN ({$placeholders}) THEN -0.22 ELSE 0 END";
            $params = array_merge($params, $preferredMakes);
        }

        if (!empty($preferredBodyTypes)) {
            $placeholders = implode(',', array_fill(0, count($preferredBodyTypes), '?'));
            $scoreSql .= " + CASE WHEN cmo.body_type IN ({$placeholders}) THEN -0.12 ELSE 0 END";
            $params = array_merge($params, $preferredBodyTypes);
        }

        $sql = "
            SELECT
                cl.*,
                cm.name AS make_name,
                cmo.name AS model_name,
                cmo.body_type AS body_type,
                loc.name AS location_name,
                ({$scoreSql}) AS similarity_score
            FROM car_listings cl
            JOIN car_makes cm ON cl.make_id = cm.id
            JOIN car_models cmo ON cl.model_id = cmo.id
            JOIN locations loc ON cl.location_id = loc.id
            WHERE cl.status = 'active'
            AND cl.approval_status = 'approved'
            ORDER BY similarity_score ASC, cl.created_at DESC
            LIMIT {$limit}
        ";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('Content filtering failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Merge and rank recommendations from different signals.
     */
    private function mergeAndRank($collaborative, $content, $preferences) {
        $merged = [];
        $preferredMakes = $this->normalizePreferenceList($preferences['preferred_makes'] ?? []);
        $preferredBodyTypes = $this->normalizePreferenceList($preferences['preferred_body_types'] ?? []);

        foreach ($collaborative as $item) {
            $id = (int)($item['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            if (!isset($merged[$id])) {
                $merged[$id] = $item;
                $merged[$id]['score'] = 0.0;
            }

            $collabWeight = (float)($item['_collab_weight'] ?? 1);
            $merged[$id]['score'] += 0.45 + min($collabWeight / 20, 0.20);
        }

        foreach ($content as $item) {
            $id = (int)($item['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            if (!isset($merged[$id])) {
                $merged[$id] = $item;
                $merged[$id]['score'] = 0.0;
            }

            $similarity = (float)($item['similarity_score'] ?? 3.0);
            $normalized = max(0, 1 - min($similarity, 3.0) / 3.0);
            $merged[$id]['score'] += 0.40 * $normalized;

            if (!empty($item['make_name']) && in_array($item['make_name'], $preferredMakes, true)) {
                $merged[$id]['score'] += 0.06;
            }

            if (!empty($item['body_type']) && in_array($item['body_type'], $preferredBodyTypes, true)) {
                $merged[$id]['score'] += 0.04;
            }
        }

        $engagementBoost = min(((float)($preferences['interaction_score'] ?? 0)) / 220, 0.08);
        foreach ($merged as &$item) {
            $item['score'] += $engagementBoost;
        }
        unset($item);

        $this->addRecencyBonus($merged);
        $this->addPopularityBonus($merged);

        uasort($merged, function ($a, $b) {
            return ((float)$b['score']) <=> ((float)$a['score']);
        });

        return array_values($merged);
    }

    private function addRecencyBonus(&$items) {
        foreach ($items as &$item) {
            if (empty($item['created_at'])) {
                continue;
            }

            $ageSeconds = time() - strtotime((string)$item['created_at']);
            $daysOld = $ageSeconds / 86400;

            if ($daysOld <= 3) {
                $item['score'] += 0.14;
            } elseif ($daysOld <= 10) {
                $item['score'] += 0.08;
            } elseif ($daysOld <= 21) {
                $item['score'] += 0.04;
            }
        }
        unset($item);
    }

    private function addPopularityBonus(&$items) {
        $ids = array_values(array_filter(array_map('intval', array_column($items, 'id'))));
        if (empty($ids)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $popularity = [];

        try {
            $sql = "
                SELECT listing_id, COUNT(*) AS views, COUNT(DISTINCT user_id) AS unique_views
                FROM viewing_history
                WHERE listing_id IN ({$placeholders})
                AND last_viewed > DATE_SUB(NOW(), INTERVAL 10 DAY)
                GROUP BY listing_id
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($ids);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $listingId = (int)$row['listing_id'];
                $popularity[$listingId] = [
                    'views' => (int)$row['views'],
                    'unique' => (int)$row['unique_views']
                ];
            }
        } catch (Exception $e) {
            // Optional table.
        }

        try {
            $sql = "
                SELECT listing_id, COUNT(*) AS views, COUNT(DISTINCT session_id) AS unique_views
                FROM guest_viewing_history
                WHERE listing_id IN ({$placeholders})
                AND last_viewed > DATE_SUB(NOW(), INTERVAL 10 DAY)
                GROUP BY listing_id
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($ids);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $listingId = (int)$row['listing_id'];
                if (!isset($popularity[$listingId])) {
                    $popularity[$listingId] = ['views' => 0, 'unique' => 0];
                }
                $popularity[$listingId]['views'] += (int)$row['views'];
                $popularity[$listingId]['unique'] += (int)$row['unique_views'];
            }
        } catch (Exception $e) {
            // Optional table.
        }

        foreach ($items as &$item) {
            $id = (int)($item['id'] ?? 0);
            if (!isset($popularity[$id])) {
                continue;
            }

            $views = (int)$popularity[$id]['views'];
            $unique = (int)$popularity[$id]['unique'];
            $item['score'] += min(($views / 120), 0.10) + min(($unique / 80), 0.06);
        }
        unset($item);
    }

    private function fetchListingDetails($listingIds) {
        $listingIds = array_values(array_filter(array_map('intval', $listingIds)));
        if (empty($listingIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($listingIds), '?'));
        $sql = "
            SELECT
                cl.*,
                cm.name AS make_name,
                cmo.name AS model_name,
                cmo.body_type AS body_type,
                loc.name AS location_name
            FROM car_listings cl
            JOIN car_makes cm ON cl.make_id = cm.id
            JOIN car_models cmo ON cl.model_id = cmo.id
            JOIN locations loc ON cl.location_id = loc.id
            WHERE cl.id IN ({$placeholders})
            AND cl.status = 'active'
            AND cl.approval_status = 'approved'
        ";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($listingIds);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('fetchListingDetails failed: ' . $e->getMessage());
            return [];
        }
    }

    private function getDefaultPreferences() {
        return [
            'avg_price' => 5000000,
            'avg_year' => 2018,
            'avg_mileage' => 50000,
            'preferred_makes' => ['Toyota', 'Nissan', 'Mazda'],
            'preferred_body_types' => ['Sedan', 'SUV'],
            'interaction_score' => 0
        ];
    }

    /**
     * Track listing view (guest or authenticated).
     */
    public function trackView($listingId, $sessionId = null) {
        $listingId = (int)$listingId;
        $sessionId = $sessionId ? trim((string)$sessionId) : $this->sessionId;

        if ($listingId <= 0) {
            return false;
        }

        if ($this->userId) {
            try {
                $sql = "
                    INSERT INTO viewing_history (user_id, listing_id, viewed_at, view_count, last_viewed)
                    VALUES (?, ?, NOW(), 1, NOW())
                    ON DUPLICATE KEY UPDATE
                        view_count = view_count + 1,
                        last_viewed = NOW(),
                        viewed_at = NOW()
                ";

                $stmt = $this->db->prepare($sql);
                $stmt->execute([$this->userId, $listingId]);
                return true;
            } catch (Exception $e) {
                try {
                    $fallback = $this->db->prepare("INSERT INTO viewing_history (user_id, listing_id, viewed_at, view_count, last_viewed) VALUES (?, ?, NOW(), 1, NOW())");
                    $fallback->execute([$this->userId, $listingId]);
                    return true;
                } catch (Exception $inner) {
                    error_log('trackView user failed: ' . $inner->getMessage());
                    return false;
                }
            }
        }

        if (!$sessionId) {
            return false;
        }

        try {
            $sql = "
                INSERT INTO guest_viewing_history (session_id, listing_id, viewed_at, view_count, last_viewed)
                VALUES (?, ?, NOW(), 1, NOW())
                ON DUPLICATE KEY UPDATE
                    view_count = view_count + 1,
                    last_viewed = NOW(),
                    viewed_at = NOW()
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$sessionId, $listingId]);
            return true;
        } catch (Exception $e) {
            try {
                $fallback = $this->db->prepare("INSERT INTO guest_viewing_history (session_id, listing_id, viewed_at, view_count, last_viewed) VALUES (?, ?, NOW(), 1, NOW())");
                $fallback->execute([$sessionId, $listingId]);
                return true;
            } catch (Exception $inner) {
                error_log('trackView guest failed: ' . $inner->getMessage());
                return false;
            }
        }
    }

    /**
     * Store learned preference snapshot from client behavior.
     */
    public function storePreferences($preferences, $sessionId = null) {
        if (!is_array($preferences) || empty($preferences)) {
            return false;
        }

        $sessionId = $sessionId ? trim((string)$sessionId) : $this->sessionId;
        $payload = json_encode($preferences, JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return false;
        }

        if ($this->userId) {
            try {
                $sql = "
                    INSERT INTO user_preferences (user_id, preferences, updated_at)
                    VALUES (?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        preferences = VALUES(preferences),
                        updated_at = NOW()
                ";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([$this->userId, $payload]);
                return true;
            } catch (Exception $e) {
                try {
                    $update = $this->db->prepare("UPDATE user_preferences SET preferences = ?, updated_at = NOW() WHERE user_id = ?");
                    $update->execute([$payload, $this->userId]);
                    if ($update->rowCount() > 0) {
                        return true;
                    }

                    $insert = $this->db->prepare("INSERT INTO user_preferences (user_id, preferences, updated_at) VALUES (?, ?, NOW())");
                    $insert->execute([$this->userId, $payload]);
                    return true;
                } catch (Exception $inner) {
                    error_log('storePreferences user failed: ' . $inner->getMessage());
                    return false;
                }
            }
        }

        if (!$sessionId) {
            return false;
        }

        try {
            $sql = "
                INSERT INTO guest_preferences (session_id, preferences, updated_at)
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    preferences = VALUES(preferences),
                    updated_at = NOW()
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$sessionId, $payload]);
            return true;
        } catch (Exception $e) {
            try {
                $update = $this->db->prepare("UPDATE guest_preferences SET preferences = ?, updated_at = NOW() WHERE session_id = ?");
                $update->execute([$payload, $sessionId]);
                if ($update->rowCount() > 0) {
                    return true;
                }

                $insert = $this->db->prepare("INSERT INTO guest_preferences (session_id, preferences, updated_at) VALUES (?, ?, NOW())");
                $insert->execute([$sessionId, $payload]);
                return true;
            } catch (Exception $inner) {
                error_log('storePreferences guest failed: ' . $inner->getMessage());
                return false;
            }
        }
    }

    /**
     * Fallback recommendations based on platform popularity.
     */
    public function getTrendingCars($limit = 10, $excludeListingId = null) {
        $limit = max(1, min(30, (int)$limit));
        $params = [];

        $excludeSql = '';
        if ($excludeListingId) {
            $excludeSql = ' AND cl.id != ?';
            $params[] = (int)$excludeListingId;
        }

        $sql = "
            SELECT
                cl.*,
                cm.name AS make_name,
                cmo.name AS model_name,
                cmo.body_type AS body_type,
                loc.name AS location_name,
                COALESCE(vh.total_views, 0) + COALESCE(gvh.total_views, 0) AS total_views,
                COALESCE(vh.unique_views, 0) + COALESCE(gvh.unique_views, 0) AS unique_viewers
            FROM car_listings cl
            JOIN car_makes cm ON cl.make_id = cm.id
            JOIN car_models cmo ON cl.model_id = cmo.id
            JOIN locations loc ON cl.location_id = loc.id
            LEFT JOIN (
                SELECT listing_id, COUNT(*) AS total_views, COUNT(DISTINCT user_id) AS unique_views
                FROM viewing_history
                WHERE last_viewed > DATE_SUB(NOW(), INTERVAL 10 DAY)
                GROUP BY listing_id
            ) vh ON vh.listing_id = cl.id
            LEFT JOIN (
                SELECT listing_id, COUNT(*) AS total_views, COUNT(DISTINCT session_id) AS unique_views
                FROM guest_viewing_history
                WHERE last_viewed > DATE_SUB(NOW(), INTERVAL 10 DAY)
                GROUP BY listing_id
            ) gvh ON gvh.listing_id = cl.id
            WHERE cl.status = 'active'
            AND cl.approval_status = 'approved'
            {$excludeSql}
            ORDER BY total_views DESC, unique_viewers DESC, cl.created_at DESC
            LIMIT {$limit}
        ";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($rows)) {
                return $rows;
            }
        } catch (Exception $e) {
            // Continue to newest fallback.
        }

        try {
            $fallbackSql = "
                SELECT
                    cl.*,
                    cm.name AS make_name,
                    cmo.name AS model_name,
                    cmo.body_type AS body_type,
                    loc.name AS location_name
                FROM car_listings cl
                JOIN car_makes cm ON cl.make_id = cm.id
                JOIN car_models cmo ON cl.model_id = cmo.id
                JOIN locations loc ON cl.location_id = loc.id
                WHERE cl.status = 'active'
                AND cl.approval_status = 'approved'
                {$excludeSql}
                ORDER BY cl.created_at DESC
                LIMIT {$limit}
            ";

            $stmt = $this->db->prepare($fallbackSql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('getTrendingCars failed: ' . $e->getMessage());
            return [];
        }
    }
}

// -----------------------------------------------------------------------------
// Endpoint handler
// -----------------------------------------------------------------------------

try {
    $rawInput = file_get_contents('php://input');
    $jsonInput = json_decode($rawInput, true);
    if (!is_array($jsonInput)) {
        $jsonInput = [];
    }

    $action = $_GET['action'] ?? $_POST['action'] ?? ($jsonInput['action'] ?? '');
    if (!$action) {
        sendError('No action specified', 400);
    }

    $sessionId = trim((string)($_GET['session_id'] ?? $_POST['session_id'] ?? ($jsonInput['session_id'] ?? '')));
    if ($sessionId === '') {
        $sessionId = null;
    }

    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    $engine = new RecommendationEngine(getDB(), $userId, $sessionId);

    switch ($action) {
        case 'get_recommendations':
            $type = strtolower((string)($_GET['type'] ?? $_POST['type'] ?? ($jsonInput['type'] ?? 'personalized')));
            $limit = (int)($_GET['limit'] ?? $_POST['limit'] ?? ($jsonInput['limit'] ?? 10));
            $excludeListingId = (int)($_GET['exclude_listing_id'] ?? $_POST['exclude_listing_id'] ?? ($jsonInput['exclude_listing_id'] ?? 0));
            if ($excludeListingId <= 0) {
                $excludeListingId = null;
            }

            if ($type === 'trending') {
                $recommendations = $engine->getTrendingCars($limit, $excludeListingId);
            } else {
                $recommendations = $engine->getPersonalizedRecommendations($limit, $excludeListingId);
                if (empty($recommendations)) {
                    $recommendations = $engine->getTrendingCars($limit, $excludeListingId);
                }
                $type = 'personalized';
            }

            sendSuccess([
                'recommendations' => $recommendations,
                'meta' => [
                    'type' => $type,
                    'count' => count($recommendations),
                    'session_tracking' => $sessionId ? true : false
                ]
            ]);
            break;

        case 'track_view':
            $listingId = (int)($_GET['listing_id'] ?? $_POST['listing_id'] ?? ($jsonInput['listing_id'] ?? 0));
            if ($listingId <= 0) {
                sendError('Missing listing_id', 400);
            }

            $tracked = $engine->trackView($listingId, $sessionId);

            $preferences = $jsonInput['preferences'] ?? $_POST['preferences'] ?? null;
            if (is_string($preferences)) {
                $decoded = json_decode($preferences, true);
                if (is_array($decoded)) {
                    $preferences = $decoded;
                }
            }

            if (is_array($preferences) && !empty($preferences)) {
                $engine->storePreferences($preferences, $sessionId);
            }

            sendSuccess([
                'message' => 'View tracking processed',
                'tracked' => $tracked
            ]);
            break;

        case 'store_preferences':
            $preferences = $jsonInput['preferences'] ?? $_POST['preferences'] ?? null;
            if (is_string($preferences)) {
                $decoded = json_decode($preferences, true);
                if (is_array($decoded)) {
                    $preferences = $decoded;
                }
            }

            if (!is_array($preferences) || empty($preferences)) {
                sendError('Missing preferences payload', 400);
            }

            $saved = $engine->storePreferences($preferences, $sessionId);
            sendSuccess([
                'message' => 'Preferences processed',
                'saved' => $saved
            ]);
            break;

        default:
            sendError('Invalid action: ' . $action, 400);
    }
} catch (Exception $e) {
    error_log('Recommendation engine fatal error: ' . $e->getMessage());
    sendError('Internal server error', 500);
}
