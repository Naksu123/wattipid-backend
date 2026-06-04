# PowerShell script to compress Wattipid Backend for Production
$sourceDir = ".\*"
$outputZip = ".\wattipid_prod.zip"

if (Test-Path $outputZip) {
    Remove-Item $outputZip
}

# Exclude list
$exclude = @(".git", ".env", "deploy_production.ps1", "deploy_production.php", "email_debug.log", "test_*.php", "backups")

# Compress files
Compress-Archive -Path $sourceDir -DestinationPath $outputZip -Update -ErrorAction SilentlyContinue

Write-Host "Deployment package generated successfully: $outputZip"
