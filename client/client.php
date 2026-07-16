#!/usr/bin/env php
<?php

/**
 * On-Premise Client Agent
 *
 * Runs on each restaurant machine. Registers with the cloud server, then
 * long-polls for commands. When the server requests a file download, this
 * script reads $HOME/file_to_download.txt and uploads it in chunks.
 *
 * Usage:
 *   php client.php --server=https://your-server.com --name="Restaurant ABC"
 *
 * The client ID and secret are saved to client_config.json after first registration.
 */

// ── Configuration ──────────────────────────────────────────────────────────────

$options = getopt('', [
    'server:',          // required: base URL of the Laravel server
    'name:',            // required on first run: human-readable client name
    'file:',            // optional: path to file (default: $HOME/file_to_download.txt)
    'chunk-size:',      // optional: chunk size in MB (default: 2)
    'config:',          // optional: path to config file (default: ./client_config.json)
]);

$serverBase  = rtrim($options['server'] ?? '', '/');
$clientName  = $options['name']         ?? gethostname();
$filePath    = $options['file']         ?? ($_SERVER['HOME'] . '/file_to_download.txt');
$chunkSizeMB = (int) ($options['chunk-size'] ?? 2);
$configFile  = $options['config']       ?? __DIR__ . '/client_config.json';

if (empty($serverBase)) {
    fwrite(STDERR, "Error: --server is required. Example: php client.php --server=http://localhost\n");
    exit(1);
}

$chunkSize = $chunkSizeMB * 1024 * 1024;

// ── Helpers ────────────────────────────────────────────────────────────────────

function log_msg(string $level, string $msg): void
{
    $ts = date('Y-m-d H:i:s');
    echo "[{$ts}] [{$level}] {$msg}\n";
}

function api(string $method, string $url, array $headers = [], $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => array_map(
            fn ($k, $v) => "{$k}: {$v}",
            array_keys($headers),
            array_values($headers)
        ),
        CURLOPT_TIMEOUT        => 35,       // slightly above server 30s long-poll
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
    ]);

    if ($body !== null) {
        if (is_array($body)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
    }

    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        throw new RuntimeException("cURL error: {$err}");
    }

    $decoded = json_decode($raw, true);
    return ['code' => $code, 'body' => $decoded, 'raw' => $raw];
}

function format_bytes(int $bytes): string
{
    if ($bytes === 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = (int) floor(log($bytes, 1024));
    return round($bytes / (1024 ** $i), 2) . ' ' . $units[$i];
}

// ── Registration ───────────────────────────────────────────────────────────────

function load_or_register(string $configFile, string $serverBase, string $clientName): array
{
    if (file_exists($configFile)) {
        $cfg = json_decode(file_get_contents($configFile), true);
        if (!empty($cfg['id']) && !empty($cfg['secret_key'])) {
            log_msg('INFO', "Loaded existing client ID: {$cfg['id']}");
            return $cfg;
        }
    }

    log_msg('INFO', "Registering new client as \"{$clientName}\"...");

    $res = api('POST', "{$serverBase}/api/v1/clients/register", [
        'Accept'       => 'application/json',
        'Content-Type' => 'application/json',
    ], json_encode(['name' => $clientName, 'hostname' => gethostname()]));

    if ($res['code'] !== 201) {
        throw new RuntimeException("Registration failed (HTTP {$res['code']}): {$res['raw']}");
    }

    $cfg = [
        'id'         => $res['body']['id'],
        'secret_key' => $res['body']['secret_key'],
        'name'       => $clientName,
        'server'     => $serverBase,
    ];

    file_put_contents($configFile, json_encode($cfg, JSON_PRETTY_PRINT));
    log_msg('INFO', "Registered! Client ID: {$cfg['id']}");
    log_msg('INFO', "Config saved to: {$configFile}");

    return $cfg;
}

// ── File Upload ────────────────────────────────────────────────────────────────

function upload_file(
    string $serverBase,
    array  $clientCfg,
    string $requestId,
    string $filePath,
    int    $chunkSize
): void {
    if (!file_exists($filePath)) {
        log_msg('ERROR', "File not found: {$filePath}");
        return;
    }

    $filename  = basename($filePath);
    $totalSize = filesize($filePath);
    $totalChunks = (int) ceil($totalSize / $chunkSize);

    log_msg('INFO', "Starting upload: {$filename} (" . format_bytes($totalSize) . ", {$totalChunks} chunks of " . format_bytes($chunkSize) . ")");

    $handle = fopen($filePath, 'rb');
    $uploadUrl = "{$serverBase}/api/v1/downloads/{$requestId}/chunks";
    $authHeaders = ['X-Client-Secret' => $clientCfg['secret_key']];

    for ($i = 0; $i < $totalChunks; $i++) {
        $data = fread($handle, $chunkSize);

        // Write chunk to a temp file so cURL can send it as multipart
        $tmpFile = tempnam(sys_get_temp_dir(), 'chunk_');
        file_put_contents($tmpFile, $data);

        $res = api('POST', $uploadUrl, $authHeaders, [
            'chunk_index'  => $i,
            'total_chunks' => $totalChunks,
            'total_size'   => $totalSize,
            'filename'     => $filename,
            'chunk'        => new CURLFile($tmpFile, 'application/octet-stream', 'chunk.bin'),
        ]);

        unlink($tmpFile);

        if ($res['code'] !== 200) {
            fclose($handle);
            log_msg('ERROR', "Chunk {$i} upload failed (HTTP {$res['code']}): {$res['raw']}");
            return;
        }

        $percent = round((($i + 1) / $totalChunks) * 100, 1);
        echo "\r  Uploading... {$percent}% (" . ($i + 1) . "/{$totalChunks} chunks)    ";
    }

    fclose($handle);
    echo "\n";
    log_msg('INFO', "Upload complete for request [{$requestId}].");
}

// ── Main Loop ──────────────────────────────────────────────────────────────────

try {
    $cfg = load_or_register($configFile, $serverBase, $clientName);
} catch (RuntimeException $e) {
    log_msg('ERROR', $e->getMessage());
    exit(1);
}

$pollUrl     = "{$serverBase}/api/v1/clients/{$cfg['id']}/poll";
$authHeaders = [
    'Accept'          => 'application/json',
    'X-Client-Secret' => $cfg['secret_key'],
];

log_msg('INFO', "Starting poll loop. Waiting for server commands...");
log_msg('INFO', "File to serve: {$filePath}");
log_msg('INFO', "Press Ctrl+C to stop.");

while (true) {
    try {
        $res = api('GET', $pollUrl, $authHeaders);

        if ($res['code'] === 200 && isset($res['body']['command'])) {
            $command = $res['body']['command'];

            if ($command === 'download') {
                $requestId = $res['body']['request_id'];
                log_msg('INFO', "Received download command. Request ID: {$requestId}");
                upload_file($serverBase, $cfg, $requestId, $filePath, $chunkSize);
            }
            // 'none' means timeout with no command — loop immediately
        } elseif ($res['code'] === 401 || $res['code'] === 403) {
            log_msg('ERROR', "Authentication failed. Delete client_config.json and re-run to re-register.");
            exit(1);
        } else {
            log_msg('WARN', "Unexpected response (HTTP {$res['code']}). Retrying in 5s...");
            sleep(5);
        }
    } catch (RuntimeException $e) {
        log_msg('WARN', "Connection error: {$e->getMessage()}. Retrying in 10s...");
        sleep(10);
    }
}
