<?php

use App\Enums\ArticleStatus;
use App\Enums\FreshnessStatus;
use App\Enums\RiskLevel;
use App\Enums\SourceType;
use App\Models\KnowledgeArticle;
use App\Models\User;
use Database\Seeders\KnowledgeArticleSeeder;

beforeEach(function () {
    $this->seed(KnowledgeArticleSeeder::class);
});

test('freshness status is fresh when review date is far in the future', function () {
    $article = KnowledgeArticle::factory()->create([
        'risk_level' => RiskLevel::Low,
        'status' => ArticleStatus::Published,
        'last_verified_at' => now(),
        'next_review_at' => now()->addDays(300),
    ]);
    $article->sources()->create([
        'source_name' => 'Test Source',
        'source_url' => 'https://example.gov/test',
        'source_type' => SourceType::StateAgency,
    ]);

    expect($article->freshnessStatus())->toBe(FreshnessStatus::Fresh);
});

test('freshness status is review soon when review date is within 14 days', function () {
    $article = KnowledgeArticle::factory()->create([
        'risk_level' => RiskLevel::Medium,
        'status' => ArticleStatus::Published,
        'last_verified_at' => now()->subDays(170),
        'next_review_at' => now()->addDays(7),
    ]);
    $article->sources()->create([
        'source_name' => 'Test Source',
        'source_url' => 'https://example.gov/test',
        'source_type' => SourceType::StateAgency,
    ]);

    expect($article->freshnessStatus())->toBe(FreshnessStatus::ReviewSoon);
});

test('freshness status is stale when review date has passed', function () {
    $article = KnowledgeArticle::factory()->create([
        'risk_level' => RiskLevel::Medium,
        'status' => ArticleStatus::NeedsReview,
        'last_verified_at' => now()->subYear(),
        'next_review_at' => now()->subMonth(),
    ]);
    $article->sources()->create([
        'source_name' => 'Test Source',
        'source_url' => 'https://example.gov/test',
        'source_type' => SourceType::StateAgency,
    ]);

    expect($article->freshnessStatus())->toBe(FreshnessStatus::Stale)
        ->and($article->isStale())->toBeTrue();
});

test('freshness status is stale when next review date is null', function () {
    $article = KnowledgeArticle::factory()->create([
        'risk_level' => RiskLevel::Low,
        'status' => ArticleStatus::Published,
        'last_verified_at' => now(),
        'next_review_at' => null,
    ]);
    $article->sources()->create([
        'source_name' => 'Test Source',
        'source_url' => 'https://example.gov/test',
        'source_type' => SourceType::StateAgency,
    ]);

    expect($article->freshnessStatus())->toBe(FreshnessStatus::Stale);
});

test('freshness status is missing sources when article has no sources', function () {
    $article = KnowledgeArticle::factory()->create([
        'risk_level' => RiskLevel::Low,
        'status' => ArticleStatus::Published,
        'last_verified_at' => now(),
        'next_review_at' => now()->addDays(365),
    ]);

    expect($article->freshnessStatus())->toBe(FreshnessStatus::MissingSources);
});

test('freshness badge is shown on article index', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('knowledge.articles.index'))
        ->assertOk()
        ->assertSee('Fresh');
});

test('stale high-risk article shows both stale and high-risk warnings', function () {
    $this->actingAs(User::factory()->create());

    $article = KnowledgeArticle::factory()->create([
        'title' => 'Stale High Risk Article',
        'slug' => 'stale-high-risk-article',
        'risk_level' => RiskLevel::High,
        'status' => ArticleStatus::NeedsReview,
        'last_verified_at' => now()->subYear(),
        'next_review_at' => now()->subMonth(),
    ]);
    $article->sources()->create([
        'source_name' => 'Test Source',
        'source_url' => 'https://example.gov/test',
        'source_type' => SourceType::StateAgency,
    ]);

    $this->get(route('knowledge.articles.show', 'stale-high-risk-article'))
        ->assertOk()
        ->assertSee('Stale content')
        ->assertSee('High-risk content');
});

test('missing sources warning is shown on article detail', function () {
    $this->actingAs(User::factory()->create());

    $article = KnowledgeArticle::factory()->create([
        'title' => 'No Sources Article',
        'slug' => 'no-sources-article',
        'risk_level' => RiskLevel::Low,
        'status' => ArticleStatus::Published,
        'last_verified_at' => now(),
        'next_review_at' => now()->addDays(365),
    ]);

    $this->get(route('knowledge.articles.show', 'no-sources-article'))
        ->assertOk()
        ->assertSee('Missing sources');
});

test('fresh article does not show stale or missing sources warnings', function () {
    $this->actingAs(User::factory()->create());

    $article = KnowledgeArticle::factory()->create([
        'title' => 'Fresh Article',
        'slug' => 'fresh-article',
        'risk_level' => RiskLevel::Low,
        'status' => ArticleStatus::Published,
        'last_verified_at' => now(),
        'next_review_at' => now()->addDays(300),
    ]);
    $article->sources()->create([
        'source_name' => 'Test Source',
        'source_url' => 'https://example.gov/test',
        'source_type' => SourceType::StateAgency,
    ]);

    $this->get(route('knowledge.articles.show', 'fresh-article'))
        ->assertOk()
        ->assertDontSee('Stale content')
        ->assertDontSee('Missing sources');
});
