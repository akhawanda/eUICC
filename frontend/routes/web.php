<?php

use App\Livewire\Devices\Form as DeviceForm;
use App\Livewire\Devices\Index as DevicesIndex;
use App\Livewire\Devices\Show as DeviceShow;
use App\Livewire\IpaConsole;
use App\Livewire\ServerStatus;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('dashboard'));

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('/dashboard', 'dashboard')->name('dashboard');

    Route::get('/devices', DevicesIndex::class)->name('devices.index');
    Route::get('/devices/create', DeviceForm::class)->name('devices.create');
    Route::get('/devices/{device}/edit', DeviceForm::class)->name('devices.edit');
    Route::get('/devices/{device}', DeviceShow::class)->name('devices.show');

    Route::get('/ipa-console', IpaConsole::class)->name('ipa.console');
    Route::get('/server-status', ServerStatus::class)->name('server.status');
});

require __DIR__.'/auth.php';
