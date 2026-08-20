<?php
require_once __DIR__ . '/../services/SettingService.php';
require_once __DIR__ . '/../helpers/ResponseHelper.php';

class SettingController {
    private $settingService;

    public function __construct($dbConnection) {
        $this->settingService = new SettingService($dbConnection);
    }

    public function getSetting($data) {
        $key = $data['key'] ?? null;
        if (!$key) ResponseHelper::error("Setting key is required", 400);
        $result = $this->settingService->getSetting($key);
        ResponseHelper::sendRaw($result);
    }

    public function getMultipleSettings($data) {
        $keys = $data['keys'] ?? [];
        $result = $this->settingService->getMultipleSettings($keys);
        ResponseHelper::sendRaw($result);
    }

    public function updateSetting($authenticatedUser, $data) {
        if (($authenticatedUser['role'] ?? '') !== 'landlord') {
            ResponseHelper::error("Unauthorized. Only landlords can update settings.", 403);
        }
        $key = $data['key'] ?? null;
        $value = $data['value'] ?? null;
        if (!$key || $value === null) ResponseHelper::error("Key and Value are required", 400);
        $result = $this->settingService->updateSetting($key, $value);
        ResponseHelper::sendRaw($result);
    }

    public function addTip($authenticatedUser, $data) {
        if (($authenticatedUser['role'] ?? '') !== 'landlord') {
            ResponseHelper::error("Unauthorized", 403);
        }
        $result = $this->settingService->addTip($data['category'], $data['title'], $data['message'], $data['icon'] ?? 'bulb-outline');
        ResponseHelper::sendRaw($result);
    }

    public function updateTip($authenticatedUser, $data) {
        if (($authenticatedUser['role'] ?? '') !== 'landlord') {
            ResponseHelper::error("Unauthorized", 403);
        }
        $result = $this->settingService->updateTip($data['id'], $data['category'], $data['title'], $data['message'], $data['icon'], $data['isActive'] ?? 1);
        ResponseHelper::sendRaw($result);
    }

    public function deleteTip($authenticatedUser, $data) {
        if (($authenticatedUser['role'] ?? '') !== 'landlord') {
            ResponseHelper::error("Unauthorized", 403);
        }
        $result = $this->settingService->deleteTip($data['id']);
        ResponseHelper::sendRaw($result);
    }

    public function likeTip($data) {
        $result = $this->settingService->likeTip($data['id']);
        ResponseHelper::sendRaw($result);
    }

    public function viewTip($authenticatedUser, $data) {
        $userId = $authenticatedUser['id'] ?? null;
        $result = $this->settingService->viewTip($data['id'] ?? null, $userId);
        ResponseHelper::sendRaw($result);
    }

    public function getSmartRecommendation($authenticatedUser, $data) {
        $userId = $authenticatedUser['id'] ?? 0;
        $excludeIds = $data['exclude_ids'] ?? [];
        $lastCategory = $data['last_category'] ?? null;
        $recentCategories = $data['recent_categories'] ?? [];
        $relevantCategories = $data['relevant_categories'] ?? [];
        $result = $this->settingService->getSmartRecommendation($userId, $excludeIds, $lastCategory, $recentCategories, $relevantCategories);
        ResponseHelper::sendRaw($result);
    }

    public function getSmartRecommendationsBatch($authenticatedUser, $data) {
        $userId = $authenticatedUser['id'] ?? 0;
        $count = $data['count'] ?? 3;
        $excludeIds = $data['exclude_ids'] ?? [];
        $lastCategory = $data['last_category'] ?? null;
        $recentCategories = $data['recent_categories'] ?? [];
        $relevantCategories = $data['relevant_categories'] ?? [];
        $result = $this->settingService->getSmartRecommendationsBatch($userId, $count, $excludeIds, $lastCategory, $recentCategories, $relevantCategories);
        ResponseHelper::sendRaw($result);
    }

    public function getTipOfTheDay($data = []) {
        $excludeIds = $data['exclude_ids'] ?? [];
        $result = $this->settingService->getTipOfTheDay($excludeIds);
        ResponseHelper::sendRaw($result);
    }

    public function getTrendingTips($data) {
        $limit = $data['limit'] ?? 5;
        $excludeIds = $data['exclude_ids'] ?? [];
        $result = $this->settingService->getTrendingTips($limit, $excludeIds);
        ResponseHelper::sendRaw($result);
    }

    public function getTips() {
        $result = $this->settingService->getTips();
        ResponseHelper::sendRaw($result);
    }
}