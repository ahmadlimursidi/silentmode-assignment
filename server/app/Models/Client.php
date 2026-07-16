<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    use HasUlids;

    protected $fillable = ['name', 'secret_key', 'hostname', 'ip_address', 'last_seen_at'];

    protected $casts = [
        'last_seen_at' => 'datetime',
    ];

    public function downloadRequests(): HasMany
    {
        return $this->hasMany(DownloadRequest::class);
    }

    public function pendingDownloadRequest(): ?DownloadRequest
    {
        return $this->downloadRequests()
            ->where('status', 'pending')
            ->oldest()
            ->first();
    }
}
