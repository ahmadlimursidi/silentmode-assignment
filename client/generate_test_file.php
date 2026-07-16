#!/usr/bin/env php
<?php

/**
 * Generates a ~100MB test file at $HOME/file_to_download.txt
 * Run this once on the client machine before starting the client agent.
 */

$targetPath = $_SERVER['HOME'] . '/file_to_download.txt';
$targetSize = 100 * 1024 * 1024; // 100 MB

echo "Generating ~100MB test file at: {$targetPath}\n";

$handle = fopen($targetPath, 'wb');
$written = 0;
$line = str_repeat('A', 1023) . "\n"; // 1024 bytes per line
$lineLen = strlen($line);

while ($written < $targetSize) {
    fwrite($handle, $line);
    $written += $lineLen;
}

fclose($handle);

$actual = filesize($targetPath);
echo "Done! File size: " . round($actual / 1024 / 1024, 2) . " MB\n";
