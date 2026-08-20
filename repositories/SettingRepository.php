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
        if (!$userId || !$tipId) return false;
        $stmt = $this->conn->prepare(
            "INSERT INTO tip_view_log (user_id, tip_id) VALUES (?, ?)"
        );
        return $stmt->execute([$userId, $tipId]);
    }

    /**
     * Smart Recommendation Engine
     * 
     * Returns 1 tip using the multi-factor scoring formula:
     * Final Score = Relevance + Unseen Bonus + Category Diversity + LRU Bonus + Rotation Tiebreaker - Recent Penalty
     */
    public function getSmartRecommendation($userId, $excludeIds = [], $lastCategory = null, $recentCategories = [], $relevantCategories = []) {
        $tips = $this->getSmartRecommendationsBatch($userId, 1, $excludeIds, $lastCategory, $recentCategories, $relevantCategories);
        return !empty($tips) ? $tips[0] : null;
    }

    /**
     * Batch Smart Recommendations Engine
     * 
     * Returns $count non-repeating, diverse, relevant tips in one call.
     */
    public function getSmartRecommendationsBatch($userId, $count = 1, $excludeIds = [], $lastCategory = null, $recentCategories = [], $relevantCategories = []) {
        $count = max(1, (int)$count);
        $userId = (int)$userId;

        // Fetch all active tips
        $stmt = $this->conn->query("SELECT id, title, message, category, icon, is_active AS isActive, views_count AS viewsCount, likes_count AS likesCount, created_at FROM electricity_tips WHERE is_active = 1");
        $allTips = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($allTips)) return [];

        // Fetch total view history and last view timestamp for this user per tip
        $userHistory = [];
        if ($userId > 0) {
            $stmtHist = $this->conn->prepare("SELECT tip_id, COUNT(*) as view_count, MAX(viewed_at) as last_viewed FROM tip_view_log WHERE user_id = ? GROUP BY tip_id");
            $stmtHist->execute([$userId]);
            while ($row = $stmtHist->fetch(PDO::FETCH_ASSOC)) {
                $userHistory[(int)$row['tip_id']] = [
                    'views' => (int)$row['view_count'],
                    'last_viewed' => strtotime($row['last_viewed'])
                ];
            }
        }

        // Recent exclusion set
        $excludeSet = array_flip(array_map('intval', (array)$excludeIds));
        $recentCategories = array_values(array_filter((array)$recentCategories));
        $relevantCategories = array_values(array_filter((array)$relevantCategories));
        $relevantSet = array_flip($relevantCategories);

        $now = time();
        $scoredTips = [];

        foreach ($allTips as $tip) {
            $tipId = (int)$tip['id'];
            $cat = $tip['category'];
            $views = $userHistory[$tipId]['views'] ?? 0;
            $lastViewed = $userHistory[$tipId]['last_viewed'] ?? 0;

            // 1. Base Score
            $score = 100.0;

            // 2. Behavior Relevance (+30 to +40 points)
            if (!empty($relevantSet)) {
                if (isset($relevantSet[$cat])) {
                    $score += 35.0;
                }
            }

            // 3. Unseen / Lifetime View Bonus (+50 for 0 views, decaying)
            if ($views === 0) {
                $score += 50.0;
            } else {
                $score += max(0, 30.0 - ($views * 6.0));
            }

            // 4. Category Diversity Bonus / Penalty
            if ($lastCategory && $cat === $lastCategory) {
                $score -= 25.0; // Avoid immediate category repeat
            }
            if (!empty($recentCategories)) {
                $catOccurrences = 0;
                foreach ($recentCategories as $rCat) {
                    if ($rCat === $cat) $catOccurrences++;
                }
                $score -= ($catOccurrences * 12.0);
            }

            // 5. Least-Recently-Used (LRU) bonus
            if ($lastViewed > 0) {
                $hoursSinceView = ($now - $lastViewed) / 3600.0;
                $score += min(20.0, $hoursSinceView * 0.5);
            } else {
                $score += 20.0; // Never viewed gets full LRU bonus
            }

            // 6. Hard Exclusion Penalty for recently displayed IDs
            if (isset($excludeSet[$tipId])) {
                $score -= 1000.0;
            }

            // 7. Controlled rotation tiebreaker (deterministic hash based on time epoch and tip ID)
            $rotationHash = (int)(($now / 60) + ($tipId * 17)) % 11;
            $score += ($rotationHash * 0.8) + (min(10, (int)$tip['likesCount']) * 0.3);

            $tip['score'] = $score;
            $scoredTips[] = $tip;
        }

        // Sort by final score descending
        usort($scoredTips, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        // Select $count tips, enforcing distinct categories among the batch
        $selected = [];
        $selectedCategories = [];

        foreach ($scoredTips as $candidate) {
            if (count($selected) >= $count) break;

            // Enforce internal batch category diversity if count > 1 and alternatives exist
            if ($count > 1 && in_array($candidate['category'], $selectedCategories) && count($scoredTips) > count($selected)) {
                $hasAlternative = false;
                foreach ($scoredTips as $alt) {
                    if (!in_array($alt['category'], $selectedCategories) && !in_array($alt['id'], array_column($selected, 'id')) && $alt['score'] > -500) {
                        $hasAlternative = true;
                        break;
                    }
                }
                if ($hasAlternative) continue;
            }

            $selected[] = $candidate;
            $selectedCategories[] = $candidate['category'];

            // Log view in database
            if ($userId > 0) {
                $this->logTipView($userId, $candidate['id']);
                $this->viewTip($candidate['id']);
            }
        }

        return $selected;
    }

    /**
     * Get trending tips (most liked/viewed), with cross-section exclusion
     */
    public function getTrendingTips($limit = 5, $excludeIds = []) {
        $limit = max(1, (int)$limit);
        $excludePlaceholders = '';
        $params = [];
        
        if (!empty($excludeIds)) {
            $excludePlaceholders = ' AND id NOT IN (' . implode(',', array_fill(0, count($excludeIds), '?')) . ')';
            $params = array_values(array_map('intval', (array)$excludeIds));
        }

        $sql = "SELECT id, title, message, category, icon, 
                       is_active AS isActive, views_count AS viewsCount, likes_count AS likesCount, created_at
                FROM electricity_tips 
                WHERE is_active = 1 {$excludePlaceholders}
                ORDER BY (likes_count * 2 + views_count) DESC, id ASC 
                LIMIT {$limit}";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get Tip of the Day (deterministic per calendar day), with cross-section exclusion
     */
    public function getTipOfTheDay($excludeIds = []) {
        $dayIndex = (int)date('z'); // 0-365
        
        $excludePlaceholders = '';
        $params = [];
        if (!empty($excludeIds)) {
            $excludePlaceholders = ' AND id NOT IN (' . implode(',', array_fill(0, count($excludeIds), '?')) . ')';
            $params = array_values(array_map('intval', (array)$excludeIds));
        }

        $stmt = $this->conn->prepare("SELECT COUNT(*) FROM electricity_tips WHERE is_active = 1 {$excludePlaceholders}");
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();
        if ($total === 0) return null;
        
        $offset = $dayIndex % $total;
        $sql = "SELECT id, title, message, category, icon, 
                       is_active AS isActive, views_count AS viewsCount, likes_count AS likesCount, created_at
                FROM electricity_tips 
                WHERE is_active = 1 {$excludePlaceholders}
                ORDER BY id ASC 
                LIMIT 1 OFFSET {$offset}";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getTips() {
        $stmt = $this->conn->query("SELECT id, title, message, category, icon, is_active AS isActive, views_count AS viewsCount, likes_count AS likesCount, created_at FROM electricity_tips ORDER BY created_at DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}