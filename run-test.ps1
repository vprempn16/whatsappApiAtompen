# run-test.ps1
# Runs a single Feature test file and writes a clean report to:
#   storage/logs/test_individual.log
#
# Usage (from project root):
#   .\run-test.ps1 AuthTest.php
#   .\run-test.ps1 RecordCrudTest.php
#   .\run-test.ps1 ActivityTest.php
#
# The test file name should be the filename inside tests/Feature/

param(
    [Parameter(Mandatory=$true)]
    [string]$TestFile
)

$xmlPath  = "/var/www/storage/logs/junit_individual.xml"
$logPath  = "/var/www/storage/logs/test_individual.csv"
$localCsv = "storage\logs\test_individual.csv"
$localLog = "storage\logs\test_individual.log"

Write-Host ""
Write-Host "Running: tests/Feature/$TestFile" -ForegroundColor Cyan

# Run PHPUnit for the single file
docker exec atompen-app php artisan test "tests/Feature/$TestFile" `
    --log-junit $xmlPath | Out-Null

# Format the XML into a clean log (overwrites previous individual log)
docker exec atompen-app php /var/www/tests/format-log.php $xmlPath $logPath

Write-Host ""
Write-Host "----------------------------------------------" -ForegroundColor DarkGray
Write-Host "Report saved to: $localCsv" -ForegroundColor Green
Write-Host "Log saved to:    $localLog" -ForegroundColor Green
Write-Host "----------------------------------------------" -ForegroundColor DarkGray
