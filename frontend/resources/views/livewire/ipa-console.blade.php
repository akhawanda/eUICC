<div class="grid gap-6 lg:grid-cols-[1fr_420px]">
    {{-- LEFT: device selector --}}
    <div class="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <div class="mb-3 flex items-center justify-between">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">
                Target devices <span class="text-slate-400">({{ count($selectedIds) }} selected)</span>
            </h2>
            <div class="flex gap-2 text-xs">
                <button wire:click="selectAll" class="rounded px-2 py-1 text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">Select all</button>
                <button wire:click="clearSelection" class="rounded px-2 py-1 text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">Clear</button>
            </div>
        </div>

        @if ($devices->isEmpty())
            <p class="text-sm text-slate-500">No devices defined yet.
                <a href="{{ route('devices.create') }}" class="text-indigo-600 hover:underline">Create one</a>.
            </p>
        @else
            <div class="max-h-[60vh] space-y-1 overflow-y-auto">
                @foreach ($devices as $d)
                    <label wire:key="sel-{{ $d->id }}"
                           class="flex cursor-pointer items-center gap-3 rounded px-2 py-2 text-sm hover:bg-slate-50 dark:hover:bg-slate-800
                                  {{ $d->enabled ? '' : 'opacity-50' }}">
                        <input type="checkbox" wire:model.live="selectedIds" value="{{ $d->id }}" {{ $d->enabled ? '' : 'disabled' }}>
                        <div class="min-w-0 flex-1">
                            <div class="truncate font-medium">{{ $d->name }}</div>
                            <div class="truncate font-mono text-xs text-slate-500">{{ $d->eid }}</div>
                        </div>
                        @unless ($d->enabled)
                            <span class="text-xs text-slate-400">disabled</span>
                        @endunless
                    </label>
                @endforeach
            </div>
        @endif
    </div>

    {{-- RIGHT: operations --}}
    <div class="space-y-4">
        <div class="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Parameters</h2>

            <div class="space-y-3 text-sm">
                <div>
                    <label class="block text-xs text-slate-500">SM-DP+ address (optional — falls back to device default)</label>
                    <input wire:model="smdpAddress" type="text" placeholder="smdpplus.connectxiot.com"
                           class="mt-1 w-full rounded border-slate-300 dark:border-slate-700 dark:bg-slate-950">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs text-slate-500">Matching ID</label>
                        <input wire:model="matchingId" type="text"
                               class="mt-1 w-full rounded border-slate-300 dark:border-slate-700 dark:bg-slate-950">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500">Activation code</label>
                        <input wire:model="activationCode" type="text"
                               class="mt-1 w-full rounded border-slate-300 dark:border-slate-700 dark:bg-slate-950">
                    </div>
                </div>
                <div>
                    <label class="block text-xs text-slate-500">Cancel reason code</label>
                    <input wire:model="cancelReason" type="number"
                           class="mt-1 w-24 rounded border-slate-300 dark:border-slate-700 dark:bg-slate-950">
                </div>
            </div>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Operations</h2>

            <div class="space-y-2">
                @foreach ($catalog as $key => $op)
                    <div class="flex items-start justify-between gap-3 rounded border border-slate-200 px-3 py-2 text-sm dark:border-slate-700">
                        <div class="min-w-0">
                            <div class="font-medium">{{ $op['label'] }}</div>
                            <div class="text-xs text-slate-500">{{ $op['description'] }}</div>
                        </div>
                        <button wire:click="run('{{ $key }}')"
                                wire:loading.attr="disabled"
                                @disabled(count($selectedIds) === 0)
                                class="shrink-0 rounded bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50">
                            <span wire:loading.remove wire:target="run('{{ $key }}')">Run</span>
                            <span wire:loading wire:target="run('{{ $key }}')">Running…</span>
                        </button>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- BOTTOM: last session results --}}
    @if ($lastSession)
        <div class="lg:col-span-2 rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <div class="mb-3 flex items-center justify-between">
                <div>
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Last run — {{ $lastSession->name }}</h2>
                    <div class="text-xs text-slate-500">
                        {{ $lastSession->started_at?->diffForHumans() }} ·
                        {{ $lastSession->finished_at && $lastSession->started_at
                             ? $lastSession->started_at->diffInMilliseconds($lastSession->finished_at) . ' ms'
                             : '—' }}
                    </div>
                </div>
                <span class="rounded-full px-2 py-0.5 text-xs
                           {{ $lastSession->status === 'completed'
                                ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900 dark:text-emerald-200'
                                : ($lastSession->status === 'failed'
                                    ? 'bg-rose-100 text-rose-700 dark:bg-rose-900 dark:text-rose-200'
                                    : 'bg-slate-100 text-slate-500 dark:bg-slate-800') }}">
                    {{ $lastSession->status }}
                </span>
            </div>

            <div class="-mx-5 overflow-x-auto px-5">
            <table class="w-full min-w-[560px] text-left text-sm">
                <thead class="text-xs uppercase text-slate-500">
                    <tr>
                        <th class="py-2">EID</th>
                        <th class="py-2">HTTP</th>
                        <th class="py-2">ms</th>
                        <th class="py-2">Response</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($lastSession->results ?? [] as $eid => $r)
                        <tr>
                            <td class="py-2 font-mono text-xs">{{ $eid }}</td>
                            <td class="py-2">
                                <span class="{{ $r['ok'] ? 'text-emerald-600' : 'text-rose-600' }}">{{ $r['status'] }}</span>
                            </td>
                            <td class="py-2 text-slate-500">{{ $r['ms'] ?? '—' }}</td>
                            <td class="py-2">
                                <details>
                                    <summary class="cursor-pointer text-indigo-600 hover:underline">view</summary>
                                    <pre class="mt-1 overflow-x-auto rounded bg-slate-100 p-2 text-xs dark:bg-slate-950">{{ is_string($r['body']) ? $r['body'] : json_encode($r['body'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
                                </details>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </div>
    @endif
</div>
