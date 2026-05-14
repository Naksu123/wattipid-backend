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
                    $success = $notifEngine->sendPushNotification($payload['userId'], $payload['alert']);
                    $error = "Push notification failed";
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
            $queue->markFailed($job['id'], $e->getMessage());
            echo "[!] Job #{$job['id']} Failed: " . $e->getMessage() . "\n";
        }
    }

    // Small gap between batches
    usleep(500000); 
}
