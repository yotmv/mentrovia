<?php

use App\Ai\Text\TextGenerationRequest;
use App\Ai\Text\TextRoleManager;
use App\Enums\AccountRole;
use App\Enums\ArticleCategory;
use App\Enums\BusinessProfileSection;
use App\Enums\BusinessProfileVersionSource;
use App\Enums\ProfileFreshness;
use App\Enums\RiskLevel;
use App\Enums\TextGenerationRole;
use App\Livewire\Advisor\Ask;
use App\Livewire\Advisor\History;
use App\Models\Account;
use App\Models\AgentConversation;
use App\Models\AgentConversationMessage;
use App\Models\BrandKit;
use App\Models\Business;
use App\Models\BusinessProfile;
use App\Models\KnowledgeArticle;
use App\Models\KnowledgeSource;
use App\Models\User;
use App\Models\ValidationRun;
use App\Services\Advertising\AdvertisingKitGenerator;
use App\Services\Advisor\AdvisorAnswerService;
use App\Services\Branding\BrandKitGenerator;
use App\Services\BusinessProfileContext;
use App\Services\BusinessProfileVersionService;
use App\Services\ProfileFreshnessService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('brand kits track full and mixed section profile provenance', function () {
    TextRoleManager::fake([
        TextGenerationRole::BrandCopy->value => json_encode(aiProfileBrandPayload()),
    ])->preventStrayPrompts();
    $business = Business::factory()->create(['city' => 'Austin']);
    $generator = app(BrandKitGenerator::class);
    $freshness = app(ProfileFreshnessService::class);

    $kit = $generator->generate($business->user, $business);

    expect($kit->profile_revision)->toBe(1)
        ->and($kit->profile_fingerprint)->toBe($business->refresh()->profile_fingerprint)
        ->and($kit->section_profile_revisions)->toHaveCount(count(BrandKitGenerator::Sections))
        ->and(array_values(array_unique($kit->section_profile_revisions)))->toBe([1])
        ->and($kit->marketing_context_fingerprints)->toHaveCount(count(BrandKitGenerator::Sections))
        ->and($freshness->brand($kit, $business->fresh(['profileAnswers'])))->toBe(ProfileFreshness::Current);

    recordAiProfileMutation($business, ['city' => 'Dallas'], ['city'], [BusinessProfileSection::LocationStructure]);
    $business = $business->fresh(['profileAnswers']);

    expect($freshness->brandSection($kit, $business, 'positioning'))->toBe(ProfileFreshness::Stale);

    $kit = $generator->regenerateSection($kit, 'positioning', $business->user);

    expect($kit->section_profile_revisions['positioning'])->toBe(2)
        ->and($freshness->brandSection($kit, $business, 'positioning'))->toBe(ProfileFreshness::Current)
        ->and($freshness->brandSection($kit, $business, 'name_ideas'))->toBe(ProfileFreshness::Stale)
        ->and($freshness->brand($kit, $business))->toBe(ProfileFreshness::Stale);
});

test('marketing provenance ignores non-marketing answers while advisor provenance does not', function () {
    TextRoleManager::fake([
        TextGenerationRole::BrandCopy->value => json_encode(aiProfileBrandPayload()),
    ])->preventStrayPrompts();
    $business = Business::factory()->create();
    $kit = app(BrandKitGenerator::class)->generate($business->user, $business);
    $context = app(BusinessProfileContext::class);
    $freshness = app(ProfileFreshnessService::class);
    $advisorBefore = $context->advisorFingerprint($business->fresh(['profileAnswers']));

    recordAiProfileAnswerMutation($business, 'banking_setup.tax-reserve', 'done');
    $business = $business->fresh(['profileAnswers']);

    expect($freshness->brand($kit, $business))->toBe(ProfileFreshness::Current)
        ->and($context->advisorFingerprint($business))->not->toBe($advisorBefore);
});

test('advertising provenance becomes stale when profile or brand inputs change', function () {
    TextRoleManager::fake([
        TextGenerationRole::AdCopy->value => json_encode(aiProfileAdvertisingPayload()),
    ])->preventStrayPrompts();
    $business = Business::factory()->create(['industry' => 'Bookkeeping']);
    $brand = BrandKit::factory()->forBusiness($business)->create();
    $generator = app(AdvertisingKitGenerator::class);
    $freshness = app(ProfileFreshnessService::class);

    $profileBound = $generator->generate($business->user, $business);

    expect($freshness->advertising($profileBound, $business->fresh(['profileAnswers']), $brand))->toBe(ProfileFreshness::Current);

    recordAiProfileMutation($business, ['industry' => 'Landscaping'], ['industry'], [BusinessProfileSection::CompanyBasics]);

    expect($freshness->advertising($profileBound, $business->fresh(['profileAnswers']), $brand))->toBe(ProfileFreshness::Stale);

    $brandBound = $generator->generate($business->user, $business->fresh(['profileAnswers']));
    $brand->update(['positioning' => 'A newly revised position.']);

    expect($freshness->advertising($brandBound, $business->fresh(['profileAnswers']), $brand->refresh()))->toBe(ProfileFreshness::Stale);
});

test('a profile change during brand generation persists one old-bound stale result', function () {
    $business = Business::factory()->create(['city' => 'Austin']);
    $changed = false;
    $fake = TextRoleManager::fake(function (TextGenerationRequest $request) use ($business, &$changed): string {
        if (! $changed) {
            $changed = true;
            recordAiProfileMutation($business, ['city' => 'Dallas'], ['city'], [BusinessProfileSection::LocationStructure]);
        }

        return json_encode(aiProfileBrandPayload());
    })->preventStrayPrompts();

    $kit = app(BrandKitGenerator::class)->generate($business->user, $business);

    expect($fake->requests())->toHaveCount(1)
        ->and($fake->requests()[0]->context['business']['city'])->toBe('Austin')
        ->and($kit->profile_revision)->toBe(1)
        ->and($business->refresh()->profile_revision)->toBe(2)
        ->and(app(ProfileFreshnessService::class)->brand($kit, $business->fresh(['profileAnswers'])))->toBe(ProfileFreshness::Stale);
});

test('advisor validation and answer calls share one pinned profile when it changes mid-run', function () {
    $business = Business::factory()->create([
        'city' => 'Austin',
        'address' => '9127 SECRET PROFILE LANE',
        'annual_revenue_range' => 'over_1m',
    ]);
    BusinessProfile::factory()->for($business)->create([
        'question_key' => 'private.advisor.fact',
        'answer_value' => 'SECRET PROFILE ANSWER',
        'confidence' => 'user_confirmed',
    ]);
    $article = aiProfileKnowledgeArticle(RiskLevel::High);
    $changed = false;
    $fake = TextRoleManager::fake(function (TextGenerationRequest $request) use ($business, &$changed): string {
        if (! $changed) {
            $changed = true;
            recordAiProfileMutation($business, ['city' => 'Dallas'], ['city'], [BusinessProfileSection::LocationStructure]);
        }

        if ($request->role === TextGenerationRole::AdvisorAnswer) {
            return aiProfileAdvisorAnswer();
        }

        return json_encode([
            'decision' => 'approved_current',
            'confidence' => 90,
            'flags' => [],
            'concerns' => [],
            'rationale' => 'The source and captured profile support the answer.',
        ]);
    })->preventStrayPrompts();

    $message = app(AdvisorAnswerService::class)->answer(
        $business->user,
        $business,
        'What should I know about the payroll checklist?',
    );
    $requests = $fake->requests();
    $advisorRequest = collect($requests)->first(fn (TextGenerationRequest $request): bool => $request->role === TextGenerationRole::AdvisorAnswer);
    $validationRequests = collect($requests)->reject(fn (TextGenerationRequest $request): bool => $request->role === TextGenerationRole::AdvisorAnswer);
    $pinned = $advisorRequest->context['business'];
    $run = ValidationRun::query()->sole();
    $rawPersistedContext = DB::table('validation_runs')->where('id', $run->id)->value('context_snapshot');

    expect($article->exists)->toBeTrue()
        ->and($requests)->toHaveCount(5)
        ->and($pinned['business']['city'])->toBe('Austin')
        ->and($validationRequests->every(fn (TextGenerationRequest $request): bool => $request->context['context']['business_profile'] === $pinned))->toBeTrue()
        ->and($run->context_snapshot['profile_binding'])->toBe(data_get($message->meta, 'profile_context'))
        ->and($run->context_snapshot)->not->toHaveKeys(['business_profile', 'profile_answers'])
        ->and($rawPersistedContext)->not->toContain('9127 SECRET PROFILE LANE')
        ->and($rawPersistedContext)->not->toContain('SECRET PROFILE ANSWER')
        ->and($rawPersistedContext)->not->toContain('over_1m')
        ->and(data_get($message->meta, 'profile_context.revision'))->toBe(1)
        ->and($business->refresh()->profile_revision)->toBe(2)
        ->and(app(ProfileFreshnessService::class)->advisor($message, $business->fresh(['profileAnswers'])))->toBe(ProfileFreshness::Stale);
});

test('advisor freshness distinguishes current stale and legacy answers', function () {
    $business = Business::factory()->create(['city' => 'Austin']);
    $version = DB::transaction(function () use ($business) {
        $locked = Business::query()->lockForUpdate()->findOrFail($business->id);

        return app(BusinessProfileVersionService::class)->ensureBaselineLocked($locked);
    });
    $conversation = AgentConversation::create([
        'user_id' => $business->user_id,
        'account_id' => $business->account_id,
        'title' => 'Advisor Q&A',
    ]);
    $current = aiProfileAdvisorMessage($conversation, $business->user, 'Current answer', [
        'revision' => $version->revision,
        'fingerprint' => $version->fingerprint,
    ]);
    $legacy = aiProfileAdvisorMessage($conversation, $business->user, 'Legacy answer');
    $freshness = app(ProfileFreshnessService::class);

    expect($freshness->advisor($current, $business->fresh(['profileAnswers'])))->toBe(ProfileFreshness::Current)
        ->and($freshness->advisor($legacy, $business->fresh(['profileAnswers'])))->toBe(ProfileFreshness::Unknown);

    recordAiProfileMutation($business, ['city' => 'Dallas'], ['city'], [BusinessProfileSection::LocationStructure]);

    expect($freshness->advisor($current, $business->fresh(['profileAnswers'])))->toBe(ProfileFreshness::Stale);
});

test('ask again restores only questions from the active workspace', function () {
    $user = User::factory()->create();
    Business::factory()->for($user)->create();
    $otherUser = User::factory()->create();
    Business::factory()->for($otherUser)->create();
    $ownConversation = AgentConversation::create([
        'user_id' => $user->id,
        'account_id' => $user->current_account_id,
        'title' => 'Advisor Q&A',
    ]);
    $otherConversation = AgentConversation::create([
        'user_id' => $otherUser->id,
        'account_id' => $otherUser->current_account_id,
        'title' => 'Advisor Q&A',
    ]);
    $own = aiProfileAdvisorMessage($ownConversation, $user, 'Own answer', null, 'How should I start bookkeeping?');
    $other = aiProfileAdvisorMessage($otherConversation, $otherUser, 'Private answer', null, 'What is the private payroll question?');

    $this->actingAs($user);

    Livewire::test(Ask::class)
        ->call('askAgain', $other->id)
        ->assertSet('question', '')
        ->call('askAgain', '00000000-0000-0000-0000-000000000000')
        ->assertSet('question', '')
        ->call('askAgain', $own->id)
        ->assertSet('question', 'How should I start bookkeeping?');

    Livewire::test(History::class)
        ->call('askAgain', $other->id)
        ->assertNoRedirect();

    Livewire::test(History::class)
        ->call('askAgain', $own->id)
        ->assertRedirect(route('advisor'))
        ->assertSessionHas('advisor_repeat_message_id', $own->id);

    $secondAccount = Account::factory()->create();
    $secondAccount->members()->attach($user, ['role' => AccountRole::Member->value]);
    $user->forceFill(['current_account_id' => $secondAccount->id])->save();
    $this->actingAs($user->fresh());

    Livewire::test(Ask::class)
        ->assertSet('question', '');
});

/** @return array<string, mixed> */
function aiProfileBrandPayload(): array
{
    return [
        'name_ideas' => ['Mentrovia Studio'],
        'tagline_options' => ['Build the next clear step.'],
        'positioning' => 'Practical guidance for small businesses.',
        'tone_voice' => ['Clear and grounded.'],
        'color_palette' => [['name' => 'Moss', 'hex' => '#2F6B4F', 'usage' => 'Primary']],
        'font_notes' => ['Use a readable humanist sans.'],
        'image_prompts' => ['A grounded small business workspace.'],
        'brand_board_prompt' => 'A complete 3840 x 2160 brand board.',
        'social_bios' => [['platform' => 'instagram', 'bio' => 'Practical small business guidance.']],
    ];
}

/** @return array<string, mixed> */
function aiProfileAdvertisingPayload(): array
{
    return [
        'ad_angles' => ['Lead with one practical next step.'],
        'facebook_instagram_copy' => [],
        'google_ads' => [],
        'social_posts' => [],
        'flyer_copy' => null,
        'image_prompts' => [],
        'landing_page_outline' => [],
        'thirty_day_plan' => [],
    ];
}

function aiProfileAdvisorAnswer(): string
{
    return json_encode([
        'direct_answer' => 'Review the payroll checklist and confirm current agency requirements.',
        'checklist' => ['Review the official source.'],
        'caveats' => ['Confirm requirements with a qualified professional.'],
        'confidence' => 80,
        'professional_review_flags' => [],
        'follow_up_question' => '',
    ]);
}

function aiProfileKnowledgeArticle(RiskLevel $risk): KnowledgeArticle
{
    $article = KnowledgeArticle::factory()->create([
        'title' => 'Texas payroll checklist',
        'slug' => 'ai-profile-texas-payroll-checklist',
        'category' => ArticleCategory::Payroll,
        'risk_level' => $risk,
        'body_markdown' => 'Review payroll registration steps before the first payroll. This is general guidance, not legal, tax, payroll, or accounting advice. Verify requirements with the appropriate government agency and a qualified professional.',
        'source_summary' => 'Reviewed official agency source.',
    ]);
    KnowledgeSource::factory()->for($article, 'article')->create([
        'source_name' => 'Texas Workforce Commission',
        'notes' => 'Official payroll registration overview.',
    ]);

    return $article->refresh();
}

/**
 * @param  array<string, mixed>  $attributes
 * @param  list<string>  $fields
 * @param  list<BusinessProfileSection>  $sections
 */
function recordAiProfileMutation(Business $business, array $attributes, array $fields, array $sections): void
{
    DB::transaction(function () use ($business, $attributes, $fields, $sections): void {
        $locked = Business::query()->lockForUpdate()->findOrFail($business->id);
        $versions = app(BusinessProfileVersionService::class);
        $versions->ensureBaselineLocked($locked);
        $locked->update($attributes);
        $locked->unsetRelation('profileAnswers');
        $versions->recordLocked($locked, BusinessProfileVersionSource::Manual, $business->user, $fields, $sections);
    });
}

function recordAiProfileAnswerMutation(Business $business, string $questionKey, string $answer): void
{
    DB::transaction(function () use ($business, $questionKey, $answer): void {
        $locked = Business::query()->lockForUpdate()->findOrFail($business->id);
        $versions = app(BusinessProfileVersionService::class);
        $versions->ensureBaselineLocked($locked);
        BusinessProfile::query()->updateOrCreate(
            ['business_id' => $locked->id, 'question_key' => $questionKey],
            ['answer_value' => $answer, 'confidence' => 'user_confirmed'],
        );
        $locked->unsetRelation('profileAnswers');
        $versions->recordLocked(
            $locked,
            BusinessProfileVersionSource::Workflow,
            $business->user,
            ['profile_answers.'.$questionKey],
            ['banking_setup'],
        );
    });
}

function aiProfileAdvisorMessage(
    AgentConversation $conversation,
    User $user,
    string $content,
    ?array $profileContext = null,
    ?string $question = null,
): AgentConversationMessage {
    return AgentConversationMessage::create([
        'conversation_id' => $conversation->id,
        'user_id' => $user->id,
        'agent' => 'advisor',
        'role' => 'assistant',
        'content' => $content,
        'attachments' => [],
        'tool_calls' => [],
        'tool_results' => [],
        'usage' => [],
        'meta' => array_filter([
            'answer' => ['source_freshness' => []],
            'question' => $question,
            'profile_context' => $profileContext,
        ], fn (mixed $value): bool => $value !== null),
    ]);
}
