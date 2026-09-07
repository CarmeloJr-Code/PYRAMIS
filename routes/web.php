<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->prefix('employee')->name('employee.')->group(function () {
    Route::livewire('dashboard', 'pages::employee.dashboard')->name('dashboard');

    Route::livewire('sales', 'pages::employee.sales')
        ->middleware('can:access-sales')
        ->name('sales');

    Route::livewire('production', 'pages::employee.production')
        ->middleware('can:access-production')
        ->name('production');

    Route::livewire('workforce', 'pages::employee.workforce')
        ->middleware('can:access-workforce')
        ->name('workforce');
});

require __DIR__.'/settings.php';
