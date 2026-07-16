<?php

use App\Ai\Text\TextRoleManager;
use App\Enums\ArticleCategory;
use App\Enums\RiskLevel;
use App\Enums\TextGenerationRole;
use App\Exceptions\PaidAiUnavailable;
use App\Livewire\Advertising\Index as AdvertisingIndex;
use App\Livewire\Advisor\Ask;
use App\Livewire\Branding\Index as BrandingIndex;
use App\Models\AgentConversationMessage;
use App\Models\BrandKit;
use App\Models\Business;
use App\Models\KnowledgeArticle;
use App\Models\KnowledgeSource;
use App\Models\Photo;
use App\Models\PhotoGenerationBatch;
use App\Models\Project;
use App\Support\Ai\AiFailurePresentation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

const RAW_AI_FAILURE = 'raw-provider-secret-sk-live-test-response-body';

test('unexpected AI failures emit only an allowlisted server log event', function () {
    Log::spy();

    AiFailurePresentation::fromException(new RuntimeException(RAW_AI_FAILURE));

    Log::shouldHaveReceived('warning')
        ->once()
        ->with(
            'Customer-facing AI action failed.',
            Mockery::on(fn (array $context): bool => $context === [
                'exception_class' => RuntimeException::class,
            ] && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), RAW_AI_FAILURE)),
        );
});

test('advisor reports an unexpected provider failure as sanitized recoverable copy', function () {
    $business = Business::factory()->create();
    $article = KnowledgeArticle::factory()->create([
        'category' => ArticleCategory::Banking,
        'risk_level' => RiskLevel::Low,
        'body_markdown' => 'Keep business and personal banking separate.',
    ]);
    KnowledgeSource::factory()->for($article, 'article')->create();

    TextRoleManager::fake([
        TextGenerationRole::AdvisorAnswer->value => new RuntimeException(RAW_AI_FAILURE),
    ])->preventStrayPrompts();

    $rateLimitKey = 'advisor-answer:'.$business->user_id;

    Livewire::actingAs($business->user)
        ->test(Ask::class)
        ->set('question', 'Should I use a separate bank account?')
        ->call('ask')
        ->assertSet('aiError', __('AI could not start this request. Retry. If the problem continues, contact support.'))
        ->assertSee(__('Advisor could not answer'))
        ->assertSeeHtml('role="alert"')
        ->assertSeeHtml('aria-live="assertive"')
        ->assertSeeHtml('aria-atomic="true"')
        ->assertSeeHtml('tabindex="-1"')
        ->assertDontSee(RAW_AI_FAILURE);

    expect(AgentConversationMessage::count())->toBe(0)
        ->and(RateLimiter::attempts($rateLimitKey))->toBe(0);
});

test('branding generation reports an unexpected provider failure without exposing details', function () {
    $business = Business::factory()->create();
    TextRoleManager::fake([
        TextGenerationRole::BrandCopy->value => new RuntimeException(RAW_AI_FAILURE),
    ])->preventStrayPrompts();

    Livewire::actingAs($business->user)
        ->test(BrandingIndex::class)
        ->call('generate')
        ->assertSet('generationError', __('AI could not start this request. Retry. If the problem continues, contact support.'))
        ->assertSee(__('Generation failed'))
        ->assertSeeHtml('role="alert"')
        ->assertSeeHtml('aria-live="assertive"')
        ->assertSeeHtml('aria-atomic="true"')
        ->assertSeeHtml('tabindex="-1"')
        ->assertDontSee(RAW_AI_FAILURE);
});

test('branding section regeneration preserves the kit and sanitizes provider details', function () {
    $business = Business::factory()->create();
    $kit = BrandKit::factory()->forBusiness($business)->create(['name_ideas' => ['Original name']]);
    TextRoleManager::fake([
        TextGenerationRole::BrandCopy->value => new RuntimeException(RAW_AI_FAILURE),
    ])->preventStrayPrompts();

    Livewire::actingAs($business->user)
        ->test(BrandingIndex::class)
        ->call('regenerateSection', 'name_ideas')
        ->assertSee(__('Generation failed'))
        ->assertDontSee(RAW_AI_FAILURE);

    expect($kit->fresh()->name_ideas)->toBe(['Original name']);
});

test('advertising generation reports an unexpected provider failure without exposing details', function () {
    $business = Business::factory()->create();
    TextRoleManager::fake([
        TextGenerationRole::AdCopy->value => new RuntimeException(RAW_AI_FAILURE),
    ])->preventStrayPrompts();

    Livewire::actingAs($business->user)
        ->test(AdvertisingIndex::class)
        ->call('generate')
        ->assertSet('generationError', __('AI could not start this request. Retry. If the problem continues, contact support.'))
        ->assertSee(__('Generation failed'))
        ->assertSeeHtml('role="alert"')
        ->assertSeeHtml('aria-live="assertive"')
        ->assertSeeHtml('aria-atomic="true"')
        ->assertSeeHtml('tabindex="-1"')
        ->assertDontSee(RAW_AI_FAILURE);
});

test('account policy failures provide a settings recovery action without exposing domain details', function () {
    $business = Business::factory()->create();
    TextRoleManager::fake([
        TextGenerationRole::BrandCopy->value => PaidAiUnavailable::routeUnavailable(),
    ])->preventStrayPrompts();

    Livewire::actingAs($business->user)
        ->test(BrandingIndex::class)
        ->call('generate')
        ->assertSet('generationErrorShowsSettings', true)
        ->assertSee(__('Review AI settings'))
        ->assertDontSee('No permitted AI provider');
});

test('photo generation initiation catches and sanitizes application failures', function () {
    $owner = Business::factory()->create()->user;
    $project = Project::factory()->for($owner, 'owner')->create();
    $photo = Photo::factory()->for($project)->for($owner, 'user')->create();
    PhotoGenerationBatch::creating(fn () => throw new RuntimeException(RAW_AI_FAILURE));

    Livewire::actingAs($owner)
        ->test('projects.show', ['project' => $project])
        ->call('toggleSelection', $photo->id)
        ->call('generate')
        ->assertSet('aiError', __('AI could not start this request. Retry. If the problem continues, contact support.'))
        ->assertSee(__('Generation could not start'))
        ->assertDontSee(RAW_AI_FAILURE);
});

test('photo generation clears a previous failure before a successful retry', function () {
    $owner = Business::factory()->create()->user;
    $project = Project::factory()->for($owner, 'owner')->create();
    $photo = Photo::factory()->for($project)->for($owner, 'user')->create();

    $component = Livewire::actingAs($owner)
        ->test('projects.show', ['project' => $project])
        ->set('aiError', 'Previous failure')
        ->set('aiErrorShowsSettings', true)
        ->call('toggleSelection', $photo->id)
        ->call('generate')
        ->assertSet('aiError', null)
        ->assertSet('aiErrorShowsSettings', false)
        ->assertDontSee('Previous failure');

    expect(PhotoGenerationBatch::count())->toBe(1);
});

test('photo analysis initiation catches and sanitizes upload workflow failures', function () {
    Storage::fake('s3');
    config(['photostudio.disk' => 's3']);

    $owner = Business::factory()->create()->user;
    $project = Project::factory()->for($owner, 'owner')->create();
    Photo::creating(fn () => throw new RuntimeException(RAW_AI_FAILURE));

    Livewire::actingAs($owner)
        ->test('projects.show', ['project' => $project])
        ->set('uploads', [UploadedFile::fake()->image('analysis-input.jpg')])
        ->call('saveUploads')
        ->assertHasErrors('uploads')
        ->assertSee(__('The upload could not be saved. Please try again.'))
        ->assertDontSee(RAW_AI_FAILURE);
});
