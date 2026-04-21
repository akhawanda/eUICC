<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IpaSession extends Model
{
    protected $fillable = [
        'name',
        'operation',
        'device_ids',
        'parameters',
        'results',
        'status',
        'triggered_by',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'device_ids' => 'array',
        'parameters' => 'array',
        'results' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function devices()
    {
        return Device::whereIn('id', $this->device_ids ?? [])->get();
    }
}
