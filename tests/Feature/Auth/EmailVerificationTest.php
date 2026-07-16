<?php

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Laravel\Fortify\Features;
use Livewire\Livewire;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::emailVerification());
});

test('email verification screen can be rendered', function () {
    $user = User::factory()->unverified()->create();

    $response = $this->actingAs($user)->get(route('verification.notice'));

    $response->assertOk();
});

test('unverified users cannot access protected product routes', function (string $routeName) {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get(route($routeName))
        ->assertRedirect(route('verification.notice'));
})->with([
    'dashboard' => 'dashboard',
    'advisor' => 'advisor',
    'branding' => 'branding',
    'advertising' => 'advertising',
    'photo studio' => 'projects.index',
]);

test('email verification remains enforced during Livewire updates', function () {
    $user = User::factory()->create();
    $page = $this->actingAs($user)->get(route('branding'));

    preg_match_all('/wire:snapshot="([^"]+)"/', $page->getContent(), $matches);

    $snapshot = collect($matches[1] ?? [])
        ->map(fn (string $encodedSnapshot): string => htmlspecialchars_decode($encodedSnapshot, ENT_QUOTES))
        ->first(fn (string $encodedSnapshot): bool => data_get(json_decode($encodedSnapshot, true), 'memo.name') === 'branding.index');

    expect(Livewire::getPersistentMiddleware())
        ->toContain(EnsureEmailIsVerified::class)
        ->and($snapshot)->not->toBeNull();

    $user->markEmailAsUnverified();

    $this->withHeader('X-Livewire', 'true')
        ->postJson(Livewire::getUpdateUri(), [
            'components' => [[
                'snapshot' => $snapshot,
                'calls' => [],
                'updates' => [],
            ]],
        ])
        ->assertForbidden();
});

test('email can be verified', function () {
    $user = User::factory()->unverified()->create();

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    $response = $this->actingAs($user)->get($verificationUrl);

    Event::assertDispatched(Verified::class);

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    $response->assertRedirect(route('dashboard', absolute: false).'?verified=1');
});

test('email is not verified with invalid hash', function () {
    $user = User::factory()->unverified()->create();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1('wrong-email')],
    );

    $this->actingAs($user)->get($verificationUrl);

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('already verified user visiting verification link is redirected without firing event again', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    $this->actingAs($user)->get($verificationUrl)
        ->assertRedirect(route('dashboard', absolute: false).'?verified=1');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    Event::assertNotDispatched(Verified::class);
});
