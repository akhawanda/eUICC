<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-slate-50 dark:bg-slate-900">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? config('app.name') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <style>[x-cloak]{display:none !important}</style>
</head>
<body class="h-full font-sans text-slate-800 antialiased dark:text-slate-200"
      x-data="{
          sidebarOpen: false,
          sidebarCollapsed: (typeof localStorage !== 'undefined' && localStorage.getItem('sidebarCollapsed') === 'true'),
          toggleCollapse() {
              this.sidebarCollapsed = !this.sidebarCollapsed;
              localStorage.setItem('sidebarCollapsed', this.sidebarCollapsed);
          }
      }">
    <div x-show="sidebarOpen"
         x-transition.opacity
         @click="sidebarOpen = false"
         @keydown.escape.window="sidebarOpen = false"
         class="fixed inset-0 z-30 bg-slate-900/50 lg:hidden"
         x-cloak></div>

    <div class="flex min-h-full">
        @include('layouts.sidebar')

        <main class="flex min-w-0 flex-1 flex-col">
            <header class="sticky top-0 z-20 border-b border-slate-200 bg-white px-4 py-3 dark:border-slate-800 dark:bg-slate-900 sm:px-6">
                <div class="flex items-center justify-between gap-3">
                    <div class="flex min-w-0 items-center gap-2">
                        <button type="button"
                                @click="sidebarOpen = true"
                                class="-ml-1 rounded p-2 text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800 lg:hidden"
                                aria-label="Open navigation">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
                        </button>
                        <button type="button"
                                @click="toggleCollapse()"
                                class="hidden rounded p-2 text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800 lg:inline-flex"
                                :aria-label="sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'">
                            <svg x-show="!sidebarCollapsed" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 19l-7-7 7-7M20 19l-7-7 7-7"/></svg>
                            <svg x-show="sidebarCollapsed" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" x-cloak><path d="M13 5l7 7-7 7M4 5l7 7-7 7"/></svg>
                        </button>
                        <h1 class="truncate text-base font-semibold sm:text-lg">{{ $header ?? ($title ?? '') }}</h1>
                    </div>
                    <div class="flex items-center gap-2 text-sm sm:gap-3">
                        <span class="hidden text-slate-500 dark:text-slate-400 sm:inline">
                            {{ auth()->user()?->email }}
                        </span>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button class="rounded px-2 py-1 text-slate-600 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800">
                                Sign out
                            </button>
                        </form>
                    </div>
                </div>
            </header>

            <div class="p-4 sm:p-6">
                @if (session('toast'))
                    @php($t = session('toast'))
                    <div class="mb-4 flex items-start justify-between gap-3 rounded border px-4 py-3 text-sm
                        {{ ($t['type'] ?? 'info') === 'success' ? 'border-emerald-300 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-100' : '' }}
                        {{ ($t['type'] ?? 'info') === 'error'   ? 'border-rose-300 bg-rose-50 text-rose-800 dark:border-rose-800 dark:bg-rose-950 dark:text-rose-100' : '' }}
                        {{ ($t['type'] ?? 'info') === 'info'    ? 'border-indigo-300 bg-indigo-50 text-indigo-800 dark:border-indigo-800 dark:bg-indigo-950 dark:text-indigo-100' : '' }}">
                        <div class="min-w-0">
                            <div class="font-medium">{{ $t['title'] ?? '' }}</div>
                            @if (! empty($t['message']))
                                <div class="mt-0.5 text-xs opacity-90">{{ $t['message'] }}</div>
                            @endif
                            @if (! empty($t['link']))
                                <a href="{{ $t['link'] }}" class="mt-1 inline-block text-xs underline opacity-90 hover:opacity-100">{{ $t['linkLabel'] ?? 'View →' }}</a>
                            @endif
                        </div>
                    </div>
                @elseif (session('status'))
                    <div class="mb-4 rounded border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">
                        {{ session('status') }}
                    </div>
                @endif

                {{ $slot ?? '' }}
                @yield('content')
            </div>
        </main>
    </div>

    @livewireScripts
</body>
</html>
