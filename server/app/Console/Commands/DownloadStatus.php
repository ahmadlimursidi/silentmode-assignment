<?php

namespace App\Console\Commands;

use App\Models\DownloadRequest;
use Illuminate\Console\Command;

class DownloadStatus extends Command
{
    protected $signature = 'download:status {id : The download request ULID} {--watch : Poll every 2s until complete}';

    protected $description = 'Check the status of a download request';

    public function handle(): int
    {
        do {
            $dr = DownloadRequest::with('client:id,name')->find($this->argument('id'));

            if (!$dr) {
                $this->error("Download request [{$this->argument('id')}] not found.");
                return self::FAILURE;
            }

            if ($this->option('watch')) {
                $this->call('clear');
            }

            $this->table(['Field', 'Value'], [
                ['ID',          $dr->id],
                ['Client',      $dr->client->name ?? '-'],
                ['Status',      strtoupper($dr->status)],
                ['File',        $dr->filename ?? '-'],
                ['Progress',    $dr->progressPercent() . '%'],
                ['Received',    $this->formatBytes($dr->received_size) . ' / ' . $this->formatBytes($dr->total_size ?? 0)],
                ['Chunks',      "{$dr->received_chunks} / " . ($dr->total_chunks ?? '?')],
                ['Started',     $dr->started_at?->diffForHumans() ?? '-'],
                ['Completed',   $dr->completed_at?->diffForHumans() ?? '-'],
                ['Error',       $dr->error_message ?? '-'],
            ]);

            if (in_array($dr->status, ['completed', 'failed'])) {
                if ($dr->status === 'completed') {
                    $this->info("File saved to: {$dr->stored_path}");
                }
                break;
            }

            if ($this->option('watch')) {
                sleep(2);
            }
        } while ($this->option('watch'));

        return self::SUCCESS;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = (int) floor(log($bytes, 1024));
        return round($bytes / (1024 ** $i), 2) . ' ' . $units[$i];
    }
}
