<?php

namespace Database\Factories;

use App\Enums\ArticleCategory;
use App\Enums\ArticleStatus;
use App\Enums\RiskLevel;
use App\Models\KnowledgeArticle;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<KnowledgeArticle>
 */
class KnowledgeArticleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->unique()->sentence(4);

        return [
            'title' => $title,
            'slug' => Str::slug($title),
            'jurisdiction' => 'TX',
            'category' => fake()->randomElement(ArticleCategory::cases()),
            'body_markdown' => fake()->paragraphs(3, true),
            'source_summary' => fake()->sentence(),
            'risk_level' => fake()->randomElement(RiskLevel::cases()),
            'last_verified_at' => now()->subDays(fake()->numberBetween(1, 30)),
            'next_review_at' => now()->addDays(fake()->numberBetween(30, 365)),
            'status' => ArticleStatus::Published,
            'version' => 1,
        ];
    }

    public function stale(): static
    {
        return $this->state(fn (): array => [
            'last_verified_at' => now()->subYear(),
            'next_review_at' => now()->subMonth(),
            'status' => ArticleStatus::NeedsReview,
        ]);
    }
}
