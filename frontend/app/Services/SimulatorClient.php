<?php

namespace App\Services;

use App\Models\Device;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;

class SimulatorClient
{
    public function euiccBase(): string
    {
        return rtrim(config('simulators.euicc.url'), '/');
    }

    public function ipaBase(): string
    {
        return rtrim(config('simulators.ipa.url'), '/');
    }

    public function euiccHealth(): array
    {
        return $this->health($this->euiccBase());
    }

    public function ipaHealth(): array
    {
        return $this->health($this->ipaBase());
    }

    /**
     * Return the polling-session key for an EID if a background poll is
     * currently running, else null. Key shape: `{eid}@{startedAtUnix}`.
     */
    public function activePollingKey(string $eid): ?string
    {
        try {
            $resp = Http::timeout(3)->get($this->ipaBase().'/api/ipa/polling');
            foreach ($resp->json('polling') ?? [] as $s) {
                if (strcasecmp($s['eid'] ?? '', $eid) === 0 && ! empty($s['startedAt'])) {
                    return $eid.'@'.(int) $s['startedAt'];
                }
            }
        } catch (\Throwable) {
            // network/IPA hiccup — caller treats null as "not in a polling session"
        }
        return null;
    }

    protected function health(string $base): array
    {
        try {
            $resp = Http::timeout(3)->get("$base/health");
            return [
                'ok' => $resp->successful(),
                'status' => $resp->status(),
                'body' => $resp->json() ?? $resp->body(),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => 0, 'body' => $e->getMessage()];
        }
    }

    public function pushDevice(Device $device): array
    {
        $resp = Http::timeout(config('simulators.euicc.timeout'))
            ->post($this->euiccBase().'/api/management/euicc', $device->toSimulatorPayload());

        return ['ok' => $resp->successful(), 'status' => $resp->status(), 'body' => $resp->json() ?? $resp->body()];
    }

    public function deleteDevice(string $eid): array
    {
        $resp = Http::timeout(config('simulators.euicc.timeout'))
            ->delete($this->euiccBase()."/api/management/euicc/$eid");

        return ['ok' => $resp->successful(), 'status' => $resp->status(), 'body' => $resp->json() ?? $resp->body()];
    }

    /**
     * Register a device with the IPA simulator (idempotent — a second
     * POST for the same EID is safe, the IPA sim updates the session).
     *
     * Returns an 'ok' result that callers can short-circuit on if the
     * device has no eIM association yet.
     */
    public function registerWithIpa(Device $device, int $pollInterval = 30): array
    {
        $eim = $device->eimAssociations->first();
        if (! $eim) {
            return [
                'ok' => false,
                'status' => 0,
                'body' => 'Device has no eIM association — add one on the device form before running IPA operations.',
            ];
        }

        $resp = Http::timeout(config('simulators.ipa.timeout'))
            ->post($this->ipaBase().'/api/ipa/devices', [
                'eid' => $device->eid,
                'eimId' => $eim->eim_id,
                'eimFqdn' => $eim->eim_fqdn,
                'pollInterval' => $pollInterval,
            ]);

        return [
            'ok' => $resp->successful(),
            'status' => $resp->status(),
            'body' => $resp->json() ?? $resp->body(),
        ];
    }

    /**
     * Fan out IPA operations to many devices in parallel.  Each target
     * carries its own method + path + payload so routes that embed the
     * EID in the path (ESipa poll, download status) work alongside ones
     * that take the EID in the body.
     *
     * @param  array<int, array{eid: string, method: string, path: string, payload?: array}>  $targets
     * @return array<string, array{ok: bool, status: int, body: mixed, ms: int}>  keyed by EID
     */
    public function ipaFanOut(array $targets): array
    {
        $base = $this->ipaBase();
        $results = [];

        $responses = Http::pool(function (Pool $pool) use ($base, $targets) {
            $reqs = [];
            foreach ($targets as $t) {
                $method = strtolower($t['method'] ?? 'post');
                $url = $base.$t['path'];
                $req = $pool->as($t['eid'])->timeout(config('simulators.ipa.timeout'));
                $reqs[] = match ($method) {
                    'get' => $req->get($url),
                    'delete' => $req->delete($url, $t['payload'] ?? []),
                    default => $req->post($url, $t['payload'] ?? []),
                };
            }
            return $reqs;
        });

        foreach ($responses as $eid => $resp) {
            if ($resp instanceof \Throwable) {
                $results[$eid] = ['ok' => false, 'status' => 0, 'body' => $resp->getMessage(), 'ms' => 0];
            } else {
                $results[$eid] = [
                    'ok' => $resp->successful(),
                    'status' => $resp->status(),
                    'body' => $resp->json() ?? $resp->body(),
                    'ms' => (int) ($resp->transferStats?->getTransferTime() * 1000 ?? 0),
                ];
            }
        }

        return $results;
    }
}
