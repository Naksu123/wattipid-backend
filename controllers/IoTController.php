<?php
require_once __DIR__ . '/../services/IoTService.php';
require_once __DIR__ . '/../helpers/ResponseHelper.php';

class IoTController {
    private $iotService;
    private $conn;

    public function __construct($dbConnection) {
        $this->conn = $dbConnection;
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
        if ($user['role'] !== 'landlord') {
            ResponseHelper::error("Unauthorized: Landlord access required", 403);
        }
        ResponseHelper::error("Feature not supported: Relay hardware is not available on the Wattipid submetering system.", 400);
    }

    public function getLatestConsumption($user, $data) {
        if (!$user) {
            ResponseHelper::error("Unauthorized: Authentication required", 401);
        }

        $roomId = $data['roomId'] ?? null;
        if (!$roomId) {
            ResponseHelper::error("Room ID is required", 400);
        }

        // Validate room exists
        $stmtRoom = $this->conn->prepare("SELECT room_id FROM rooms WHERE room_id = ? LIMIT 1");
        $stmtRoom->execute([$roomId]);
        if (!$stmtRoom->fetch()) {
            ResponseHelper::error("Room not found", 404);
        }

        // Role & Room Authorization: Tenants may only view their assigned room; Landlords can view building rooms
        if ($user['role'] === 'tenant') {
            $tenantRoomId = $user['room_id'] ?? null;
            if (!$tenantRoomId || $tenantRoomId !== $roomId) {
                ResponseHelper::error("Forbidden: You can only access your assigned room.", 403);
            }
        } elseif ($user['role'] !== 'landlord') {
            ResponseHelper::error("Forbidden: Invalid user role.", 403);
        }

        // Direct query to get ALL sensor columns from the latest log
        $stmt = $this->conn->prepare(
            "SELECT voltage, current_val, power, energy_cumulative, timestamp 
             FROM consumption_logs 
             WHERE room_id = ? 
             ORDER BY timestamp DESC 
             LIMIT 1"
        );
        $stmt->execute([$roomId]);
        $log = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$log) {
            ResponseHelper::sendRaw(['success' => true, 'data' => null]);
            return;
        }

        // Check if last reading is within 30 seconds (device is online)
        $lastTime = strtotime($log['timestamp']);
        $isOnline = (time() - $lastTime) < 30;

        $voltage = (float)$log['voltage'];
        $current = (float)$log['current_val'];
        $power = (float)$log['power'];
        $energy = (float)$log['energy_cumulative'];
        $pf = ($voltage * $current > 0) ? round($power / ($voltage * $current), 2) : 0;

        ResponseHelper::sendRaw(['success' => true, 'data' => [
            'voltage' => $voltage,
            'current' => $current,
            'power' => $power,
            'energy' => $energy,
            'powerFactor' => $pf,
            'online' => $isOnline,
            'lastSeen' => $log['timestamp']
        ]]);
    }
}
