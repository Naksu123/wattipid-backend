<?php
require_once __DIR__ . '/../services/ForecastService.php';
require_once __DIR__ . '/../helpers/ResponseHelper.php';

class ForecastController {
    private $forecastService;

    public function __construct($dbConnection) {
        $this->forecastService = new ForecastService($dbConnection);
    }

    public function getMonthlyForecast($data) {
        $result = $this->forecastService->getMonthlyForecast($data['roomId'], $data['tenantName'] ?? null);
        ResponseHelper::sendRaw($result);
    }

    public function getPeakHourPrediction($data) {
        $result = $this->forecastService->getPeakHourPrediction($data['roomId'], $data['tenantName'] ?? null);
        ResponseHelper::sendRaw($result);
    }
}
