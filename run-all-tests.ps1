# run-all-tests.ps1
# Runs the entire Feature test suite and writes a clean report to:
#   storage/logs/test_suite.log
#
# Usage (from project root):
#   .\run-all-tests.ps1

$xmlPath = "/var/www/storage/logs/junit_all.xml"
$logPath = "/var/www/storage/logs/test_suite.csv"
$localCsv = "storage\logs\test_suite.csv"
$localLog = "storage\logs\test_suite.log"

Write-Host ""
Write-Host "Running all Feature tests..." -ForegroundColor Cyan

# Run PHPUnit and capture JUnit XML
docker exec atompen-app php artisan test --testsuite=Feature `
    --log-junit $xmlPath | Out-Null

# Format the XML into a clean log
docker exec atompen-app php /var/www/tests/format-log.php $xmlPath $logPath

Write-Host ""
Write-Host "----------------------------------------------" -ForegroundColor DarkGray
Write-Host "Report saved to: $localCsv" -ForegroundColor Green
Write-Host "Log saved to:    $localLog" -ForegroundColor Green
Write-Host "----------------------------------------------" -ForegroundColor DarkGray
