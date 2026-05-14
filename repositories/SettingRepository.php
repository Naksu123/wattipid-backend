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
        return $stmt->execute([$id]);
    }

    public function getTips() {
        $stmt = $this->conn->query("SELECT id, title, message, category, icon, is_active AS isActive, views_count AS viewsCount, likes_count AS likesCount, created_at FROM electricity_tips ORDER BY created_at DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
