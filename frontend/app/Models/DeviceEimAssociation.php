<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceEimAssociation extends Model
{
    protected $fillable = [
        'device_id',
        'eim_id',
        'eim_fqdn',
        'counter_value',
        'supported_protocol',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
