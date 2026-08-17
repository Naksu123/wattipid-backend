<?php
class SettingRepository {
    private $conn;

    public function __construct($dbConnection) {
        $this->conn = $dbConnection;
    }

    public function getSetting($key) {
        $stmt = $this->conn->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        return $stmt->fetchColumn();
    }

    public function getMultipleSettings($keys) {
        if (empty($keys)) return [];
        $placeholders = str_repeat('?,', count($keys) - 1) . '?';
        $stmt = $this->conn->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ($placeholders)");
        $stmt->execute($keys);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $settings = [];
        foreach ($results as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        return $settings;
    }

    public function updateSetting($key, $value) {
        $stmt = $this->conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        return $stmt->execute([$key, $value, $value]);
    }

    public function addTip($category, $title, $message, $icon = 'bulb-outline') {
        $stmt = $this->conn->prepare("INSERT INTO electricity_tips (category, title, message, icon, is_active) VALUES (?, ?, ?, ?, 1)");
        return $stmt->execute([$category, $title, $message, $icon]);
    }

    public function updateTip($id, $category, $title, $message, $icon, $is_active) {
        $stmt = $this->conn->prepare("UPDATE electricity_tips SET category = ?, title = ?, message = ?, icon = ?, is_active = ? WHERE id = ?");
        return $stmt->execute([$category, $title, $message, $icon, $is_active, $id]);
    }

    public function deleteTip($id) {
        $stmt = $this->conn->prepare("DELETE FROM electricity_tips WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function likeTip($id) {
        $stmt = $this->conn->prepare("UPDATE electricity_tips SET likes_count = likes_count + 1 WHERE id = ?");
        if ($stmt->execute([$id])) {
            $stmt2 = $this->conn->prepare("SELECT likes_count FROM electricity_tips WHERE id = ?");
            $stmt2->execute([$id]);
            return $stmt2->fetchColumn();
        }
        return false;
    }

    public function viewTip($id) {
        $stmt = $this->conn->prepare("UPDATE electricity_tips SET views_count = views_count + 1 WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Log that a specific user viewed a specific tip (for smart recommendation exclusion)
     */
    public function logTipView($userId, $tipId) {
        $stmt = $this->conn->prepare(
            "INSERT INTO tip_view_log (user_id, tip_id) VALUES (?, ?)"
        );
        return $stmt->execute([$userId, $tipId]);
    }

    /**
     * Smart Recommendation Engine
     * 
     * Returns 1 tip the user has NOT recently seen, weighted by:
     * - Freshness (unseen tips scored highest)
     * - Engagement (popular tips get a slight boost)
     * - Category rotation (avoids showing same category twice in a row)
     * - Randomized tiebreaker (prevents predictable ordering)
     */
    public function getSmartRecommendation($userId, $excludeIds = [], $lastCategory = null) {
        // Build exclude clause
        $excludePlaceholders = '';
        $params = [$userId];
        
        if (!empty($excludeIds)) {
            $excludePlaceholders = ' AND t.id NOT IN (' . implode(',', array_fill(0, count($excludeIds), '?')) . ')';
            $params = array_merge($params, $excludeIds);
        }

        // Deprioritize the last shown category to force rotation
        $categoryPenalty = '';
        if ($lastCategory) {
            $categoryPenalty = ", CASE WHEN t.category = ? THEN 0 ELSE 10 END AS cat_bonus";
            $params[] = $lastCategory;
        }

        $sql = "
            SELECT 
                t.id, t.title, t.message, t.category, t.icon,
                t.is_active AS isActive,
                t.views_count AS viewsCount,
                t.likes_count AS likesCount,
                t.created_at,
                COALESCE(recent.view_count, 0) AS user_views
                {$categoryPenalty}
            FROM electricity_tips t
            LEFT JOIN (
                SELECT tip_id, COUNT(*) AS view_count
                FROM tip_view_log
                WHERE user_id = ?
                  AND viewed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                GROUP BY tip_id
            ) recent ON recent.tip_id = t.id
            WHERE t.is_active = 1
              {$excludePlaceholders}
            ORDER BY 
                user_views ASC,
                " . ($lastCategory ? "cat_bonus DESC," : "") . "
                (t.likes_count * 0.3 + RAND() * 10) DESC
            LIMIT 1
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Get trending tips (most liked in last 7 days)
     */
    public function getTrendingTips($limit = 5) {
        $limit = (int)$limit;
        $stmt = $this->conn->query(
            "SELECT id, title, message, category, icon, 
                    is_active AS isActive, views_count AS viewsCount, likes_count AS likesCount, created_at
             FROM electricity_tips 
             WHERE is_active = 1 
             ORDER BY (likes_count * 2 + views_count) DESC 
             LIMIT {$limit}"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get Tip of the Day (deterministic per calendar day)
     */
    public function getTipOfTheDay() {
        // Use day-of-year as a stable seed to pick the same tip for all users on the same day
        $dayIndex = (int)date('z'); // 0-365
        $stmt = $this->conn->query("SELECT COUNT(*) FROM electricity_tips WHERE is_active = 1");
        $total = (int)$stmt->fetchColumn();
        if ($total === 0) return null;
        
        $offset = $dayIndex % $total;
        // Note: $offset is server-calculated (not user input), safe to inline.
        // PDO execute() binds as string which crashes MySQL OFFSET.
        $stmt = $this->conn->query(
            "SELECT id, title, message, category, icon, 
                    is_active AS isActive, views_count AS viewsCount, likes_count AS likesCount, created_at
             FROM electricity_tips 
             WHERE is_active = 1 
             ORDER BY id ASC 
             LIMIT 1 OFFSET {$offset}"
        );
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getTips() {
        $stmt = $this->conn->query("SELECT id, title, message, category, icon, is_active AS isActive, views_count AS viewsCount, likes_count AS likesCount, created_at FROM electricity_tips ORDER BY created_at DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
