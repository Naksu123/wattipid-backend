<?php
/**
 * Wattipid QueueService
 * 
 * Manages background jobs to keep the API responsive.
 */

class QueueService {
    private $conn;

    public function __construct($dbConnection) {
        $this->conn = $dbConnection;
    }

    /**
     * Push a new job to the queue.
     * @param string $type 'email' or 'push_notification'
     * @param array $payload The data needed to execute the job
     * @param int $delaySeconds Optional delay before the job becomes available
     */
    public function push($type, $payload, $delaySeconds = 0) {
        $availableAt = date('Y-m-d H:i:s', time() + $delaySeconds);
        $stmt = $this->conn->prepare("INSERT INTO jobs (job_type, payload_json, available_at) VALUES (?, ?, ?)");
        return $stmt->execute([$type, json_encode($payload), $availableAt]);
    }

    /**
     * Fetches the next pending jobs for processing.
     */
    public function getPendingJobs($limit = 10) {
        $limitInt = (int)$limit;
        $stmt = $this->conn->prepare("
            SELECT * FROM jobs 
            WHERE status = 'pending' 
            AND available_at <= NOW() 
            AND attempts < max_attempts 
            ORDER BY created_at ASC 
            LIMIT $limitInt
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function markProcessing($jobId) {
        $stmt = $this->conn->prepare("UPDATE jobs SET status = 'processing', attempts = attempts + 1 WHERE id = ?");
        $stmt->execute([$jobId]);
    }

    public function markCompleted($jobId) {
        $stmt = $this->conn->prepare("UPDATE jobs SET status = 'completed' WHERE id = ?");
        $stmt->execute([$jobId]);
    }

    public function markFailed($jobId, $error) {
        $stmt = $this->conn->prepare("
            UPDATE jobs 
            SET error_log = ?, 
                status = CASE WHEN attempts >= max_attempts THEN 'failed' ELSE 'pending' END 
            WHERE id = ?
        ");
        $stmt->execute([$error, $jobId]);
    }
}
