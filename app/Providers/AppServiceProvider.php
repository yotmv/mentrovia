<?php

namespace App\Providers;

use App\Ai\Providers\OpenRouterProvider;
use App\Ai\Providers\ReplicateProvider;
use App\Ai\Providers\StabilityProvider;
use App\Ai\Text\Contracts\TextRoleGenerator;
use App\Ai\Text\TextRoleManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Ai\Ai;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TextRoleGenerator::class, TextRoleManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->registerAiImageProviders();
    }

    /**
     * Register the image-only AI provider drivers not shipped with the SDK.
     */
    protected function registerAiImageProviders(): void
    {
        Ai::extend('replicate', fn (Application $app, array $config) => new ReplicateProvider($config, $app['events']));
        Ai::extend('stability', fn (Application $app, array $config) => new StabilityProvider($config, $app['events']));

        // Override the stock OpenRouter driver with a subclass whose image
        // gateway captures the actual billed cost from usage accounting.
        Ai::extend('openrouter', fn (Application $app, array $config) => new OpenRouterProvider($config, $app['events']));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
