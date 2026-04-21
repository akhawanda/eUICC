<?php

namespace App\Livewire\Devices;

use App\Models\Device;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $search = '';
    public string $statusFilter = 'all';

    protected $queryString = ['search', 'statusFilter'];

    public function updating($name): void
    {
        if (in_array($name, ['search', 'statusFilter'], true)) {
            $this->resetPage();
        }
    }

    public function toggleEnabled(int $id): void
    {
        $d = Device::findOrFail($id);
        $d->update(['enabled' => ! $d->enabled]);
        session()->flash('status', "Device {$d->name} " . ($d->enabled ? 'enabled' : 'disabled'));
    }

    public function delete(int $id): void
    {
        $d = Device::findOrFail($id);
        $name = $d->name;
        $d->delete();
        session()->flash('status', "Device {$name} deleted");
    }

    public function clone(int $id): void
    {
        $d = Device::with(['eimAssociations', 'preloadedProfiles'])->findOrFail($id);
        $copy = $d->replicate();
        $copy->name = $d->name . ' (copy)';
        // EID must remain unique — user edits after clone
        $copy->eid = substr(bin2hex(random_bytes(16)), 0, 32);
        $copy->save();

        foreach ($d->eimAssociations as $a) {
            $copy->eimAssociations()->create($a->only(['eim_id', 'eim_fqdn', 'counter_value', 'supported_protocol']));
        }
        foreach ($d->preloadedProfiles as $p) {
            $copy->preloadedProfiles()->create($p->only(['iccid', 'name', 'sp_name', 'state', 'class']));
        }

        session()->flash('status', "Cloned as {$copy->name} — edit EID before use");
        $this->redirectRoute('devices.edit', $copy);
    }

    public function render()
    {
        $q = Device::query()->withCount(['eimAssociations', 'preloadedProfiles']);

        if ($this->search !== '') {
            $term = '%' . $this->search . '%';
            $q->where(fn ($x) => $x
                ->where('name', 'like', $term)
                ->orWhere('eid', 'like', $term)
                ->orWhere('eum_manufacturer', 'like', $term));
        }
        if ($this->statusFilter === 'enabled') {
            $q->where('enabled', true);
        } elseif ($this->statusFilter === 'disabled') {
            $q->where('enabled', false);
        }

        return view('livewire.devices.index', [
            'devices' => $q->orderByDesc('id')->paginate(20),
        ])->layout('layouts.app', ['title' => 'Devices', 'header' => 'Devices']);
    }
}
