<?php
$component = app(App\Livewire\IpaConsole::class);
$component->mount();
$component->selectedIds = [2];
$component->run("retrieve_eim_package", app(App\Services\SimulatorClient::class));
$tx = App\Models\SimTransaction::latest("id")->first();
echo "tx " . $tx->id . " status=" . $tx->status . " steps=" . $tx->steps()->count() . " summary=" . ($tx->result_summary ?? "-") . PHP_EOL;
