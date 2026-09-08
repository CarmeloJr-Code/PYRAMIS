<?php

use Illuminate\Support\Facades\Route;

// Purple Yam Malaybalay storefront. No authentication — customers never hold
// an account (BR-001).
Route::livewire('/', 'pages::storefront.home')->name('home');
Route::livewire('products', 'pages::storefront.products')->name('products.index');
Route::livewire('products/{product:slug}', 'pages::storefront.product')->name('products.show');
Route::livewire('order', 'pages::storefront.order')->name('order');
Route::livewire('orders/{order:reference}', 'pages::storefront.order-status')->name('orders.show');

Route::middleware(['auth', 'verified'])->prefix('employee')->name('employee.')->group(function () {
    Route::livewire('dashboard', 'pages::employee.dashboard')->name('dashboard');

    Route::middleware('can:manage-orders')->group(function () {
        Route::livewire('orders', 'pages::employee.orders.index')->name('orders.index');
        Route::livewire('orders/{order:reference}', 'pages::employee.orders.show')->name('orders.show');
    });

    Route::middleware('can:manage-outlets')->group(function () {
        Route::livewire('outlets', 'pages::employee.outlets.index')->name('outlets.index');
        Route::livewire('outlets/create', 'pages::employee.outlets.manage')->name('outlets.create');
        Route::livewire('outlets/{outlet}/edit', 'pages::employee.outlets.manage')->name('outlets.edit');
    });

    Route::middleware('can:manage-products')->group(function () {
        Route::livewire('products', 'pages::employee.products.index')->name('products.index');
        Route::livewire('products/create', 'pages::employee.products.manage')->name('products.create');
        Route::livewire('products/{product}/edit', 'pages::employee.products.manage')->name('products.edit');
    });

    Route::middleware('can:access-sales')->group(function () {
        Route::livewire('sales', 'pages::employee.sales.index')->name('sales.index');
        Route::livewire('sales/create', 'pages::employee.sales.create')->name('sales.create');
    });

    Route::middleware('can:access-inventory')->group(function () {
        Route::livewire('inventory', 'pages::employee.inventory.index')->name('inventory.index');
        // Before the wildcard, so neither is ever read as an ingredient.
        Route::livewire('inventory/create', 'pages::employee.inventory.manage')->name('inventory.create');
        Route::livewire('inventory/usage', 'pages::employee.inventory.usage')->name('inventory.usage');
        Route::livewire('inventory/{ingredient}', 'pages::employee.inventory.show')->name('inventory.show');
        Route::livewire('inventory/{ingredient}/edit', 'pages::employee.inventory.manage')->name('inventory.edit');
    });

    Route::middleware('can:access-production')->group(function () {
        Route::livewire('production', 'pages::employee.production.index')->name('production');
        Route::livewire('production/runs', 'pages::employee.production.runs.index')->name('production.runs.index');
        Route::livewire('production/runs/{productionRun:reference}', 'pages::employee.production.runs.show')->name('production.runs.show');
        Route::livewire('production/recipes', 'pages::employee.production.recipes.index')->name('production.recipes.index');
        Route::livewire('production/recipes/{productVariant}', 'pages::employee.production.recipes.manage')->name('production.recipes.manage');
    });

    Route::livewire('workforce', 'pages::employee.workforce')
        ->middleware('can:access-workforce')
        ->name('workforce');
});

require __DIR__.'/settings.php';
