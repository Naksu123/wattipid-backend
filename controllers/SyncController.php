<?php
require_once __DIR__ . '/../helpers/ResponseHelper.php';
require_once __DIR__ . '/../services/RoomService.php';
require_once __DIR__ . '/../services/DashboardSyncService.php';

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

        if (!$roomId && $authenticatedUser['role'] === 'tenant') {
            ResponseHelper::error("Room ID required for tenant", 400);
            return;
        }

        $lastSync = $data['last_sync_timestamp'] ?? '2000-01-01 00:00:00';

        try {
            $hasUpdates = false;
            
            // 1. Check for new Activities
            if ($roomId) {
                $stmtAct = $this->db->prepare("SELECT * FROM activity_logs WHERE room_id = ? AND created_at > ? ORDER BY created_at DESC LIMIT 10");
                $stmtAct->execute([$roomId, $lastSync]);
            } else {
                $stmtAct = $this->db->prepare("SELECT * FROM activity_logs WHERE created_at > ? ORDER BY created_at DESC LIMIT 10");
                $stmtAct->execute([$lastSync]);
            }
            $activities = $stmtAct->fetchAll(PDO::FETCH_ASSOC);

            // 2. Check for new Notifications for this user
            $stmtNotif = $this->db->prepare("SELECT * FROM notification_history WHERE user_id = ? AND created_at > ? AND is_read = 0");
            $stmtNotif->execute([$userId, $lastSync]);
            $newNotifsList = $stmtNotif->fetchAll(PDO::FETCH_ASSOC);
            $newNotifsCount = count($newNotifsList);

            // Authoritative total unread count for this user
            $stmtUnread = $this->db->prepare("SELECT COUNT(*) FROM notification_history WHERE user_id = ? AND is_read = 0");
            $stmtUnread->execute([$userId]);
            $unreadNotificationsCount = (int) $stmtUnread->fetchColumn();

            // 3. Check Billing Cycle changes (e.g. payment_status changed)
            // For simplicity, we just check if any billing cycle for the room was updated since last_sync
            // But wait, billing_cycles doesn't have an `updated_at` column. It has `created_at`.
            // Instead, we just fetch the active billing cycle payment status if they want.
            // Since this is a lightweight sync, we just tell the frontend if they need to refresh `fetchStaticData`.
            
            $hasUpdates = false;
            $triggerFullRefresh = false;

            if (count($activities) > 0 || $newNotifsCount > 0) {
                $hasUpdates = true;
            }

            // Only trigger full static refresh for actionable financial, billing, or penalty updates
            $actionableNotifTypes = [
                'payment_submitted',
                'payment_verified',
                'payment_rejected',
                'bill_overdue',
                'penalty_applied',
                'bill_generated',
                'invoice_created',
                'cycle_closed',
                'room_updated',
                'rate_updated'
            ];

            foreach ($newNotifsList as $notif) {
                $nType = $notif['type'] ?? '';
                $nCat = $notif['category'] ?? '';
                if (in_array($nType, $actionableNotifTypes) || $nCat === 'billing' || $nCat === 'payment') {
                    $triggerFullRefresh = true;
                    break;
                }
            }

            if (!$triggerFullRefresh) {
                foreach ($activities as $act) {
                    $aType = $act['type'] ?? '';
                    $title = $act['title'] ?? '';
                    $msg = $act['message'] ?? ($act['description'] ?? '');
                    if (in_array($aType, ['payment', 'billing', 'room', 'penalty']) || 
                        stripos($title, 'payment') !== false ||
                        stripos($title, 'invoice') !== false ||
                        stripos($title, 'bill') !== false ||
                        stripos($msg, 'payment') !== false ||
                        stripos($msg, 'invoice') !== false ||
                        stripos($msg, 'bill') !== false) {
                        $triggerFullRefresh = true;
                        break;
                    }
                }
            }

            $responsePayload = [
                'has_updates' => $hasUpdates,
                'trigger_full_refresh' => $triggerFullRefresh,
                'new_activities' => $activities,
                'new_notifications_count' => $newNotifsCount,
                'unread_notifications_count' => $unreadNotificationsCount,
                'new_notifications' => $newNotifsList,
                'server_timestamp' => date('Y-m-d H:i:s')
            ];

            // 4. Attach Landlord Real-Time Sync Data (Throttled)
            $requestLandlordData = isset($data['request_landlord_data']) && $data['request_landlord_data'];
            
            if (in_array($authenticatedUser['role'], ['landlord', 'admin']) && $requestLandlordData) {
                $roomService = new RoomService($this->db);
                $dashboardSyncService = new DashboardSyncService($this->db);
                
                $responsePayload['landlord_sync_data'] = [
                    'liveOverview' => $dashboardSyncService->getLiveOverview($authenticatedUser['id'], $authenticatedUser['role'])['data'] ?? null,
                    'roomsSummary' => $roomService->getBuildingSummary()['data'] ?? null
                ];
                $responsePayload['has_updates'] = true; // Always true for landlord real-time streaming
            }

            ResponseHelper::success($responsePayload);

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
