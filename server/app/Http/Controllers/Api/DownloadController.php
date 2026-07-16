<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\DownloadRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadController extends Controller
{
    /** Server triggers a download from a specific client. */
    public function request(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_id' => 'required|ulid|exists:clients,id',
        ]);

        $downloadRequest = DownloadRequest::create([
            'client_id' => $validated['client_id'],
            'status'    => 'pending',
        ]);

        return response()->json([
            'id'      => $downloadRequest->id,
            'message' => 'Download request queued. Client will upload the file when it next polls.',
        ], 201);
    }

    public function index(): JsonResponse
    {
        $requests = DownloadRequest::with('client:id,name')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['download_requests' => $requests]);
    }

    public function show(string $id): JsonResponse
    {
        $dr = DownloadRequest::with('client:id,name')->findOrFail($id);

        return response()->json([
            'download_request' => array_merge($dr->toArray(), [
                'progress_percent' => $dr->progressPercent(),
            ]),
        ]);
    }

    /** Client uploads a single chunk of the file. */
    public function receiveChunk(string $id, Request $request): JsonResponse
    {
        $dr = DownloadRequest::findOrFail($id);

        abort_if(!in_array($dr->status, ['pending', 'in_progress']), 409, 'Request is not active.');

        $validated = $request->validate([
            'chunk_index'  => 'required|integer|min:0',
            'total_chunks' => 'required|integer|min:1',
            'total_size'   => 'required|integer|min:1',
            'filename'     => 'required|string|max:255',
            'chunk'        => 'required|file',
        ]);

        $chunkDir = $dr->chunkDirectory();
        if (!is_dir($chunkDir)) {
            mkdir($chunkDir, 0755, true);
        }

        $chunkSize = $request->file('chunk')->getSize();
        $request->file('chunk')->move($chunkDir, "{$validated['chunk_index']}.bin");

        $dr->update([
            'status'          => 'in_progress',
            'filename'        => $validated['filename'],
            'total_size'      => $validated['total_size'],
            'total_chunks'    => $validated['total_chunks'],
            'received_chunks' => $validated['chunk_index'] + 1,
            'received_size'   => $dr->received_size + $chunkSize,
            'started_at'      => $dr->started_at ?? now(),
        ]);

        $allReceived = $validated['chunk_index'] + 1 >= $validated['total_chunks'];

        if ($allReceived) {
            $this->assembleChunks($dr, $validated['total_chunks']);
        }

        return response()->json([
            'received_chunk'  => $validated['chunk_index'],
            'total_chunks'    => $validated['total_chunks'],
            'progress_percent' => $dr->fresh()->progressPercent(),
            'complete'        => $allReceived,
        ]);
    }

    /** Stream the fully assembled file to the requester. */
    public function download(string $id): StreamedResponse
    {
        $dr = DownloadRequest::findOrFail($id);

        abort_if($dr->status !== 'completed', 404, 'File not yet available.');
        abort_unless(file_exists($dr->stored_path), 404, 'File missing on disk.');

        return response()->streamDownload(function () use ($dr) {
            $handle = fopen($dr->stored_path, 'rb');
            while (!feof($handle)) {
                echo fread($handle, 8192);
                ob_flush();
                flush();
            }
            fclose($handle);
        }, $dr->filename);
    }

    private function assembleChunks(DownloadRequest $dr, int $totalChunks): void
    {
        $finalPath = $dr->finalDirectory() . '/' . $dr->filename;
        $out = fopen($finalPath, 'wb');

        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkPath = $dr->chunkDirectory() . "/{$i}.bin";
            if (!file_exists($chunkPath)) {
                fclose($out);
                $dr->update(['status' => 'failed', 'error_message' => "Missing chunk {$i}."]);
                return;
            }
            $in = fopen($chunkPath, 'rb');
            stream_copy_to_stream($in, $out);
            fclose($in);
            unlink($chunkPath);
        }

        fclose($out);
        rmdir($dr->chunkDirectory());

        $dr->update([
            'status'       => 'completed',
            'stored_path'  => $finalPath,
            'completed_at' => now(),
        ]);
    }
}
