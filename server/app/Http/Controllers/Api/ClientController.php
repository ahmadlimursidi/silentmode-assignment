<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ClientController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $client = Client::create([
            'name'       => $validated['name'],
            'secret_key' => Str::random(64),
            'hostname'   => $request->input('hostname'),
            'ip_address' => $request->ip(),
            'last_seen_at' => now(),
        ]);

        return response()->json([
            'id'         => $client->id,
            'secret_key' => $client->secret_key,
            'message'    => 'Client registered successfully.',
        ], 201);
    }

    public function index(): JsonResponse
    {
        $clients = Client::orderByDesc('last_seen_at')->get([
            'id', 'name', 'hostname', 'ip_address', 'last_seen_at', 'created_at',
        ]);

        return response()->json(['clients' => $clients]);
    }

    /**
     * Long-poll endpoint: client waits here (up to 30s) for a pending command.
     * Returns immediately if a pending download request exists.
     */
    public function poll(string $clientId, Request $request): JsonResponse
    {
        $client = $this->authorizeClient($clientId, $request);

        $client->update(['last_seen_at' => now()]);

        $deadline = now()->addSeconds(28);

        do {
            $pending = $client->pendingDownloadRequest();

            if ($pending) {
                $pending->update(['status' => 'in_progress', 'started_at' => now()]);

                return response()->json([
                    'command'    => 'download',
                    'request_id' => $pending->id,
                    'file_path'  => $request->input('file_path', '$HOME/file_to_download.txt'),
                ]);
            }

            sleep(2);
        } while (now()->lt($deadline));

        return response()->json(['command' => 'none']);
    }

    private function authorizeClient(string $clientId, Request $request): Client
    {
        $client = Client::findOrFail($clientId);

        abort_if(
            $request->header('X-Client-Secret') !== $client->secret_key,
            403,
            'Invalid client secret.'
        );

        return $client;
    }
}
