<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Device extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'eid',
        'eum_manufacturer',
        'default_smdp_address',
        'description',
        'enabled',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public function eimAssociations(): HasMany
    {
        return $this->hasMany(DeviceEimAssociation::class);
    }

    public function preloadedProfiles(): HasMany
    {
        return $this->hasMany(DevicePreloadedProfile::class);
    }

    public function toSimulatorPayload(): array
    {
        return [
            'eid' => $this->eid,
            'name' => $this->name,
            'eum_manufacturer' => $this->eum_manufacturer,
            'default_smdp_address' => $this->default_smdp_address ?? '',
            'eim_associations' => $this->eimAssociations->map(fn ($a) => [
                'eim_id' => $a->eim_id,
                'eim_fqdn' => $a->eim_fqdn,
                'counter_value' => $a->counter_value,
                'supported_protocol' => $a->supported_protocol,
            ])->values()->all(),
            'preloaded_profiles' => $this->preloadedProfiles->map(fn ($p) => [
                'iccid' => $p->iccid,
                'name' => $p->name,
                'spName' => $p->sp_name,
                'state' => $p->state,
                'class' => $p->class,
            ])->values()->all(),
        ];
    }
}
