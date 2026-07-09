<?php

use App\Ai\Text\TextRoleManager;
use App\Enums\RiskLevel;
use App\Enums\TextGenerationRole;
use App\Enums\ValidationDecision;
use App\Enums\ValidationRunStatus;
use App\Models\Business;
use App\Models\KnowledgeArticle;
use App\Models\KnowledgeSource;
use App\Models\ValidationVote;
use App\Services\ComplianceValidation\ValidationPipeline;

test('validation pipeline approves current sourced guidance and stores all votes', function () {
    $business = Business::factory()->create([
        'state' => 'TX',
        'industry' => 'Bookkeeping',
    ]);
    $article = sourcedArticle([
        'body_markdown' => compliantBody('Keep separate records for business income and expenses.'),
        'risk_level' => RiskLevel::Low,
    ]);

    $fake = TextRoleManager::fake([
        TextGenerationRole::ValidatorFactual->value => validationJson(ValidationDecision::ApprovedCurrent, 91),
        TextGenerationRole::ValidatorContradiction->value => validationJson(ValidationDecision::ApprovedCurrent, 88),
        TextGenerationRole::ValidatorUserFit->value => validationJson(ValidationDecision::ApprovedCurrent, 86),
        TextGenerationRole::FinalJudge->value => validationJson(ValidationDecision::ApprovedCurrent, 90, rationale: 'Sources and profile fit are acceptable.'),
    ])->preventStrayPrompts();

    $result = app(ValidationPipeline::class)->validate($article, business: $business, question: 'Can I rely on this?');

    expect($result->status)->toBe(ValidationRunStatus::Completed)
        ->and($result->decision)->toBe(ValidationDecision::ApprovedCurrent)
        ->and($result->run->aggregate_decision)->toBe(ValidationDecision::ApprovedCurrent)
        ->and($result->run->business_id)->toBe($business->id)
        ->and($result->run->confidence)->toBe(89)
        ->and($result->run->votes)->toHaveCount(4)
        ->and($result->run->votes->pluck('model_role')->all())->toContain(TextGenerationRole::FinalJudge)
        ->and($result->run->normalized_request['article'])->toHaveKey('slug', $article->slug)
        ->and($result->run->context_snapshot['business'])->toHaveKey('state', 'TX');

    $fake->assertGenerated(TextGenerationRole::ValidatorFactual)
        ->assertGenerated(TextGenerationRole::ValidatorContradiction)
        ->assertGenerated(TextGenerationRole::ValidatorUserFit)
        ->assertGenerated(TextGenerationRole::FinalJudge);
});

test('validation pipeline returns caveats when reviewers or guardrails require caveats', function () {
    $article = sourcedArticle([
        'body_markdown' => 'Keep business records organized before tax season.',
        'risk_level' => RiskLevel::Medium,
    ]);

    TextRoleManager::fake([
        TextGenerationRole::ValidatorFactual->value => validationJson(ValidationDecision::ApprovedCurrent),
        TextGenerationRole::ValidatorContradiction->value => validationJson(ValidationDecision::ApprovedCurrent),
        TextGenerationRole::ValidatorUserFit->value => validationJson(ValidationDecision::ApprovedWithCaveats, flags: ['needs_context']),
        TextGenerationRole::FinalJudge->value => validationJson(ValidationDecision::ApprovedCurrent),
    ])->preventStrayPrompts();

    $result = app(ValidationPipeline::class)->validate($article);

    expect($result->decision)->toBe(ValidationDecision::ApprovedWithCaveats)
        ->and($result->run->flags)->toContain('missing_disclaimer')
        ->and($result->run->flags)->toContain('needs_context');
});

test('validation pipeline escalates conflicting source votes', function () {
    $article = sourcedArticle([
        'body_markdown' => compliantBody('Review the agency sources before filing.'),
        'risk_level' => RiskLevel::Medium,
    ]);

    TextRoleManager::fake([
        TextGenerationRole::ValidatorFactual->value => validationJson(ValidationDecision::ApprovedCurrent),
        TextGenerationRole::ValidatorContradiction->value => validationJson(ValidationDecision::ConflictingSources, flags: ['conflict_detected']),
        TextGenerationRole::ValidatorUserFit->value => validationJson(ValidationDecision::ApprovedCurrent),
        TextGenerationRole::FinalJudge->value => validationJson(ValidationDecision::ApprovedWithCaveats),
    ])->preventStrayPrompts();

    $result = app(ValidationPipeline::class)->validate($article);

    expect($result->decision)->toBe(ValidationDecision::ConflictingSources)
        ->and($result->run->aggregate_decision)->toBe(ValidationDecision::ConflictingSources)
        ->and($result->run->votes()->where('vote', ValidationDecision::ConflictingSources->value)->count())->toBe(1);
});

test('high-risk stale content cannot be silently approved', function () {
    $article = sourcedArticle([
        'body_markdown' => compliantBody('Review current Texas franchise tax guidance before filing.'),
        'risk_level' => RiskLevel::High,
        'last_verified_at' => now()->subMonths(6),
        'next_review_at' => now()->subDay(),
    ]);

    TextRoleManager::fake(approvedResponses())->preventStrayPrompts();

    $result = app(ValidationPipeline::class)->validate($article);

    expect($result->decision)->toBe(ValidationDecision::NeedsSourceRefresh)
        ->and($result->run->flags)->toContain('stale_sources')
        ->and($result->run->flags)->toContain('high_risk_not_fresh');
});

test('guardrails catch unsupported threshold and deadline language', function () {
    $article = sourcedArticle([
        'body_markdown' => compliantBody('The no-tax-due threshold is $2.47 million and the report is due May 15.'),
        'source_summary' => 'Agency overview.',
        'risk_level' => RiskLevel::Low,
    ]);

    TextRoleManager::fake(approvedResponses())->preventStrayPrompts();

    $result = app(ValidationPipeline::class)->validate($article);

    expect($result->decision)->toBe(ValidationDecision::NeedsSourceRefresh)
        ->and($result->run->flags)->toContain('unsupported_numeric_claim')
        ->and($result->run->status)->toBe(ValidationRunStatus::Completed);
});

test('pipeline failures are stored as admin review required', function () {
    $article = sourcedArticle([
        'body_markdown' => compliantBody('Keep routine bookkeeping records.'),
        'risk_level' => RiskLevel::Low,
    ]);

    TextRoleManager::fake([
        TextGenerationRole::ValidatorFactual->value => validationJson(ValidationDecision::ApprovedCurrent),
    ])->preventStrayPrompts();

    $result = app(ValidationPipeline::class)->validate($article);

    expect($result->status)->toBe(ValidationRunStatus::Failed)
        ->and($result->decision)->toBe(ValidationDecision::AdminReviewRequired)
        ->and($result->run->aggregate_decision)->toBe(ValidationDecision::AdminReviewRequired)
        ->and($result->run->flags)->toContain('pipeline_error')
        ->and(ValidationVote::whereBelongsTo($result->run, 'validationRun')->count())->toBe(1);
});

function sourcedArticle(array $attributes = []): KnowledgeArticle
{
    $article = KnowledgeArticle::factory()->create([
        'body_markdown' => compliantBody('Use official sources to verify compliance steps.'),
        'source_summary' => 'Reviewed official agency source.',
        'risk_level' => RiskLevel::Low,
        'last_verified_at' => now()->subDay(),
        'next_review_at' => now()->addMonth(),
        ...$attributes,
    ]);

    KnowledgeSource::factory()->for($article, 'article')->create([
        'source_name' => 'Texas Comptroller',
        'notes' => $attributes['source_notes'] ?? 'Reviewed official agency source.',
    ]);

    return $article->refresh();
}

function compliantBody(string $body): string
{
    return $body.' This is general small business guidance, not legal, tax, payroll, or accounting advice. Verify requirements with the appropriate government agency and review decisions with a qualified professional before filing or relying on them.';
}

function approvedResponses(): array
{
    return [
        TextGenerationRole::ValidatorFactual->value => validationJson(ValidationDecision::ApprovedCurrent),
        TextGenerationRole::ValidatorContradiction->value => validationJson(ValidationDecision::ApprovedCurrent),
        TextGenerationRole::ValidatorUserFit->value => validationJson(ValidationDecision::ApprovedCurrent),
        TextGenerationRole::FinalJudge->value => validationJson(ValidationDecision::ApprovedCurrent),
    ];
}

function validationJson(
    ValidationDecision $decision,
    int $confidence = 90,
    array $flags = [],
    array $concerns = [],
    string $rationale = 'Validation completed.',
): string {
    return json_encode([
        'decision' => $decision->value,
        'confidence' => $confidence,
        'flags' => $flags,
        'concerns' => $concerns,
        'rationale' => $rationale,
    ], JSON_THROW_ON_ERROR);
}
