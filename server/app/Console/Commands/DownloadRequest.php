<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\DownloadRequest as DownloadRequestModel;
use Illuminate\Console\Command;

class DownloadRequest extends Command
{
    protected $signature = 'download:request {client_id : The ULID of the target client}';

    protected $description = 'Queue a file download request for a connected on-premise client';

    public function handle(): int
    {
        $clientId = $this->argument('client_id');
        $client   = Client::find($clientId);

        if (!$client) {
            $this->error("Client [{$clientId}] not found.");
            return self::FAILURE;
        }

        $dr = DownloadRequestModel::create([
            'client_id' => $client->id,
            'status'    => 'pending',
        ]);

        $this->info("Download request queued.");
        $this->table(['Field', 'Value'], [
            ['Request ID', $dr->id],
            ['Client',     "{$client->name} ({$client->id})"],
            ['Status',     $dr->status],
        ]);

        $this->line('');
        $this->line('The client will pick this up on its next poll cycle.');
        $this->line("Monitor progress: php artisan download:status {$dr->id}");

        return self::SUCCESS;
    }
}
