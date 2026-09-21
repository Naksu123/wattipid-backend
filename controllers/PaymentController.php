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
        $amount = round((float)($data['amount'] ?? 0), 2);
        $proofUrl = $data['proofUrl'] ?? null; // Optional for Cash
        $referenceNumber = $data['referenceNumber'] ?? null;
        $paymentMethod = $data['paymentMethod'] ?? 'Cash';
        $paymentDate = $data['paymentDate'] ?? date('Y-m-d H:i:s');
        $isTotalDue = !empty($data['isTotalDue']) || ($billingCycleId === 'total') || ($billingCycleId === '0') || ($billingCycleId === 0);

        if (!$roomId || $amount <= 0 || (!$isTotalDue && !$billingCycleId)) {
            echo json_encode(["success" => false, "message" => "Missing required payment fields"]);
            return;
        }

        // Authorization: Enforce tenant can only pay for their assigned room
        if ($authenticatedUser['role'] === 'tenant') {
            $tenantRoomId = $authenticatedUser['room_id'] ?? null;
            if ($tenantRoomId && $tenantRoomId != $roomId) {
                echo json_encode(["success" => false, "message" => "Forbidden: You can only submit payments for your assigned room."]);
                return;
            }
            if (!$isTotalDue) {
                // Verify billing cycle belongs to this room
                $stmtAuth = $this->db->prepare("SELECT id FROM billing_cycles WHERE id = ? AND room_id = ?");
                $stmtAuth->execute([$billingCycleId, $roomId]);
                if (!$stmtAuth->fetch()) {
                    echo json_encode(["success" => false, "message" => "Invalid billing cycle for your room."]);
                    return;
                }
            }
        }

        // Validate accepted payment methods
        $acceptedMethods = ['Cash', 'GCash', 'Maya'];
        if (!in_array($paymentMethod, $acceptedMethods)) {
            echo json_encode(["success" => false, "message" => "Invalid payment method. Accepted: Cash, GCash, Maya."]);
            return;
        }

        if (in_array(strtolower($paymentMethod), ['gcash', 'maya'])) {
            // E-wallet: require EITHER proof screenshot OR reference number (>=6 chars)
            if (empty($proofUrl) && (empty($referenceNumber) || strlen(trim($referenceNumber)) < 6)) {
                echo json_encode(["success" => false, "message" => "Please attach a payment screenshot or enter a reference number (at least 6 characters) for e-wallet payments."]);
                return;
            }
            if (!empty($proofUrl)) {
                try {
                    require_once __DIR__ . '/../utils/SecurityMiddleware.php';
                    SecurityMiddleware::validateFileUpload($proofUrl);
                } catch (Exception $e) {
                    echo json_encode(["success" => false, "message" => $e->getMessage()]);
                    return;
                }
            }
        }

        if ($isTotalDue) {
            // Find all unpaid completed billing cycles for this room (FIFO: oldest due date first)
            $stmtCycles = $this->db->prepare("SELECT * FROM billing_cycles 
                WHERE room_id = ? 
                  AND status = 'completed' 
                  AND payment_status IN ('unpaid', 'overdue', 'partially_paid') 
                ORDER BY due_date ASC, id ASC");
            $stmtCycles->execute([$roomId]);
            $unpaidCycles = $stmtCycles->fetchAll(PDO::FETCH_ASSOC);

            if (empty($unpaidCycles)) {
                echo json_encode(["success" => false, "message" => "No outstanding bills found to pay for your room."]);
                return;
            }

            // Allocate amount across unpaid cycles using FIFO (First-In, First-Out)
            $remaining = $amount;
            $allocations = [];

            foreach ($unpaidCycles as $c) {
                if ($remaining <= 0) break;

                $cPaid = (float)($c['amount_paid'] ?? 0);
                $cElec = (float)($c['electricity_charge'] ?? $c['total_cost'] ?? 0);
                $cMisc = (float)($c['miscellaneous_fee'] ?? 0);
                $cRent = (float)($c['monthly_rent'] ?? 0);
                $cAdd = (float)($c['additional_charges'] ?? 0);
                $cDisc = (float)($c['discounts'] ?? 0);
                $cPen = (float)($c['penalty_amount'] ?? 0);
                $baseAmount = round($cElec + $cMisc + $cRent + $cAdd - $cDisc, 2);
                $cycleDue = max(0.00, round($baseAmount + $cPen - $cPaid, 2));

                if ($cycleDue <= 0) continue;

                $allocAmount = min($remaining, $cycleDue);
                $allocations[] = [
                    'cycle_id' => (int)$c['id'],
                    'invoice_number' => $c['invoice_number'],
                    'amount' => $allocAmount
                ];
                $remaining = round($remaining - $allocAmount, 2);
            }

            // If any remaining amount (e.g. overpayment), allocate to the last cycle
            if ($remaining > 0 && !empty($allocations)) {
                $lastIdx = count($allocations) - 1;
                $allocations[$lastIdx]['amount'] = round($allocations[$lastIdx]['amount'] + $remaining, 2);
                $remaining = 0;
            }

            if (empty($allocations)) {
                echo json_encode(["success" => false, "message" => "Unable to allocate payment to any outstanding invoice."]);
                return;
            }

            // Duplicate Submission Protection for Total Due: Prevent reuse of e-wallet reference numbers
            if (!empty($referenceNumber) && in_array(strtolower($paymentMethod), ['gcash', 'maya'])) {
                $stmtRef = $this->db->prepare("SELECT id, status FROM payments WHERE reference_number = ? AND (status = 'pending' OR status = 'verified') LIMIT 1");
                $stmtRef->execute([$referenceNumber]);
                $existingRef = $stmtRef->fetch(PDO::FETCH_ASSOC);
                if ($existingRef) {
                    if ($existingRef['status'] === 'pending') {
                        echo json_encode([
                            "success" => true,
                            "message" => "A payment with this reference number is already pending landlord verification.",
                            "paymentId" => $existingRef['id'],
                            "alreadyPending" => true
                        ]);
                        return;
                    } else {
                        echo json_encode([
                            "success" => false,
                            "message" => "This reference number has already been used and verified."
                        ]);
                        return;
                    }
                }
            }

            try {
                $this->db->beginTransaction();

                $insertedPaymentIds = [];
                $paymentInsertStmt = $this->db->prepare("INSERT INTO payments (billing_cycle_id, room_id, tenant_id, amount, payment_method, payment_date, reference_number, proof_url, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
                $cycleUpdateStmt = $this->db->prepare("UPDATE billing_cycles SET payment_status = 'pending_verification' WHERE id = ?");

                foreach ($allocations as $alloc) {
                    $paymentInsertStmt->execute([
                        $alloc['cycle_id'],
                        $roomId,
                        $authenticatedUser['id'],
                        $alloc['amount'],
                        $paymentMethod,
                        $paymentDate,
                        $referenceNumber,
                        $proofUrl
                    ]);
                    $pId = $this->db->lastInsertId();
                    $insertedPaymentIds[] = $pId;

                    $cycleUpdateStmt->execute([$alloc['cycle_id']]);
                    $this->logAudit($authenticatedUser['id'], 'tenant', 'submit_payment', 'payments', $pId, null, "Amount: {$alloc['amount']} (Status: pending, Total Due Allocation for {$alloc['invoice_number']})");
                }

                $this->db->commit();

                // Notify Landlord safely
                try {
                    require_once __DIR__ . '/../services/BillingNotificationService.php';
                    $tenantName = $authenticatedUser['name'] ?? null;
                    if (empty($tenantName)) {
                        $uStmt = $this->db->prepare("SELECT name FROM users WHERE id = ?");
                        $uStmt->execute([$authenticatedUser['id']]);
                        $tenantName = $uStmt->fetchColumn() ?: 'Tenant';
                    }
                    $firstAllocCycleId = $allocations[0]['cycle_id'] ?? null;
                    $firstAllocInv = $allocations[0]['invoice_number'] ?? null;
                    $notifSvc = new BillingNotificationService($this->db);
                    $notifSvc->sendPaymentSubmittedAlert(
                        $roomId,
                        $authenticatedUser['id'],
                        $tenantName,
                        $amount,
                        $paymentMethod,
                        $referenceNumber,
                        $insertedPaymentIds[0] ?? null,
                        $firstAllocCycleId,
                        $firstAllocInv
                    );
                } catch (Throwable $notifErr) {
                    error_log("Failed to send landlord payment notification: " . $notifErr->getMessage());
                }

                echo json_encode([
                    "success" => true,
                    "message" => "Total Amount Due payment of ₱" . number_format($amount, 2) . " submitted successfully and pending landlord verification."
                ]);
                return;
            } catch (Exception $e) {
                $this->db->rollBack();
                echo json_encode(["success" => false, "message" => "Failed to submit payment: " . $e->getMessage()]);
                return;
            }
        }

        try {
            $this->db->beginTransaction();

            $stmt_bc = $this->db->prepare("SELECT * FROM billing_cycles WHERE id = ? FOR UPDATE");
            $stmt_bc->execute([$billingCycleId]);
            $bc = $stmt_bc->fetch(PDO::FETCH_ASSOC);

            if (!$bc) {
                throw new Exception("Billing cycle not found");
            }

            if ($bc['payment_status'] === 'paid') {
                $this->db->rollBack();
                echo json_encode(["success" => false, "message" => "This invoice has already been fully paid and verified."]);
                return;
            }

            // Duplicate Submission Protection 1: Prevent duplicate submission if payment is already pending verification
            $stmtPending = $this->db->prepare("SELECT id, amount, reference_number FROM payments WHERE billing_cycle_id = ? AND status = 'pending' LIMIT 1");
            $stmtPending->execute([$billingCycleId]);
            $existingPending = $stmtPending->fetch(PDO::FETCH_ASSOC);
            if ($existingPending) {
                $this->db->rollBack();
                echo json_encode([
                    "success" => true,
                    "message" => "A payment submission for this invoice is already pending landlord verification.",
                    "paymentId" => $existingPending['id'],
                    "alreadyPending" => true
                ]);
                return;
            }

            // Duplicate Submission Protection 2: Prevent reuse of e-wallet reference numbers
            if (!empty($referenceNumber) && in_array(strtolower($paymentMethod), ['gcash', 'maya'])) {
                $stmtRef = $this->db->prepare("SELECT id, status FROM payments WHERE reference_number = ? AND (status = 'pending' OR status = 'verified') LIMIT 1");
                $stmtRef->execute([$referenceNumber]);
                $existingRef = $stmtRef->fetch(PDO::FETCH_ASSOC);
                if ($existingRef) {
                    $this->db->rollBack();
                    if ($existingRef['status'] === 'pending') {
                        echo json_encode([
                            "success" => true,
                            "message" => "A payment with this reference number is already pending landlord verification.",
                            "paymentId" => $existingRef['id'],
                            "alreadyPending" => true
                        ]);
                        return;
                    } else {
                        echo json_encode([
                            "success" => false,
                            "message" => "This reference number has already been used and verified."
                        ]);
                        return;
                    }
                }
            }

            // Insert payment with status 'pending' awaiting landlord verification
            $stmt = $this->db->prepare("INSERT INTO payments (billing_cycle_id, room_id, tenant_id, amount, payment_method, payment_date, reference_number, proof_url, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
            $stmt->execute([$billingCycleId, $roomId, $authenticatedUser['id'], $amount, $paymentMethod, $paymentDate, $referenceNumber, $proofUrl]);
            $paymentId = $this->db->lastInsertId();

            // Mark billing cycle as pending_verification
            $stmt2 = $this->db->prepare("UPDATE billing_cycles SET payment_status = 'pending_verification' WHERE id = ?");
            $stmt2->execute([$billingCycleId]);

            // Audit Log
            $this->logAudit($authenticatedUser['id'], 'tenant', 'submit_payment', 'payments', $paymentId, null, "Amount: $amount (Status: pending_verification)");

            $this->db->commit();

            // Notify Landlord safely without failing payment
            try {
                require_once __DIR__ . '/../services/BillingNotificationService.php';
                $tenantName = $authenticatedUser['name'] ?? null;
                if (empty($tenantName)) {
                    $uStmt = $this->db->prepare("SELECT name FROM users WHERE id = ?");
                    $uStmt->execute([$authenticatedUser['id']]);
                    $tenantName = $uStmt->fetchColumn() ?: 'Tenant';
                }
                $notifSvc = new BillingNotificationService($this->db);
                $notifSvc->sendPaymentSubmittedAlert(
                    $roomId, 
                    $authenticatedUser['id'], 
                    $tenantName, 
                    $amount, 
                    $paymentMethod, 
                    $referenceNumber, 
                    $paymentId,
                    $billingCycleId,
                    $bc['invoice_number'] ?? null
                );
            } catch (Throwable $notifErr) {
                error_log("Failed to send landlord payment notification: " . $notifErr->getMessage());
            }

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

                // Reconcile billing cycle from verified payments (single source of truth)
                $reconciled = $this->reconcileBillingCyclePayment($payment['billing_cycle_id']);
                $newAmountPaid = $reconciled['verifiedPaid'];
                $newStatus = $reconciled['newStatus'];

                // Fetch billing cycle for notification calculations
                $stmt_bc = $this->db->prepare("SELECT * FROM billing_cycles WHERE id = ?");
                $stmt_bc->execute([$payment['billing_cycle_id']]);
                $bc = $stmt_bc->fetch(PDO::FETCH_ASSOC);

                $grandTotal = (float)$bc['grand_total'];
                if ($grandTotal == 0) {
                     $grandTotal = (float)$bc['electricity_charge'] + (float)$bc['penalty_amount'] + (float)$bc['monthly_rent'] + (float)$bc['previous_balance'] + (float)$bc['additional_charges'] - (float)$bc['discounts'];
                }
                if ($grandTotal == 0) {
                     $grandTotal = (float)$bc['total_cost'] + (float)$bc['penalty_amount'];
                }

                if ($newStatus === 'paid') {
                    // Auto-supersede any remaining duplicate pending payments for this cycle
                    $stmtVoid = $this->db->prepare("UPDATE payments SET status = 'rejected', rejection_reason = ? WHERE billing_cycle_id = ? AND status = 'pending' AND id != ?");
                    $stmtVoid->execute(["Superseded by verified payment #{$paymentId}", $payment['billing_cycle_id'], $paymentId]);
                }

                $this->logAudit($authenticatedUser['id'], 'landlord', 'approve_payment', 'payments', $paymentId, 'pending', "verified (amount: $actualAmount, status: $newStatus)");

                // Fetch tenant name for email template
                $tenantStmt = $this->db->prepare("SELECT name FROM users WHERE id = ?");
                $tenantStmt->execute([$payment['tenant_id']]);
                $tenantName = $tenantStmt->fetchColumn() ?: 'Tenant';

                $paymentData = [
                    'tenantName' => $tenantName,
                    'roomNumber' => $payment['room_id'],
                    'paymentMethod' => $payment['payment_method'],
                    'referenceNumber' => $payment['reference_number'],
                    'dateSubmitted' => $payment['payment_date'],
                    'verifiedBy' => $authenticatedUser['name'] ?? 'Landlord'
                ];

                // Send Real-time Notification
                require_once __DIR__ . '/../services/BillingNotificationService.php';
                $notifSvc = new BillingNotificationService($this->db);
                $remainingBalance = max($grandTotal - $newAmountPaid, 0);
                $notifSvc->sendPaymentVerificationAlert($payment['room_id'], $payment['tenant_id'], $actualAmount, $newStatus, $payment['payment_method'], $remainingBalance, $paymentData);
            } else {
                $stmt2 = $this->db->prepare("UPDATE payments SET status = 'rejected', verified_by = ?, rejection_reason = ? WHERE id = ?");
                $stmt2->execute([$authenticatedUser['id'], $reason, $paymentId]);

                // Reconcile billing cycle status from verified payments (handles partial payments correctly)
                $this->reconcileBillingCyclePayment($payment['billing_cycle_id']);

                $this->logAudit($authenticatedUser['id'], 'landlord', 'reject_payment', 'payments', $paymentId, 'pending', 'rejected: ' . $reason);

                // Send Real-time Notification
                require_once __DIR__ . '/../services/BillingNotificationService.php';
                $notifSvc = new BillingNotificationService($this->db);
                $notifSvc->sendPaymentRejectionAlert($payment['room_id'], $payment['tenant_id'], $payment['amount'], $reason);
            }

            // Always synchronize subsequent cycles' previous_balance for this room
            $this->recalculateRoomPreviousBalances($payment['room_id']);

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

        $query = "SELECT p.*, bc.due_date, bc.penalty_amount, bc.total_cost, bc.grand_total, bc.amount_paid, bc.miscellaneous_fee, u.name as tenant_name 
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

        // 3. Overdue Amount (from billing_cycles, calculated per individual invoice without double counting previous_balance)
        $stmt3 = $this->db->query("SELECT 
            COALESCE(SUM(GREATEST(0.00, (COALESCE(electricity_charge, total_cost, 0.00) + COALESCE(miscellaneous_fee, 0.00) + COALESCE(monthly_rent, 0.00) + COALESCE(additional_charges, 0.00) - COALESCE(discounts, 0.00) + COALESCE(penalty_amount, 0.00)) - COALESCE(amount_paid, 0.00))), 0) as total_overdue, 
            COUNT(*) as overdue_count 
        FROM billing_cycles 
        WHERE payment_status = 'overdue'");
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

            // Reconcile billing cycle from verified payments (includes the new cash payment)
            $this->reconcileBillingCyclePayment($billingCycleId);

            // Recalculate room previous balances
            $this->recalculateRoomPreviousBalances($roomId);

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

    public function getTenantBillingOverview($authenticatedUser, $data) {
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

        if ($authenticatedUser['role'] === 'tenant' && $authenticatedUser['room_id'] !== $roomId) {
            echo json_encode(["success" => false, "message" => "Forbidden: You can only view billing for your assigned room."]);
            return;
        }

        try {
            // 1. Check for Active Billing Cycle (Recording Consumption in Real Time)
            $stmtActive = $this->db->prepare("SELECT * FROM billing_cycles WHERE room_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
            $stmtActive->execute([$roomId]);
            $activeRow = $stmtActive->fetch(PDO::FETCH_ASSOC);
            $activeCycle = null;
            if ($activeRow) {
                $activeCycle = [
                    'id' => (int)$activeRow['id'],
                    'room_id' => $activeRow['room_id'],
                    'cycle_start' => $activeRow['cycle_start'],
                    'cycle_end' => $activeRow['cycle_end'],
                    'status' => 'active',
                    'is_recording' => true,
                    'amount_due' => 0.00
                ];
            }

            // 2. Fetch Completed Billing Cycles
            $stmt = $this->db->prepare("SELECT * FROM billing_cycles WHERE room_id = ? AND status = 'completed' ORDER BY id DESC LIMIT 24");
            $stmt->execute([$roomId]);
            $completedCycles = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $currentBill = null;
            $overdueBills = [];
            $totalCurrentDue = 0.00;
            $totalOverdueBase = 0.00;
            $totalOverduePenalties = 0.00;
            $totalOverdue = 0.00;

            // Separate completed cycles into:
            // - Overdue bills (due date passed and not settled)
            // - Current generated bill (not overdue, unpaid)
            $candidateCurrentCycle = null;

            foreach ($completedCycles as $c) {
                $cPaid = (float)($c['amount_paid'] ?? 0);
                $cElec = (float)($c['electricity_charge'] ?? $c['total_cost'] ?? 0);
                $cMisc = (float)($c['miscellaneous_fee'] ?? 0);
                $cRent = (float)($c['monthly_rent'] ?? 0);
                $cAdd = (float)($c['additional_charges'] ?? 0);
                $cDisc = (float)($c['discounts'] ?? 0);
                $cPen = (float)($c['penalty_amount'] ?? 0);
                $baseAmount = round($cElec + $cMisc + $cRent + $cAdd - $cDisc, 2);
                $cycleTotal = round($baseAmount + $cPen, 2);

                // Auto-heal status if amount_paid covers standalone total
                if ($cycleTotal > 0 && $cPaid >= $cycleTotal - 0.01 && $c['payment_status'] !== 'paid') {
                    $this->db->prepare("UPDATE billing_cycles SET payment_status = 'paid' WHERE id = ?")->execute([$c['id']]);
                    $c['payment_status'] = 'paid';
                }

                $isOverdue = ($c['payment_status'] === 'overdue');
                $daysOverdue = 0;

                if (!empty($c['due_date'])) {
                    $dueTimestamp = strtotime($c['due_date']);
                    if ($dueTimestamp < time()) {
                        $daysOverdue = (int)floor((time() - $dueTimestamp) / 86400);
                        if ($daysOverdue > 0 && $c['payment_status'] !== 'paid') {
                            $isOverdue = true;
                        }
                    }
                }

                if ($isOverdue && $c['payment_status'] !== 'paid') {
                    $remOverdue = max(0.00, round($baseAmount + $cPen - $cPaid, 2));
                    if ($remOverdue > 0.01) {
                        $overdueBills[] = [
                            'id' => (int)$c['id'],
                            'invoice_number' => $c['invoice_number'],
                            'cycle_start' => $c['cycle_start'],
                            'cycle_end' => $c['cycle_end'],
                            'due_date' => $c['due_date'],
                            'payment_status' => $c['payment_status'],
                            'days_overdue' => $daysOverdue,
                            'total_kwh' => (float)($c['total_kwh'] ?? 0),
                            'rate_per_kwh' => (float)($c['rate_per_kwh'] ?? 12.50),
                            'electricity_charge' => $cElec,
                            'miscellaneous_fee' => $cMisc,
                            'monthly_rent' => $cRent,
                            'previous_reading' => (float)($c['previous_reading'] ?? 0),
                            'current_reading' => (float)($c['current_reading'] ?? 0),
                            'previous_balance' => (float)($c['previous_balance'] ?? 0),
                            'base_amount' => $baseAmount,
                            'penalty_amount' => $cPen,
                            'amount_paid' => $cPaid,
                            'total_overdue' => $remOverdue
                        ];
                        $totalOverdueBase += max(0.00, $baseAmount - $cPaid);
                        $totalOverduePenalties += $cPen;
                        $totalOverdue += $remOverdue;
                    }
                } else if (!$isOverdue && $candidateCurrentCycle === null && $c['payment_status'] !== 'paid') {
                    $candidateCurrentCycle = $c;
                }
            }

            // Sort overdue bills in chronological order (oldest due date first)
            usort($overdueBills, function($a, $b) {
                return strcmp($a['due_date'] ?? '', $b['due_date'] ?? '');
            });

            // Determine Current Bill State (State 1: None, State 2: Active Cycle recording, State 3: Generated bill)
            $currentBillState = 'none';

            if ($candidateCurrentCycle !== null) {
                $currentBillState = 'generated';
                $elec = (float)($candidateCurrentCycle['electricity_charge'] ?? $candidateCurrentCycle['total_cost'] ?? 0);
                $misc = (float)($candidateCurrentCycle['miscellaneous_fee'] ?? 0);
                $rent = (float)($candidateCurrentCycle['monthly_rent'] ?? 0);
                $add = (float)($candidateCurrentCycle['additional_charges'] ?? 0);
                $disc = (float)($candidateCurrentCycle['discounts'] ?? 0);
                $paid = (float)($candidateCurrentCycle['amount_paid'] ?? 0);
                $pen = (float)($candidateCurrentCycle['penalty_amount'] ?? 0);

                $currentCycleCost = round($elec + $misc + $rent + $add - $disc, 2);
                $currentAmountDue = max(0.00, round($currentCycleCost + $pen - $paid, 2));

                $currentBill = [
                    'id' => (int)$candidateCurrentCycle['id'],
                    'invoice_number' => $candidateCurrentCycle['invoice_number'],
                    'cycle_start' => $candidateCurrentCycle['cycle_start'],
                    'cycle_end' => $candidateCurrentCycle['cycle_end'],
                    'due_date' => $candidateCurrentCycle['due_date'],
                    'status' => $candidateCurrentCycle['status'],
                    'payment_status' => $candidateCurrentCycle['payment_status'],
                    'current_cycle_cost' => $currentCycleCost,
                    'electricity_charge' => $elec,
                    'miscellaneous_fee' => $misc,
                    'monthly_rent' => $rent,
                    'additional_charges' => $add,
                    'discounts' => $disc,
                    'penalty_amount' => $pen,
                    'amount_paid' => $paid,
                    'amount_due' => $currentAmountDue,
                    'total_amount_due' => $currentAmountDue,
                    'previous_reading' => (float)($candidateCurrentCycle['previous_reading'] ?? 0),
                    'current_reading' => (float)($candidateCurrentCycle['current_reading'] ?? 0),
                    'total_kwh' => (float)($candidateCurrentCycle['total_kwh'] ?? 0),
                    'rate_per_kwh' => (float)($candidateCurrentCycle['rate_per_kwh'] ?? 12.50),
                    'breakdown' => [
                        'electricity' => $elec,
                        'miscellaneous' => $misc,
                        'rent' => $rent,
                        'generation' => (float)($candidateCurrentCycle['generation_charge'] ?? 0),
                        'transmission' => (float)($candidateCurrentCycle['transmission_charge'] ?? 0),
                        'system_loss' => (float)($candidateCurrentCycle['system_loss_charge'] ?? 0),
                        'distribution' => (float)($candidateCurrentCycle['distribution_charge'] ?? 0),
                        'metering' => (float)($candidateCurrentCycle['metering_charge'] ?? 0),
                        'supply' => (float)($candidateCurrentCycle['supply_charge'] ?? 0),
                        'vat' => (float)($candidateCurrentCycle['vat_amount'] ?? 0),
                        'additional' => $add,
                        'discounts' => $disc
                    ]
                ];
                $totalCurrentDue = $currentAmountDue;
            } else if ($activeCycle !== null) {
                // State 2: Current cycle active, recording meter consumption, bill not generated yet
                $currentBillState = 'cycle_active';
                $totalCurrentDue = 0.00;
            }

            // 3. Collect Paid Bills for billing history
            $paidBills = [];
            $stmtPaid = $this->db->prepare("SELECT * FROM billing_cycles WHERE room_id = ? AND status = 'completed' AND payment_status = 'paid' ORDER BY cycle_end DESC LIMIT 12");
            $stmtPaid->execute([$roomId]);
            $paidRows = $stmtPaid->fetchAll(PDO::FETCH_ASSOC);
            foreach ($paidRows as $pb) {
                $pbElec = (float)($pb['electricity_charge'] ?? $pb['total_cost'] ?? 0);
                $pbMisc = (float)($pb['miscellaneous_fee'] ?? 0);
                $pbRent = (float)($pb['monthly_rent'] ?? 0);
                $pbAdd = (float)($pb['additional_charges'] ?? 0);
                $pbDisc = (float)($pb['discounts'] ?? 0);
                $pbPen = (float)($pb['penalty_amount'] ?? 0);
                $pbGrand = (float)($pb['grand_total'] ?? 0);
                if ($pbGrand <= 0) {
                    $pbGrand = round($pbElec + $pbMisc + $pbRent + $pbAdd + $pbPen - $pbDisc, 2);
                }
                $paidBills[] = [
                    'id' => (int)$pb['id'],
                    'invoice_number' => $pb['invoice_number'],
                    'cycle_start' => $pb['cycle_start'],
                    'cycle_end' => $pb['cycle_end'],
                    'due_date' => $pb['due_date'],
                    'payment_status' => 'paid',
                    'total_kwh' => (float)($pb['total_kwh'] ?? 0),
                    'electricity_charge' => $pbElec,
                    'miscellaneous_fee' => $pbMisc,
                    'monthly_rent' => $pbRent,
                    'penalty_amount' => $pbPen,
                    'amount_paid' => (float)($pb['amount_paid'] ?? 0),
                    'total_amount_due' => $pbGrand,
                    'status' => 'paid'
                ];
            }

            // 4. Reconciled Total Outstanding Summary
            $totalOutstanding = [
                'current_bill_due' => round($totalCurrentDue, 2),
                'previous_balance' => round($totalOverdueBase, 2),
                'overdue_penalties' => round($totalOverduePenalties, 2),
                'total_overdue' => round($totalOverdue, 2),
                'grand_total' => round($totalCurrentDue + $totalOverdue, 2)
            ];

            echo json_encode([
                "success" => true,
                "data" => [
                    "has_current_bill" => ($currentBill !== null),
                    "current_bill_state" => $currentBillState, // 'generated' | 'cycle_active' | 'none'
                    "active_cycle" => $activeCycle,
                    "current_bill" => $currentBill,
                    "has_overdue" => !empty($overdueBills),
                    "overdue_bills" => $overdueBills,
                    "paid_bills" => $paidBills,
                    "total_outstanding" => $totalOutstanding
                ]
            ]);
        } catch (Exception $e) {
            echo json_encode(["success" => false, "message" => "Error loading billing overview: " . $e->getMessage()]);
        }
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

    public function recalculateRoomPreviousBalances($roomId) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM billing_cycles WHERE room_id = ? ORDER BY id ASC");
            $stmt->execute([$roomId]);
            $cycles = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $cumulativeUnpaid = 0.00;
            foreach ($cycles as $c) {
                $cycleId = $c['id'];
                $newPrevBal = round($cumulativeUnpaid, 2);
                
                $elec = (float)$c['electricity_charge'];
                $misc = (float)($c['miscellaneous_fee'] ?? 0);
                $rent = (float)$c['monthly_rent'];
                $add = (float)$c['additional_charges'];
                $pen = (float)$c['penalty_amount'];
                $disc = (float)$c['discounts'];
                
                $newGrandTotal = round($elec + $misc + $rent + $newPrevBal + $add + $pen - $disc, 2);
                
                if ($c['payment_status'] !== 'paid') {
                    $up = $this->db->prepare("UPDATE billing_cycles SET previous_balance = ?, grand_total = ? WHERE id = ?");
                    $up->execute([$newPrevBal, $newGrandTotal, $cycleId]);
                }

                $paid = (float)$c['amount_paid'];
                $unpaidThisCycle = ($c['payment_status'] === 'paid') ? 0.00 : max(0.00, $newGrandTotal - $paid);
                $cumulativeUnpaid = $unpaidThisCycle;
            }
        } catch (Exception $e) {
            error_log("Failed to recalculate room previous balances: " . $e->getMessage());
        }
    }

    /**
     * Reconcile billing cycle payment status from verified payments.
     * Single source of truth: amount_paid = SUM(verified payments).
     * Status derived from verified total vs standalone cycle total.
     */
    public function reconcileBillingCyclePayment($cycleId) {
        try {
            // 1. Get verified payment total for this cycle
            $stmtVerified = $this->db->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE billing_cycle_id = ? AND status = 'verified'");
            $stmtVerified->execute([$cycleId]);
            $verifiedPaid = (float)$stmtVerified->fetchColumn();

            // 2. Check for pending payments
            $stmtPending = $this->db->prepare("SELECT COUNT(*) FROM payments WHERE billing_cycle_id = ? AND status = 'pending'");
            $stmtPending->execute([$cycleId]);
            $hasPending = (int)$stmtPending->fetchColumn() > 0;

            // 3. Get billing cycle details
            $stmtCycle = $this->db->prepare("SELECT * FROM billing_cycles WHERE id = ?");
            $stmtCycle->execute([$cycleId]);
            $bc = $stmtCycle->fetch(PDO::FETCH_ASSOC);

            if (!$bc) {
                return ['verifiedPaid' => 0, 'newStatus' => 'unpaid'];
            }

            // 4. Compute standalone cycle total (without previous_balance to avoid double-counting)
            $cElec = (float)($bc['electricity_charge'] ?? $bc['total_cost'] ?? 0);
            $cMisc = (float)($bc['miscellaneous_fee'] ?? 0);
            $cRent = (float)($bc['monthly_rent'] ?? 0);
            $cAdd = (float)($bc['additional_charges'] ?? 0);
            $cDisc = (float)($bc['discounts'] ?? 0);
            $cPen = (float)($bc['penalty_amount'] ?? 0);
            $standaloneTotal = round($cElec + $cMisc + $cRent + $cAdd + $cPen - $cDisc, 2);

            // 5. Determine new status
            if ($standaloneTotal > 0 && $verifiedPaid >= $standaloneTotal - 0.01) {
                $newStatus = 'paid';
            } elseif ($hasPending) {
                $newStatus = 'pending_verification';
            } elseif ($verifiedPaid > 0.00) {
                $newStatus = 'partially_paid';
            } else {
                // No verified, no pending: check due date
                $dueDate = $bc['due_date'] ?? null;
                if ($dueDate && strtotime($dueDate) < time()) {
                    $newStatus = 'overdue';
                } else {
                    $newStatus = 'unpaid';
                }
            }

            // 6. Update billing cycle
            $stmtUpdate = $this->db->prepare("UPDATE billing_cycles SET amount_paid = ?, payment_status = ? WHERE id = ?");
            $stmtUpdate->execute([$verifiedPaid, $newStatus, $cycleId]);

            return ['verifiedPaid' => $verifiedPaid, 'newStatus' => $newStatus];
        } catch (Exception $e) {
            error_log("[PaymentController] reconcileBillingCyclePayment error for cycle $cycleId: " . $e->getMessage());
            return ['verifiedPaid' => 0, 'newStatus' => 'unpaid'];
        }
    }
}
