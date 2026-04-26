<div wire:poll.5s="refresh">
    <div class="mb-3 flex items-center justify-between gap-3">
        <p class="text-xs text-slate-500">Live snapshot from the IPA simulator (`/api/ipa/polling`). Refreshes every 5s.</p>
        <button wire:click="refresh"
                class="rounded border border-slate-300 px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">
            Refresh now
        </button>
    </div>

    @if ($error)
        <div class="mb-3 rounded border border-rose-200 bg-rose-50 px-4 py-2 text-sm text-rose-800 dark:border-rose-800 dark:bg-rose-950 dark:text-rose-200">
            Couldn't reach IPA: {{ $error }}
        </div>
    @endif

    <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <table class="w-full min-w-[720px] text-left text-sm">
            <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-950 dark:text-slate-400">
                <tr>
                    <th class="px-4 py-3">EID</th>
                    <th class="px-4 py-3">eIM ID</th>
                    <th class="px-4 py-3">Interval</th>
                    <th class="px-4 py-3">Started</th>
                    <th class="px-4 py-3">Last poll</th>
                    <th class="px-4 py-3">Ops processed</th>
                    <th class="px-4 py-3">Errors</th>
                    <th class="px-4 py-3">Transactions</th>
                    <th class="px-4 py-3 text-right"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                @forelse ($sessions as $s)
                    <tr wire:key="poll-{{ $s['eid'] }}">
                        <td class="px-4 py-3 font-mono text-xs" title="{{ $s['eid'] }}">…{{ substr($s['eid'], -12) }}</td>
                        <td class="px-4 py-3 font-mono text-xs" title="{{ $s['eimId'] }}">{{ \Illuminate\Support\Str::limit($s['eimId'], 32) }}</td>
                        <td class="px-4 py-3">{{ $s['pollInterval'] }}s</td>
                        <td class="px-4 py-3 text-slate-500">
                            @if (! empty($s['startedAt']))
                                {{ \Carbon\Carbon::createFromTimestamp($s['startedAt'])->diffForHumans() }}
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-4 py-3 text-slate-500">
                            @if (! empty($s['lastPolledAt']))
                                {{ \Carbon\Carbon::createFromTimestamp($s['lastPolledAt'])->diffForHumans() }}
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-4 py-3 text-slate-500">{{ $s['operationsProcessed'] ?? 0 }}</td>
                        <td class="px-4 py-3">
                            <span class="{{ ($s['errorCount'] ?? 0) > 0 ? 'text-rose-600' : 'text-slate-500' }}">{{ $s['errorCount'] ?? 0 }}</span>
                        </td>
                        <td class="px-4 py-3 text-xs">
                            @if (($s['txCount'] ?? 0) > 0)
                                <a href="{{ route('transactions.index', ['polling' => $s['key']]) }}"
                                   class="text-indigo-600 hover:underline dark:text-indigo-300">
                                    {{ $s['txCount'] }} {{ \Illuminate\Support\Str::plural('tx', $s['txCount']) }}
                                </a>
                                @if (! empty($s['firstTxId']))
                                    <span class="text-slate-400">·</span>
                                    <a href="{{ route('transactions.show', $s['firstTxId']) }}"
                                       class="text-slate-500 hover:underline">start tx #{{ $s['firstTxId'] }}</a>
                                @endif
                            @else
                                <span class="text-slate-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <button wire:click="stop('{{ $s['eid'] }}')"
                                    wire:confirm="Stop polling for {{ $s['eid'] }}?"
                                    class="rounded px-2 py-1 text-xs text-rose-600 hover:bg-rose-50 dark:text-rose-400 dark:hover:bg-rose-950">
                                Stop
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="px-4 py-10 text-center text-slate-500">
                            No active polling sessions. Start one from the
                            <a href="{{ route('ipa.console') }}" class="text-indigo-600 hover:underline">IPA Console</a>.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
