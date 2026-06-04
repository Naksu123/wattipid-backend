<?php
class AuditController {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function getSystemAuditLogs($authenticatedUser) {
        if ($authenticatedUser['role'] !== 'landlord') {
            echo json_encode(["success" => false, "message" => "Unauthorized access. Only landlords can view system audit logs."]);
            return;
        }

        try {
            // Fetch Financial Audit Logs
            $financialQuery = "SELECT id, 'financial' as log_type, action_type, table_affected, old_value, new_value, ip_address, created_at, actor_id
                               FROM financial_audit_logs 
                               ORDER BY created_at DESC LIMIT 50";
            $financialStmt = $this->db->query($financialQuery);
            $financialLogs = $financialStmt->fetchAll(PDO::FETCH_ASSOC);

            // Fetch General Activity Logs
            $activityQuery = "SELECT id, 'activity' as log_type, type as action_type, title as table_affected, NULL as old_value, message as new_value, NULL as ip_address, created_at, user_id as actor_id
                              FROM activity_logs 
                              ORDER BY created_at DESC LIMIT 50";
            $activityStmt = $this->db->query($activityQuery);
            $activityLogs = $activityStmt->fetchAll(PDO::FETCH_ASSOC);

            // Combine and sort by date descending
            $allLogs = array_merge($financialLogs, $activityLogs);
            usort($allLogs, function($a, $b) {
                return strtotime($b['created_at']) - strtotime($a['created_at']);
            });

            // Trim to top 100 most recent combined
            $allLogs = array_slice($allLogs, 0, 100);

            echo json_encode(["success" => true, "data" => $allLogs]);
        } catch (Exception $e) {
            echo json_encode(["success" => false, "message" => "Failed to fetch audit logs: " . $e->getMessage()]);
        }
    }
}
?>
