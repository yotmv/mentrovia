<?php

use App\Enums\BusinessProfileSection;
use App\Http\Controllers\AccountInvitationController;
use App\Http\Controllers\BankingSetupController;
use App\Http\Controllers\Business\IntakeController;
use App\Http\Controllers\Business\OnboardingTemplateController;
use App\Http\Controllers\Business\OverviewController;
use App\Http\Controllers\CspReportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\GrowthController;
use App\Http\Controllers\GuidesController;
use App\Http\Controllers\Knowledge\ArticleController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\OwnerPayController;
use App\Http\Controllers\PhotoDeliveryController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectInvitationController;
use App\Http\Controllers\RoadmapController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\TaskController;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Models\KnowledgeArticle;
use Illuminate\Support\Facades\Route;
use Laravel\Cashier\Http\Controllers\PaymentController;

Route::view('/', 'welcome')->name('home');

Route::post('csp-reports', CspReportController::class)
    ->middleware('throttle:csp-reports')
    ->name('csp-reports');

Route::prefix(trim((string) config('cashier.path', 'stripe'), '/'))->name('cashier.')->group(function () {
    Route::get('payment/{id}', [PaymentController::class, 'show'])->name('payment');
    Route::post('webhook', [StripeWebhookController::class, 'handleWebhook'])->name('webhook');
});

Route::middleware(['auth', EnsureAccountIsActive::class, 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('onboarding', [OnboardingController::class, 'welcome'])->name('onboarding.welcome');
    Route::get('onboarding/plan-ready', [OnboardingController::class, 'planReady'])->name('onboarding.plan-ready');
    Route::get('feedback', [FeedbackController::class, 'create'])->name('feedback.create');
    Route::post('feedback', [FeedbackController::class, 'store'])->name('feedback.store');
    Route::get('business', OverviewController::class)->name('business.overview');
    Route::get('business/intake', IntakeController::class)->name('business.intake');
    Route::get('business/intake/template.csv', OnboardingTemplateController::class)->name('business.intake.template');
    Route::view('business/profile/edit', 'pages.business.profile-editor')->name('business.edit');
    Route::view('business/profile/edit/{section}', 'pages.business.profile-editor')
        ->whereIn('section', array_column(BusinessProfileSection::cases(), 'value'))
        ->name('business.profile.section');
    Route::view('business/profile/history', 'pages.business.profile-history')->name('business.profile.history');
    Route::view('business/profile/import', 'pages.business.profile-import')->name('business.profile.import');
    Route::get('business/not-supported', [OnboardingController::class, 'notSupported'])->name('business.not-supported');
    Route::view('advisor', 'pages.advisor')->name('advisor');
    Route::view('advisor/history', 'pages.advisor-history')->name('advisor.history');
    Route::get('roadmap', RoadmapController::class)->name('roadmap');
    Route::get('banking-setup', BankingSetupController::class)->name('banking-setup');
    Route::patch('banking-setup/items/{key}', [BankingSetupController::class, 'update'])->name('banking-setup.items.update');
    Route::get('owner-pay', OwnerPayController::class)->name('owner-pay');
    Route::get('guides', [GuidesController::class, 'index'])->name('guides.index');
    Route::get('guides/{guide}', [GuidesController::class, 'show'])->name('guides.show');
    Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
    Route::patch('tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
    Route::view('branding', 'pages.branding')->name('branding');
    Route::view('advertising', 'pages.advertising')->name('advertising');
    Route::get('grow', GrowthController::class)->name('grow');
    Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::get('projects/{project}/photos/{photo}', PhotoDeliveryController::class)->name('projects.photos.show');
    Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
    Route::get('project-invitations/{projectInvitation}', [ProjectInvitationController::class, 'show'])
        ->middleware(['signed', 'throttle:project-invitations'])
        ->name('project-invitations.show');
    Route::post('project-invitations/{projectInvitation}', [ProjectInvitationController::class, 'store'])
        ->middleware(['signed', 'throttle:project-invitations'])
        ->name('project-invitations.accept');
    Route::post('account-invitations/{accountInvitation}', [AccountInvitationController::class, 'store'])
        ->middleware(['signed', 'throttle:account-invitations'])
        ->name('account-invitations.accept');
    Route::get('knowledge/articles', [ArticleController::class, 'index'])->name('knowledge.articles.index');
    Route::get('knowledge/articles/{slug}', [ArticleController::class, 'show'])->name('knowledge.articles.show');
});

Route::get('account-invitations/{accountInvitation}', [AccountInvitationController::class, 'show'])
    ->middleware(['auth', EnsureAccountIsActive::class, 'signed', 'throttle:account-invitations'])
    ->name('account-invitations.show');

Route::middleware(['auth', EnsureAccountIsActive::class, 'verified', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::view('/knowledge/reviews', 'pages.admin.knowledge.reviews')->name('knowledge.reviews.index');
    Route::view('/knowledge/articles', 'pages.admin.knowledge.index')->name('knowledge.articles.index');
    Route::view('/knowledge/articles/create', 'pages.admin.knowledge.form')->name('knowledge.articles.create');
    Route::get('/knowledge/articles/{article}/edit', function (KnowledgeArticle $article) {
        return view('pages.admin.knowledge.form', ['article' => $article]);
    })->name('knowledge.articles.edit');
});

require __DIR__.'/settings.php';
