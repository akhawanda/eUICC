<?php

namespace App\Livewire\Devices;

use App\Models\Device;
use App\Services\SimulatorClient;
use Livewire\Component;

class Show extends Component
{
    public Device $device;
    public ?array $pushResult = null;

    public function mount(Device $device): void
    {
        $this->device = $device->load(['eimAssociations', 'preloadedProfiles']);
    }

    public function pushToSim(SimulatorClient $sim): void
    {
        $this->pushResult = $sim->pushDevice($this->device);
        session()->flash('status', $this->pushResult['ok']
            ? 'Pushed to eUICC simulator'
            : 'Push failed — see details below');
    }

    public function render()
    {
        return view('livewire.devices.show')
            ->layout('layouts.app', ['title' => $this->device->name, 'header' => $this->device->name]);
    }
}
