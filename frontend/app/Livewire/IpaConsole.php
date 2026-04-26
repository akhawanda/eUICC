<?php

namespace App\Livewire;

use App\Models\Device;
use App\Models\IpaSession;
use App\Models\SimTransaction;
use App\Services\SimulatorClient;
use Livewire\Attributes\Url;
use Livewire\Component;

class IpaConsole extends Component
{
    /** @var array<int, int> EIDs ticked in the device list */
    public array $selectedIds = [];

    #[Url(as: 'device')]
    public ?int $preselect = null;

    public string $smdpAddress = '';
    public string $matchingId = '';
    public string $activationCode = '';
    public int $cancelReason = 0;
    public int $pollIntervalSec = 30;

    public ?IpaSession $lastSession = null;

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
                'fields' => ['pollIntervalSec'],
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

        $devices = Device::with('eimAssociations')
            ->whereIn('id', $this->selectedIds)
            ->where('enabled', true)
            ->get();
        if ($devices->isEmpty()) {
            session()->flash('status', 'No devices selected.');
            return;
        }

        $session = IpaSession::create([
            'name' => $catalog[$operation]['label'].' · '.$devices->count().' device(s)',
            'operation' => $operation,
            'device_ids' => $devices->pluck('id')->all(),
            'parameters' => collect($catalog[$operation]['fields'])
                ->mapWithKeys(fn ($f) => [$f => $this->$f])->all(),
            'status' => 'running',
            'triggered_by' => auth()->id(),
            'started_at' => now(),
        ]);

        $readyDevices = [];
        $results = [];
        /** @var array<string, SimTransaction> $transactions */
        $transactions = [];

        foreach ($devices as $d) {
            $tx = SimTransaction::create([
                'ipa_session_id' => $session->id,
                'device_id' => $d->id,
                'eid' => $d->eid,
                'operation' => $operation,
                'status' => 'running',
                'triggered_by' => auth()->id(),
            ]);
            $transactions[$d->eid] = $tx;

            // ── Phase 1: push device to eUICC sim ──────────────────────
            $pushPayload = $d->toSimulatorPayload();
            $this->recordRequest($tx, 'push_device', 'euicc', 'POST', '/api/management/euicc', $pushPayload);
            $t0 = microtime(true);
            $push = $sim->pushDevice($d);
            $this->recordResponse($tx, 'push_device', 'euicc', 'POST', '/api/management/euicc', $push, (int) ((microtime(true) - $t0) * 1000));

            if (! $push['ok']) {
                $results[$d->eid] = ['ok' => false, 'status' => $push['status'], 'body' => 'eUICC push failed: '.json_encode($push['body']), 'ms' => 0];
                $this->finalizeTx($tx, 'failed', 'eUICC push failed (HTTP '.$push['status'].')');
                $this->notifyForTx($tx->fresh(), $operation, $catalog[$operation]['label']);
                continue;
            }

            // ── Phase 2: register device with IPA sim ──────────────────
            $eim = $d->eimAssociations->first();
            if (! $eim) {
                $results[$d->eid] = ['ok' => false, 'status' => 0, 'body' => 'Device has no eIM association.', 'ms' => 0];
                $this->finalizeTx($tx, 'failed', 'No eIM association on device');
                $this->notifyForTx($tx->fresh(), $operation, $catalog[$operation]['label']);
                continue;
            }
            $regPayload = [
                'eid' => $d->eid,
                'eimId' => $eim->eim_id,
                'eimFqdn' => $eim->eim_fqdn,
                'pollInterval' => max(1, $this->pollIntervalSec),
            ];
            $this->recordRequest($tx, 'register_ipa', 'ipa', 'POST', '/api/ipa/devices', $regPayload);
            $t0 = microtime(true);
            $reg = $sim->registerWithIpa($d, max(1, $this->pollIntervalSec));
            $this->recordResponse($tx, 'register_ipa', 'ipa', 'POST', '/api/ipa/devices', $reg, (int) ((microtime(true) - $t0) * 1000));

            if (! $reg['ok']) {
                $results[$d->eid] = ['ok' => false, 'status' => $reg['status'], 'body' => $reg['body'], 'ms' => 0];
                $this->finalizeTx($tx, 'failed', 'IPA register failed (HTTP '.$reg['status'].')');
                $this->notifyForTx($tx->fresh(), $operation, $catalog[$operation]['label']);
                continue;
            }

            // Tag this tx with the active polling session for this EID, if any.
            // Done after register (which is idempotent and doesn't disturb running
            // sessions) so the tag reflects the session as it existed at the moment
            // the user triggered this op.
            if ($key = $sim->activePollingKey($d->eid)) {
                $tx->update(['polling_session_key' => $key]);
            }

            $readyDevices[] = $d;
        }

        // ── Phase 3: fan-out the chosen operation to IPA sim ───────────
        if ($readyDevices) {
            $targets = collect($readyDevices)->map(fn (Device $d) => array_merge(
                ['eid' => $d->eid],
                ($catalog[$operation]['build'])($d),
            ))->values()->all();

            foreach ($targets as $t) {
                if ($tx = $transactions[$t['eid']] ?? null) {
                    $this->recordRequest($tx, 'run_op', 'ipa', strtoupper($t['method']), $t['path'], $t['payload'] ?? []);
                }
            }

            $opResults = $sim->ipaFanOut($targets);

            $targetByEid = collect($targets)->keyBy('eid');
            foreach ($opResults as $eid => $r) {
                // Extract and strip the IPA-side trace before we record
                // the outer run_op response, so the JSON tab on that step
                // isn't polluted with the inlined inner-call log.
                $trace = null;
                if (is_array($r['body'] ?? null) && isset($r['body']['_trace'])) {
                    $trace = $r['body']['_trace'];
                    unset($r['body']['_trace']);
                }
                $opResults[$eid] = $r;

                if ($tx = $transactions[$eid] ?? null) {
                    $t = $targetByEid[$eid] ?? null;
                    $this->recordResponse(
                        $tx,
                        'run_op',
                        'ipa',
                        strtoupper($t['method'] ?? 'POST'),
                        $t['path'] ?? '/',
                        $r,
                        $r['ms'] ?? 0,
                    );
                    if (is_array($trace)) {
                        foreach ($trace as $traceStep) {
                            $this->recordTraceStep($tx, 'run_op', $traceStep);
                        }
                    }
                    [$status, $summary] = $this->deriveOutcome($r);
                    // For start_polling: the session starts during run_op, so
                    // re-check now and tag if needed.
                    if (! $tx->polling_session_key && $operation === 'start_polling') {
                        if ($key = $sim->activePollingKey($eid)) {
                            $tx->update(['polling_session_key' => $key]);
                        }
                    }
                    $this->finalizeTx($tx, $status, $summary);
                    $this->notifyForTx($tx->fresh(), $operation, $catalog[$operation]['label']);
                }
            }

            $results = array_merge($results, $opResults);
        }

        $session->update([
            'results' => $results,
            'status' => collect($results)->every(fn ($r) => $r['ok']) ? 'completed' : 'failed',
            'finished_at' => now(),
        ]);

        $this->lastSession = $session->fresh();
    }

    private function recordRequest(SimTransaction $tx, string $phase, string $actorTo, string $method, string $endpoint, ?array $payload): void
    {
        $tx->addStep([
            'direction' => 'request',
            'phase' => $phase,
            'actor_from' => 'dashboard',
            'actor_to' => $actorTo,
            'method' => $method,
            'endpoint' => $endpoint,
            'http_headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            'http_body' => $payload
                ? json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                : null,
        ]);
    }

    private function recordResponse(SimTransaction $tx, string $phase, string $actorFrom, string $method, string $endpoint, array $result, int $ms): void
    {
        $body = $result['body'] ?? null;
        $tx->addStep([
            'direction' => 'response',
            'phase' => $phase,
            'actor_from' => $actorFrom,
            'actor_to' => 'dashboard',
            'method' => $method,
            'endpoint' => $endpoint,
            'http_status' => $result['status'] ?? null,
            'http_headers' => ['Content-Type' => 'application/json'],
            'http_body' => is_string($body)
                ? $body
                : ($body !== null ? json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : null),
            'response_time_ms' => $ms,
        ]);
    }

    private function recordTraceStep(SimTransaction $tx, string $phase, array $t): void
    {
        $body = $t['http_body'] ?? null;
        if (is_array($body) || is_object($body)) {
            $body = json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } elseif (is_string($body)) {
            // Pretty-print JSON bodies the IPA already stringified.
            $decoded = json_decode($body, true);
            if (json_last_error() === JSON_ERROR_NONE && $decoded !== null) {
                $body = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }
        }

        $tx->addStep([
            'direction' => $t['direction'] ?? 'request',
            'phase' => $phase,
            'actor_from' => $t['actor_from'] ?? 'ipa',
            'actor_to' => $t['actor_to'] ?? 'ipa',
            'method' => $t['method'] ?? null,
            'endpoint' => $t['endpoint'] ?? null,
            'http_status' => $t['http_status'] ?? null,
            'http_headers' => $t['http_headers'] ?? null,
            'http_body' => $body,
            'response_time_ms' => $t['response_time_ms'] ?? null,
        ]);
    }

    private function finalizeTx(SimTransaction $tx, string $status, ?string $summary = null): void
    {
        $tx->update([
            'status' => $status,
            'result_summary' => $summary,
            'duration_ms' => (int) $tx->created_at->diffInMilliseconds(now()),
        ]);
    }

    /**
     * Push a toast event for a finalised transaction. Picks a tone + deep
     * link appropriate for the operation (start/stop polling go to /polling,
     * everything else to the tx detail page).
     */
    private function notifyForTx(SimTransaction $tx, string $operation, string $label): void
    {
        $type = $tx->status === 'completed' ? 'success' : 'failed';
        $tail = '…'.substr($tx->eid, -8);
        if ($operation === 'start_polling' && $tx->status === 'completed') {
            $title = 'Polling started';
            $message = "{$label} for {$tail}. " . ($tx->result_summary ?: 'Background poll cycle running.');
            $link = route('polling.index');
            $linkLabel = 'View on /polling →';
            $type = 'info';
        } elseif ($operation === 'stop_polling' && $tx->status === 'completed') {
            $title = 'Polling stopped';
            $message = "{$label} for {$tail}.";
            $link = route('polling.index');
            $linkLabel = 'View on /polling →';
            $type = 'info';
        } elseif ($tx->status === 'completed') {
            $title = "{$label} done";
            $message = "Tx #{$tx->id} · {$tx->result_summary}";
            $link = route('transactions.show', $tx);
            $linkLabel = "Open tx #{$tx->id} →";
            $type = 'success';
        } else {
            $title = "{$label} failed";
            $message = "Tx #{$tx->id} · " . ($tx->result_summary ?: 'see transaction for details');
            $link = route('transactions.show', $tx);
            $linkLabel = "Open tx #{$tx->id} →";
            $type = 'error';
        }

        session()->flash('toast', [
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'link' => $link,
            'linkLabel' => $linkLabel,
        ]);
    }

    /**
     * Derive operational status from the IPA's response body.
     *
     * Returns [status, summary] where status reflects whether the requested
     * operation actually achieved its goal — not just whether the HTTP call
     * succeeded. eIM marks queue rows `failed` for non-zero PSMO results
     * and parsing/transport failures; we mirror that here so the eUICC
     * dashboard tells the same story.
     *
     * Failure detection layers, in order:
     *   1. HTTP non-2xx
     *   2. Top-level `error` field (e.g., device_not_registered)
     *   3. `result.euiccPackageError` (eUICC rejected: unknownEim, counterMismatch)
     *   4. Per-op failures inside `result.euiccPackageResult`
     *   5. `eimResponse.error` after a successful relay (eIM didn't ack)
     *   6. Known-failure `status` values (parse / transport / dispatch)
     *   7. Default: completed
     */
    private function deriveOutcome(array $r): array
    {
        if (! ($r['ok'] ?? false)) {
            $msg = is_string($r['body'] ?? null)
                ? substr($r['body'], 0, 200)
                : 'HTTP '.($r['status'] ?? 0);
            return ['failed', $msg];
        }

        $body = is_array($r['body'] ?? null) ? $r['body'] : null;
        if (! $body) {
            return ['completed', 'OK'];
        }

        if (! empty($body['error'])) {
            return ['failed', is_string($body['error']) ? $body['error'] : json_encode($body['error'])];
        }

        $pkgError = $body['result']['euiccPackageError'] ?? null;
        if ($pkgError) {
            return ['failed', 'euiccPackageError: '.$pkgError];
        }

        $opResults = $body['result']['euiccPackageResult'] ?? null;
        if (is_array($opResults)) {
            $bad = collect($opResults)
                ->filter(fn ($o) => ($o['result'] ?? null) !== 'ok')
                ->map(fn ($o) => ($o['action'] ?? '?').': '.($o['result'] ?? '?'))
                ->values()
                ->all();
            if ($bad) {
                return ['failed', implode(' · ', $bad)];
            }
            $good = collect($opResults)
                ->map(fn ($o) => ($o['action'] ?? '?').': ok')
                ->implode(' · ');
            $tail = $this->eimAckTail($body);
            return ['completed', trim(($good ?: 'OK').$tail)];
        }

        $status = $body['status'] ?? null;
        $failureStatuses = [
            'eim_unreachable', 'euicc_unreachable',
            'invalid_euicc_package', 'invalid_download_trigger',
            'unknown_package_type',
            'auth_failed', 'download_failed', 'failed',
            'error',
        ];
        if (in_array($status, $failureStatuses, true)) {
            $detail = $body['error']
                ?? $body['hint']
                ?? ($body['code'] !== null ? 'code='.$body['code'] : null)
                ?? ($body['httpStatus'] ?? null);
            return ['failed', $status.($detail ? ': '.$detail : '')];
        }

        $okStatuses = [
            'profile_installed', 'data_collected', 'no_package',
            'polling_started', 'polling_stopped', 'already_polling',
            'euicc_package_processed',
        ];
        if (in_array($status, $okStatuses, true)) {
            $tail = $this->eimAckTail($body);
            return ['completed', $status.$tail];
        }

        if ($status) {
            return ['failed', $status];
        }

        return ['completed', 'OK'];
    }

    /**
     * If the IPA reported the result back to eIM and got a non-Executed-Success
     * response, surface that — the eUICC did its job but eIM rejected the relay.
     */
    private function eimAckTail(array $body): string
    {
        $eim = $body['eimResponse'] ?? null;
        if (is_array($eim) && ! empty($eim['error'])) {
            return ' (eIM relay failed: '.$eim['error'].')';
        }
        return '';
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
