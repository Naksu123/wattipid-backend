<?php
require_once __DIR__ . '/../repositories/SettingRepository.php';

class SettingService {
    private $settingRepo;

    public function __construct($dbConnection) {
        $this->settingRepo = new SettingRepository($dbConnection);
    }

    public function getSetting($key) {
        $val = $this->settingRepo->getSetting($key);
        return ['success' => true, 'data' => $val];
    }

    public function getMultipleSettings($keys) {
        $res = $this->settingRepo->getMultipleSettings($keys);
        return ['success' => true, 'data' => $res];
    }

    public function updateSetting($key, $value) {
        if ($this->settingRepo->updateSetting($key, $value)) {
            return ['success' => true, 'message' => 'Setting updated successfully'];
        }
        return ['success' => false, 'message' => 'Failed to update setting'];
    }

    public function addTip($category, $title, $message, $icon = 'bulb-outline') {
        if ($this->settingRepo->addTip($category, $title, $message, $icon)) {
            return ['success' => true, 'message' => 'Tip added successfully'];
        }
        return ['success' => false, 'message' => 'Failed to add tip'];
    }

    public function updateTip($id, $category, $title, $message, $icon, $isActive) {
        if ($this->settingRepo->updateTip($id, $category, $title, $message, $icon, $isActive)) {
            return ['success' => true, 'message' => 'Tip updated successfully'];
        }
        return ['success' => false, 'message' => 'Failed to update tip'];
    }

    public function deleteTip($id) {
        if ($this->settingRepo->deleteTip($id)) {
            return ['success' => true, 'message' => 'Tip deleted successfully'];
        }
        return ['success' => false, 'message' => 'Failed to delete tip'];
    }

    public function likeTip($id) {
        $likes = $this->settingRepo->likeTip($id);
        if ($likes !== false) {
            return ['success' => true, 'message' => 'Tip liked', 'likes' => (int)$likes];
        }
        return ['success' => false, 'message' => 'Failed to like tip'];
    }

    public function viewTip($id, $userId = null) {
        $this->settingRepo->viewTip($id);
        if ($userId) {
            $this->settingRepo->logTipView($userId, $id);
        }
        return ['success' => true, 'message' => 'Tip view logged successfully'];
    }

    /**
     * Smart Recommendation: Returns a fresh, non-repeating, diverse tip
     */
    public function getSmartRecommendation($userId, $excludeIds = [], $lastCategory = null, $recentCategories = [], $relevantCategories = []) {
        $tip = $this->settingRepo->getSmartRecommendation($userId, $excludeIds, $lastCategory, $recentCategories, $relevantCategories);
        if ($tip) {
            return ['success' => true, 'data' => $tip];
        }
        $allTips = $this->settingRepo->getTips();
        $activeTips = array_filter($allTips, fn($t) => $t['isActive'] == 1);
        if (!empty($activeTips)) {
            $tip = $activeTips[array_rand($activeTips)];
            return ['success' => true, 'data' => $tip];
        }
        return ['success' => false, 'message' => 'No tips available'];
    }

    /**
     * Batch Smart Recommendations: Returns multiple diverse, non-repeating tips in one call
     */
    public function getSmartRecommendationsBatch($userId, $count = 3, $excludeIds = [], $lastCategory = null, $recentCategories = [], $relevantCategories = []) {
        $tips = $this->settingRepo->getSmartRecommendationsBatch($userId, $count, $excludeIds, $lastCategory, $recentCategories, $relevantCategories);
        return ['success' => true, 'data' => $tips];
    }

    /**
     * Tip of the Day: Deterministic per calendar day with cross-section exclusion
     */
    public function getTipOfTheDay($excludeIds = []) {
        $tip = $this->settingRepo->getTipOfTheDay($excludeIds);
        if ($tip) {
            return ['success' => true, 'data' => $tip];
        }
        return ['success' => false, 'message' => 'No tips available'];
    }

    /**
     * Trending Tips: Most engaged tips with cross-section exclusion
     */
    public function getTrendingTips($limit = 5, $excludeIds = []) {
        $tips = $this->settingRepo->getTrendingTips($limit, $excludeIds);
        return ['success' => true, 'data' => $tips];
    }

    public function getTips() {
        return ['success' => true, 'data' => $this->settingRepo->getTips()];
    }
}