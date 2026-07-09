<?php

use App\Enums\ArticleStatus;
use App\Enums\FreshnessStatus;
use App\Enums\ValidationDecision;
use App\Livewire\Admin\Knowledge\ReviewQueue;
use App\Models\KnowledgeArticle;
use App\Models\KnowledgeSource;
use App\Models\User;
use App\Models\ValidationRun;
use App\Models\ValidationVote;
use Livewire\Livewire;

test('non-admin users are forbidden from the validation review queue', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('admin.knowledge.reviews.index'))
        ->assertForbidden();
});

test('admin can view articles that need validation review', function () {
    $this->actingAs(User::factory()->admin()->create());

    $staleArticle = validationReviewSourcedArticle([
        'title' => 'Stale filing guide',
        'status' => ArticleStatus::Published,
        'next_review_at' => now()->subDay(),
    ]);

    $conflictingArticle = validationReviewSourcedArticle([
        'title' => 'Conflicting source guide',
    ]);

    $conflictingRun = ValidationRun::factory()
        ->for($conflictingArticle, 'article')
        ->completed(ValidationDecision::ConflictingSources)
        ->create();

    ValidationVote::factory()
        ->for($conflictingRun, 'validationRun')
        ->contradiction()
        ->create([
            'vote' => ValidationDecision::ConflictingSources,
            'concerns' => ['Two sources disagree on the threshold.'],
        ]);

    ValidationVote::factory()
        ->for($conflictingRun, 'validationRun')
        ->finalJudge()
        ->create([
            'vote' => ValidationDecision::ConflictingSources,
        ]);

    $failedArticle = validationReviewSourcedArticle([
        'title' => 'Failed validation guide',
    ]);

    ValidationRun::factory()
        ->for($failedArticle, 'article')
        ->failed()
        ->create();

    $adminReviewArticle = validationReviewSourcedArticle([
        'title' => 'Admin decision guide',
    ]);

    ValidationRun::factory()
        ->for($adminReviewArticle, 'article')
        ->completed(ValidationDecision::AdminReviewRequired)
        ->create();

    $approvedArticle = validationReviewSourcedArticle([
        'title' => 'Already approved guide',
        'next_review_at' => now()->addMonth(),
    ]);

    ValidationRun::factory()
        ->for($approvedArticle, 'article')
        ->completed(ValidationDecision::ApprovedCurrent)
        ->create();

    $reviewedConflictArticle = validationReviewSourcedArticle([
        'title' => 'Reviewed conflict guide',
        'next_review_at' => now()->addMonth(),
    ]);

    ValidationRun::factory()
        ->for($reviewedConflictArticle, 'article')
        ->completed(ValidationDecision::ConflictingSources)
        ->create();

    $reviewedConflictArticle->update(['admin_reviewed_at' => now()]);

    $this->get(route('admin.knowledge.reviews.index'))
        ->assertOk()
        ->assertSee('Validation Review Queue')
        ->assertSee($staleArticle->title)
        ->assertSee($conflictingArticle->title)
        ->assertSee('Conflicting sources')
        ->assertSee('Judge: Conflicting sources')
        ->assertSee($failedArticle->title)
        ->assertSee('Failed validation')
        ->assertSee($adminReviewArticle->title)
        ->assertDontSee($approvedArticle->title)
        ->assertDontSee($reviewedConflictArticle->title);
});

test('admin can approve current content from the review queue', function () {
    $this->actingAs(User::factory()->admin()->create());

    $article = validationReviewSourcedArticle([
        'status' => ArticleStatus::NeedsReview,
        'next_review_at' => now()->subDay(),
    ]);

    $run = ValidationRun::factory()
        ->for($article, 'article')
        ->completed(ValidationDecision::ConflictingSources)
        ->create();

    Livewire::test(ReviewQueue::class)
        ->call('approveCurrent', $article->id)
        ->assertHasNoErrors();

    expect($article->fresh()->status)->toBe(ArticleStatus::Published)
        ->and($article->fresh()->freshnessStatus())->toBe(FreshnessStatus::Fresh)
        ->and($article->fresh()->admin_reviewed_at)->not->toBeNull()
        ->and($run->fresh()->aggregate_decision)->toBe(ValidationDecision::ConflictingSources)
        ->and($run->fresh()->status->value)->toBe('completed');

    $this->get(route('admin.knowledge.reviews.index'))
        ->assertDontSee($article->title);
});

test('admin can mark an article stale from the review queue', function () {
    $this->actingAs(User::factory()->admin()->create());

    $article = validationReviewSourcedArticle([
        'status' => ArticleStatus::Published,
        'next_review_at' => now()->addMonth(),
    ]);

    Livewire::test(ReviewQueue::class)
        ->call('markStale', $article->id)
        ->assertHasNoErrors();

    expect($article->fresh()->status)->toBe(ArticleStatus::NeedsReview)
        ->and($article->fresh()->next_review_at->isPast())->toBeTrue()
        ->and($article->fresh()->freshnessStatus())->toBe(FreshnessStatus::Stale);
});

test('admin can request revalidation from the review queue', function () {
    $this->actingAs(User::factory()->admin()->create());

    $article = validationReviewSourcedArticle([
        'status' => ArticleStatus::Published,
    ]);

    Livewire::test(ReviewQueue::class)
        ->call('requestRevalidation', $article->id)
        ->assertHasNoErrors();

    expect($article->fresh()->status)->toBe(ArticleStatus::NeedsReview)
        ->and($article->fresh()->revalidation_requested_at)->not->toBeNull();
});

test('admin can save review notes from the review queue', function () {
    $this->actingAs(User::factory()->admin()->create());

    $article = validationReviewSourcedArticle([
        'status' => ArticleStatus::Published,
        'next_review_at' => now()->addMonth(),
    ]);

    ValidationRun::factory()
        ->for($article, 'article')
        ->completed(ValidationDecision::ConflictingSources)
        ->create();

    Livewire::test(ReviewQueue::class)
        ->set("reviewNotes.{$article->id}", 'Reviewed source links and asked for a Texas Comptroller refresh.')
        ->call('saveReviewNotes', $article->id)
        ->assertHasNoErrors();

    expect($article->fresh()->admin_review_notes)->toBe('Reviewed source links and asked for a Texas Comptroller refresh.')
        ->and($article->fresh()->admin_reviewed_at)->toBeNull();

    $this->get(route('admin.knowledge.reviews.index'))
        ->assertSee($article->title);
});

test('admin can archive an article from the review queue', function () {
    $this->actingAs(User::factory()->admin()->create());

    $article = validationReviewSourcedArticle([
        'status' => ArticleStatus::NeedsReview,
    ]);

    Livewire::test(ReviewQueue::class)
        ->call('archive', $article->id)
        ->assertHasNoErrors();

    expect($article->fresh()->status)->toBe(ArticleStatus::Archived);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function validationReviewSourcedArticle(array $attributes = []): KnowledgeArticle
{
    $article = KnowledgeArticle::factory()->create($attributes);

    KnowledgeSource::factory()
        ->for($article, 'article')
        ->create();

    return $article;
}
