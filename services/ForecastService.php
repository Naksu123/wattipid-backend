<?php
require_once __DIR__ . '/../utils/forecast_engine.php';

class ForecastService {
    private $conn;

    public function __construct($dbConnection) {
        $this->conn = $dbConnection;
    }

    public function getMonthlyForecast($roomId, $tenantName) {
        $forecastEngine = new ForecastEngine($this->conn);
        $forecast = $forecastEngine->getMonthlyForecast($roomId, $tenantName);
        return ['success' => true, 'data' => $forecast];
    }

    public function getPeakHourPrediction($roomId, $tenantName) {
        $forecastEngine = new ForecastEngine($this->conn);
        $peaks = $forecastEngine->getPeakHourPrediction($roomId, $tenantName);
        return ['success' => true, 'data' => $peaks];
    }
}
