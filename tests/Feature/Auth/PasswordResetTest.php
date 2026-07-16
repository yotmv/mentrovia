<?php

use App\Models\User;
use App\Notifications\QueuedResetPasswordNotification;
use Carbon\CarbonInterval;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::resetPasswords());
});

test('reset password link screen can be rendered', function () {
    $response = $this->get(route('password.request'));

    $response->assertOk();
});

test('reset password link can be requested', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.request'), ['email' => $user->email]);

    Notification::assertSentTo($user, QueuedResetPasswordNotification::class);
});

test('password reset requests do not reveal whether an account exists', function () {
    Notification::fake();

    $user = User::factory()->create();

    $knownAccountResponse = $this->from(route('password.request'))
        ->post(route('password.email'), ['email' => $user->email]);
    $unknownAccountResponse = $this->from(route('password.request'))
        ->post(route('password.email'), ['email' => 'unknown@example.com']);

    $knownAccountResponse
        ->assertRedirect(route('password.request'))
        ->assertSessionHas('status', trans('passwords.sent'));
    $unknownAccountResponse
        ->assertRedirect(route('password.request'))
        ->assertSessionHas('status', trans('passwords.sent'))
        ->assertSessionDoesntHaveErrors('email');

    Notification::assertSentTo($user, QueuedResetPasswordNotification::class);
});

test('known and unknown reset requests share a minimum response boundary while real mail is queued', function () {
    $user = User::factory()->create();

    config(['auth.timebox_duration' => 0]);

    Queue::fake();
    Sleep::fake();

    $this->post(route('password.email'), ['email' => $user->email])
        ->assertRedirect();

    Sleep::assertSlept(
        fn (CarbonInterval $duration): bool => $duration->totalMilliseconds >= 200
            && $duration->totalMilliseconds <= 250,
    );
    Queue::assertPushed(SendQueuedNotifications::class, function (SendQueuedNotifications $job): bool {
        return $job->notification instanceof QueuedResetPasswordNotification
            && $job->deleteWhenMissingModels
            && $job->shouldBeEncrypted;
    });

    Queue::fake();
    Sleep::fake();

    $this->post(route('password.email'), ['email' => 'unknown@example.com'])
        ->assertRedirect();

    Sleep::assertSlept(
        fn (CarbonInterval $duration): bool => $duration->totalMilliseconds >= 200
            && $duration->totalMilliseconds <= 250,
    );
    Queue::assertNothingPushed();
});

test('known and unknown reset requests return identical json responses', function () {
    Notification::fake();
    Sleep::fake();
    config(['auth.timebox_duration' => 0]);

    $user = User::factory()->create();
    $expected = ['message' => trans('passwords.sent')];

    $this->postJson(route('password.email'), ['email' => $user->email])
        ->assertOk()
        ->assertExactJson($expected);

    $this->postJson(route('password.email'), ['email' => 'unknown@example.com'])
        ->assertOk()
        ->assertExactJson($expected);
});

test('the response equalizer does not delay other Fortify routes', function () {
    Sleep::fake();

    $this->get(route('password.request'))->assertOk();

    Sleep::assertNeverSlept();
});

test('password reset link requests are rate limited', function () {
    Notification::fake();

    foreach (range(1, 3) as $attempt) {
        $this->post(route('password.email'), ['email' => "unknown{$attempt}@example.com"])
            ->assertRedirect();
    }

    $this->post(route('password.email'), ['email' => 'unknown4@example.com'])
        ->assertTooManyRequests();
});

test('password reset link requests are limited per target across IP addresses', function () {
    Notification::fake();

    foreach (range(1, 3) as $attempt) {
        $this->withServerVariables(['REMOTE_ADDR' => "192.0.2.{$attempt}"])
            ->post(route('password.email'), ['email' => 'target@example.com'])
            ->assertRedirect();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.4'])
        ->post(route('password.email'), ['email' => 'target@example.com'])
        ->assertTooManyRequests();
});

test('reset password screen can be rendered', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.request'), ['email' => $user->email]);

    Notification::assertSentTo($user, QueuedResetPasswordNotification::class, function ($notification) {
        $response = $this->get(route('password.reset', $notification->token));

        $response->assertOk();

        return true;
    });
});

test('password can be reset with valid token', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.request'), ['email' => $user->email]);

    Notification::assertSentTo($user, QueuedResetPasswordNotification::class, function ($notification) use ($user) {
        $response = $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login', absolute: false));

        return true;
    });
});
