<?php

use App\Http\Controllers\Settings\AppearanceController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('settings/appearance', [AppearanceController::class, 'edit'])->name('appearance.edit');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware([
            'password.confirm',
        ])
        ->name('security.edit');
});
