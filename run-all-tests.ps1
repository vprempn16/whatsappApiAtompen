# run-all-tests.ps1
# This script runs all Phase 1 API tests sequentially.
# Sequential execution is ensured by numeric prefixes on filenames and class names.

Write-Host "Running All API Tests..." -ForegroundColor Cyan

# Set environment variables for the TestReporter
docker exec -e TEST_LOG_PATH="storage/logs/test_suite.log" -e TEST_CSV_PATH="tests/ApiTests/reports/reports.csv" atompen-app php artisan test tests/ApiTests/Phase1 --order-by=default

Write-Host "`nAll Tests Complete. Check storage/logs/test_suite.log and tests/ApiTests/reports/reports.csv for details." -ForegroundColor Green
