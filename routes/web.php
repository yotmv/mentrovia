<?php

use App\Http\Controllers\Business\IntakeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Knowledge\ArticleController;
use App\Http\Controllers\OwnerPayController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\RoadmapController;
use App\Http\Controllers\TaskController;
use App\Models\KnowledgeArticle;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('business/intake', IntakeController::class)->name('business.intake');
    Route::view('advisor', 'pages.advisor')->name('advisor');
    Route::view('advisor/history', 'pages.advisor-history')->name('advisor.history');
    Route::get('roadmap', RoadmapController::class)->name('roadmap');
    Route::get('owner-pay', OwnerPayController::class)->name('owner-pay');
    Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
    Route::patch('tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
    Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
    Route::get('knowledge/articles', [ArticleController::class, 'index'])->name('knowledge.articles.index');
    Route::get('knowledge/articles/{slug}', [ArticleController::class, 'show'])->name('knowledge.articles.show');
});

Route::middleware(['auth', 'verified', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::view('/knowledge/reviews', 'pages.admin.knowledge.reviews')->name('knowledge.reviews.index');
    Route::view('/knowledge/articles', 'pages.admin.knowledge.index')->name('knowledge.articles.index');
    Route::view('/knowledge/articles/create', 'pages.admin.knowledge.form')->name('knowledge.articles.create');
    Route::get('/knowledge/articles/{article}/edit', function (KnowledgeArticle $article) {
        return view('pages.admin.knowledge.form', ['article' => $article]);
    })->name('knowledge.articles.edit');
});

require __DIR__.'/settings.php';
