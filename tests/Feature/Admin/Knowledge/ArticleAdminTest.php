<?php

use App\Enums\ArticleCategory;
use App\Enums\ArticleStatus;
use App\Enums\RiskLevel;
use App\Enums\SourceType;
use App\Livewire\Admin\Knowledge\ArticleForm;
use App\Livewire\Admin\Knowledge\ArticleIndex;
use App\Models\KnowledgeArticle;
use App\Models\KnowledgeSource;
use App\Models\User;
use Database\Seeders\KnowledgeArticleSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(KnowledgeArticleSeeder::class);
});

test('guests are redirected from admin knowledge index', function () {
    $this->get(route('admin.knowledge.articles.index'))
        ->assertRedirect(route('login'));
});

test('non-admin users are forbidden from admin knowledge index', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('admin.knowledge.articles.index'))
        ->assertForbidden();
});

test('admin users can view the admin knowledge index', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->get(route('admin.knowledge.articles.index'))
        ->assertOk()
        ->assertSee('Knowledge Admin');
});

test('admin can view the create article form', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->get(route('admin.knowledge.articles.create'))
        ->assertOk()
        ->assertSee('New Article');
});

test('admin can view the edit article form', function () {
    $this->actingAs(User::factory()->admin()->create());

    $article = KnowledgeArticle::firstOrFail();

    $this->get(route('admin.knowledge.articles.edit', $article))
        ->assertOk()
        ->assertSee('Edit Article')
        ->assertSee($article->title);
});

test('admin can create an article via livewire', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(ArticleForm::class)
        ->set('title', 'Test Admin Article')
        ->set('slug', 'test-admin-article')
        ->set('jurisdiction', 'TX')
        ->set('category', ArticleCategory::Banking->value)
        ->set('body_markdown', '## Test heading\n\nBody content here.')
        ->set('risk_level', RiskLevel::Low->value)
        ->set('status', ArticleStatus::Draft->value)
        ->set('version', 1)
        ->call('save')
        ->assertHasNoErrors();

    expect(KnowledgeArticle::where('slug', 'test-admin-article')->exists())->toBeTrue();
});

test('admin can update an article via livewire', function () {
    $this->actingAs(User::factory()->admin()->create());

    $article = KnowledgeArticle::firstOrFail();

    Livewire::test(ArticleForm::class, ['article' => $article])
        ->set('title', 'Updated Title')
        ->call('save')
        ->assertHasNoErrors();

    expect($article->fresh()->title)->toBe('Updated Title');
});

test('admin can archive an article via livewire', function () {
    $this->actingAs(User::factory()->admin()->create());

    $article = KnowledgeArticle::firstOrFail();

    Livewire::test(ArticleIndex::class)
        ->call('archive', $article->id)
        ->assertHasNoErrors();

    expect($article->fresh()->status)->toBe(ArticleStatus::Archived);
});

test('admin can mark an article stale via livewire', function () {
    $this->actingAs(User::factory()->admin()->create());

    $article = KnowledgeArticle::firstOrFail();

    Livewire::test(ArticleIndex::class)
        ->call('markStale', $article->id)
        ->assertHasNoErrors();

    expect($article->fresh()->status)->toBe(ArticleStatus::NeedsReview)
        ->and($article->fresh()->next_review_at)->not->toBeNull();
});

test('admin can request revalidation via livewire', function () {
    $this->actingAs(User::factory()->admin()->create());

    $article = KnowledgeArticle::where('status', ArticleStatus::Published)->firstOrFail();

    Livewire::test(ArticleIndex::class)
        ->call('requestRevalidation', $article->id)
        ->assertHasNoErrors();

    expect($article->fresh()->status)->toBe(ArticleStatus::NeedsReview);
});

test('admin can add a source to an article via livewire', function () {
    $this->actingAs(User::factory()->admin()->create());

    $article = KnowledgeArticle::with('sources')->firstOrFail();
    $nextIndex = $article->sources->count();

    Livewire::test(ArticleForm::class, ['article' => $article])
        ->call('addSource')
        ->set("sources.{$nextIndex}.source_name", 'Test Source')
        ->set("sources.{$nextIndex}.source_url", 'https://example.gov/test')
        ->set("sources.{$nextIndex}.source_type", SourceType::StateAgency->value)
        ->call('save')
        ->assertHasNoErrors();

    expect($article->fresh()->sources()->where('source_name', 'Test Source')->exists())->toBeTrue();
});

test('admin can remove a source from an article via livewire', function () {
    $this->actingAs(User::factory()->admin()->create());

    $article = KnowledgeArticle::firstOrFail();
    $source = $article->sources->first();

    Livewire::test(ArticleForm::class, ['article' => $article])
        ->call('removeSource', 0)
        ->call('save')
        ->assertHasNoErrors();

    expect(KnowledgeSource::find($source->id))->toBeNull();
});

test('admin can update a source on an article via livewire', function () {
    $this->actingAs(User::factory()->admin()->create());

    $article = KnowledgeArticle::with('sources')->firstOrFail();
    $source = $article->sources->first();

    Livewire::test(ArticleForm::class, ['article' => $article])
        ->set('sources.0.source_name', 'Updated Source Name')
        ->set('sources.0.source_url', 'https://updated.example.gov/test')
        ->call('save')
        ->assertHasNoErrors();

    expect($source->fresh()->source_name)->toBe('Updated Source Name')
        ->and($source->fresh()->source_url)->toBe('https://updated.example.gov/test');
});

test('published high-risk article requires at least one source', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(ArticleForm::class)
        ->set('title', 'High Risk No Sources')
        ->set('slug', 'high-risk-no-sources')
        ->set('jurisdiction', 'TX')
        ->set('category', ArticleCategory::FranchiseTax->value)
        ->set('body_markdown', '## Test heading\n\nBody content.')
        ->set('risk_level', RiskLevel::High->value)
        ->set('status', ArticleStatus::Published->value)
        ->set('version', 1)
        ->call('save')
        ->assertHasErrors('sources');
});

test('non-admin cannot access create article form', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('admin.knowledge.articles.create'))
        ->assertForbidden();
});

test('non-admin cannot access edit article form', function () {
    $this->actingAs(User::factory()->create());

    $article = KnowledgeArticle::firstOrFail();

    $this->get(route('admin.knowledge.articles.edit', $article))
        ->assertForbidden();
});
