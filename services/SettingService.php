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
        $this->settingRepo->likeTip($id);
        return ['success' => true];
    }

    public function getTips() {
        return ['success' => true, 'data' => $this->settingRepo->getTips()];
    }
}
