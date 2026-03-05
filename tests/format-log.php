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
        // Use the 'message' attribute first — it's the short human-readable string
        $message = trim((string) ($failures[0]['message'] ?? ''));
        if (empty($message)) {
            // Fall back to first line of element body (full stacktrace)
            $body = (string) $failures[0];
            $message = trim(explode("\n", $body)[0]);
        }
        // Keep only first line, strip PHPUnit class prefix if present
        $message = trim(explode("\n", $message)[0]);
        $message = preg_replace('/^PHPUnit\\\\.+?:\s*/i', '', $message);
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

// ── Build log ────────────────────────────────────────────────────────────────
$sep = str_repeat('─', 65);
$total = $passed + $failed;

$lines[] = '';
$lines[] = str_repeat('═', 65);
$lines[] = "  TEST REPORT  ·  $now";
$lines[] = str_repeat('═', 65);
$lines[] = '';

foreach ($entries as $e) {
    $icon = $e['status'] === 'PASS' ? '✓' : '✗';
    $padded = str_pad($e['label'], 50, ' ');
    $dur = str_pad($e['duration'] . 's', 8, ' ', STR_PAD_LEFT);
    $status = $e['status'] === 'PASS' ? 'PASSED' : 'FAILED';
    $lines[] = "  {$icon}  {$padded} {$dur}   {$status}";
    if ($e['reason']) {
        $lines[] = "       └─ " . $e['reason'];
    }
}

$lines[] = '';
$lines[] = $sep;
$lines[] = "  TOTAL: {$total} tests  |  {$passed} passed  |  {$failed} failed";
$lines[] = $sep;
$lines[] = '';

// ── Write ─────────────────────────────────────────────────────────────────────
$content = implode("\n", $lines) . "\n";
file_put_contents($logPath, $content);

// Print path so PowerShell can echo it
echo "Log written to: $logPath\n";
echo $content;
