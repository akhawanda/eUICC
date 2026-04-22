<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SimTransactionStep extends Model
{
    protected $fillable = [
        'sim_transaction_id',
        'order',
        'direction',
        'phase',
        'actor_from',
        'actor_to',
        'method',
        'endpoint',
        'http_status',
        'http_headers',
        'http_body',
        'response_time_ms',
    ];

    protected $casts = [
        'http_headers' => 'array',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(SimTransaction::class, 'sim_transaction_id');
    }

    public function isRequest(): bool
    {
        return $this->direction === 'request';
    }
}
