<?php

use App\Enums\FeedbackCategory;
use App\Models\User;
use App\Models\UserFeedback;

test('guests are redirected from the feedback page', function () {
    $this->get(route('feedback.create'))->assertRedirect(route('login'));
});

test('authenticated users can open the feedback page from the user menu', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('feedback.create', ['page' => '/advisor']))
        ->assertSuccessful()
        ->assertSee('Help improve Mentrovia')
        ->assertSee('Send feedback')
        ->assertSee('value="/advisor"', escape: false);
});

test('an authenticated user can submit feedback', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('feedback.store'), [
            'category' => FeedbackCategory::Content->value,
            'page' => '/knowledge/articles/texas-sales-tax-permit-basics',
            'message' => 'This source needs a clearer explanation of the review date.',
        ])
        ->assertRedirect(route('feedback.create'))
        ->assertSessionHas('status');

    $feedback = UserFeedback::query()->sole();

    expect($feedback->user_id)->toBe($user->id)
        ->and($feedback->category)->toBe(FeedbackCategory::Content)
        ->and($feedback->page)->toBe('/knowledge/articles/texas-sales-tax-permit-basics')
        ->and($feedback->message)->toBe('This source needs a clearer explanation of the review date.');
});

test('feedback requires a valid category and useful message', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('feedback.store'), [
            'category' => 'not-a-category',
            'message' => 'short',
        ])
        ->assertInvalid(['category', 'message']);

    expect(UserFeedback::query()->count())->toBe(0);
});
