@extends('layouts.app', ['title' => 'Transaction #'.$transaction->id, 'header' => 'Transaction #'.$transaction->id])

@php
    // Build lane list dynamically from actors that actually appear in the
    // steps. Canonical order keeps the Dashboard on the left (originator)
    // and the IPA in the middle as the orchestrator, with its downstream
    // peers (eUICC / eIM / SM-DP+) spread around it.
    $canonicalOrder = ['dashboard', 'euicc', 'ipa', 'eim', 'smdpplus', 'external'];
    $allLaneLabels = [
        'dashboard' => ['Dashboard', 'this UI'],
        'euicc'     => ['eUICC',     'eUICC sim · :8100'],
        'ipa'       => ['IPA',       'IPA sim · :8101'],
        'eim'       => ['eIM',       'eim.connectxiot.com'],
        'smdpplus'  => ['SM-DP+',    'smdpplus.connectxiot.com'],
        'external'  => ['External',  ''],
    ];
    $actorsPresent = $steps
        ->flatMap(fn ($s) => [$s->actor_from, $s->actor_to])
        ->filter()
        ->unique()
        ->values();
    $orderedActors = collect($canonicalOrder)
        ->filter(fn ($a) => $actorsPresent->contains($a))
        ->values();
    // Include any unknown actors that slipped through, pinned to the right.
    foreach ($actorsPresent as $a) {
        if (! $orderedActors->contains($a)) {
            $orderedActors->push($a);
            $allLaneLabels[$a] = [ucfirst($a), ''];
        }
    }
    $lanes = $orderedActors->mapWithKeys(fn ($a, $i) => [$a => $i])->all();
    $laneCount = max(2, count($lanes));
    $colPct = [];
    for ($i = 0; $i < $laneCount; $i++) {
        $colPct[$i] = ($i + 0.5) / $laneCount * 100;
    }

    $phaseLabels = [
        'push_device'  => 'Create / update virtual eUICC',
        'register_ipa' => 'Register device with IPA',
        'run_op'       => $transaction->operation,
    ];

    $stepAsn1 = [];
    $asn1Candidates = [
        'euiccPackageRequest', 'ipaEuiccDataRequest', 'profileDownloadTriggerRequest',
        'eimAcknowledgements', 'provideEimPackageResult', 'eimPackageResult', 'smdpSigned2',
        'boundProfilePackage', 'authenticateServerResponse', 'prepareDownloadResponse',
    ];
    $tagNames = [
        'BF20' => 'AuthenticateServerResponse',
        'BF21' => 'PrepareDownloadResponse',
        'BF22' => 'GetEuiccChallenge',
        'BF2E' => 'GetEuiccInfo1',
        'BF30' => 'ListNotification',
        'BF36' => 'BoundProfilePackage',
        'BF38' => 'ProfileInstallationResult',
        'BF50' => 'ProvideEimPackageResult',
        'BF51' => 'EuiccPackageRequest',
        'BF52' => 'IpaEuiccData',
        'BF53' => 'EimAcknowledgements',
        'BF54' => 'ProfileDownloadTrigger',
    ];
    foreach ($steps as $step) {
        $hex = null;
        $tagName = null;
        if ($step->http_body) {
            $body = json_decode($step->http_body, true);
            if (is_array($body)) {
                foreach ($asn1Candidates as $field) {
                    if (! empty($body[$field]) && is_string($body[$field])) {
                        $raw = base64_decode($body[$field], true);
                        if ($raw !== false && strlen($raw) > 2) {
                            $hex = strtoupper(bin2hex($raw));
                            break;
                        }
                    }
                }
            }
        }
        if ($hex) {
            $tag4 = substr($hex, 0, 4);
            $tagName = $tagNames[$tag4] ?? "Tag: $tag4";
        }
        $stepAsn1[$step->id] = ['hex' => $hex, 'tag' => $tagName];
    }
@endphp

@section('content')
    <div class="space-y-4" x-data="{ selectedStep: null, tab: 'json' }" x-init="$watch('selectedStep', () => tab = 'json')">

        <div class="rounded-lg border border-slate-200 bg-white p-4 text-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="flex flex-wrap gap-x-8 gap-y-3">
                <div>
                    <div class="text-xs text-slate-500">EID</div>
                    <div class="break-all font-mono text-xs">{{ $transaction->eid }}</div>
                </div>
                <div>
                    <div class="text-xs text-slate-500">Operation</div>
                    <div class="font-medium">{{ $transaction->operation }}</div>
                </div>
                <div>
                    <div class="text-xs text-slate-500">Device</div>
                    <div>
                        @if ($transaction->device)
                            <a href="{{ route('devices.show', $transaction->device) }}" class="text-indigo-600 hover:underline">
                                {{ $transaction->device->name }}
                            </a>
                        @else
                            <span class="text-slate-400">—</span>
                        @endif
                    </div>
                </div>
                <div>
                    <div class="text-xs text-slate-500">Status</div>
                    <span class="rounded-full px-2 py-0.5 text-xs
                               {{ $transaction->status === 'completed'
                                    ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900 dark:text-emerald-200'
                                    : ($transaction->status === 'failed'
                                        ? 'bg-rose-100 text-rose-700 dark:bg-rose-900 dark:text-rose-200'
                                        : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400') }}">
                        {{ $transaction->status }}
                    </span>
                </div>
                <div>
                    <div class="text-xs text-slate-500">Duration</div>
                    <div>{{ $transaction->duration_ms !== null ? $transaction->duration_ms.' ms' : '—' }}</div>
                </div>
                <div>
                    <div class="text-xs text-slate-500">By</div>
                    <div>{{ $transaction->user?->email ?? '—' }}</div>
                </div>
                <div class="ml-auto text-xs text-slate-400">
                    {{ $transaction->created_at?->format('Y-m-d H:i:s') }}
                </div>
            </div>
            @if ($transaction->result_summary)
                <div class="mt-3 border-t border-slate-100 pt-3 text-sm text-slate-600 dark:border-slate-800 dark:text-slate-300">
                    {{ $transaction->result_summary }}
                </div>
            @endif
        </div>

        <div class="flex flex-col gap-6 lg:flex-row">

            <div class="shrink-0 rounded-lg border border-slate-200 bg-white lg:w-[640px] xl:w-[720px] dark:border-slate-800 dark:bg-slate-900">
                <div class="border-b border-slate-100 p-4 dark:border-slate-800">
                    <h3 class="text-sm font-semibold">Message Sequence</h3>
                    <p class="mt-0.5 text-xs text-slate-400">{{ $steps->count() }} messages across {{ $steps->pluck('phase')->unique()->count() }} phase(s)</p>
                </div>
                <div class="p-5">
                    {{-- Lane headers --}}
                    <div class="flex px-2">
                        @foreach ($lanes as $k => $i)
                            <div class="flex-1 text-center">
                                <div class="inline-block rounded border-2 border-slate-800 bg-white px-2 py-1 dark:border-slate-200 dark:bg-slate-950">
                                    <div class="text-xs font-bold sm:text-sm">{{ $allLaneLabels[$k][0] ?? $k }}</div>
                                    @if (! empty($allLaneLabels[$k][1]))
                                        <div class="text-[9px] text-slate-500 sm:text-[10px]">{{ $allLaneLabels[$k][1] }}</div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>

                    {{-- Lifelines + arrows --}}
                    <div class="relative px-2">
                        @foreach ($colPct as $p)
                            <div class="pointer-events-none absolute inset-y-0"
                                 style="left: {{ $p }}%; width: 1px; background: repeating-linear-gradient(to bottom, #94a3b8 0, #94a3b8 4px, transparent 4px, transparent 8px);"></div>
                        @endforeach

                        @if ($steps->isEmpty())
                            <p class="py-16 text-center text-sm text-slate-400">No messages recorded.</p>
                        @else
                            <div class="space-y-1 py-4">
                                @php $prevPhase = null; @endphp
                                @foreach ($steps as $index => $step)
                                    @php
                                        $from = $lanes[$step->actor_from] ?? 0;
                                        $to   = $lanes[$step->actor_to]   ?? 0;
                                        $leftIdx  = min($from, $to);
                                        $rightIdx = max($from, $to);
                                        $leftPct  = $colPct[$leftIdx];
                                        $rightPct = 100 - $colPct[$rightIdx];
                                        $rightArrow = $to > $from;
                                        $selfCall = $from === $to;
                                        $isReq = $step->isRequest();
                                        $label = $step->endpoint ?? '';
                                        // Strip common API prefixes for readability
                                        $label = preg_replace('#^/api/(ipa|es10|management)/?#', '', $label);
                                        $label = preg_replace('#^/gsma/rsp2/(esipa|es9plus)/?#', '', $label);
                                    @endphp

                                    @if ($prevPhase !== null && $prevPhase !== $step->phase)
                                        <div class="relative py-1">
                                            <div class="h-px w-full bg-slate-200 dark:bg-slate-700"></div>
                                        </div>
                                    @endif
                                    @if ($prevPhase !== $step->phase)
                                        <div class="px-1 pb-1 pt-2 text-[10px] font-semibold uppercase tracking-wider text-slate-400">
                                            {{ $phaseLabels[$step->phase] ?? $step->phase }}
                                        </div>
                                    @endif
                                    @php $prevPhase = $step->phase; @endphp

                                    <button type="button"
                                            @click="selectedStep = {{ $step->id }}; tab = 'json'"
                                            class="group relative block h-9 w-full text-left">
                                        <div class="absolute inset-0 rounded transition"
                                             :class="selectedStep === {{ $step->id }} ? 'bg-indigo-50 ring-1 ring-indigo-400 dark:bg-indigo-950' : 'group-hover:bg-slate-50 dark:group-hover:bg-slate-800'"></div>
                                        <div class="absolute top-1/2 -translate-y-1/2"
                                             style="left: {{ $leftPct }}%; right: {{ $rightPct }}%;">
                                            @if ($selfCall)
                                                <div class="flex h-px items-center justify-center text-[10px] text-slate-400">◍ internal</div>
                                            @elseif ($rightArrow)
                                                <div class="flex h-px items-center">
                                                    <div class="h-px flex-1 {{ $isReq ? 'bg-slate-700 dark:bg-slate-300' : 'border-t border-dashed border-slate-500' }}"></div>
                                                    <svg class="h-2.5 w-2.5 shrink-0 {{ $isReq ? 'text-slate-700 dark:text-slate-300' : 'text-slate-500' }}" fill="currentColor" viewBox="0 0 10 10"><polygon points="10,5 0,0 0,10"/></svg>
                                                </div>
                                            @else
                                                <div class="flex h-px items-center">
                                                    <svg class="h-2.5 w-2.5 shrink-0 {{ $isReq ? 'text-slate-700 dark:text-slate-300' : 'text-slate-500' }}" fill="currentColor" viewBox="0 0 10 10"><polygon points="0,5 10,0 10,10"/></svg>
                                                    <div class="h-px flex-1 {{ $isReq ? 'bg-slate-700 dark:bg-slate-300' : 'border-t border-dashed border-slate-500' }}"></div>
                                                </div>
                                            @endif
                                        </div>
                                        <div class="absolute top-0 truncate text-[11px] font-medium {{ $isReq ? 'text-slate-800 dark:text-slate-100' : 'text-slate-500' }}"
                                             style="left: calc({{ $leftPct }}% + 6px); right: calc({{ $rightPct }}% + 6px); line-height: 14px;">
                                            <span class="text-slate-400">({{ $index + 1 }})</span>
                                            {{ $step->method ? $step->method.' ' : '' }}{{ $label }}
                                            @if ($step->http_status)
                                                <span class="ml-1 {{ $step->http_status >= 200 && $step->http_status < 300 ? 'text-emerald-600' : 'text-rose-600' }}">{{ $step->http_status }}</span>
                                            @endif
                                        </div>
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div class="flex px-2">
                        @foreach ($lanes as $k => $i)
                            <div class="flex-1 text-center">
                                <div class="inline-block rounded border-2 border-slate-800 bg-white px-2 py-1 text-xs font-bold dark:border-slate-200 dark:bg-slate-950">
                                    {{ $allLaneLabels[$k][0] ?? $k }}
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="min-w-0 flex-1">
                @foreach ($steps as $index => $step)
                    @php
                        $hex = $stepAsn1[$step->id]['hex'] ?? null;
                        $tagName = $stepAsn1[$step->id]['tag'] ?? null;
                        $bodyDecoded = $step->http_body ? json_decode($step->http_body) : null;
                        $bodyPretty = $bodyDecoded
                            ? json_encode($bodyDecoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                            : $step->http_body;
                    @endphp
                    <div x-show="selectedStep === {{ $step->id }}" x-cloak class="rounded-lg border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                        <div class="border-b border-slate-200 dark:border-slate-800">
                            <div class="flex items-center justify-between gap-3 px-5 pt-4">
                                <span class="text-sm font-semibold {{ $step->isRequest() ? 'text-blue-700 dark:text-blue-300' : 'text-emerald-700 dark:text-emerald-300' }}">
                                    ({{ $index + 1 }}) {{ $step->isRequest() ? 'REQUEST' : 'RESPONSE' }} · {{ $step->actor_from }} → {{ $step->actor_to }}
                                </span>
                                <span class="text-xs text-slate-400">{{ $step->created_at?->format('H:i:s.v') }}</span>
                            </div>
                            <div class="px-5 pb-2 pt-1 text-xs text-slate-500">
                                {{ $step->method }} {{ $step->endpoint }}
                                @if ($step->response_time_ms)
                                    · {{ $step->response_time_ms }} ms
                                @endif
                            </div>
                            <nav class="-mb-px flex flex-wrap gap-1 px-5">
                                @foreach (['json' => 'JSON', 'http' => 'HTTP', 'tlv' => 'TLV', 'asn1' => 'ASN.1'] as $tk => $tl)
                                    <button type="button" @click="tab = '{{ $tk }}'"
                                            :class="tab === '{{ $tk }}' ? 'border-indigo-500 text-indigo-600 bg-indigo-50/50 dark:bg-indigo-950/50' : 'border-transparent text-slate-400 hover:text-slate-600 dark:hover:text-slate-300'"
                                            class="rounded-t border-b-2 px-4 py-2 text-xs font-medium transition">
                                        {{ $tl }}
                                    </button>
                                @endforeach
                            </nav>
                        </div>

                        <div class="max-h-[640px] overflow-auto p-5">
                            {{-- JSON --}}
                            <div x-show="tab === 'json'">
                                @if ($bodyPretty)
                                    <pre class="overflow-auto whitespace-pre-wrap break-all rounded-lg bg-slate-900 p-4 font-mono text-xs text-emerald-300">{{ $bodyPretty }}</pre>
                                @else
                                    <p class="py-12 text-center text-sm text-slate-400">No body</p>
                                @endif
                            </div>

                            {{-- HTTP --}}
                            <div x-show="tab === 'http'" x-cloak>
                                @php
                                    $httpMsg = '';
                                    if ($step->isRequest()) {
                                        $target = match ($step->actor_to) {
                                            'euicc' => 'euicc.connectxiot.com:8100',
                                            'ipa'   => 'euicc.connectxiot.com:8101',
                                            default => 'localhost',
                                        };
                                        $httpMsg .= ($step->method ?: 'POST')." ".($step->endpoint ?: '/')." HTTP/1.1\r\n";
                                        $httpMsg .= "Host: {$target}\r\n";
                                    } else {
                                        $status = $step->http_status ?? 0;
                                        $phrase = $status >= 200 && $status < 300 ? 'OK' : ($status >= 400 ? 'Error' : '');
                                        $httpMsg .= "HTTP/1.1 {$status} {$phrase}\r\n";
                                    }
                                    if ($step->http_headers) {
                                        foreach ($step->http_headers as $hk => $hv) {
                                            $val = is_array($hv) ? implode(', ', $hv) : $hv;
                                            $httpMsg .= "{$hk}: {$val}\r\n";
                                        }
                                    }
                                    $httpMsg .= "\r\n";
                                    if ($bodyPretty) {
                                        $httpMsg .= $bodyPretty;
                                    }
                                @endphp
                                <pre class="overflow-auto whitespace-pre-wrap break-all rounded-lg bg-slate-900 p-4 font-mono text-xs leading-relaxed text-green-300">{{ $httpMsg }}</pre>
                            </div>

                            {{-- TLV (hex dump) --}}
                            <div x-show="tab === 'tlv'" x-cloak>
                                @if ($hex)
                                    <div class="mb-3 flex flex-wrap items-center gap-3 text-xs">
                                        <span class="text-slate-500">{{ strlen($hex) / 2 }} bytes</span>
                                        @if ($tagName)
                                            <span class="rounded bg-violet-100 px-2 py-0.5 font-medium text-violet-700 dark:bg-violet-950 dark:text-violet-200">{{ $tagName }}</span>
                                        @endif
                                    </div>
                                    <pre class="overflow-auto whitespace-pre rounded-lg bg-slate-900 p-4 font-mono text-xs leading-relaxed text-cyan-300">{{ implode("\n", str_split($hex, 64)) }}</pre>
                                @else
                                    <p class="py-12 text-center text-sm text-slate-400">No ASN.1 payload detected in this message.</p>
                                @endif
                            </div>

                            {{-- ASN.1 meta --}}
                            <div x-show="tab === 'asn1'" x-cloak>
                                @if ($hex)
                                    <div class="rounded-lg border border-violet-200 bg-violet-50 p-4 dark:border-violet-900 dark:bg-violet-950/40">
                                        <div class="text-sm font-bold text-violet-700 dark:text-violet-200">{{ $tagName ?: 'Unknown tag' }}</div>
                                        <div class="mt-1 text-xs text-violet-600 dark:text-violet-300">Tag: {{ substr($hex, 0, 4) }} · {{ strlen($hex) / 2 }} bytes total</div>
                                    </div>
                                    <p class="mt-3 text-xs text-slate-500">
                                        Full ASN.1 decode happens on the eIM / eUICC side. The outer envelope is shown above; view the TLV tab for the raw bytes.
                                    </p>
                                @else
                                    <p class="py-12 text-center text-sm text-slate-400">No ASN.1 structure in this JSON body.</p>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach

                <div x-show="selectedStep === null" class="rounded-lg border border-dashed border-slate-300 p-16 text-center text-slate-400 dark:border-slate-700">
                    <svg class="mx-auto mb-4 h-12 w-12 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M15 15l-2 5L9 9l11 4-5 2zm0 0l5 5"/></svg>
                    <p class="text-sm font-medium">Select a message</p>
                    <p class="mt-1 text-xs">Click any arrow in the sequence diagram on the left.</p>
                </div>
            </div>
        </div>

        <div>
            <a href="{{ route('transactions.index') }}"
               class="text-sm text-slate-500 hover:text-slate-700 dark:hover:text-slate-300">
                ← Back to transactions
            </a>
        </div>
    </div>
@endsection
