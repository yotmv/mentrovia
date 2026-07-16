<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Responses\PasswordResetLinkResponse;
use App\Models\User;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;
use Livewire\Livewire;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            FailedPasswordResetLinkRequestResponse::class,
            fn (): PasswordResetLinkResponse => new PasswordResetLinkResponse,
        );

        $this->app->bind(
            SuccessfulPasswordResetLinkRequestResponse::class,
            fn (): PasswordResetLinkResponse => new PasswordResetLinkResponse,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Livewire::addPersistentMiddleware(EnsureEmailIsVerified::class);
        Livewire::addPersistentMiddleware(EnsureAccountIsActive::class);

        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::query()->where('email', (string) $request->string(Fortify::username()))->first();
            $passwordHash = $user->password
                ?? '$2y$12$UV/3xM4UUBbpXLyBFzpng.l1bLrWzZqrik9FQkFIc9FbJQ8C5H3Hq';
            $passwordIsValid = Hash::check((string) $request->input('password'), $passwordHash);

            return $passwordIsValid && $user?->account_erasure_started_at === null
                ? $user
                : null;
        });
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn () => view('livewire.auth.login'));
        Fortify::verifyEmailView(fn () => view('livewire.auth.verify-email'));
        Fortify::twoFactorChallengeView(fn () => view('livewire.auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('livewire.auth.confirm-password'));
        Fortify::registerView(fn () => view('livewire.auth.register'));
        Fortify::resetPasswordView(fn () => view('livewire.auth.reset-password'));
        Fortify::requestPasswordResetLinkView(fn () => view('livewire.auth.forgot-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('fortify-sensitive', function (Request $request) {
            $routeName = $request->route()?->getName();
            $emailFingerprint = hash('sha256', Str::lower(trim((string) $request->input(Fortify::email()))));

            return match ($routeName) {
                'register.store' => [
                    Limit::perMinute(3)->by('registration-minute|'.$request->ip()),
                    Limit::perDay(10)->by('registration-day|'.$request->ip()),
                ],
                'password.email' => [
                    Limit::perMinute(3)->by('password-reset-link-minute|'.$request->ip()),
                    Limit::perHour(3)->by('password-reset-link-email|'.$emailFingerprint),
                ],
                default => Limit::none(),
            };
        });
    }
}
