<form wire:submit="save" class="max-w-4xl space-y-6">
    {{-- Core identity --}}
    <div class="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-500">Identity</h2>

        <div class="grid gap-4 md:grid-cols-2">
            <div>
                <label class="block text-sm font-medium">Name</label>
                <input wire:model="name" type="text"
                       class="mt-1 w-full rounded border-slate-300 dark:border-slate-700 dark:bg-slate-950">
                @error('name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium">EID <span class="text-xs text-slate-500">(32 hex)</span></label>
                <input wire:model="eid" type="text" maxlength="32"
                       class="mt-1 w-full rounded border-slate-300 font-mono uppercase dark:border-slate-700 dark:bg-slate-950">
                @error('eid') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium">EUM manufacturer</label>
                <input wire:model="eum_manufacturer" type="text" placeholder="e.g. ConnectX IoT"
                       class="mt-1 w-full rounded border-slate-300 dark:border-slate-700 dark:bg-slate-950">
            </div>

            <div>
                <label class="block text-sm font-medium">Default SM-DP+ address</label>
                <input wire:model="default_smdp_address" type="text" placeholder="smdpplus.connectxiot.com"
                       class="mt-1 w-full rounded border-slate-300 dark:border-slate-700 dark:bg-slate-950">
            </div>

            <div class="md:col-span-2">
                <label class="block text-sm font-medium">Description</label>
                <textarea wire:model="description" rows="2"
                          class="mt-1 w-full rounded border-slate-300 dark:border-slate-700 dark:bg-slate-950"></textarea>
            </div>

            <label class="inline-flex items-center gap-2">
                <input wire:model="enabled" type="checkbox" class="rounded">
                <span class="text-sm">Enabled</span>
            </label>
        </div>
    </div>

    {{-- eIM associations --}}
    <div class="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">eIM associations</h2>
            <button type="button" wire:click="addEim"
                    class="rounded bg-slate-100 px-3 py-1 text-xs hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700">
                + Add eIM
            </button>
        </div>

        @if (empty($eimAssociations))
            <p class="text-sm text-slate-500">No eIM associations — device will bootstrap without eIM config.</p>
        @endif

        @foreach ($eimAssociations as $i => $a)
            <div wire:key="eim-{{ $i }}" class="mb-3 grid gap-3 md:grid-cols-[1fr_1fr_auto]">
                <input wire:model="eimAssociations.{{ $i }}.eim_id" type="text" placeholder="eIM ID"
                       class="rounded border-slate-300 dark:border-slate-700 dark:bg-slate-950">
                <input wire:model="eimAssociations.{{ $i }}.eim_fqdn" type="text" placeholder="eim.example.com"
                       class="rounded border-slate-300 dark:border-slate-700 dark:bg-slate-950">
                <button type="button" wire:click="removeEim({{ $i }})"
                        class="rounded px-2 text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950">Remove</button>
                @error('eimAssociations.'.$i.'.eim_id')   <p class="col-span-3 text-xs text-rose-600">{{ $message }}</p> @enderror
                @error('eimAssociations.'.$i.'.eim_fqdn') <p class="col-span-3 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
        @endforeach
    </div>

    {{-- Preloaded profiles --}}
    <div class="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Preloaded profiles</h2>
            <button type="button" wire:click="addProfile"
                    class="rounded bg-slate-100 px-3 py-1 text-xs hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700">
                + Add profile
            </button>
        </div>

        @if (empty($preloadedProfiles))
            <p class="text-sm text-slate-500">No preloaded profiles — new eUICC starts empty.</p>
        @endif

        @foreach ($preloadedProfiles as $i => $p)
            <div wire:key="prof-{{ $i }}" class="mb-3 grid gap-3 md:grid-cols-[1.1fr_1.1fr_1fr_0.7fr_0.9fr_auto]">
                <input wire:model="preloadedProfiles.{{ $i }}.iccid" type="text" placeholder="ICCID (18–20 hex)"
                       class="rounded border-slate-300 font-mono uppercase dark:border-slate-700 dark:bg-slate-950">
                <input wire:model="preloadedProfiles.{{ $i }}.name" type="text" placeholder="Profile name"
                       class="rounded border-slate-300 dark:border-slate-700 dark:bg-slate-950">
                <input wire:model="preloadedProfiles.{{ $i }}.sp_name" type="text" placeholder="SP name (opt)"
                       class="rounded border-slate-300 dark:border-slate-700 dark:bg-slate-950">
                <select wire:model="preloadedProfiles.{{ $i }}.state"
                        class="rounded border-slate-300 dark:border-slate-700 dark:bg-slate-950">
                    <option value="disabled">disabled</option>
                    <option value="enabled">enabled</option>
                </select>
                <select wire:model="preloadedProfiles.{{ $i }}.class"
                        class="rounded border-slate-300 dark:border-slate-700 dark:bg-slate-950">
                    <option value="operational">operational</option>
                    <option value="provisioning">provisioning</option>
                    <option value="test">test</option>
                </select>
                <button type="button" wire:click="removeProfile({{ $i }})"
                        class="rounded px-2 text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950">Remove</button>
            </div>
        @endforeach
    </div>

    <div class="flex items-center justify-between">
        <label class="inline-flex items-center gap-2">
            <input wire:model="pushToSim" type="checkbox" class="rounded">
            <span class="text-sm">Push to eUICC simulator immediately after save</span>
        </label>

        <div class="flex gap-2">
            <a href="{{ route('devices.index') }}"
               class="rounded px-4 py-2 text-sm text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">
                Cancel
            </a>
            <button type="submit"
                    class="rounded bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                Save device
            </button>
        </div>
    </div>
</form>
