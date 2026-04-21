@php
    $nav = [
        ['label' => 'Dashboard',      'route' => 'dashboard',      'icon' => 'home'],
        ['label' => 'Devices',        'route' => 'devices.index',  'icon' => 'cpu'],
        ['label' => 'IPA Console',    'route' => 'ipa.console',    'icon' => 'terminal'],
        ['label' => 'Server Status',  'route' => 'server.status',  'icon' => 'activity'],
    ];
@endphp

<aside class="flex w-64 shrink-0 flex-col border-r border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
    <div class="flex h-16 items-center gap-2 border-b border-slate-200 px-5 dark:border-slate-800">
        <div class="flex h-8 w-8 items-center justify-center rounded bg-indigo-600 text-xs font-bold text-white">CX</div>
        <div class="leading-tight">
            <div class="text-sm font-semibold">ConnectX</div>
            <div class="text-xs text-slate-500 dark:text-slate-400">eUICC / IPA Simulator</div>
        </div>
    </div>

    <nav class="flex-1 space-y-1 px-3 py-4">
        @foreach ($nav as $item)
            @php $active = request()->routeIs($item['route']); @endphp
            <a href="{{ route($item['route']) }}"
               class="flex items-center gap-3 rounded px-3 py-2 text-sm transition
                      {{ $active
                           ? 'bg-indigo-50 font-medium text-indigo-700 dark:bg-indigo-950 dark:text-indigo-200'
                           : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800' }}">
                <span class="inline-block h-2 w-2 rounded-full {{ $active ? 'bg-indigo-500' : 'bg-slate-400' }}"></span>
                {{ $item['label'] }}
            </a>
        @endforeach
    </nav>

    <div class="border-t border-slate-200 px-5 py-3 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">
        GSMA SGP.22 v3.1 · SGP.32 v1.2
    </div>
</aside>
