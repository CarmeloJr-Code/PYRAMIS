<?php

use Illuminate\Support\Facades\Route;

// Purple Yam Malaybalay storefront. No authentication — customers never hold
// an account (BR-001).
Route::livewire('/', 'pages::storefront.home')->name('home');
Route::livewire('products', 'pages::storefront.products')->name('products.index');
Route::livewire('products/{product:slug}', 'pages::storefront.product')->name('products.show');

Route::middleware(['auth', 'verified'])->prefix('employee')->name('employee.')->group(function () {
    Route::livewire('dashboard', 'pages::employee.dashboard')->name('dashboard');

    Route::middleware('can:manage-products')->group(function () {
        Route::livewire('products', 'pages::employee.products.index')->name('products.index');
        Route::livewire('products/create', 'pages::employee.products.manage')->name('products.create');
        Route::livewire('products/{product}/edit', 'pages::employee.products.manage')->name('products.edit');
    });

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
