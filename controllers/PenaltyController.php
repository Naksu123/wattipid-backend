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
        
        $overdueAccounts = $this->penaltyService->getOverdueAccounts();
        $analytics = $this->penaltyService->getPenaltyAnalytics();
        
        echo json_encode([
            "success" => true, 
            "data" => [
                "accounts" => $overdueAccounts,
                "analytics" => $analytics
            ]
        ]);
    }

    public function triggerCalculation($authenticatedUser) {
        if (!$authenticatedUser || $authenticatedUser['role'] !== 'landlord') {
            echo json_encode(["success" => false, "message" => "Unauthorized"]);
            return;
        }
        
        $result = $this->penaltyService->calculateDailyPenalties();
        echo json_encode($result);
    }
}
