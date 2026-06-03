<?php

class PaymentController {
    private $db;

    public function __construct($dbConnection) {
        $this->db = $dbConnection;
    }

    public function submitPayment($authenticatedUser, $data) {
        if (!$authenticatedUser) {
            echo json_encode(["success" => false, "message" => "Unauthorized"]);
            return;
        }

        $billingCycleId = $data['billingCycleId'] ?? null;
        $roomId = $data['roomId'] ?? null;
        $amount = $data['amount'] ?? 0;
        $proofUrl = $data['proofUrl'] ?? null; // In a real system, handle multipart file upload. For now, we accept a URL or base64.
        $referenceNumber = $data['referenceNumber'] ?? null;

        if (!$billingCycleId || !$roomId || $amount <= 0 || !$proofUrl) {
            echo json_encode(["success" => false, "message" => "Missing required payment fields"]);
            return;
        }

        try {
            $this->db->beginTransaction();

            // Insert into payments
            $stmt = $this->db->prepare("INSERT INTO payments (billing_cycle_id, room_id, tenant_id, amount, payment_method, reference_number, proof_url, status) VALUES (?, ?, ?, ?, 'online', ?, ?, 'pending')");
            $stmt->execute([$billingCycleId, $roomId, $authenticatedUser['id'], $amount, $referenceNumber, $proofUrl]);
            $paymentId = $this->db->lastInsertId();

            // Update billing_cycles
            $stmt2 = $this->db->prepare("UPDATE billing_cycles SET payment_status = 'pending_verification' WHERE id = ?");
            $stmt2->execute([$billingCycleId]);

            // Audit Log
            $this->logAudit($authenticatedUser['id'], 'tenant', 'submit_payment', 'payments', $paymentId, null, "Amount: $amount");

            $this->db->commit();
            echo json_encode(["success" => true, "message" => "Payment submitted and pending verification"]);
        } catch (Exception $e) {
            $this->db->rollBack();
            echo json_encode(["success" => false, "message" => "Failed to submit payment: " . $e->getMessage()]);
        }
    }

    public function verifyPayment($authenticatedUser, $data) {
        if (!$authenticatedUser || $authenticatedUser['role'] !== 'landlord') {
            echo json_encode(["success" => false, "message" => "Unauthorized. Only landlords can verify payments."]);
            return;
        }

        $paymentId = $data['paymentId'] ?? null;
        $actionType = $data['action_type'] ?? null; // 'approve' or 'reject'
        $reason = $data['reason'] ?? null;

        if (!$paymentId || !in_array($actionType, ['approve', 'reject'])) {
            echo json_encode(["success" => false, "message" => "Invalid verification request"]);
            return;
        }

        if ($actionType === 'reject' && empty($reason)) {
            echo json_encode(["success" => false, "message" => "Rejection reason is required"]);
            return;
        }

        try {
            $this->db->beginTransaction();

            // Get payment info
            $stmt = $this->db->prepare("SELECT * FROM payments WHERE id = ? FOR UPDATE");
            $stmt->execute([$paymentId]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$payment || $payment['status'] !== 'pending') {
                throw new Exception("Payment not found or not in pending state");
            }

            if ($actionType === 'approve') {
                $stmt2 = $this->db->prepare("UPDATE payments SET status = 'verified', verified_by = ?, paid_at = NOW() WHERE id = ?");
                $stmt2->execute([$authenticatedUser['id'], $paymentId]);

                $stmt3 = $this->db->prepare("UPDATE billing_cycles SET payment_status = 'paid' WHERE id = ?");
                $stmt3->execute([$payment['billing_cycle_id']]);

                $this->logAudit($authenticatedUser['id'], 'landlord', 'approve_payment', 'payments', $paymentId, 'pending', 'verified');
            } else {
                $stmt2 = $this->db->prepare("UPDATE payments SET status = 'rejected', verified_by = ?, rejection_reason = ? WHERE id = ?");
                $stmt2->execute([$authenticatedUser['id'], $reason, $paymentId]);

                // Revert billing cycle back to unpaid (or overdue if past date)
                $stmt3 = $this->db->prepare("UPDATE billing_cycles SET payment_status = IF(due_date < NOW(), 'overdue', 'unpaid') WHERE id = ?");
                $stmt3->execute([$payment['billing_cycle_id']]);

                $this->logAudit($authenticatedUser['id'], 'landlord', 'reject_payment', 'payments', $paymentId, 'pending', 'rejected: ' . $reason);
            }

            $this->db->commit();
            echo json_encode(["success" => true, "message" => "Payment successfully $actionType" . "d"]);
        } catch (Exception $e) {
            $this->db->rollBack();
            echo json_encode(["success" => false, "message" => "Failed to verify payment: " . $e->getMessage()]);
        }
    }

    public function getPaymentHistory($authenticatedUser, $data) {
        $roomId = $data['roomId'] ?? null;
        $status = $data['status'] ?? null; // pending, verified, rejected
        $limit = $data['limit'] ?? 50;

        $query = "SELECT p.*, bc.due_date, bc.penalty_amount, bc.total_cost, u.name as tenant_name 
                  FROM payments p 
                  JOIN billing_cycles bc ON p.billing_cycle_id = bc.id 
                  LEFT JOIN users u ON p.tenant_id = u.id 
                  WHERE 1=1";
        $params = [];

        if ($roomId) {
            $query .= " AND p.room_id = ?";
            $params[] = $roomId;
        }

        if ($status) {
            $query .= " AND p.status = ?";
            $params[] = $status;
        }
        
        if ($authenticatedUser['role'] === 'tenant') {
            $query .= " AND p.tenant_id = ?";
            $params[] = $authenticatedUser['id'];
        }

        $query .= " ORDER BY p.created_at DESC LIMIT ?";
        $params[] = (int)$limit;

        $stmt = $this->db->prepare($query);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key + 1, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(["success" => true, "data" => $history]);
    }

    public function getPaymentWidgets($authenticatedUser, $data) {
        if ($authenticatedUser['role'] !== 'landlord') {
            echo json_encode(["success" => false, "message" => "Unauthorized"]);
            return;
        }

        // 1. Pending Payments Count
        $stmt1 = $this->db->query("SELECT COUNT(*) as pending_count FROM payments WHERE status = 'pending'");
        $pendingCount = $stmt1->fetch(PDO::FETCH_ASSOC)['pending_count'];

        // 2. Total Collected (Verified) this month
        $stmt2 = $this->db->query("SELECT SUM(amount) as total_collected FROM payments WHERE status = 'verified' AND MONTH(paid_at) = MONTH(CURRENT_DATE()) AND YEAR(paid_at) = YEAR(CURRENT_DATE())");
        $totalCollected = $stmt2->fetch(PDO::FETCH_ASSOC)['total_collected'] ?? 0;

        // 3. Overdue Amount (from billing_cycles)
        $stmt3 = $this->db->query("SELECT SUM(total_cost + penalty_amount) as total_overdue, COUNT(*) as overdue_count FROM billing_cycles WHERE payment_status = 'overdue'");
        $overdueData = $stmt3->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            "success" => true,
            "data" => [
                "pending_verifications" => (int)$pendingCount,
                "total_collected_month" => (float)$totalCollected,
                "total_overdue" => (float)($overdueData['total_overdue'] ?? 0),
                "overdue_count" => (int)($overdueData['overdue_count'] ?? 0)
            ]
        ]);
    }

    public function processPenalties($data) {
        // In a real production app, this would be a secured CRON job endpoint.
        // It calculates a flat 2% daily penalty for any overdue unpaid billing cycles.
        
        try {
            $this->db->beginTransaction();

            // 1. Find all cycles that are past due_date and unpaid/overdue
            $stmt = $this->db->query("SELECT id, total_cost, due_date FROM billing_cycles WHERE (payment_status = 'unpaid' OR payment_status = 'overdue') AND due_date < NOW()");
            $overdueCycles = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $processedCount = 0;
            foreach ($overdueCycles as $cycle) {
                // Calculate days late
                $dueDate = new DateTime($cycle['due_date']);
                $today = new DateTime();
                $daysLate = $today->diff($dueDate)->days;

                if ($daysLate > 0) {
                    // 2% of original total_cost per day late
                    $dailyPenalty = $cycle['total_cost'] * 0.02;
                    $totalPenalty = $dailyPenalty * $daysLate;

                    $updateStmt = $this->db->prepare("UPDATE billing_cycles SET payment_status = 'overdue', penalty_amount = ? WHERE id = ?");
                    $updateStmt->execute([$totalPenalty, $cycle['id']]);
                    $processedCount++;
                }
            }

            $this->db->commit();
            echo json_encode(["success" => true, "message" => "Processed penalties for $processedCount overdue invoices."]);
        } catch (Exception $e) {
            $this->db->rollBack();
            echo json_encode(["success" => false, "message" => "Failed to process penalties: " . $e->getMessage()]);
        }
    }

    public function submitOfflinePayment($authenticatedUser, $data) {
        if (!$authenticatedUser || $authenticatedUser['role'] !== 'landlord') {
            echo json_encode(["success" => false, "message" => "Unauthorized. Only landlords can process offline payments."]);
            return;
        }

        $billingCycleId = $data['billingCycleId'] ?? null;
        $roomId = $data['roomId'] ?? null;
        $amount = $data['amount'] ?? 0;

        if (!$billingCycleId || !$roomId || $amount <= 0) {
            echo json_encode(["success" => false, "message" => "Missing or invalid payment details"]);
            return;
        }

        try {
            $this->db->beginTransaction();

            // Check if billing cycle is already paid
            $stmtCheck = $this->db->prepare("SELECT payment_status FROM billing_cycles WHERE id = ? FOR UPDATE");
            $stmtCheck->execute([$billingCycleId]);
            $cycle = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if (!$cycle) {
                throw new Exception("Billing cycle not found");
            }
            if ($cycle['payment_status'] === 'paid') {
                throw new Exception("This billing cycle is already paid");
            }

            // Insert into payments as verified cash payment
            $stmt = $this->db->prepare("INSERT INTO payments (billing_cycle_id, room_id, tenant_id, amount, payment_method, reference_number, status, verified_by, paid_at) VALUES (?, ?, ?, ?, 'cash', 'OFFLINE-CASH', 'verified', ?, NOW())");
            
            // We need a tenant_id, just get it from the room's current tenant if any
            $stmtTenant = $this->db->prepare("SELECT tenant_id FROM rooms WHERE room_id = ?");
            $stmtTenant->execute([$roomId]);
            $tenantData = $stmtTenant->fetch(PDO::FETCH_ASSOC);
            $tenantId = $tenantData ? $tenantData['tenant_id'] : null;

            $stmt->execute([
                $billingCycleId,
                $roomId,
                $tenantId,
                $amount,
                $authenticatedUser['id']
            ]);
            $paymentId = $this->db->lastInsertId();

            // Update billing cycle to paid
            $stmt2 = $this->db->prepare("UPDATE billing_cycles SET payment_status = 'paid' WHERE id = ?");
            $stmt2->execute([$billingCycleId]);

            $this->logAudit($authenticatedUser['id'], 'landlord', 'offline_payment', 'payments', $paymentId, 'none', 'verified');

            $this->db->commit();
            echo json_encode(["success" => true, "message" => "Payment successfully recorded and verified as cash"]);
        } catch (Exception $e) {
            $this->db->rollBack();
            echo json_encode(["success" => false, "message" => "Failed to process offline payment: " . $e->getMessage()]);
        }
    }

    private function logAudit($actorId, $role, $action, $table, $recordId, $oldVal, $newVal) {
        $stmt = $this->db->prepare("INSERT INTO financial_audit_logs (actor_id, actor_role, action_type, table_affected, record_id, old_value, new_value, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $stmt->execute([$actorId, $role, $action, $table, $recordId, $oldVal, $newVal, $ip]);
    }
}
