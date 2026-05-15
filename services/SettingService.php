<?php
require_once __DIR__ . '/../repositories/SettingRepository.php';

class SettingService {
    private $settingRepo;

    public function __construct($dbConnection) {
        $this->settingRepo = new SettingRepository($dbConnection);
    }

    public function getSetting($key) {
        return ['success' => true, 'data' => $this->settingRepo->getSetting($key)];
    }

    public function updateSetting($key, $value) {
        $this->settingRepo->updateSetting($key, $value);
        return ['success' => true];
    }

    public function addTip($category, $title, $message, $icon = 'bulb-outline') {
        $this->settingRepo->addTip($category, $title, $message, $icon);
        return ['success' => true];
    }

    public function updateTip($id, $category, $title, $message, $icon, $is_active) {
        $this->settingRepo->updateTip($id, $category, $title, $message, $icon, $is_active);
        return ['success' => true];
    }

    public function deleteTip($id) {
        $this->settingRepo->deleteTip($id);
        return ['success' => true];
    }

    public function likeTip($id) {
        $newCount = $this->settingRepo->likeTip($id);
        if ($newCount !== false) {
            return [
                'success' => true, 
                'message' => 'Tip liked successfully', 
                'data' => ['likes_count' => (int)$newCount, 'tip_id' => (int)$id, 'liked' => true]
            ];
        }
        return ['success' => false, 'message' => 'Failed to like tip'];
    }

    public function viewTip($id, $userId = null) {
        if (!$id) return ['success' => false, 'message' => 'ID required'];
        $this->settingRepo->viewTip($id);
        // Also log per-user view for smart recommendations
        if ($userId) {
            $this->settingRepo->logTipView($userId, $id);
        }
        return ['success' => true, 'message' => 'Tip view logged successfully'];
    }

    /**
     * Smart Recommendation: Returns a fresh, non-repeating tip for the user
     */
    public function getSmartRecommendation($userId, $excludeIds = [], $lastCategory = null) {
        $tip = $this->settingRepo->getSmartRecommendation($userId, $excludeIds, $lastCategory);
        if ($tip) {
            // Log this view automatically
            $this->settingRepo->viewTip($tip['id']);
            if ($userId > 0) {
                $this->settingRepo->logTipView($userId, $tip['id']);
            }
            return ['success' => true, 'data' => $tip];
        }
        // Fallback: if all tips have been seen, return any random active tip
        $allTips = $this->settingRepo->getTips();
        $activeTips = array_filter($allTips, fn($t) => $t['isActive'] == 1);
        if (!empty($activeTips)) {
            $tip = $activeTips[array_rand($activeTips)];
            return ['success' => true, 'data' => $tip];
        }
        return ['success' => false, 'message' => 'No tips available'];
    }

    /**
     * Tip of the Day: Same tip for all users on a given calendar day
     */
    public function getTipOfTheDay() {
        $tip = $this->settingRepo->getTipOfTheDay();
        if ($tip) {
            return ['success' => true, 'data' => $tip];
        }
        return ['success' => false, 'message' => 'No tips available'];
    }

    /**
     * Trending Tips: Most engaged tips
     */
    public function getTrendingTips($limit = 5) {
        $tips = $this->settingRepo->getTrendingTips($limit);
        return ['success' => true, 'data' => $tips];
    }

    public function getTips() {
        return ['success' => true, 'data' => $this->settingRepo->getTips()];
    }
}
