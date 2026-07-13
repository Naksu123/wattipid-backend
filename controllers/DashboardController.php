<?php
require_once __DIR__ . '/../services/DashboardService.php';
require_once __DIR__ . '/../services/DashboardSyncService.php';
require_once __DIR__ . '/../helpers/ResponseHelper.php';

class DashboardController {
    private $dashboardService;
    private $syncService;

    public function __construct($dbConnection) {
        $this->dashboardService = new DashboardService($dbConnection);
        $this->syncService = new DashboardSyncService($dbConnection);
    }

    public function getLiveOverview($user, $data) {
        $result = $this->syncService->getLiveOverview($user['id'], $user['role']);
        ResponseHelper::sendRaw($result);
    }

    private function validateRoomAccess($data, $user) {
        $roomId = $data['roomId'] ?? null;
        if (!$roomId) {
            ResponseHelper::error("Room ID is required", 400);
        }

        // Security: Landlords can see all rooms. Tenants ONLY their own.
        if ($user['role'] !== 'landlord' && $user['room_id'] !== $roomId) {
            ResponseHelper::error("Access Denied: You do not have permission to view this room's data.", 403);
        }
        return $roomId;
    }

    public function getBillingCycle($user, $data) {
        $roomId = $this->validateRoomAccess($data, $user);
        $result = $this->dashboardService->getBillingCycleData($roomId, $user['id'], $user['role']);
        if ($result['success'] === false) {
            ResponseHelper::error($result['message'], 400);
        }
        ResponseHelper::sendRaw($result);
    }

    public function getTotalConsumptionToday($user, $data) {
        $roomId = $this->validateRoomAccess($data, $user);
        $result = $this->dashboardService->getTotalConsumptionToday($roomId, $user['id'], $user['role']);
        ResponseHelper::sendRaw($result);
    }

    public function getTotalConsumptionWeek($user, $data) {
        $roomId = $this->validateRoomAccess($data, $user);
        $result = $this->dashboardService->getTotalConsumptionWeek($roomId, $user['id'], $user['role']);
        ResponseHelper::sendRaw($result);
    }

    public function getTotalConsumptionMonth($user, $data) {
        $roomId = $this->validateRoomAccess($data, $user);
        $result = $this->dashboardService->getTotalConsumptionMonth($roomId, $user['id'], $user['role']);
        ResponseHelper::sendRaw($result);
    }

    public function getMonthlyConsumptionFiltered($user, $data) {
        $roomId = $this->validateRoomAccess($data, $user);
        if (!isset($data['year']) || !isset($data['month'])) {
            ResponseHelper::error("Year and month are required", 400);
        }
        $result = $this->dashboardService->getMonthlyConsumptionFiltered($roomId, $user['id'], $user['role'], $data['year'], $data['month']);
        ResponseHelper::sendRaw($result);
    }

    public function getHourlyBreakdown($user, $data) {
        $roomId = $this->validateRoomAccess($data, $user);
        $dateStr = $data['dateStr'] ?? null;
        $result = $this->dashboardService->getHourlyBreakdown($roomId, $user['id'], $user['role'], $dateStr);
        ResponseHelper::sendRaw($result);
    }

    public function getWeeklyBreakdown($user, $data) {
        $roomId = $this->validateRoomAccess($data, $user);
        $dateStr = $data['dateStr'] ?? null;
        $result = $this->dashboardService->getWeeklyBreakdown($roomId, $user['id'], $user['role'], $dateStr);
        ResponseHelper::sendRaw($result);
    }

    public function getDailyBreakdownFiltered($user, $data) {
        $roomId = $this->validateRoomAccess($data, $user);
        if (!isset($data['year']) || !isset($data['month'])) {
            ResponseHelper::error("Year and month are required", 400);
        }
        $result = $this->dashboardService->getDailyBreakdownFiltered($roomId, $user['id'], $user['role'], $data['year'], $data['month']);
        ResponseHelper::sendRaw($result);
    }

    public function getConsumptionComparison($user, $data) {
        $roomId = $this->validateRoomAccess($data, $user);
        $result = $this->dashboardService->getConsumptionComparison($roomId, $user['id'], $user['role'], $data['period'] ?? 'weekly');
        ResponseHelper::sendRaw($result);
    }

    public function getTransactionHistory($user, $data) {
        $roomId = $this->validateRoomAccess($data, $user);
        $result = $this->dashboardService->getTransactionHistory(
            $roomId, 
            $user['id'],
            $user['role'], 
            $data['limit'] ?? 50, 
            $data['offset'] ?? 0,
            $data['filter'] ?? 'minute',
            $data['startDate'] ?? null,
            $data['endDate'] ?? null
        );
        ResponseHelper::sendRaw($result);
    }

    public function getAvailableBillingCycles($user, $data) {
        $roomId = $this->validateRoomAccess($data, $user);
        $result = $this->dashboardService->getAvailableBillingCycles($roomId, $user['id'], $user['role']);
        ResponseHelper::sendRaw($result);
    }

    public function getYearlyBreakdown($user, $data) {
        $roomId = $this->validateRoomAccess($data, $user);
        $year = $data['year'] ?? date('Y');
        $result = $this->dashboardService->getYearlyBreakdown($roomId, $user['id'], $user['role'], (int)$year);
        ResponseHelper::sendRaw($result);
    }
}
