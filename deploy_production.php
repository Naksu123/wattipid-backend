<?php
/**
 * Wattipid Production Deployment Prepper
 * Zips the backend repository while excluding sensitive development files, 
 * logs, and unneeded directories for deployment to a cloud server.
 * 
 * Usage: php deploy_production.php
 */

$sourceDir = __DIR__;
$outputZip = __DIR__ . '/wattipid_prod.zip';

// Ignore list
$exclude = [
    '.git',
    '.env', // Let production handle its own .env
    'deploy_production.php',
    'wattipid_prod.zip',
    'email_debug.log',
    'test_*.php',
    'backups'
];

if (file_exists($outputZip)) {
    unlink($outputZip);
}

$zip = new ZipArchive();
if ($zip->open($outputZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    die("Failed to create ZIP file.\n");
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

$count = 0;
foreach ($iterator as $file) {
    $relativePath = str_replace($sourceDir . DIRECTORY_SEPARATOR, '', $file->getPathname());
    
    // Check exclude list
    $skip = false;
    foreach ($exclude as $item) {
        if (strpos($relativePath, $item) === 0) {
            $skip = true;
            break;
        }
    }
    
    if ($skip) continue;

    if ($file->isDir()) {
        $zip->addEmptyDir($relativePath);
    } elseif ($file->isFile()) {
        $zip->addFile($file->getPathname(), $relativePath);
        $count++;
    }
}

$zip->close();
echo "Deployment package generated successfully!\n";
echo "Files archived: $count\n";
echo "Output: $outputZip\n";
echo "Ready for production upload.\n";
?>
