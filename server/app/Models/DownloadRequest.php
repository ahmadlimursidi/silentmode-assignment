<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DownloadRequest extends Model
{
    use HasUlids;

    protected $fillable = [
        'client_id', 'status', 'filename', 'stored_path',
        'total_size', 'received_size', 'total_chunks', 'received_chunks',
        'error_message', 'started_at', 'completed_at',
    ];

    protected $casts = [
        'started_at'  => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function progressPercent(): float
    {
        if (!$this->total_size) {
            return 0;
        }
        return round(($this->received_size / $this->total_size) * 100, 1);
    }

    public function chunkDirectory(): string
    {
        return storage_path("app/downloads/{$this->id}/chunks");
    }

    public function finalDirectory(): string
    {
        return storage_path("app/downloads/{$this->id}");
    }
}
