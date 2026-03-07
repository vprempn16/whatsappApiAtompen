<?php

$dir = __DIR__ . '/database/migrations';
$files = glob($dir . '/*.php');

foreach ($files as $file) {
    if (!is_file($file))
        continue;

    $content = file_get_contents($file);

    // Look for lines that declare an auto-increment column (e.g. bigInteger('id', true))
    if (preg_match('/->(?:big)?integer\s*\(\s*\'id\'\s*,\s*true\s*\)/i', $content)) {
        // Find and remove the duplicate ->primary('id'); declaration
        $newContent = preg_replace('/^\s*\$table->primary\(\'id\'\);\s*$/m', '', $content);

        if ($content !== $newContent) {
            file_put_contents($file, $newContent);
            echo "Fixed $file\n";
        }
    }
}
