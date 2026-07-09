<?php

use App\Enums\TextGenerationRole;
use App\Enums\ValidationDecision;
use App\Enums\ValidationRunStatus;
use App\Models\Business;
use App\Models\KnowledgeArticle;
use App\Models\User;
use App\Models\ValidationRun;
use App\Models\ValidationVote;
use Carbon\CarbonInterface;

test('validation records can be stored and queried by article user and business', function () {
    $user = User::factory()->create();
    $business = Business::factory()->for($user)->create();
    $article = KnowledgeArticle::factory()->create();

    $run = ValidationRun::factory()
        ->forBusiness($business)
        ->completed(ValidationDecision::ApprovedWithCaveats)
        ->create([
            'knowledge_article_id' => $article->id,
        ]);

    ValidationVote::factory()
        ->for($run, 'validationRun')
        ->factual()
        ->create([
            'vote' => ValidationDecision::NeedsSourceRefresh,
        ]);

    expect(ValidationRun::whereBelongsTo($article, 'article')->sole()->is($run))->toBeTrue()
        ->and(ValidationRun::whereBelongsTo($user)->sole()->is($run))->toBeTrue()
        ->and(ValidationRun::whereBelongsTo($business)->sole()->is($run))->toBeTrue()
        ->and($article->validationRuns()->sole()->is($run))->toBeTrue()
        ->and($user->validationRuns()->sole()->is($run))->toBeTrue()
        ->and($business->validationRuns()->sole()->is($run))->toBeTrue()
        ->and($run->votes()->count())->toBe(1);
});

test('validation runs cast status decision context audit fields and timestamps', function () {
    $run = ValidationRun::factory()
        ->completed(ValidationDecision::NeedsProfessionalReview)
        ->create([
            'normalized_request' => [
                'topic' => 'franchise tax',
                'article_version' => 3,
            ],
            'context_snapshot' => [
                'business' => [
                    'state' => 'TX',
                    'legal_structure' => 'llc',
                ],
            ],
            'flags' => ['stale_sources'],
            'concerns' => ['Source freshness must be reviewed.'],
            'metadata' => ['pipeline' => 'v1'],
            'confidence' => 91,
        ])
        ->refresh();

    expect($run->status)->toBe(ValidationRunStatus::Completed)
        ->and($run->aggregate_decision)->toBe(ValidationDecision::NeedsProfessionalReview)
        ->and($run->final_model_role)->toBe(TextGenerationRole::FinalJudge)
        ->and($run->normalized_request)->toHaveKey('topic', 'franchise tax')
        ->and($run->context_snapshot['business'])->toHaveKey('state', 'TX')
        ->and($run->flags)->toBe(['stale_sources'])
        ->and($run->concerns)->toBe(['Source freshness must be reviewed.'])
        ->and($run->raw_response)->toHaveKey('decision', ValidationDecision::NeedsProfessionalReview->value)
        ->and($run->metadata)->toHaveKey('pipeline', 'v1')
        ->and($run->confidence)->toBe(91)
        ->and($run->started_at)->toBeInstanceOf(CarbonInterface::class)
        ->and($run->completed_at)->toBeInstanceOf(CarbonInterface::class);
});

test('validation votes cast model role vote response fields and response timestamp', function () {
    $vote = ValidationVote::factory()
        ->contradiction()
        ->create([
            'vote' => ValidationDecision::ConflictingSources,
            'flags' => ['conflict_detected'],
            'concerns' => ['Two sources disagree on the filing threshold.'],
            'raw_response' => ['evidence' => ['source_a', 'source_b']],
            'metadata' => ['tokens' => 842],
        ])
        ->refresh();

    expect($vote->model_role)->toBe(TextGenerationRole::ValidatorContradiction)
        ->and($vote->vote)->toBe(ValidationDecision::ConflictingSources)
        ->and($vote->flags)->toBe(['conflict_detected'])
        ->and($vote->concerns)->toBe(['Two sources disagree on the filing threshold.'])
        ->and($vote->raw_response)->toHaveKey('evidence')
        ->and($vote->metadata)->toHaveKey('tokens', 842)
        ->and($vote->responded_at)->toBeInstanceOf(CarbonInterface::class)
        ->and($vote->validationRun)->toBeInstanceOf(ValidationRun::class);
});

test('validation factories expose common run and vote states', function () {
    $pending = ValidationRun::factory()->create();
    $running = ValidationRun::factory()->running()->create();
    $failed = ValidationRun::factory()->failed()->create();
    $finalJudgeVote = ValidationVote::factory()->finalJudge()->create();
    $userFitVote = ValidationVote::factory()->userFit()->create();

    expect($pending->status)->toBe(ValidationRunStatus::Pending)
        ->and($running->status)->toBe(ValidationRunStatus::Running)
        ->and($running->started_at)->not->toBeNull()
        ->and($failed->status)->toBe(ValidationRunStatus::Failed)
        ->and($failed->aggregate_decision)->toBe(ValidationDecision::AdminReviewRequired)
        ->and($finalJudgeVote->model_role)->toBe(TextGenerationRole::FinalJudge)
        ->and($finalJudgeVote->vote)->toBe(ValidationDecision::ApprovedCurrent)
        ->and($userFitVote->model_role)->toBe(TextGenerationRole::ValidatorUserFit);
});
