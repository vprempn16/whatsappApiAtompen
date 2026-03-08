# run-test.ps1
# Usage: .\run-test.ps1 phase1
param([string]$phase)

if ($phase -eq "phase1") {
    Write-Host "Running Phase 1 API Test Suite..." -ForegroundColor Cyan

    # Set environment variables for the TestReporter for Phase 1 specifically
    docker exec -e TEST_LOG_PATH="tests/ApiTests/Phase1/phase1.log" -e TEST_CSV_PATH="tests/ApiTests/Phase1/report.csv" atompen-app php artisan test tests/ApiTests/Phase1 --order-by=default

    Write-Host "`nPhase 1 Complete. Check tests/ApiTests/Phase1/phase1.log and tests/ApiTests/Phase1/report.csv for details." -ForegroundColor Green
} else {
    Write-Host "Usage: .\run-test.ps1 phase1" -ForegroundColor Yellow
}
