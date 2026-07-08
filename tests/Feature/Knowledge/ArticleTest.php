<?php

use App\Enums\ArticleCategory;
use App\Enums\ArticleStatus;
use App\Enums\RiskLevel;
use App\Models\KnowledgeArticle;
use App\Models\User;
use Database\Seeders\KnowledgeArticleSeeder;

beforeEach(function () {
    $this->seed(KnowledgeArticleSeeder::class);
});

test('guests are redirected from the knowledge index', function () {
    $this->get(route('knowledge.articles.index'))
        ->assertRedirect(route('login'));
});

test('guests are redirected from a knowledge article detail', function () {
    $article = KnowledgeArticle::firstOrFail();

    $this->get(route('knowledge.articles.show', $article->slug))
        ->assertRedirect(route('login'));
});

test('authenticated users can view the article index', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('knowledge.articles.index'))
        ->assertOk()
        ->assertSee('Knowledge Articles');
});

test('article index renders category labels', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('knowledge.articles.index'))
        ->assertOk()
        ->assertSee(ArticleCategory::Formation->label())
        ->assertSee(ArticleCategory::SalesTax->label());
});

test('article index can be filtered by category', function () {
    $this->actingAs(User::factory()->create());

    $formationArticle = KnowledgeArticle::where('category', ArticleCategory::Formation)->firstOrFail();

    $this->get(route('knowledge.articles.index', ['category' => ArticleCategory::Formation->value]))
        ->assertOk()
        ->assertSee($formationArticle->title);
});

test('article index can be filtered by status', function () {
    $this->actingAs(User::factory()->create());

    $published = KnowledgeArticle::where('status', ArticleStatus::Published)->firstOrFail();

    $this->get(route('knowledge.articles.index', ['status' => ArticleStatus::Published->value]))
        ->assertOk()
        ->assertSee($published->title);
});

test('article detail renders source links and verified dates', function () {
    $this->actingAs(User::factory()->create());

    $article = KnowledgeArticle::with('sources')->firstOrFail();

    $response = $this->get(route('knowledge.articles.show', $article->slug))
        ->assertOk()
        ->assertSee($article->title)
        ->assertSee($article->source_summary)
        ->assertSee($article->last_verified_at->format('M j, Y'))
        ->assertSee($article->next_review_at->format('M j, Y'));

    foreach ($article->sources as $source) {
        $response->assertSee($source->source_name)
            ->assertSee($source->source_url);
    }
});

test('article detail shows high-risk warning for high-risk articles', function () {
    $this->actingAs(User::factory()->create());

    $article = KnowledgeArticle::where('risk_level', RiskLevel::High)->firstOrFail();

    $this->get(route('knowledge.articles.show', $article->slug))
        ->assertOk()
        ->assertSee('High-risk content')
        ->assertSee('qualified professional');
});

test('article detail shows missing sources fallback', function () {
    $this->actingAs(User::factory()->create());

    $article = KnowledgeArticle::factory()->create([
        'title' => 'Test Article No Sources',
        'slug' => 'test-article-no-sources',
        'risk_level' => RiskLevel::Low,
        'status' => ArticleStatus::Published,
        'last_verified_at' => now(),
        'next_review_at' => now()->addDays(365),
    ]);

    $this->get(route('knowledge.articles.show', 'test-article-no-sources'))
        ->assertOk()
        ->assertSee('No source links are available');
});

test('article detail shows stale warning when review date has passed', function () {
    $this->actingAs(User::factory()->create());

    $article = KnowledgeArticle::factory()->create([
        'title' => 'Stale Test Article',
        'slug' => 'stale-test-article',
        'risk_level' => RiskLevel::Medium,
        'status' => ArticleStatus::NeedsReview,
        'last_verified_at' => now()->subYear(),
        'next_review_at' => now()->subMonth(),
    ]);

    $this->get(route('knowledge.articles.show', 'stale-test-article'))
        ->assertOk()
        ->assertSee('Due for review');
});

test('unknown slug returns 404', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('knowledge.articles.show', 'nonexistent-slug'))
        ->assertNotFound();
});

test('article detail renders markdown body as html', function () {
    $this->actingAs(User::factory()->create());

    $article = KnowledgeArticle::factory()->create([
        'title' => 'Markdown Render Test',
        'slug' => 'markdown-render-test',
        'body_markdown' => "## Heading\n\nA paragraph with **bold** text.",
        'risk_level' => RiskLevel::Low,
        'status' => ArticleStatus::Published,
        'last_verified_at' => now(),
        'next_review_at' => now()->addDays(365),
    ]);

    $this->get(route('knowledge.articles.show', 'markdown-render-test'))
        ->assertOk()
        ->assertSee('<h2>Heading</h2>', false)
        ->assertSee('<strong>bold</strong>', false);
});
