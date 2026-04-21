<?php

namespace App\Livewire;

use App\Models\Device;
use App\Models\IpaSession;
use App\Services\SimulatorClient;
use Livewire\Attributes\Url;
use Livewire\Component;

class IpaConsole extends Component
{
    /** @var array<int, int> EIDs ticked in the device list */
    public array $selectedIds = [];

    #[Url(as: 'device')]
    public ?int $preselect = null;

    // Operation parameters (shown conditionally)
    public string $smdpAddress = '';
    public string $matchingId = '';
    public string $activationCode = '';
    public int $cancelReason = 0;

    public ?IpaSession $lastSession = null;

    /**
     * Catalog of operations offered in the console.
     *
     * Each entry: label, key (stored in DB), whether it needs a body
     * (fields to render), and a closure that builds the per-target
     * {method, path, payload} for the fan-out.
     */
    protected function operations(): array
    {
        return [
            'retrieve_eim_package' => [
                'label' => 'Retrieve eIM Package',
                'description' => 'One-shot ESipa poll (manual trigger).',
                'fields' => [],
                'build' => fn (Device $d) => [
                    'method' => 'post',
                    'path' => "/api/ipa/esipa/{$d->eid}/poll-once",
                    'payload' => [],
                ],
            ],
            'start_polling' => [
                'label' => 'Start ESipa Polling',
                'description' => 'Begin periodic eIM polling for selected devices.',
                'fields' => [],
                'build' => fn (Device $d) => [
                    'method' => 'post',
                    'path' => "/api/ipa/esipa/{$d->eid}/start-polling",
                    'payload' => [],
                ],
            ],
            'stop_polling' => [
                'label' => 'Stop ESipa Polling',
                'description' => 'Stop periodic eIM polling.',
                'fields' => [],
                'build' => fn (Device $d) => [
                    'method' => 'post',
                    'path' => "/api/ipa/esipa/{$d->eid}/stop-polling",
                    'payload' => [],
                ],
            ],
            'profile_download' => [
                'label' => 'Profile Download',
                'description' => '8-step ES9+/ES10b mutual authentication + BPP install.',
                'fields' => ['smdpAddress', 'matchingId', 'activationCode'],
                'build' => fn (Device $d) => [
                    'method' => 'post',
                    'path' => '/api/ipa/download/start',
                    'payload' => [
                        'eid' => $d->eid,
                        'smdpAddress' => $this->smdpAddress ?: ($d->default_smdp_address ?? ''),
                        'matchingId' => $this->matchingId,
                        'activationCode' => $this->activationCode,
                    ],
                ],
            ],
            'cancel_download' => [
                'label' => 'Cancel Download',
                'description' => 'Abort an in-flight profile download.',
                'fields' => ['cancelReason'],
                'build' => fn (Device $d) => [
                    'method' => 'post',
                    'path' => '/api/ipa/download/cancel',
                    'payload' => ['eid' => $d->eid, 'reason' => $this->cancelReason],
                ],
            ],
        ];
    }

    public function mount(): void
    {
        if ($this->preselect && Device::whereKey($this->preselect)->exists()) {
            $this->selectedIds = [$this->preselect];
        }
    }

    public function selectAll(): void
    {
        $this->selectedIds = Device::where('enabled', true)->pluck('id')->all();
    }

    public function clearSelection(): void
    {
        $this->selectedIds = [];
    }

    public function run(string $operation, SimulatorClient $sim): void
    {
        $catalog = $this->operations();
        abort_unless(isset($catalog[$operation]), 400, 'Unknown operation');

        $devices = Device::whereIn('id', $this->selectedIds)->where('enabled', true)->get();
        if ($devices->isEmpty()) {
            session()->flash('status', 'No devices selected.');
            return;
        }

        $session = IpaSession::create([
            'name' => $catalog[$operation]['label'] . ' · ' . $devices->count() . ' device(s)',
            'operation' => $operation,
            'device_ids' => $devices->pluck('id')->all(),
            'parameters' => collect($catalog[$operation]['fields'])->mapWithKeys(fn ($f) => [$f => $this->$f])->all(),
            'status' => 'running',
            'triggered_by' => auth()->id(),
            'started_at' => now(),
        ]);

        $targets = $devices->map(fn (Device $d) => array_merge(
            ['eid' => $d->eid],
            ($catalog[$operation]['build'])($d),
        ))->values()->all();

        $results = $sim->ipaFanOut($targets);

        $session->update([
            'results' => $results,
            'status' => collect($results)->every(fn ($r) => $r['ok']) ? 'completed' : 'failed',
            'finished_at' => now(),
        ]);

        $this->lastSession = $session->fresh();
    }

    public function render()
    {
        $devices = Device::orderBy('name')->get();
        $catalog = $this->operations();

        return view('livewire.ipa-console', [
            'devices' => $devices,
            'catalog' => $catalog,
        ])->layout('layouts.app', ['title' => 'IPA Console', 'header' => 'IPA Console']);
    }
}
