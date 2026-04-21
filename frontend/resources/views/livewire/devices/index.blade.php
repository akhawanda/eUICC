<div>
    <div class="mb-4 flex items-end justify-between gap-3">
        <div class="flex items-end gap-3">
            <div>
                <label class="block text-xs text-slate-500">Search</label>
                <input wire:model.live.debounce.300ms="search" type="search" placeholder="Name, EID, manufacturer"
                       class="mt-1 w-72 rounded border-slate-300 dark:border-slate-700 dark:bg-slate-900">
            </div>
            <div>
                <label class="block text-xs text-slate-500">Status</label>
                <select wire:model.live="statusFilter"
                        class="mt-1 rounded border-slate-300 dark:border-slate-700 dark:bg-slate-900">
                    <option value="all">All</option>
                    <option value="enabled">Enabled</option>
                    <option value="disabled">Disabled</option>
                </select>
            </div>
        </div>
        <a href="{{ route('devices.create') }}"
           class="rounded bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
            + New device
        </a>
    </div>

    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <table class="w-full text-left text-sm">
            <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-950 dark:text-slate-400">
                <tr>
                    <th class="px-4 py-3">Name</th>
                    <th class="px-4 py-3 font-mono">EID</th>
                    <th class="px-4 py-3">EUM</th>
                    <th class="px-4 py-3">eIMs</th>
                    <th class="px-4 py-3">Profiles</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                @forelse ($devices as $d)
                    <tr wire:key="dev-{{ $d->id }}">
                        <td class="px-4 py-3 font-medium">
                            <a href="{{ route('devices.show', $d) }}" class="hover:text-indigo-600">{{ $d->name }}</a>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs text-slate-600 dark:text-slate-400">{{ $d->eid }}</td>
                        <td class="px-4 py-3 text-slate-600 dark:text-slate-400">{{ $d->eum_manufacturer ?? '—' }}</td>
                        <td class="px-4 py-3">{{ $d->eim_associations_count }}</td>
                        <td class="px-4 py-3">{{ $d->preloaded_profiles_count }}</td>
                        <td class="px-4 py-3">
                            <span class="rounded-full px-2 py-0.5 text-xs
                                       {{ $d->enabled
                                            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900 dark:text-emerald-200'
                                            : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400' }}">
                                {{ $d->enabled ? 'enabled' : 'disabled' }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="inline-flex gap-1">
                                <a href="{{ route('devices.edit', $d) }}"
                                   class="rounded px-2 py-1 text-xs text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">Edit</a>
                                <button wire:click="clone({{ $d->id }})"
                                        class="rounded px-2 py-1 text-xs text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">Clone</button>
                                <button wire:click="toggleEnabled({{ $d->id }})"
                                        class="rounded px-2 py-1 text-xs text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">
                                    {{ $d->enabled ? 'Disable' : 'Enable' }}
                                </button>
                                <button wire:click="delete({{ $d->id }})"
                                        wire:confirm="Delete {{ $d->name }}? This cannot be undone."
                                        class="rounded px-2 py-1 text-xs text-rose-600 hover:bg-rose-50 dark:text-rose-400 dark:hover:bg-rose-950">Delete</button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-10 text-center text-slate-500">
                            No devices yet.
                            <a href="{{ route('devices.create') }}" class="text-indigo-600 hover:underline">Create one</a>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $devices->links() }}</div>
</div>
