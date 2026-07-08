<?php

use App\Enums\ArticleStatus;
use App\Enums\RiskLevel;
use App\Models\KnowledgeArticle;
use Database\Seeders\KnowledgeArticleSeeder;

beforeEach(function () {
    $this->seed(KnowledgeArticleSeeder::class);
});

test('all twenty seed articles are created with unique slugs', function () {
    expect(KnowledgeArticle::count())->toBe(20)
        ->and(KnowledgeArticle::pluck('slug')->unique())->toHaveCount(20);
});

test('the seeder is idempotent', function () {
    $this->seed(KnowledgeArticleSeeder::class);

    expect(KnowledgeArticle::count())->toBe(20);
});

test('every article has at least one official https source', function () {
    $officialHosts = ['comptroller.texas.gov', 'sos.state.tx.us', 'twc.texas.gov', 'tdi.texas.gov', 'irs.gov', 'dol.gov'];

    KnowledgeArticle::with('sources')->get()->each(function (KnowledgeArticle $article) use ($officialHosts) {
        expect($article->sources)->not->toBeEmpty();

        $article->sources->each(function ($source) use ($officialHosts, $article) {
            $host = parse_url($source->source_url, PHP_URL_HOST);

            expect(str_starts_with($source->source_url, 'https://'))->toBeTrue()
                ->and(collect($officialHosts)->contains(fn (string $official): bool => str_ends_with((string) $host, $official)))->toBeTrue(
                    "Unexpected source host [{$host}] on article [{$article->slug}]",
                );
        });
    });
});

test('every article opens with the standard disclaimer and has substantial content', function () {
    KnowledgeArticle::all()->each(function (KnowledgeArticle $article) {
        expect($article->body_markdown)
            ->toContain('not legal, tax, payroll, or accounting advice')
            ->and(strlen($article->body_markdown))->toBeGreaterThan(1000, "Article [{$article->slug}] body looks too short");
    });
});

test('every article has verification timestamps and a published status', function () {
    KnowledgeArticle::all()->each(function (KnowledgeArticle $article) {
        expect($article->last_verified_at)->not->toBeNull()
            ->and($article->next_review_at)->not->toBeNull()
            ->and($article->next_review_at->isAfter($article->last_verified_at))->toBeTrue()
            ->and($article->status)->toBe(ArticleStatus::Published)
            ->and($article->jurisdiction)->toBe('TX');
    });
});

test('high risk articles get the shortest review interval', function () {
    $article = KnowledgeArticle::where('slug', 'texas-franchise-tax-basics')->firstOrFail();

    expect($article->risk_level)->toBe(RiskLevel::High)
        ->and($article->last_verified_at->diffInDays($article->next_review_at))->toEqual(90.0);
});

test('article bodies avoid hard-coded rates and thresholds', function () {
    KnowledgeArticle::all()->each(function (KnowledgeArticle $article) {
        expect(preg_match('/\d+(\.\d+)?\s?(percent|%)/i', $article->body_markdown))->toBe(0, "Article [{$article->slug}] contains a hard-coded percentage")
            ->and(preg_match('/\$\s?[\d,]+/', $article->body_markdown))->toBe(0, "Article [{$article->slug}] contains a hard-coded dollar amount");
    });
});
