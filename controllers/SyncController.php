<?php
require_once __DIR__ . '/../helpers/ResponseHelper.php';

class SyncController {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function syncState($authenticatedUser, $data) {
        if (!$authenticatedUser) {
            ResponseHelper::error("Unauthorized", 401);
            return;
        }

        $roomId = $data['roomId'] ?? null;
        $userId = $authenticatedUser['id'];
        
        if (!$roomId && $authenticatedUser['role'] === 'tenant') {
            $roomId = $authenticatedUser['room_id'];
        }

        if (!$roomId) {
            ResponseHelper::error("Room ID required", 400);
            return;
        }

        $lastSync = $data['last_sync_timestamp'] ?? '2000-01-01 00:00:00';

        try {
            $hasUpdates = false;
            
            // 1. Check for new Activities
            $stmtAct = $this->db->prepare("SELECT * FROM activity_logs WHERE room_id = ? AND created_at > ? ORDER BY created_at DESC LIMIT 10");
            $stmtAct->execute([$roomId, $lastSync]);
            $activities = $stmtAct->fetchAll(PDO::FETCH_ASSOC);

            // 2. Check for new Notifications for this user
            $stmtNotif = $this->db->prepare("SELECT COUNT(*) FROM notification_history WHERE user_id = ? AND created_at > ? AND is_read = 0");
            $stmtNotif->execute([$userId, $lastSync]);
            $newNotifs = $stmtNotif->fetchColumn();

            // 3. Check Billing Cycle changes (e.g. payment_status changed)
            // For simplicity, we just check if any billing cycle for the room was updated since last_sync
            // But wait, billing_cycles doesn't have an `updated_at` column. It has `created_at`.
            // Instead, we just fetch the active billing cycle payment status if they want.
            // Since this is a lightweight sync, we just tell the frontend if they need to refresh `fetchStaticData`.
            
            $triggerFullRefresh = false;
            if (count($activities) > 0 || $newNotifs > 0) {
                $hasUpdates = true;
                $triggerFullRefresh = true; // Tell frontend to refetch dashboard static data
            }

            ResponseHelper::success([
                'has_updates' => $hasUpdates,
                'trigger_full_refresh' => $triggerFullRefresh,
                'new_activities' => $activities,
                'new_notifications_count' => $newNotifs,
                'server_timestamp' => date('Y-m-d H:i:s')
            ]);

        } catch (PDOException $e) {
            error_log("Sync Error: " . $e->getMessage());
            ResponseHelper::error("Failed to sync state", 500);
        }
    }

    public function registerPushToken($authenticatedUser, $data) {
        if (!$authenticatedUser) {
            ResponseHelper::error("Unauthorized", 401);
            return;
        }

        $token = $data['token'] ?? null;
        if (!$token) {
            ResponseHelper::error("Token required", 400);
            return;
        }

        try {
            $stmt = $this->db->prepare("UPDATE users SET expo_push_token = ? WHERE id = ?");
            $stmt->execute([$token, $authenticatedUser['id']]);
            
            ResponseHelper::success(["message" => "Push token registered successfully"]);
        } catch (PDOException $e) {
            error_log("Push Token Error: " . $e->getMessage());
            ResponseHelper::error("Failed to register token", 500);
        }
    }
}
