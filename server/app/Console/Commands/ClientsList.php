<?php

namespace App\Console\Commands;

use App\Models\Client;
use Illuminate\Console\Command;

class ClientsList extends Command
{
    protected $signature = 'clients:list';

    protected $description = 'List all registered on-premise clients';

    public function handle(): int
    {
        $clients = Client::orderByDesc('last_seen_at')->get();

        if ($clients->isEmpty()) {
            $this->warn('No clients registered yet.');
            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Hostname', 'IP Address', 'Last Seen'],
            $clients->map(fn ($c) => [
                $c->id,
                $c->name,
                $c->hostname ?? '-',
                $c->ip_address ?? '-',
                $c->last_seen_at?->diffForHumans() ?? 'Never',
            ])
        );

        return self::SUCCESS;
    }
}
