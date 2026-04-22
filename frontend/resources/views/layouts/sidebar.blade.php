@php
    $nav = [
        ['label' => 'Dashboard',      'route' => 'dashboard',          'icon' => 'home'],
        ['label' => 'Devices',        'route' => 'devices.index',      'icon' => 'cpu'],
        ['label' => 'IPA Console',    'route' => 'ipa.console',        'icon' => 'terminal'],
        ['label' => 'Transactions',   'route' => 'transactions.index', 'icon' => 'list'],
        ['label' => 'Architecture',   'route' => 'architecture',       'icon' => 'diagram'],
        ['label' => 'Server Status',  'route' => 'server.status',      'icon' => 'activity'],
    ];

    $icons = [
        'home'     => '<path d="M3 10.5 12 3l9 7.5v9A1.5 1.5 0 0 1 19.5 21H15v-6H9v6H4.5A1.5 1.5 0 0 1 3 19.5v-9Z"/>',
        'cpu'      => '<rect x="6" y="6" width="12" height="12" rx="1.5"/><rect x="9.5" y="9.5" width="5" height="5"/><path d="M9 2v3M15 2v3M9 19v3M15 19v3M2 9h3M2 15h3M19 9h3M19 15h3"/>',
        'terminal' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m7 9 3 3-3 3M13 15h4"/>',
        'list'     => '<rect x="4" y="4" width="16" height="16" rx="2"/><path d="M8 9h8M8 13h8M8 17h5"/>',
        'diagram'  => '<rect x="3" y="3" width="6" height="6" rx="1"/><rect x="15" y="3" width="6" height="6" rx="1"/><rect x="9" y="15" width="6" height="6" rx="1"/><path d="M6 9v2a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V9M12 12v3"/>',
        'activity' => '<path d="M3 12h4l3-8 4 16 3-8h4"/>',
    ];
@endphp

<aside
    class="fixed inset-y-0 left-0 z-40 flex w-64 shrink-0 flex-col border-r border-slate-200 bg-white transition-[transform,width] duration-200 ease-out dark:border-slate-800 dark:bg-slate-950 lg:static lg:translate-x-0"
    :class="{
        '-translate-x-full': !sidebarOpen,
        'translate-x-0': sidebarOpen,
        'lg:w-16': sidebarCollapsed,
        'lg:w-64': !sidebarCollapsed
    }">
    <div class="flex h-16 items-center gap-2 border-b border-slate-200 px-4 dark:border-slate-800"
         :class="{ 'lg:justify-center lg:px-2': sidebarCollapsed }">
        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded bg-indigo-600 text-xs font-bold text-white">CX</div>
        <div class="min-w-0 leading-tight" :class="{ 'lg:hidden': sidebarCollapsed }">
            <div class="truncate text-sm font-semibold">ConnectX</div>
            <div class="truncate text-xs text-slate-500 dark:text-slate-400">eUICC / IPA Simulator</div>
        </div>
        <button type="button"
                @click="sidebarOpen = false"
                class="ml-auto rounded p-1.5 text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800 lg:hidden"
                aria-label="Close navigation">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 6l12 12M6 18L18 6"/></svg>
        </button>
    </div>

    <nav class="flex-1 space-y-1 overflow-y-auto px-2 py-4">
        @foreach ($nav as $item)
            @php $active = request()->routeIs($item['route']); @endphp
            <a href="{{ route($item['route']) }}"
               @click="sidebarOpen = false"
               title="{{ $item['label'] }}"
               class="group flex items-center gap-3 rounded px-3 py-2 text-sm transition
                      {{ $active
                           ? 'bg-indigo-50 font-medium text-indigo-700 dark:bg-indigo-950 dark:text-indigo-200'
                           : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800' }}"
               :class="{ 'lg:justify-center lg:px-2': sidebarCollapsed }">
                <svg class="h-5 w-5 shrink-0 {{ $active ? 'text-indigo-600 dark:text-indigo-300' : 'text-slate-400 group-hover:text-slate-600 dark:text-slate-500 dark:group-hover:text-slate-300' }}"
                     viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    {!! $icons[$item['icon']] ?? '' !!}
                </svg>
                <span class="truncate" :class="{ 'lg:hidden': sidebarCollapsed }">{{ $item['label'] }}</span>
            </a>
        @endforeach
    </nav>

    <div class="border-t border-slate-200 px-4 py-3 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400"
         :class="{ 'lg:hidden': sidebarCollapsed }">
        GSMA SGP.22 v3.1 · SGP.32 v1.2
    </div>
</aside>
