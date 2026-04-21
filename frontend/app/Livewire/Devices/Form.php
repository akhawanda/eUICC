<?php

namespace App\Livewire\Devices;

use App\Models\Device;
use App\Services\SimulatorClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Form extends Component
{
    public ?Device $device = null;

    #[Validate('required|string|max:120')]
    public string $name = '';

    #[Validate('required|string|size:32|regex:/^[0-9A-Fa-f]{32}$/')]
    public string $eid = '';

    #[Validate('nullable|string|max:120')]
    public ?string $eum_manufacturer = null;

    #[Validate('nullable|string|max:255')]
    public ?string $default_smdp_address = null;

    #[Validate('nullable|string|max:1000')]
    public ?string $description = null;

    public bool $enabled = true;

    /** @var array<int, array{eim_id: string, eim_fqdn: string}> */
    public array $eimAssociations = [];

    /** @var array<int, array{iccid: string, name: string, sp_name: ?string, state: string, class: string}> */
    public array $preloadedProfiles = [];

    public bool $pushToSim = false;

    public function mount(?Device $device = null): void
    {
        if ($device && $device->exists) {
            $this->device = $device->load(['eimAssociations', 'preloadedProfiles']);
            $this->fill($device->only([
                'name', 'eid', 'eum_manufacturer', 'default_smdp_address', 'description', 'enabled',
            ]));
            $this->eimAssociations = $device->eimAssociations
                ->map(fn ($a) => $a->only(['eim_id', 'eim_fqdn']))->values()->all();
            $this->preloadedProfiles = $device->preloadedProfiles
                ->map(fn ($p) => $p->only(['iccid', 'name', 'sp_name', 'state', 'class']))->values()->all();
        } else {
            $this->eid = strtoupper(bin2hex(random_bytes(16)));
        }
    }

    public function addEim(): void
    {
        $this->eimAssociations[] = ['eim_id' => '', 'eim_fqdn' => ''];
    }

    public function removeEim(int $i): void
    {
        unset($this->eimAssociations[$i]);
        $this->eimAssociations = array_values($this->eimAssociations);
    }

    public function addProfile(): void
    {
        $this->preloadedProfiles[] = [
            'iccid' => '',
            'name' => '',
            'sp_name' => null,
            'state' => 'disabled',
            'class' => 'operational',
        ];
    }

    public function removeProfile(int $i): void
    {
        unset($this->preloadedProfiles[$i]);
        $this->preloadedProfiles = array_values($this->preloadedProfiles);
    }

    public function save(SimulatorClient $sim)
    {
        $this->eid = strtoupper($this->eid);

        $this->validate([
            ...$this->rules(),
            'eid' => [
                'required', 'string', 'size:32', 'regex:/^[0-9A-F]{32}$/',
                Rule::unique('devices', 'eid')->ignore($this->device?->id),
            ],
            'eimAssociations.*.eim_id'   => 'required|string|max:120',
            'eimAssociations.*.eim_fqdn' => 'required|string|max:255',
            'preloadedProfiles.*.iccid'  => 'required|string|max:20|regex:/^[0-9A-Fa-f]{18,20}$/',
            'preloadedProfiles.*.name'   => 'required|string|max:120',
            'preloadedProfiles.*.state'  => 'required|in:enabled,disabled',
            'preloadedProfiles.*.class'  => 'required|in:test,provisioning,operational',
        ]);

        $device = DB::transaction(function () {
            $device = $this->device ?? new Device;
            $device->fill([
                'name' => $this->name,
                'eid' => $this->eid,
                'eum_manufacturer' => $this->eum_manufacturer,
                'default_smdp_address' => $this->default_smdp_address,
                'description' => $this->description,
                'enabled' => $this->enabled,
            ])->save();

            $device->eimAssociations()->delete();
            foreach ($this->eimAssociations as $a) {
                $device->eimAssociations()->create([
                    'eim_id' => $a['eim_id'],
                    'eim_fqdn' => $a['eim_fqdn'],
                    'counter_value' => 0,
                    'supported_protocol' => 0,
                ]);
            }

            $device->preloadedProfiles()->delete();
            foreach ($this->preloadedProfiles as $p) {
                $device->preloadedProfiles()->create([
                    'iccid' => strtoupper($p['iccid']),
                    'name' => $p['name'],
                    'sp_name' => $p['sp_name'] ?? null,
                    'state' => $p['state'],
                    'class' => $p['class'],
                ]);
            }

            return $device;
        });

        if ($this->pushToSim) {
            $result = $sim->pushDevice($device->fresh(['eimAssociations', 'preloadedProfiles']));
            session()->flash('status', $result['ok']
                ? "Device saved and pushed to eUICC simulator"
                : "Device saved — push to sim failed (HTTP {$result['status']})");
        } else {
            session()->flash('status', "Device {$device->name} saved");
        }

        return redirect()->route('devices.show', $device);
    }

    public function render()
    {
        return view('livewire.devices.form')
            ->layout('layouts.app', [
                'title' => $this->device ? "Edit {$this->device->name}" : 'New device',
                'header' => $this->device ? "Edit device" : 'New device',
            ]);
    }
}
