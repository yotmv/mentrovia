<?php

use App\Ai\Text\TextGenerationRequest;
use App\Ai\Text\TextRoleManager;
use App\Enums\ArticleCategory;
use App\Enums\ArticleStatus;
use App\Enums\RiskLevel;
use App\Enums\SourceType;
use App\Enums\TextGenerationRole;
use App\Enums\ValidationDecision;
use App\Livewire\Advisor\Ask;
use App\Models\AgentConversation;
use App\Models\AgentConversationMessage;
use App\Models\Business;
use App\Models\KnowledgeArticle;
use App\Models\KnowledgeSource;
use App\Models\User;
use App\Models\ValidationRun;
use Livewire\Livewire;

test('guests are redirected from advisor', function () {
    $this->get(route('advisor'))
        ->assertRedirect(route('login'));
});

test('users without a business profile are prompted to complete intake', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('advisor'))
        ->assertOk()
        ->assertSee('No company profile yet')
        ->assertSee('Tell us about your business');
});

test('low-risk advisor answer uses business context and skips validation', function () {
    $user = User::factory()->create();
    $business = Business::factory()->for($user)->create([
        'name' => 'Austin Ledger Co.',
        'industry' => 'Bookkeeping',
    ]);
    advisorArticle([
        'title' => 'Business banking separation basics',
        'slug' => 'business-banking-separation-basics',
        'category' => ArticleCategory::Banking,
        'risk_level' => RiskLevel::Low,
        'body_markdown' => advisorCompliantBody('Open a separate business bank account and keep income and expenses separate.'),
    ]);

    $fake = TextRoleManager::fake([
        TextGenerationRole::AdvisorAnswer->value => advisorAnswerJson('Open a separate business bank account before mixing customer payments with personal spending.'),
    ])->preventStrayPrompts();

    $this->actingAs($user);

    Livewire::test(Ask::class)
        ->set('question', 'Should I open a separate business bank account?')
        ->call('ask')
        ->assertSee('Open a separate business bank account');

    expect(ValidationRun::count())->toBe(0)
        ->and(AgentConversation::count())->toBe(1)
        ->and(AgentConversationMessage::count())->toBe(2);

    $fake->assertGenerated(function (TextGenerationRequest $request) use ($business): bool {
        return $request->role === TextGenerationRole::AdvisorAnswer
            && $request->context['business']['id'] === $business->id
            && $request->context['knowledge'][0]['title'] === 'Business banking separation basics';
    });
});

test('high-risk advisor answer runs validation and shows professional review language', function () {
    $user = User::factory()->create();
    $business = Business::factory()->withEmployees()->for($user)->create();
    advisorArticle([
        'title' => 'First employee payroll checklist',
        'slug' => 'first-employee-payroll-checklist',
        'category' => ArticleCategory::Payroll,
        'risk_level' => RiskLevel::High,
        'body_markdown' => advisorCompliantBody('Register payroll accounts before the first employee starts.'),
    ]);

    TextRoleManager::fake([
        ...advisorApprovedValidationResponses(),
        TextGenerationRole::AdvisorAnswer->value => advisorAnswerJson(
            'Set up payroll before the employee starts and review the checklist with a payroll professional.',
            professionalFlags: ['Payroll setup should be reviewed by a qualified professional.'],
        ),
    ])->preventStrayPrompts();

    $this->actingAs($user);

    Livewire::test(Ask::class)
        ->set('question', 'What should I do before hiring my first employee for payroll?')
        ->call('ask')
        ->assertSee('Set up payroll')
        ->assertSee('qualified professional');

    expect(ValidationRun::count())->toBe(1)
        ->and(ValidationRun::firstOrFail()->business_id)->toBe($business->id);
});

test('stale advisor source runs validation and surfaces source refresh decision', function () {
    $user = User::factory()->create();
    Business::factory()->for($user)->create();
    advisorArticle([
        'title' => 'Texas sales tax permit basics',
        'slug' => 'texas-sales-tax-permit-basics',
        'category' => ArticleCategory::SalesTax,
        'risk_level' => RiskLevel::Medium,
        'body_markdown' => advisorCompliantBody('Review official sales tax permit guidance before collecting taxable sales.'),
        'last_verified_at' => now()->subYear(),
        'next_review_at' => now()->subDay(),
        'status' => ArticleStatus::NeedsReview,
    ]);

    TextRoleManager::fake([
        ...advisorApprovedValidationResponses(),
        TextGenerationRole::AdvisorAnswer->value => advisorAnswerJson('Check the permit guidance, but treat the source as stale until it is refreshed.'),
    ])->preventStrayPrompts();

    $this->actingAs($user);

    Livewire::test(Ask::class)
        ->set('question', 'Do I need a Texas sales tax permit?')
        ->call('ask')
        ->assertSee('source as stale')
        ->assertSee('Needs source refresh');

    expect(ValidationRun::firstOrFail()->aggregate_decision)->toBe(ValidationDecision::NeedsSourceRefresh);
});

test('advisor session history persists across questions', function () {
    $user = User::factory()->create();
    Business::factory()->for($user)->create();
    advisorArticle([
        'title' => 'Bookkeeping setup basics',
        'slug' => 'bookkeeping-setup-basics',
        'category' => ArticleCategory::Accounting,
        'risk_level' => RiskLevel::Low,
        'body_markdown' => advisorCompliantBody('Use bookkeeping software and reconcile accounts monthly.'),
    ]);

    TextRoleManager::fake(fn (TextGenerationRequest $request): string => advisorAnswerJson(
        'Answer for '.$request->context['question'],
    ))->preventStrayPrompts();

    $this->actingAs($user);

    Livewire::test(Ask::class)
        ->set('question', 'How should I start bookkeeping?')
        ->call('ask')
        ->set('question', 'How often should I reconcile bookkeeping?')
        ->call('ask')
        ->assertSee('How should I start bookkeeping?')
        ->assertSee('How often should I reconcile bookkeeping?');

    expect(AgentConversation::count())->toBe(1)
        ->and(AgentConversationMessage::query()->where('agent', 'advisor')->count())->toBe(4);
});

test('advisor message history is scoped to the authenticated user', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    Business::factory()->for($user)->create();

    $otherConversation = AgentConversation::create([
        'user_id' => $otherUser->id,
        'title' => 'Advisor Q&A',
    ]);

    AgentConversationMessage::create([
        'conversation_id' => $otherConversation->id,
        'user_id' => $otherUser->id,
        'agent' => 'advisor',
        'role' => 'assistant',
        'content' => 'Private advisor answer for another business.',
        'attachments' => [],
        'tool_calls' => [],
        'tool_results' => [],
        'usage' => [],
        'meta' => [],
    ]);

    $this->actingAs($user);

    Livewire::test(Ask::class)
        ->set('conversationId', $otherConversation->id)
        ->assertDontSee('Private advisor answer for another business.');
});

function advisorArticle(array $attributes = []): KnowledgeArticle
{
    $article = KnowledgeArticle::factory()->create([
        'title' => $attributes['title'] ?? 'Texas compliance basics',
        'slug' => $attributes['slug'] ?? 'texas-compliance-basics',
        'jurisdiction' => 'TX',
        'category' => $attributes['category'] ?? ArticleCategory::Formation,
        'body_markdown' => $attributes['body_markdown'] ?? advisorCompliantBody('Review official Texas guidance before acting.'),
        'source_summary' => 'Official agency guidance with current source metadata.',
        'risk_level' => $attributes['risk_level'] ?? RiskLevel::Low,
        'last_verified_at' => $attributes['last_verified_at'] ?? now(),
        'next_review_at' => $attributes['next_review_at'] ?? now()->addMonths(3),
        'status' => $attributes['status'] ?? ArticleStatus::Published,
    ]);

    KnowledgeSource::factory()->for($article, 'article')->create([
        'source_name' => 'Texas Comptroller',
        'source_url' => 'https://comptroller.texas.gov/taxes/',
        'source_type' => SourceType::StateAgency,
        'retrieved_at' => now(),
        'notes' => 'Retrieved current official guidance.',
    ]);

    return $article;
}

function advisorCompliantBody(string $guidance): string
{
    return $guidance."\n\nThis is educational information, not legal, tax, payroll, or accounting advice. Review your situation with a qualified professional.";
}

/**
 * @return array<string, string>
 */
function advisorApprovedValidationResponses(): array
{
    return [
        TextGenerationRole::ValidatorFactual->value => advisorValidationJson(ValidationDecision::ApprovedCurrent),
        TextGenerationRole::ValidatorContradiction->value => advisorValidationJson(ValidationDecision::ApprovedCurrent),
        TextGenerationRole::ValidatorUserFit->value => advisorValidationJson(ValidationDecision::ApprovedCurrent),
        TextGenerationRole::FinalJudge->value => advisorValidationJson(ValidationDecision::ApprovedCurrent),
    ];
}

/**
 * @param  array<int, string>  $professionalFlags
 */
function advisorAnswerJson(string $directAnswer, array $professionalFlags = []): string
{
    return json_encode([
        'direct_answer' => $directAnswer,
        'checklist' => ['Review the linked source.', 'Save notes in your task list.'],
        'caveats' => ['Confirm source details before acting.'],
        'confidence' => 82,
        'professional_review_flags' => $professionalFlags,
    ], JSON_THROW_ON_ERROR);
}

function advisorValidationJson(ValidationDecision $decision): string
{
    return json_encode([
        'decision' => $decision->value,
        'confidence' => 90,
        'flags' => [],
        'concerns' => [],
        'rationale' => 'Validation completed.',
    ], JSON_THROW_ON_ERROR);
}
