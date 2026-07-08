<?php

use App\Http\Controllers\Business\IntakeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\RoadmapController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('business/intake', IntakeController::class)->name('business.intake');
    Route::get('roadmap', RoadmapController::class)->name('roadmap');
    Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
});

require __DIR__.'/settings.php';
