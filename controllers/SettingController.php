<?php
require_once __DIR__ . '/../services/SettingService.php';
require_once __DIR__ . '/../helpers/ResponseHelper.php';

class SettingController {
    private $settingService;

    public function __construct($dbConnection) {
        $this->settingService = new SettingService($dbConnection);
    }

    public function getSetting($data) {
        $result = $this->settingService->getSetting($data['key'] ?? '');
        ResponseHelper::sendRaw($result);
    }

    public function updateSetting($authenticatedUser, $data) {
        if ($authenticatedUser['role'] !== 'landlord') {
            ResponseHelper::error("Forbidden", 403);
        }
        $result = $this->settingService->updateSetting($data['key'], $data['value']);
        ResponseHelper::sendRaw($result);
    }

    public function addTip($authenticatedUser, $data) {
        if ($authenticatedUser['role'] !== 'landlord') {
            ResponseHelper::error("Forbidden", 403);
        }
        $result = $this->settingService->addTip($data['category'], $data['title'], $data['message'], $data['icon'] ?? 'bulb-outline');
        ResponseHelper::sendRaw($result);
    }

    public function updateTip($authenticatedUser, $data) {
        if ($authenticatedUser['role'] !== 'landlord') {
            ResponseHelper::error("Forbidden", 403);
        }
        $result = $this->settingService->updateTip(
            $data['id'], 
            $data['category'], 
            $data['title'], 
            $data['message'], 
            $data['icon'], 
            $data['is_active']
        );
        ResponseHelper::sendRaw($result);
    }

    public function deleteTip($authenticatedUser, $data) {
        if ($authenticatedUser['role'] !== 'landlord') {
            ResponseHelper::error("Forbidden", 403);
        }
        $result = $this->settingService->deleteTip($data['id']);
        ResponseHelper::sendRaw($result);
    }

    public function likeTip($data) {
        $result = $this->settingService->likeTip($data['id']);
        ResponseHelper::sendRaw($result);
    }

    public function getTips() {
        $result = $this->settingService->getTips();
        ResponseHelper::sendRaw($result);
    }
}
