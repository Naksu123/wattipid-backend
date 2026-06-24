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
        $proofUrl = $data['proofUrl'] ?? null; // Optional for Cash
        $referenceNumber = $data['referenceNumber'] ?? null;
        $paymentMethod = $data['paymentMethod'] ?? 'Cash';
        $paymentDate = $data['paymentDate'] ?? date('Y-m-d H:i:s');

        if (!$billingCycleId || !$roomId || $amount <= 0) {
            echo json_encode(["success" => false, "message" => "Missing required payment fields"]);
            return;
        }

        if (in_array(strtolower($paymentMethod), ['gcash', 'maya'])) {
            if (empty($proofUrl)) {
                echo json_encode(["success" => false, "message" => "Proof of payment is required for e-wallets"]);
                return;
            }
            try {
                require_once __DIR__ . '/../utils/SecurityMiddleware.php';
                SecurityMiddleware::validateFileUpload($proofUrl);
            } catch (Exception $e) {
                echo json_encode(["success" => false, "message" => $e->getMessage()]);
                return;
            }
        }

        try {
            $this->db->beginTransaction();

            // Insert into payments
            $stmt = $this->db->prepare("INSERT INTO payments (billing_cycle_id, room_id, tenant_id, amount, payment_method, payment_date, reference_number, proof_url, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
            $stmt->execute([$billingCycleId, $roomId, $authenticatedUser['id'], $amount, $paymentMethod, $paymentDate, $referenceNumber, $proofUrl]);
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
                $actualAmount = isset($data['actual_amount']) ? (float)$data['actual_amount'] : (float)$payment['amount'];

                $stmt2 = $this->db->prepare("UPDATE payments SET status = 'verified', amount = ?, verified_by = ?, paid_at = NOW() WHERE id = ?");
                $stmt2->execute([$actualAmount, $authenticatedUser['id'], $paymentId]);

                // Calculate if this is a partial or full payment
                $stmt_bc = $this->db->prepare("SELECT * FROM billing_cycles WHERE id = ? FOR UPDATE");
                $stmt_bc->execute([$payment['billing_cycle_id']]);
                $bc = $stmt_bc->fetch(PDO::FETCH_ASSOC);

                $newAmountPaid = (float)$bc['amount_paid'] + $actualAmount;
                
                $grandTotal = (float)$bc['grand_total'];
                if ($grandTotal == 0) {
                     $grandTotal = (float)$bc['electricity_charge'] + (float)$bc['penalty_amount'] + (float)$bc['monthly_rent'] + (float)$bc['previous_balance'] + (float)$bc['additional_charges'] - (float)$bc['discounts'];
                }
                if ($grandTotal == 0) {
                     $grandTotal = (float)$bc['total_cost'] + (float)$bc['penalty_amount'];
                }

                $newStatus = ($newAmountPaid >= $grandTotal - 0.01) ? 'paid' : 'partially_paid'; // 0.01 margin for float errors

                $stmt3 = $this->db->prepare("UPDATE billing_cycles SET payment_status = ?, amount_paid = ? WHERE id = ?");
                $stmt3->execute([$newStatus, $newAmountPaid, $payment['billing_cycle_id']]);

                $this->logAudit($authenticatedUser['id'], 'landlord', 'approve_payment', 'payments', $paymentId, 'pending', "verified (amount: $actualAmount, status: $newStatus)");

                // Send Real-time Notification
                require_once __DIR__ . '/../services/BillingNotificationService.php';
                $notifSvc = new BillingNotificationService($this->db);
                $remainingBalance = max($grandTotal - $newAmountPaid, 0);
                $notifSvc->sendPaymentVerificationAlert($payment['room_id'], $payment['tenant_id'], $actualAmount, $newStatus, $payment['payment_method'], $remainingBalance);
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
        // Delegates to the PenaltyService for centralized penalty logic.
        // Applies a one-time flat penalty (configurable %) for any unpaid cycle past due date.
        
        require_once __DIR__ . '/../services/PenaltyService.php';
        $penaltySvc = new PenaltyService($this->db);
        $result = $penaltySvc->calculateDailyPenalties();
        echo json_encode($result);
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

    public function getBillingDetails($authenticatedUser, $data) {
        if (!$authenticatedUser) {
            echo json_encode(["success" => false, "message" => "Unauthorized"]);
            return;
        }
        $invoiceNumber = $data['invoiceNumber'] ?? null;
        $id = $data['id'] ?? null;
        $roomId = $data['roomId'] ?? null; // For validation

        if (!$invoiceNumber && !$id) {
            echo json_encode(["success" => false, "message" => "Missing invoice identifier"]);
            return;
        }

        $query = "SELECT * FROM billing_cycles WHERE ";
        $params = [];
        if ($invoiceNumber) {
            $query .= "invoice_number = ?";
            $params[] = $invoiceNumber;
        } else {
            $query .= "id = ?";
            $params[] = $id;
        }

        if ($roomId && $authenticatedUser['role'] === 'tenant') {
            $query .= " AND room_id = ?";
            $params[] = $roomId;
        }

        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        $billing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$billing) {
            echo json_encode(["success" => false, "message" => "Billing record not found"]);
            return;
        }
        
        // Fetch applied payments
        $stmtPayments = $this->db->prepare("SELECT amount, status, created_at, payment_method, paid_at, reference_number FROM payments WHERE billing_cycle_id = ? ORDER BY created_at DESC");
        $stmtPayments->execute([$billing['id']]);
        $payments = $stmtPayments->fetchAll(PDO::FETCH_ASSOC);
        
        $billing['payments'] = $payments;

        echo json_encode(["success" => true, "data" => $billing]);
    }

    public function getBillingHistory($authenticatedUser, $data) {
        if (!$authenticatedUser) {
            echo json_encode(["success" => false, "message" => "Unauthorized"]);
            return;
        }
        $roomId = $data['roomId'] ?? null;
        $limit = $data['limit'] ?? 20;
        $offset = $data['offset'] ?? 0;

        if (!$roomId && $authenticatedUser['role'] === 'tenant') {
            $roomId = $authenticatedUser['room_id'] ?? null;
        }
        
        if (!$roomId) {
            echo json_encode(["success" => false, "message" => "Room ID required"]);
            return;
        }

        $query = "
            SELECT bc.*,
                   (SELECT payment_method FROM payments WHERE billing_cycle_id = bc.id ORDER BY id DESC LIMIT 1) as payment_method,
                   (SELECT paid_at FROM payments WHERE billing_cycle_id = bc.id AND status = 'verified' ORDER BY id DESC LIMIT 1) as verification_date,
                   (SELECT u.name FROM payments p JOIN users u ON p.verified_by = u.id WHERE p.billing_cycle_id = bc.id AND p.status = 'verified' ORDER BY p.id DESC LIMIT 1) as verified_by_name
            FROM billing_cycles bc
            WHERE bc.room_id = ? AND bc.status = 'completed'
            ORDER BY bc.cycle_end DESC
            LIMIT ? OFFSET ?
        ";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(1, $roomId);
        $stmt->bindValue(2, (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(3, (int)$offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(["success" => true, "data" => $history]);
    }
    public function getPaymentInsights($authenticatedUser, $data) {
        if (!$authenticatedUser) {
            echo json_encode(["success" => false, "message" => "Unauthorized"]);
            return;
        }

        $roomId = $data['roomId'] ?? null;
        if (!$roomId && $authenticatedUser['role'] === 'tenant') {
            $roomId = $authenticatedUser['room_id'] ?? null;
        }

        if (!$roomId) {
            echo json_encode(["success" => false, "message" => "Room ID required"]);
            return;
        }

        try {
            // Total Paid All-Time
            $stmt = $this->db->prepare("SELECT SUM(amount) as total FROM payments WHERE room_id = ? AND status = 'verified'");
            $stmt->execute([$roomId]);
            $totalPaid = $stmt->fetchColumn() ?: 0;

            // Total Paid This Year
            $year = date('Y');
            $stmtYear = $this->db->prepare("SELECT SUM(amount) as total FROM payments WHERE room_id = ? AND status = 'verified' AND YEAR(paid_at) = ?");
            $stmtYear->execute([$roomId, $year]);
            $totalPaidThisYear = $stmtYear->fetchColumn() ?: 0;

            // Payment Methods Breakdown
            $stmtMethods = $this->db->prepare("SELECT payment_method, COUNT(*) as count, SUM(amount) as total FROM payments WHERE room_id = ? AND status = 'verified' GROUP BY payment_method");
            $stmtMethods->execute([$roomId]);
            $methods = $stmtMethods->fetchAll(PDO::FETCH_ASSOC);

            // Total Overdue
            $stmtOverdue = $this->db->prepare("SELECT SUM(grand_total - amount_paid) as overdue FROM billing_cycles WHERE room_id = ? AND payment_status = 'overdue'");
            $stmtOverdue->execute([$roomId]);
            $totalOverdue = $stmtOverdue->fetchColumn() ?: 0;

            // Total Pending Verification
            $stmtPending = $this->db->prepare("SELECT SUM(amount) as pending FROM payments WHERE room_id = ? AND status = 'pending'");
            $stmtPending->execute([$roomId]);
            $totalPending = $stmtPending->fetchColumn() ?: 0;

            echo json_encode([
                "success" => true,
                "data" => [
                    "totalPaid" => (float)$totalPaid,
                    "totalPaidThisYear" => (float)$totalPaidThisYear,
                    "totalOverdue" => (float)$totalOverdue,
                    "totalPending" => (float)$totalPending,
                    "methods" => $methods
                ]
            ]);
        } catch (Exception $e) {
            echo json_encode(["success" => false, "message" => "Error generating insights"]);
        }
    }
}
