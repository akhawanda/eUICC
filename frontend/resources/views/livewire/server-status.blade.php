<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-slate-500">Health of the eUICC and IPA simulator processes, plus recent IPA console runs.</p>
        <button wire:click="refresh"
                class="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">
            Refresh
        </button>
    </div>

    <div class="grid gap-4 md:grid-cols-2">
        @foreach (['eUICC Simulator (port 8100)' => $euicc, 'IPA Simulator (port 8101)' => $ipa] as $label => $h)
            <div class="rounded-lg border bg-white p-5 dark:bg-slate-900
                       {{ $h['ok']
                            ? 'border-emerald-200 dark:border-emerald-800'
                            : 'border-rose-200 dark:border-rose-800' }}">
                <div class="flex items-start justify-between">
                    <div>
                        <h3 class="text-sm font-semibold">{{ $label }}</h3>
                        <div class="mt-1 text-xs text-slate-500">
                            HTTP {{ $h['status'] }}
                        </div>
                    </div>
                    <span class="rounded-full px-2 py-0.5 text-xs
                               {{ $h['ok']
                                    ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900 dark:text-emerald-200'
                                    : 'bg-rose-100 text-rose-700 dark:bg-rose-900 dark:text-rose-200' }}">
                        {{ $h['ok'] ? 'healthy' : 'down' }}
                    </span>
                </div>
                <pre class="mt-3 max-h-40 overflow-auto rounded bg-slate-50 p-2 text-xs dark:bg-slate-950">{{ is_string($h['body']) ? $h['body'] : json_encode($h['body'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
        @endforeach
    </div>

    <div class="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <h3 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Recent IPA sessions</h3>

        @if ($recent->isEmpty())
            <p class="text-sm text-slate-500">No IPA operations recorded yet.</p>
        @else
            <div class="-mx-5 overflow-x-auto px-5">
            <table class="w-full min-w-[640px] text-left text-sm">
                <thead class="text-xs uppercase text-slate-500">
                    <tr>
                        <th class="py-2">When</th>
                        <th class="py-2">Operation</th>
                        <th class="py-2">Devices</th>
                        <th class="py-2">By</th>
                        <th class="py-2">Status</th>
                        <th class="py-2">Duration</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($recent as $s)
                        <tr>
                            <td class="py-2 text-slate-500">{{ $s->started_at?->diffForHumans() ?? '—' }}</td>
                            <td class="py-2 font-medium">{{ $s->operation }}</td>
                            <td class="py-2">{{ count($s->device_ids ?? []) }}</td>
                            <td class="py-2 text-slate-500">{{ $s->user?->email ?? '—' }}</td>
                            <td class="py-2">
                                <span class="rounded-full px-2 py-0.5 text-xs
                                           {{ $s->status === 'completed'
                                                ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900 dark:text-emerald-200'
                                                : ($s->status === 'failed'
                                                    ? 'bg-rose-100 text-rose-700 dark:bg-rose-900 dark:text-rose-200'
                                                    : 'bg-slate-100 text-slate-500 dark:bg-slate-800') }}">
                                    {{ $s->status }}
                                </span>
                            </td>
                            <td class="py-2 text-slate-500">
                                {{ $s->started_at && $s->finished_at
                                     ? $s->started_at->diffInMilliseconds($s->finished_at) . ' ms'
                                     : '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </div>
</div>
