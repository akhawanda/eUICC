<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-slate-50 dark:bg-slate-900">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? config('app.name') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full font-sans text-slate-800 antialiased dark:text-slate-200">
    <div class="flex min-h-full">
        @include('layouts.sidebar')

        <main class="flex-1">
            <header class="border-b border-slate-200 bg-white px-6 py-4 dark:border-slate-800 dark:bg-slate-900">
                <div class="flex items-center justify-between">
                    <h1 class="text-lg font-semibold">{{ $header ?? ($title ?? '') }}</h1>
                    <div class="flex items-center gap-3 text-sm">
                        <span class="text-slate-500 dark:text-slate-400">
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

            <div class="p-6">
                @if (session('status'))
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
