<?php
require_once __DIR__ . '/../services/PenaltyService.php';

class PenaltyController {
    private $penaltyService;

    public function __construct($dbConnection) {
        $this->penaltyService = new PenaltyService($dbConnection);
    }

    public function getSettings($authenticatedUser) {
        if (!$authenticatedUser || $authenticatedUser['role'] !== 'landlord') {
            echo json_encode(["success" => false, "message" => "Unauthorized"]);
            return;
        }
        $settings = $this->penaltyService->getPenaltySettings();
        echo json_encode(["success" => true, "data" => $settings]);
    }

    public function updateSettings($authenticatedUser, $data) {
        if (!$authenticatedUser || $authenticatedUser['role'] !== 'landlord') {
            echo json_encode(["success" => false, "message" => "Unauthorized"]);
            return;
        }
        $result = $this->penaltyService->updatePenaltySettings($data, $authenticatedUser['id']);
        echo json_encode($result);
    }

    public function getOverdueCenter($authenticatedUser) {
        if (!$authenticatedUser || $authenticatedUser['role'] !== 'landlord') {
            echo json_encode(["success" => false, "message" => "Unauthorized"]);
            return;
        }
        
        try {
            $overdueAccounts = $this->penaltyService->getOverdueAccounts();
            $analytics = $this->penaltyService->getPenaltyAnalytics();
            $activity = $this->penaltyService->getRecentActivity(50);
            
            echo json_encode([
                "success" => true, 
                "data" => [
                    "accounts" => $overdueAccounts ?? [],
                    "analytics" => $analytics ?? [
                        'totalOverdueAccounts' => 0,
                        'totalActivePenalties' => 0,
                        'totalOutstandingBalance' => 0,
                        'billsDueToday' => 0,
                        'billsDueTomorrow' => 0,
                        'totalPenaltiesCollected' => 0,
                    ],
                    "activity" => $activity ?? []
                ]
            ]);
        } catch (Throwable $e) {
            error_log("[PenaltyController] getOverdueCenter error: " . $e->getMessage());
            echo json_encode([
                "success" => true,
                "data" => [
                    "accounts" => [],
                    "analytics" => [
                        'totalOverdueAccounts' => 0,
                        'totalActivePenalties' => 0,
                        'totalOutstandingBalance' => 0,
                        'billsDueToday' => 0,
                        'billsDueTomorrow' => 0,
                        'totalPenaltiesCollected' => 0,
                    ],
                    "activity" => []
                ],
                "warning" => "System recovered from query failure: " . $e->getMessage()
            ]);
        }
    }

    public function triggerCalculation($authenticatedUser) {
        if (!$authenticatedUser || $authenticatedUser['role'] !== 'landlord') {
            echo json_encode(["success" => false, "message" => "Unauthorized"]);
            return;
        }
        
        $result = $this->penaltyService->calculateDailyPenalties();
        echo json_encode($result);
    }

    public function waivePenalty($authenticatedUser, $data) {
        if (!$authenticatedUser || $authenticatedUser['role'] !== 'landlord') {
            echo json_encode(["success" => false, "message" => "Unauthorized"]);
            return;
        }

        $billingCycleId = $data['billing_cycle_id'] ?? null;
        if (!$billingCycleId) {
            echo json_encode(["success" => false, "message" => "Missing billing cycle ID"]);
            return;
        }

        $result = $this->penaltyService->waivePenalty($billingCycleId);
        echo json_encode($result);
    }
}
