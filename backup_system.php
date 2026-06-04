<?php
/**
 * Wattipid Automated Backup Script
 * Generates a full database backup for Disaster Recovery.
 * Usage: php backup_system.php
 */

require_once __DIR__ . '/config/config.php';

$backupDir = __DIR__ . '/backups';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0777, true);
}

$date = date('Y-m-d_H-i-s');
$filename = "wattipid_backup_{$date}.sql";
$filepath = $backupDir . '/' . $filename;

// Extract config values assuming format matches config.php
$dbHost = "127.0.0.1";
$dbName = "wattipid";
$dbUser = "root";
$dbPass = ""; // Assuming empty on XAMPP for this project

// Note: Windows requires the full path to mysqldump if it's not in environment variables
$mysqldumpPath = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';

if (file_exists($mysqldumpPath)) {
    $command = "\"$mysqldumpPath\" --user={$dbUser} --host={$dbHost} {$dbName} > \"{$filepath}\"";
} else {
    $command = "mysqldump --user={$dbUser} --host={$dbHost} {$dbName} > \"{$filepath}\"";
}

// Ignore empty passwords for CLI
if (!empty($dbPass)) {
    $command = str_replace("--user={$dbUser}", "--user={$dbUser} --password={$dbPass}", $command);
}

echo "Generating backup for database: {$dbName}...\n";
exec($command, $output, $returnVar);

if ($returnVar === 0) {
    echo "Backup successfully created: {$filepath}\n";
    // Keep only the last 5 backups to save space
    $files = glob($backupDir . '/*.sql');
    array_multisort(array_map('filemtime', $files), SORT_NUMERIC, SORT_DESC, $files);
    
    $keepCount = 5;
    if (count($files) > $keepCount) {
        $toDelete = array_slice($files, $keepCount);
        foreach ($toDelete as $file) {
            unlink($file);
            echo "Deleted old backup: " . basename($file) . "\n";
        }
    }
} else {
    echo "Backup failed with exit code: {$returnVar}\n";
    print_r($output);
}
?>
