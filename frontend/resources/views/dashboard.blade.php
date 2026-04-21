@extends('layouts.app', ['title' => 'Dashboard', 'header' => 'Dashboard'])

@section('content')
    <div class="grid gap-4 md:grid-cols-3">
        <a href="{{ route('devices.index') }}" class="rounded-lg border border-slate-200 bg-white p-5 hover:border-indigo-300 hover:shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Devices</div>
            <div class="mt-2 text-2xl font-semibold">{{ \App\Models\Device::count() }}</div>
            <div class="mt-1 text-xs text-slate-500 dark:text-slate-400">virtual eUICCs defined</div>
        </a>

        <a href="{{ route('ipa.console') }}" class="rounded-lg border border-slate-200 bg-white p-5 hover:border-indigo-300 hover:shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">IPA Console</div>
            <div class="mt-2 text-2xl font-semibold">Run</div>
            <div class="mt-1 text-xs text-slate-500 dark:text-slate-400">target one or many devices</div>
        </a>

        <a href="{{ route('server.status') }}" class="rounded-lg border border-slate-200 bg-white p-5 hover:border-indigo-300 hover:shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Server Status</div>
            <div class="mt-2 text-2xl font-semibold">Check</div>
            <div class="mt-1 text-xs text-slate-500 dark:text-slate-400">simulator health</div>
        </a>
    </div>

    <div class="mt-6 rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <h2 class="text-sm font-semibold">Architecture</h2>
        <pre class="mt-3 overflow-x-auto rounded bg-slate-100 p-3 text-xs dark:bg-slate-950">Browser ─► Nginx (443)
          ├─ /           ─► PHP-FPM (this app)
          ├─ /api/es10/* ─► 127.0.0.1:8100 (eUICC simulator)
          └─ /api/ipa/*  ─► 127.0.0.1:8101 (IPA simulator)</pre>
    </div>
@endsection
