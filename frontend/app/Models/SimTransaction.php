<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SimTransaction extends Model
{
    protected $fillable = [
        'ipa_session_id',
        'device_id',
        'eid',
        'operation',
        'polling_session_key',
        'status',
        'result_summary',
        'duration_ms',
        'triggered_by',
    ];

    public function steps(): HasMany
    {
        return $this->hasMany(SimTransactionStep::class)->orderBy('order');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(IpaSession::class, 'ipa_session_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function addStep(array $attrs): SimTransactionStep
    {
        return $this->steps()->create(array_merge(
            ['order' => $this->steps()->count()],
            $attrs,
        ));
    }
}
