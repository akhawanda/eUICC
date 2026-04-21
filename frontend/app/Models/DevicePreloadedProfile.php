<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DevicePreloadedProfile extends Model
{
    protected $fillable = [
        'device_id',
        'iccid',
        'name',
        'sp_name',
        'state',
        'class',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
