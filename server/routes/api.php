<?php

use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\DownloadController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Client management
    Route::post('clients/register', [ClientController::class, 'register']);
    Route::get('clients', [ClientController::class, 'index']);

    // Long-poll command channel — client calls this to wait for server commands
    Route::get('clients/{clientId}/poll', [ClientController::class, 'poll']);

    // Download request management
    Route::post('downloads', [DownloadController::class, 'request']);
    Route::get('downloads', [DownloadController::class, 'index']);
    Route::get('downloads/{id}', [DownloadController::class, 'show']);
    Route::get('downloads/{id}/file', [DownloadController::class, 'download']);

    // Client uploads file chunks to this endpoint
    Route::post('downloads/{id}/chunks', [DownloadController::class, 'receiveChunk']);
});
