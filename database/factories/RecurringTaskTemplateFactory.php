<?php

namespace Database\Factories;

use App\Enums\TaskCategory;
use App\Enums\TaskConfidence;
use App\Enums\TaskFrequency;
use App\Models\RecurringTaskTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RecurringTaskTemplate>
 */
class RecurringTaskTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->sentence(4);

        return [
            'knowledge_article_id' => null,
            'slug' => Str::slug($title).'-'.fake()->unique()->numberBetween(1000, 9999),
            'title' => $title,
            'description' => fake()->paragraph(),
            'category' => fake()->randomElement(TaskCategory::cases()),
            'frequency' => fake()->randomElement(TaskFrequency::cases()),
            'applies_to' => ['employees' => 'any', 'sales_tax' => 'any', 'contractors' => 'any'],
            'due_rule' => ['type' => 'end_of_period'],
            'confidence' => TaskConfidence::High,
            'requires_professional_review' => false,
            'is_active' => true,
        ];
    }

    public function salesTaxExposed(): static
    {
        return $this->state(fn (): array => [
            'category' => TaskCategory::SalesTax,
            'applies_to' => ['sales_tax' => 'exposed', 'employees' => 'any', 'contractors' => 'any'],
        ]);
    }

    public function withEmployees(): static
    {
        return $this->state(fn (): array => [
            'category' => TaskCategory::Payroll,
            'applies_to' => ['employees' => 'with', 'sales_tax' => 'any', 'contractors' => 'any'],
        ]);
    }

    public function withContractors(): static
    {
        return $this->state(fn (): array => [
            'category' => TaskCategory::Contractors,
            'applies_to' => ['contractors' => 'uses', 'employees' => 'any', 'sales_tax' => 'any'],
        ]);
    }
}
