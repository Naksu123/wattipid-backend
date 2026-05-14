<?php
require_once __DIR__ . '/../services/IoTService.php';
require_once __DIR__ . '/../helpers/ResponseHelper.php';

class IoTController {
    private $iotService;

    public function __construct($dbConnection) {
        $this->iotService = new IoTService($dbConnection);
    }

    public function logConsumption($data) {
        // SECURITY: Verify Hardware Token to prevent spoofing
        $providedKey = $data['apiKey'] ?? '';
        if ($providedKey !== HARDWARE_API_KEY) {
            ResponseHelper::error("Unauthorized Hardware Key", 401);
        }

        if (empty($data['roomId'])) {
            ResponseHelper::error("Room ID is required", 400);
        }

        $voltage = (float) ($data['voltage'] ?? 0);
        $current = (float) ($data['current'] ?? 0);
        $power = (float) ($data['power'] ?? 0);
        $cumulativeEnergy = (float) ($data['energy'] ?? 0);

        $result = $this->iotService->logConsumption($data['roomId'], $voltage, $current, $power, $cumulativeEnergy);
        ResponseHelper::sendRaw($result);
    }

    public function toggleRelay($user, $data) {
        // SECURITY: Only landlords can remotely cut power
        if ($user['role'] !== 'landlord') {
            ResponseHelper::error("Unauthorized: Landlord access required", 403);
        }

        if (empty($data['roomId']) || !isset($data['state'])) {
            ResponseHelper::error("Missing Room ID or State", 400);
        }

        $result = $this->iotService->toggleRelay($data['roomId'], $data['state']);
        ResponseHelper::send($result['success'], $result['message']);
    }
}
