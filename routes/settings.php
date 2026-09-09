<?php

use App\Http\Middleware\EnsureEmployeeIsActive;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', EnsureEmployeeIsActive::class])->prefix('employee')->group(function () {
    Route::redirect('settings', 'employee/settings/profile');

    Route::livewire('settings/profile', 'pages::settings.profile')->name('profile.edit');
});

Route::middleware(['auth', 'verified', EnsureEmployeeIsActive::class])->prefix('employee')->group(function () {
    Route::livewire('settings/appearance', 'pages::settings.appearance')->name('appearance.edit');

    Route::livewire('settings/security', 'pages::settings.security')
        ->middleware([
            'password.confirm',
        ])
        ->name('security.edit');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
