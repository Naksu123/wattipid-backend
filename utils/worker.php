<?php
/**
 * Wattipid Background Worker
 * 
 * Processes the job queue (Emails and Push Notifications).
 * Usage: php worker.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/QueueService.php';
require_once __DIR__ . '/email_service.php';
require_once __DIR__ . '/notification_engine.php';

$queue = new QueueService($conn);
$notifEngine = new NotificationEngine($conn);

echo "[*] Wattipid Worker Started... " . date('Y-m-d H:i:s') . "\n";

while (true) {
    try {
        $jobs = $queue->getPendingJobs(5);
        
        if (empty($jobs)) {
            // Sleep for a bit if queue is empty
            sleep(2);
            continue;
        }

        foreach ($jobs as $job) {
            echo "[+] Processing Job #{$job['id']} ({$job['job_type']})...\n";
            $queue->markProcessing($job['id']);
            
            $payload = json_decode($job['payload_json'], true);
            $success = false;
            $error = "";

            try {
                switch ($job['job_type']) {
                    case 'email':
                        $result = sendEmail(
                            $payload['to'], 
                            $payload['name'] ?? '', 
                            $payload['subject'], 
                            $payload['htmlBody'], 
                            $payload['textBody'] ?? ''
                        );
                        $success = $result['success'];
                        $error = $result['message'] ?? 'Unknown email error';
                        break;

                    case 'push_notification':
                        // We call the internal push logic from NotificationEngine
                        // This will now throw an Exception on failure, which will bubble up naturally
                        $success = $notifEngine->sendPushNotification($payload['userId'], $payload['alert']);
                        if (!$success) {
                            $error = "Push notification returned false without exception";
                        }
                        break;
                    
                    default:
                        $error = "Unknown job type";
                }

                if ($success) {
                    $queue->markCompleted($job['id']);
                    echo "[✓] Job #{$job['id']} Completed.\n";
                } else {
                    throw new Exception($error);
                }

            } catch (Exception $e) {
                // If it's a PDOException (db disconnected), throw it up to the outer catch to trigger reconnect
                if ($e instanceof PDOException) {
                    throw $e;
                }
                $queue->markFailed($job['id'], $e->getMessage());
                echo "[!] Job #{$job['id']} Failed: " . $e->getMessage() . "\n";
            }
        }

        // Small gap between batches
        usleep(500000); 

    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'server has gone away') !== false || strpos($e->getMessage(), 'Lost connection') !== false) {
            echo "[!] Database disconnected (Timeout). Reconnecting...\n";
            // Force re-require to get a fresh $conn
            $conn = null;
            require __DIR__ . '/../config/db.php';
            $queue = new QueueService($conn);
            $notifEngine = new NotificationEngine($conn);
            sleep(2);
            continue;
        }
        throw $e;
    }
}
