<?php

namespace App\Livewire;

use App\Services\SimulatorClient;
use Illuminate\Support\Facades\Http;
use Livewire\Attributes\On;
use Livewire\Component;

class PollingIndex extends Component
{
    public array $sessions = [];
    public ?string $error = null;

    public function mount(SimulatorClient $sim): void
    {
        $this->refresh($sim);
    }

    #[On('polling-changed')]
    public function refresh(SimulatorClient $sim): void
    {
        try {
            $resp = Http::timeout(5)->get($sim->ipaBase().'/api/ipa/polling');
            $sessions = $resp->json('polling') ?? [];
            // Decorate each session with its polling_session_key + tx counts
            // so the table can link straight to /transactions filtered.
            foreach ($sessions as &$s) {
                $key = ($s['eid'] ?? '').'@'.(int) ($s['startedAt'] ?? 0);
                $s['key'] = $key;
                $s['txCount'] = \App\Models\SimTransaction::where('polling_session_key', $key)->count();
                $s['firstTxId'] = \App\Models\SimTransaction::where('polling_session_key', $key)
                    ->orderBy('id')->value('id');
            }
            unset($s);
            $this->sessions = $sessions;
            $this->error = null;
        } catch (\Throwable $e) {
            $this->sessions = [];
            $this->error = $e->getMessage();
        }
    }

    public function stop(string $eid, SimulatorClient $sim): void
    {
        try {
            Http::timeout(5)->post($sim->ipaBase()."/api/ipa/esipa/{$eid}/stop-polling");
            $this->dispatch('polling-changed');
            session()->flash('status', "Stopped polling for {$eid}");
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
        $this->refresh($sim);
    }

    public function render()
    {
        return view('livewire.polling-index')
            ->layout('layouts.app', ['title' => 'Active Polling', 'header' => 'Active Polling']);
    }
}
