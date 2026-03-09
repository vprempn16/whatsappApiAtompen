<?php

namespace Tests\ApiTests;

use Illuminate\Support\Facades\File;

class TestReporter
{
    private static $results = [];
    private static $csvPath = '';
    private static $logPath = '';
    private static $isFirstCall = true;

    private static function getPaths()
    {
        if (empty(self::$csvPath)) {
            self::$csvPath = getenv('TEST_CSV_PATH') ?: 'tests/ApiTests/reports/reports.csv';
            self::$logPath = getenv('TEST_LOG_PATH') ?: 'storage/logs/test_suite.log';
        }
    }

    public static function logResult($name, $status, $duration, $module = 'N/A', $metadata = [])
    {
        self::getPaths();

        if (self::$isFirstCall) {
            self::initializeFiles();
            self::$isFirstCall = false;
        }

        $result = [
            'name' => $name,
            'status' => $status,
            'duration' => $duration,
            'module' => $module,
            'metadata' => $metadata,
            'timestamp' => now()->toDateTimeString()
        ];

        self::$results[] = $result;
        self::appendToCsv($result);
        self::appendToLog($result);
        self::printToConsole($result);
    }

    private static function initializeFiles()
    {
        self::getPaths();
        $csvPath = base_path(self::$csvPath);
        $logPath = base_path(self::$logPath);

        $dirCsv = dirname($csvPath);
        $dirLog = dirname($logPath);

        if (!file_exists($dirCsv))
            mkdir($dirCsv, 0755, true);
        if (!file_exists($dirLog))
            mkdir($dirLog, 0755, true);

        // Clear and write CSV header
        $file = fopen($csvPath, 'w');
        fputcsv($file, ['Test Name', 'Status', 'Duration', 'Module', 'Timestamp']);
        fclose($file);

        // Clear and write Log header
        $now = now()->toDateTimeString();
        $header = "═════════════════════════════════════════════════════════════════\n" .
            "  TEST REPORT  ·  $now\n" .
            "═════════════════════════════════════════════════════════════════\n\n";
        File::put($logPath, $header);

        // Register summary on shutdown
        register_shutdown_function([self::class, 'printSummary']);
    }

    private static function appendToCsv($result)
    {
        $path = base_path(self::$csvPath);
        $file = fopen($path, 'a');

        fputcsv($file, [
            $result['name'],
            $result['status'],
            round($result['duration'], 2) . 's',
            $result['module'],
            $result['timestamp']
        ]);

        fclose($file);
    }

    private static function appendToLog($result)
    {
        $path = base_path(self::$logPath);

        $symbol = $result['status'] === 'PASSED' ? '✓' : '✗';
        $metaStr = self::formatMetadata($result['metadata']);

        // No truncation for name and metadata
        $fullTestName = $result['name'] . ($metaStr ? " ($metaStr)" : "");

        $line = sprintf(
            "  %s  %s\n      %8.2fs   %s\n",
            $symbol,
            $fullTestName,
            $result['duration'],
            $result['status']
        );

        File::append($path, $line);
    }

    private static function printToConsole($result)
    {
        $isFirst = count(self::$results) === 1;
        if ($isFirst) {
            echo "\n═════════════════════════════════════════════════════════════════\n";
            echo "  TEST REPORT  ·  " . now()->toDateTimeString() . "\n";
            echo "═════════════════════════════════════════════════════════════════\n\n";
        }

        $symbol = $result['status'] === 'PASSED' ? "\e[32m✓\e[0m" : "\e[31m✗\e[0m";
        $color = $result['status'] === 'PASSED' ? "\e[32m" : "\e[31m";
        $reset = "\e[0m";

        $metaStr = self::formatMetadata($result['metadata']);

        printf(
            "  %s  %-65s %8.2fs   %s%s%s\n",
            $symbol,
            substr($result['name'] . ($metaStr ? " ($metaStr)" : ""), 0, 64),
            $result['duration'],
            $color,
            $result['status'],
            $reset
        );
    }

    private static function formatMetadata($metadata)
    {
        if (empty($metadata))
            return "";
        $parts = [];
        foreach ($metadata as $key => $value) {
            if ($key === 'error')
                continue; // Don't put long errors in the single line
            $parts[] = "$key: $value";
        }
        return implode(' / ', $parts);
    }

    public static function printSummary()
    {
        $total = count(self::$results);
        $passed = count(array_filter(self::$results, fn($r) => $r['status'] === 'PASSED'));
        $failed = $total - $passed;

        echo "\n" . str_repeat('─', 75) . "\n";
        echo "  TOTAL: $total tests  |  $passed passed  |  $failed failed\n";
        echo str_repeat('─', 75) . "\n\n";

        // Also append summary to log
        $summary = "\n" . str_repeat('─', 75) . "\n" .
            "  TOTAL: $total tests  |  $passed passed  |  $failed failed\n" .
            str_repeat('─', 75) . "\n";
        File::append(base_path(self::$logPath), $summary);
    }

    public static function generateSummaryFromCsv()
    {
        $path = base_path(self::$csvPath);
        if (!file_exists($path))
            return;

        $rows = array_map('str_getcsv', file($path));
        array_shift($rows); // header

        $total = count($rows);
        $passed = 0;
        foreach ($rows as $row) {
            if (($row[1] ?? '') === 'PASSED')
                $passed++;
        }
        $failed = $total - $passed;

        echo "\n═════════════════════════════════════════════════════════════════\n";
        echo "  PHASE 1 COMPLETE  ·  " . now()->toDateTimeString() . "\n";
        echo "═════════════════════════════════════════════════════════════════\n";

        foreach ($rows as $row) {
            $name = $row[0] ?? 'N/A';
            $status = $row[1] ?? 'N/A';
            $duration = $row[2] ?? '0s';
            $color = $status === 'PASSED' ? "\e[32m" : "\e[31m";
            $reset = "\e[0m";

            printf("  %-65s %8s   %s%s%s\n", substr($name, 0, 64), $duration, $color, $status, $reset);
        }

        echo str_repeat('─', 75) . "\n";
        echo "  TOTAL: $total tests  |  $passed passed  |  $failed failed\n";
        echo str_repeat('─', 75) . "\n\n";
    }
}
