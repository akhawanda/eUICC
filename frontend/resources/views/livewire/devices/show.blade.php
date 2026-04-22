<div class="max-w-5xl space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="text-xl font-semibold">{{ $device->name }}</h2>
                <span class="rounded-full px-2 py-0.5 text-xs
                           {{ $device->enabled
                                ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900 dark:text-emerald-200'
                                : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400' }}">
                    {{ $device->enabled ? 'enabled' : 'disabled' }}
                </span>
            </div>
            <div class="mt-1 break-all font-mono text-sm text-slate-500">{{ $device->eid }}</div>
        </div>

        <div class="flex flex-wrap gap-2">
            <a href="{{ route('devices.edit', $device) }}"
               class="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">
                Edit
            </a>
            <button wire:click="pushToSim"
                    class="rounded bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500">
                Push to eUICC simulator
            </button>
        </div>
    </div>

    @if ($pushResult)
        <div class="rounded border px-4 py-3 text-sm
                  {{ $pushResult['ok']
                       ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200'
                       : 'border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-800 dark:bg-rose-950 dark:text-rose-200' }}">
            <div class="font-medium">Push result — HTTP {{ $pushResult['status'] }}</div>
            <pre class="mt-2 overflow-x-auto text-xs">{{ is_string($pushResult['body']) ? $pushResult['body'] : json_encode($pushResult['body'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
        </div>
    @endif

    <div class="grid gap-4 md:grid-cols-2">
        <div class="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <h3 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Identity</h3>
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between"><dt class="text-slate-500">EUM</dt><dd>{{ $device->eum_manufacturer ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">Default SM-DP+</dt><dd class="truncate">{{ $device->default_smdp_address ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">Created</dt><dd>{{ $device->created_at?->diffForHumans() }}</dd></div>
            </dl>
            @if ($device->description)
                <p class="mt-3 text-sm text-slate-600 dark:text-slate-400">{{ $device->description }}</p>
            @endif
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <h3 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">eIM associations</h3>
            @forelse ($device->eimAssociations as $a)
                <div class="flex items-start justify-between py-1 text-sm">
                    <div>
                        <div class="font-medium">{{ $a->eim_id }}</div>
                        <div class="text-xs text-slate-500">{{ $a->eim_fqdn }}</div>
                    </div>
                    <div class="text-xs text-slate-500">counter {{ $a->counter_value }}</div>
                </div>
            @empty
                <p class="text-sm text-slate-500">No eIM associations</p>
            @endforelse
        </div>
    </div>

    <div class="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <h3 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Preloaded profiles</h3>
        @if ($device->preloadedProfiles->isEmpty())
            <p class="text-sm text-slate-500">No preloaded profiles</p>
        @else
            <div class="-mx-5 overflow-x-auto px-5">
            <table class="w-full min-w-[560px] text-left text-sm">
                <thead class="text-xs uppercase text-slate-500">
                    <tr>
                        <th class="py-2">ICCID</th>
                        <th class="py-2">Name</th>
                        <th class="py-2">SP</th>
                        <th class="py-2">State</th>
                        <th class="py-2">Class</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($device->preloadedProfiles as $p)
                        <tr>
                            <td class="py-2 font-mono text-xs">{{ $p->iccid }}</td>
                            <td class="py-2">{{ $p->name }}</td>
                            <td class="py-2 text-slate-500">{{ $p->sp_name ?? '—' }}</td>
                            <td class="py-2">{{ $p->state }}</td>
                            <td class="py-2">{{ $p->class }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </div>

    <div class="flex justify-end">
        <a href="{{ route('ipa.console') }}?device={{ $device->id }}"
           class="rounded bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-500">
            Run IPA on this device →
        </a>
    </div>
</div>
