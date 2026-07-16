<?php

namespace App\Providers;

use App\Ai\Providers\OpenRouterProvider;
use App\Ai\Providers\ReplicateProvider;
use App\Ai\Providers\StabilityProvider;
use App\Ai\Text\Contracts\TextRoleGenerator;
use App\Ai\Text\TextRoleManager;
use App\Contracts\OpenRouterPreflightClient;
use App\Models\Account;
use App\Models\User;
use App\Services\Accounts\CurrentAccount;
use App\Services\Ai\ByokHttpFactory;
use App\Services\Ai\IsolatedOpenRouterPreflightClient;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Ai\Ai;
use Laravel\Cashier\Cashier;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CurrentAccount::class);
        $this->app->scoped(TextRoleGenerator::class, TextRoleManager::class);
        $this->app->scoped(ByokHttpFactory::class, fn (): ByokHttpFactory => new ByokHttpFactory);
        $this->app->scoped(OpenRouterPreflightClient::class, IsolatedOpenRouterPreflightClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Cashier::useCustomerModel(Account::class);
        Cashier::ignoreRoutes();
        PreventRequestForgery::except(trim((string) config('cashier.path', 'stripe'), '/').'/webhook');
        $this->configureDefaults();
        $this->configureRateLimiting();
        $this->registerAiImageProviders();
    }

    protected function configureRateLimiting(): void
    {
        RateLimiter::for('csp-reports', fn (Request $request): Limit => Limit::perMinute(60)
            ->by('csp-report|'.$request->ip()));

        RateLimiter::for('project-invitations', function (Request $request): array {
            return [
                Limit::perMinute(10)->by('user|'.$request->user()?->getAuthIdentifier()),
                Limit::perMinute(30)->by('ip|'.$request->ip()),
            ];
        });

        RateLimiter::for('account-invitations', function (Request $request): array {
            return [
                Limit::perMinute(10)->by('user|'.$request->user()?->getAuthIdentifier()),
                Limit::perMinute(30)->by('ip|'.$request->ip()),
            ];
        });

        RateLimiter::for('ai-trust-export', function (Request $request): Limit {
            $user = $request->user();
            $accountId = $user instanceof User ? $user->current_account_id : 'guest';
            $actorId = $user instanceof User ? $user->getAuthIdentifier() : $request->ip();

            return Limit::perHour(5)->by('ai-trust-export|'.$accountId.'|'.$actorId);
        });

        RateLimiter::for('billing', function (Request $request): array {
            return [
                Limit::perMinute(10)->by('billing-user|'.$request->user()?->getAuthIdentifier()),
                Limit::perMinute(30)->by('billing-ip|'.$request->ip()),
            ];
        });
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
        $trustedProxies = config('security.trusted_proxies', []);

        if (is_array($trustedProxies) && $trustedProxies !== []) {
            TrustProxies::at($trustedProxies);
        }

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
