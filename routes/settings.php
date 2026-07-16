<?php

use App\Http\Controllers\Settings\AccountController;
use App\Http\Controllers\Settings\AiController;
use App\Http\Controllers\Settings\AiTrustExportController;
use App\Http\Controllers\Settings\AppearanceController;
use App\Http\Controllers\Settings\BillingController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Middleware\EnsureAccountIsActive;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', EnsureAccountIsActive::class])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
});

Route::middleware(['auth', EnsureAccountIsActive::class, 'verified'])->group(function () {
    Route::get('settings/account', [AccountController::class, 'edit'])->name('account.edit');
    Route::get('settings/appearance', [AppearanceController::class, 'edit'])->name('appearance.edit');
    Route::get('settings/billing', [BillingController::class, 'edit'])->name('billing.edit');
    Route::post('settings/billing/checkout', [BillingController::class, 'checkout'])
        ->middleware(['password.confirm', 'throttle:billing'])
        ->name('billing.checkout');
    Route::post('settings/billing/portal', [BillingController::class, 'portal'])
        ->middleware(['password.confirm', 'throttle:billing'])
        ->name('billing.portal');

    Route::get('settings/ai', [AiController::class, 'edit'])->name('ai.edit');
    Route::get('settings/ai/trust', [AiController::class, 'trust'])->name('ai.trust');
    Route::get('settings/ai/trust/export', AiTrustExportController::class)
        ->middleware('throttle:ai-trust-export')
        ->name('ai.trust.export');
    Route::post('settings/ai/openrouter-credential', [AiController::class, 'storeCredential'])
        ->middleware('password.confirm')
        ->name('ai.credential.store');
    Route::delete('settings/ai/openrouter-credential', [AiController::class, 'destroyCredential'])
        ->middleware('password.confirm')
        ->name('ai.credential.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware([
            'password.confirm',
        ])
        ->name('security.edit');
});
