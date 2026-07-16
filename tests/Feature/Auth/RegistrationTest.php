<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    Notification::fake();

    $response = $this->post(route('register.store'), [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();

    $user = User::query()->sole();

    expect($user->hasVerifiedEmail())->toBeFalse();
    Notification::assertSentTo($user, VerifyEmail::class);

    $this->get(route('dashboard'))
        ->assertRedirect(route('verification.notice'));
});

test('registration requests are rate limited', function () {
    $payload = [
        'name' => 'John Doe',
        'email' => 'invalid',
        'password' => 'password',
        'password_confirmation' => 'password',
    ];

    foreach (range(1, 3) as $attempt) {
        $this->post(route('register.store'), $payload)
            ->assertSessionHasErrors('email');
    }

    $this->post(route('register.store'), $payload)
        ->assertTooManyRequests();
});

test('users cannot grant themselves administrator access through mass assignment', function () {
    $user = User::create([
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'is_admin' => true,
    ]);

    expect($user->refresh()->is_admin)->toBeFalse()
        ->and(User::factory()->admin()->create()->is_admin)->toBeTrue();
});
