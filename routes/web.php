<?php

use App\Http\Middleware\EnsureEmployeeIsActive;
use Illuminate\Support\Facades\Route;

// Purple Yam Malaybalay storefront. No authentication — customers never hold
// an account (BR-001).
Route::livewire('/', 'pages::storefront.home')->name('home');
Route::livewire('products', 'pages::storefront.products')->name('products.index');
Route::livewire('products/{product:slug}', 'pages::storefront.product')->name('products.show');
Route::livewire('order', 'pages::storefront.order')->name('order');
Route::livewire('orders/{order:reference}', 'pages::storefront.order-status')->name('orders.show');

Route::middleware(['auth', 'verified', EnsureEmployeeIsActive::class])->prefix('employee')->name('employee.')->group(function () {
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

    Route::middleware('can:access-expenses')->group(function () {
        Route::livewire('expenses', 'pages::employee.expenses.index')->name('expenses.index');
        // Before the wildcard, so neither is ever read as an expense.
        Route::livewire('expenses/create', 'pages::employee.expenses.manage')->name('expenses.create');
        Route::livewire('expenses/categories', 'pages::employee.expenses.categories')
            ->middleware('can:manage-expenses')
            ->name('expenses.categories');
        Route::livewire('expenses/{expense}/edit', 'pages::employee.expenses.manage')
            ->middleware('can:manage-expenses')
            ->name('expenses.edit');
    });

    Route::middleware('can:access-inventory')->group(function () {
        Route::livewire('inventory', 'pages::employee.inventory.index')->name('inventory.index');
        // Before the wildcard, so neither is ever read as an ingredient.
        Route::livewire('inventory/create', 'pages::employee.inventory.manage')->name('inventory.create');
        Route::livewire('inventory/usage', 'pages::employee.inventory.usage')->name('inventory.usage');
        Route::livewire('inventory/finished-goods', 'pages::employee.inventory.finished-goods')->name('inventory.finished');
        Route::livewire('inventory/{ingredient}', 'pages::employee.inventory.show')->name('inventory.show');
        Route::livewire('inventory/{ingredient}/edit', 'pages::employee.inventory.manage')->name('inventory.edit');
    });

    Route::middleware('can:access-restocking')->group(function () {
        Route::livewire('restocks', 'pages::employee.restocks.index')->name('restocks.index');
        // Before the wildcard, so "create" is never read as a reference.
        Route::livewire('restocks/create', 'pages::employee.restocks.create')
            ->middleware('can:schedule-restocks')
            ->name('restocks.create');
        Route::livewire('restocks/{restock:reference}', 'pages::employee.restocks.show')->name('restocks.show');
    });

    Route::middleware('can:access-production')->group(function () {
        Route::livewire('production', 'pages::employee.production.index')->name('production');
        Route::livewire('production/runs', 'pages::employee.production.runs.index')->name('production.runs.index');
        Route::livewire('production/runs/{productionRun:reference}', 'pages::employee.production.runs.show')->name('production.runs.show');
        Route::livewire('production/recipes', 'pages::employee.production.recipes.index')->name('production.recipes.index');
        Route::livewire('production/recipes/{productVariant}', 'pages::employee.production.recipes.manage')->name('production.recipes.manage');
    });

    Route::middleware('can:access-workforce')->group(function () {
        Route::livewire('workforce', 'pages::employee.workforce.index')->name('workforce');
        // Before the wildcard, so none of these is ever read as a shift.
        Route::livewire('workforce/create', 'pages::employee.workforce.manage')->name('workforce.shifts.create');
        Route::livewire('workforce/employees', 'pages::employee.workforce.employees.index')->name('workforce.employees.index');
        Route::livewire('workforce/employees/create', 'pages::employee.workforce.employees.manage')->name('workforce.employees.create');
        Route::livewire('workforce/employees/{user}/edit', 'pages::employee.workforce.employees.manage')->name('workforce.employees.edit');
        Route::livewire('workforce/{shift}', 'pages::employee.workforce.show')->name('workforce.shifts.show');
        Route::livewire('workforce/{shift}/edit', 'pages::employee.workforce.manage')->name('workforce.shifts.edit');
    });

    // Reports. The hub is open to any signed-in employee and lists only what
    // they can reach; each report keeps the gate that guards the screens its
    // figures come from, so the matrix's "relevant subset" is enforced by the
    // abilities already in use rather than a second set of rules.
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::livewire('/', 'pages::employee.reports.index')->name('index');

        Route::livewire('sales', 'pages::employee.reports.sales')
            ->middleware('can:access-sales')->name('sales');

        Route::livewire('inventory', 'pages::employee.reports.inventory')
            ->middleware('can:access-inventory')->name('inventory');

        Route::livewire('production', 'pages::employee.reports.production')
            ->middleware('can:access-production')->name('production');

        Route::livewire('expenses', 'pages::employee.reports.expenses')
            ->middleware('can:access-expenses')->name('expenses');

        Route::livewire('workforce', 'pages::employee.reports.workforce')
            ->middleware('can:access-workforce')->name('workforce');

        // Outlet performance reads across sales, restocking and expenses at
        // once, which no single operational gate covers. Outlet records are
        // management territory (BR-007), so it follows manage-outlets.
        Route::livewire('outlets', 'pages::employee.reports.outlets')
            ->middleware('can:manage-outlets')->name('outlets');
    });

    // Every employee's own roster. No gate beyond being signed in: this shows
    // the signed-in employee their own work and nobody else's.
    Route::livewire('schedule', 'pages::employee.schedule')->name('schedule');

    // Internal chat, which the capability matrix gives to all three roles. Each
    // screen still scopes to the conversations the signed-in employee is in.
    Route::livewire('messages', 'pages::employee.messages.index')->name('messages.index');
    // Before the wildcard, so "create" is never read as a conversation.
    Route::livewire('messages/create', 'pages::employee.messages.create')->name('messages.create');
    Route::livewire('messages/{conversation}', 'pages::employee.messages.show')->name('messages.show');
});

require __DIR__.'/settings.php';
