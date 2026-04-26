@extends('layouts.app', ['title' => 'Transactions', 'header' => 'Transactions'])

@section('content')
    @php($hasFilters = request()->hasAny(['eid', 'operation', 'status', 'polling']))
    @if (request()->filled('polling'))
        <div class="mb-3 flex items-center justify-between gap-3 rounded border border-indigo-200 bg-indigo-50 px-3 py-2 text-xs text-indigo-700 dark:border-indigo-900 dark:bg-indigo-950/40 dark:text-indigo-200">
            <span>Filtered to polling session <span class="font-mono">{{ request('polling') }}</span></span>
            <a href="{{ route('transactions.index') }}" class="rounded px-2 py-0.5 hover:bg-indigo-100 dark:hover:bg-indigo-900">Clear</a>
        </div>
    @endif
    <div class="mb-4" x-data="{ open: {{ $hasFilters ? 'true' : 'false' }} }">
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" @click="open = !open"
                    class="inline-flex items-center gap-2 rounded border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M7 12h10M11 18h2"/></svg>
                <span>Filters</span>
                @if ($hasFilters)
                    <span class="rounded-full bg-indigo-100 px-1.5 text-[10px] font-semibold text-indigo-700 dark:bg-indigo-900 dark:text-indigo-200">{{ collect(['eid', 'operation', 'status'])->filter(fn ($k) => request()->filled($k))->count() }}</span>
                @endif
            </button>
            @if ($hasFilters)
                <a href="{{ route('transactions.index') }}"
                   class="rounded px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">
                    Clear
                </a>
            @endif
        </div>

        <form x-show="open" x-cloak x-transition method="GET"
              class="mt-3 flex flex-wrap items-end gap-3 rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
            <div class="min-w-0 flex-1 sm:flex-none">
                <label class="block text-xs text-slate-500">EID</label>
                <input type="text" name="eid" value="{{ request('eid') }}" placeholder="32-hex or partial"
                       class="mt-1 w-full rounded border-slate-300 dark:border-slate-700 dark:bg-slate-900 sm:w-72">
            </div>
            <div>
                <label class="block text-xs text-slate-500">Operation</label>
                <select name="operation"
                        class="mt-1 rounded border-slate-300 dark:border-slate-700 dark:bg-slate-900">
                    <option value="">All</option>
                    @foreach ($operations as $op)
                        <option value="{{ $op }}" @selected(request('operation') === $op)>{{ $op }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-slate-500">Status</label>
                <select name="status"
                        class="mt-1 rounded border-slate-300 dark:border-slate-700 dark:bg-slate-900">
                    <option value="">All</option>
                    @foreach (['completed', 'failed', 'running', 'pending'] as $s)
                        <option value="{{ $s }}" @selected(request('status') === $s)>{{ $s }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit"
                    class="rounded bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                Apply
            </button>
        </form>
    </div>

    <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <table class="w-full min-w-[720px] text-left text-sm">
            <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-950 dark:text-slate-400">
                <tr>
                    <th class="px-4 py-3">#</th>
                    <th class="px-4 py-3">When</th>
                    <th class="px-4 py-3">EID</th>
                    <th class="px-4 py-3">Operation</th>
                    <th class="px-4 py-3">Steps</th>
                    <th class="px-4 py-3">Duration</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3 text-right"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                @forelse ($transactions as $tx)
                    <tr>
                        <td class="px-4 py-3 text-slate-500">#{{ $tx->id }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ $tx->created_at?->diffForHumans() }}</td>
                        <td class="px-4 py-3 font-mono text-xs" title="{{ $tx->eid }}">
                            …{{ substr($tx->eid, -12) }}
                        </td>
                        <td class="px-4 py-3 font-medium">
                            {{ $tx->operation }}
                            @if ($tx->polling_session_key)
                                <a href="{{ route('transactions.index', ['polling' => $tx->polling_session_key]) }}"
                                   title="Part of polling session {{ $tx->polling_session_key }}"
                                   class="ml-1 rounded bg-indigo-100 px-1.5 text-[10px] font-semibold uppercase text-indigo-700 hover:bg-indigo-200 dark:bg-indigo-900 dark:text-indigo-200">poll</a>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-slate-500">{{ $tx->steps()->count() }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ $tx->duration_ms !== null ? $tx->duration_ms.' ms' : '—' }}</td>
                        <td class="px-4 py-3">
                            <span class="rounded-full px-2 py-0.5 text-xs
                                       {{ $tx->status === 'completed'
                                            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900 dark:text-emerald-200'
                                            : ($tx->status === 'failed'
                                                ? 'bg-rose-100 text-rose-700 dark:bg-rose-900 dark:text-rose-200'
                                                : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400') }}">
                                {{ $tx->status }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('transactions.show', $tx) }}"
                               class="rounded px-2 py-1 text-xs text-indigo-600 hover:bg-indigo-50 dark:text-indigo-300 dark:hover:bg-indigo-950">
                                Details
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-10 text-center text-slate-500">
                            No transactions yet. Trigger an operation from the
                            <a href="{{ route('ipa.console') }}" class="text-indigo-600 hover:underline">IPA Console</a>
                            to record one.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($transactions->hasPages())
        <div class="mt-4">{{ $transactions->links() }}</div>
    @endif
@endsection
