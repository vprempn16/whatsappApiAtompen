<?php

/**
 * PHPUnit JUnit XML → Clean Human-Readable Log Formatter
 *
 * Usage (called automatically by PowerShell scripts):
 *   php tests/format-log.php <xml-input-path> <log-output-path>
 *
 * The output is a clean text file you can read easily.
 */

$xmlPath = $argv[1] ?? '/var/www/html/storage/logs/junit_tmp.xml';
$logPath = $argv[2] ?? '/var/www/html/storage/logs/test_suite.log';

if (!file_exists($xmlPath)) {
    file_put_contents($logPath, "[ERROR] No JUnit XML found at: $xmlPath\n");
    exit(1);
}

$xml = simplexml_load_file($xmlPath);
$now = date('Y-m-d H:i:s');

$lines = [];
$passed = 0;
$failed = 0;
$entries = [];

// Walk all <testcase> nodes regardless of nesting depth
$iter = new RecursiveIteratorIterator(
    new RecursiveArrayIterator(json_decode(json_encode($xml), true)),
    RecursiveIteratorIterator::SELF_FIRST
);

// Collect all testcase nodes using XPath (handles arbitrary nesting)
$testcases = $xml->xpath('//testcase');

foreach ($testcases as $tc) {
    $attrs = $tc->attributes();
    $name = (string) $attrs['name'];
    $duration = round((float) $attrs['time'], 2);

    // Convert camelCase / snake_case test name → human label
    $label = str_replace('_', ' ', preg_replace('/^test_?/', '', $name));
    $label = ucfirst($label);

    $failures = $tc->xpath('failure | error');

    if (!empty($failures)) {
        $failed++;
        $body = (string) $failures[0];
        $lines = array_map('trim', explode("\n", $body));
        $message = '';

        // 1. If it's an API JSON assertion failure, grab the exact "message" string from the dump
        if (preg_match('/"message":\s*"([^"]+)"/', $body, $match)) {
            $message = "API Error: " . $match[1];
        }
        // 2. Otherwise look for the actual PHPUnit assertion failure text
        else {
            foreach ($lines as $line) {
                if (preg_match('/^(Failed asserting that|Expected response status|Expected status code|Response status code)/i', $line)) {
                    $message = $line;
                    break;
                }
            }
        }

        // 3. Fallback to the first available lines if nothing matched
        if (empty($message)) {
            foreach ($lines as $line) {
                if ($line === '' || preg_match('/^Tests\\\\/', $line) || preg_match('/^Unable to find JSON/', $line))
                    continue;
                $message = $line;
                break;
            }
        }
        if (empty($message)) {
            $message = trim((string) ($failures[0]['message'] ?? 'Unknown error'));
            $message = explode("\n", $message)[0];
        }
        $entries[] = [
            'label' => $label,
            'status' => 'FAIL',
            'duration' => $duration,
            'reason' => $message,
        ];
    } else {
        $passed++;
        $entries[] = [
            'label' => $label,
            'status' => 'PASS',
            'duration' => $duration,
            'reason' => '',
        ];
    }
}

// ── Output Console & CSV ───────────────────────────────────────────────────
$csvData = [];

// Adding the header specifically requested by the user
$csvData[] = ['═════════════════════════════════════════════════════════════════'];
$csvData[] = ["  TEST REPORT  ·  $now"];
$csvData[] = ['═════════════════════════════════════════════════════════════════'];
$csvData[] = [];
$csvData[] = ['Icon', 'Test Name', 'Reason', 'Duration', 'Status'];

$passedTotal = 0;
$failedTotal = 0;

$lines = [];
$lines[] = '═════════════════════════════════════════════════════════════════';
$lines[] = "  TEST REPORT  ·  $now";
$lines[] = '═════════════════════════════════════════════════════════════════';
$lines[] = '';

foreach ($entries as $e) {
    if ($e['status'] === 'PASS') {
        $icon = '✓';
        $status = 'PASSED';
        $passedTotal++;
    } else {
        $icon = '✗';
        $status = 'FAILED';
        $failedTotal++;
    }

    // Add to CSV
    $csvData[] = [
        $icon,
        $e['label'],
        $e['reason'] ?: '',
        $e['duration'] . 's',
        $status
    ];

    // Single-line console output
    $paddedLabel = str_pad($e['label'], 30, ' ');
    $paddedReason = str_pad($e['reason'] ?: ' ', 60, ' ');
    $paddedDur = str_pad($e['duration'] . 's', 8, ' ', STR_PAD_LEFT);

    $lines[] = "  {$icon}  {$paddedLabel} {$paddedReason} {$paddedDur}   {$status}";
}

$total = $passedTotal + $failedTotal;
$lines[] = "";
$lines[] = str_repeat('─', 115);
$lines[] = "  TOTAL: {$total} tests  |  {$passedTotal} passed  |  {$failedTotal} failed";
$lines[] = str_repeat('─', 115);

// Write CSV
$fp = fopen($logPath, 'w');
// UTF-8 BOM for Excel visibility of icons
fputs($fp, $bom = (chr(0xEF) . chr(0xBB) . chr(0xBF)));
foreach ($csvData as $fields) {
    fputcsv($fp, $fields);
}
fclose($fp);

// Write plain text LOG
$txtLogPath = str_replace('.csv', '.log', $logPath);
$logContent = implode("\n", $lines) . "\n";
file_put_contents($txtLogPath, $logContent);

// Delete the intermediate XML if you want to keep it totally clean
if (file_exists($xmlPath)) {
    unlink($xmlPath);
}

// Print paths so PowerShell can echo them
echo "Test results written to CSV: $logPath\n";
echo "Test results written to LOG: $txtLogPath\n\n";
echo $logContent;
