<?php

namespace Database\Factories;

use App\Enums\SourceType;
use App\Models\KnowledgeArticle;
use App\Models\KnowledgeSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeSource>
 */
class KnowledgeSourceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'knowledge_article_id' => KnowledgeArticle::factory(),
            'source_name' => fake()->randomElement(['Texas Comptroller', 'Texas Secretary of State', 'IRS', 'Texas Workforce Commission']),
            'source_url' => 'https://comptroller.texas.gov/taxes/',
            'source_type' => fake()->randomElement(SourceType::cases()),
            'retrieved_at' => now()->subDays(fake()->numberBetween(1, 30)),
            'effective_date' => null,
            'notes' => null,
        ];
    }
}
