<?php
require_once __DIR__ . '/../services/BudgetService.php';
require_once __DIR__ . '/../helpers/ResponseHelper.php';

class BudgetController {
    private $budgetService;

    public function __construct($dbConnection) {
        $this->budgetService = new BudgetService($dbConnection);
    }

    public function getBudget($authenticatedUser, $data) {
        if (!$authenticatedUser) {
            ResponseHelper::error("Unauthorized", 401);
        }
        $result = $this->budgetService->getBudget($data['roomId'] ?? '', $data['month'] ?? null, $data['year'] ?? null);
        ResponseHelper::sendRaw($result);
    }

    public function updateBudget($authenticatedUser, $data) {
        if (!$authenticatedUser) {
            ResponseHelper::error("Unauthorized", 401);
        }
        $result = $this->budgetService->updateBudget($data['roomId'], $data['monthlyBudget'], $data['dailyAllowance'] ?? null, $data['weeklyAllowance'] ?? null, $data['month'] ?? null, $data['year'] ?? null);
        ResponseHelper::sendRaw($result);
    }

    public function resetBudget($authenticatedUser, $data) {
        if (!$authenticatedUser) {
            ResponseHelper::error("Unauthorized", 401);
        }
        $result = $this->budgetService->resetBudget($data['roomId'], $data['month'] ?? null, $data['year'] ?? null);
        ResponseHelper::sendRaw($result);
    }
}
