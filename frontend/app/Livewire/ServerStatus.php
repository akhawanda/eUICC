<?php

namespace App\Livewire;

use App\Models\IpaSession;
use App\Services\SimulatorClient;
use Livewire\Component;

class ServerStatus extends Component
{
    public array $euicc = ['ok' => null, 'status' => 0, 'body' => null];
    public array $ipa   = ['ok' => null, 'status' => 0, 'body' => null];

    public function mount(SimulatorClient $sim): void
    {
        $this->refresh($sim);
    }

    public function refresh(SimulatorClient $sim): void
    {
        $this->euicc = $sim->euiccHealth();
        $this->ipa   = $sim->ipaHealth();
    }

    public function render()
    {
        $recent = IpaSession::with('user')
            ->latest('id')
            ->limit(15)
            ->get();

        return view('livewire.server-status', [
            'recent' => $recent,
        ])->layout('layouts.app', ['title' => 'Server Status', 'header' => 'Server Status']);
    }
}
