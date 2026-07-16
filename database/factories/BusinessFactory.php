<?php

namespace Database\Factories;

use App\Enums\AnnualRevenueRange;
use App\Enums\FilingConfidence;
use App\Enums\LegalStructure;
use App\Enums\LocationType;
use App\Enums\MonthlyRevenueRange;
use App\Enums\YesNoUnsure;
use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Business>
 */
class BusinessFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'account_id' => fn (array $attributes): int => (int) (User::query()
                ->whereKey((int) $attributes['user_id'])
                ->value('current_account_id') ?? $attributes['user_id']),
            'name' => fake()->company(),
            'desired_name' => null,
            'dba_status' => YesNoUnsure::No,
            'stage' => null,
            'legal_structure' => LegalStructure::SoleProprietor,
            'industry' => fake()->randomElement(['Landscaping', 'Consulting', 'Retail', 'Cleaning services', 'Photography']),
            'city' => fake()->randomElement(['Austin', 'Houston', 'Dallas', 'San Antonio', 'Fort Worth']),
            'county' => fake()->randomElement(['Travis', 'Harris', 'Dallas', 'Bexar', 'Tarrant']),
            'state' => 'TX',
            'location_type' => fake()->randomElement(LocationType::cases()),
            'address' => fake()->streetAddress(),
            'owner_count' => 1,
            'employee_count' => 0,
            'uses_contractors' => false,
            'sells_taxable_goods' => YesNoUnsure::Unsure,
            'sells_taxable_services' => YesNoUnsure::Unsure,
            'has_sales_tax_permit' => YesNoUnsure::No,
            'has_ein' => YesNoUnsure::No,
            'has_business_bank' => false,
            'has_bookkeeping' => false,
            'has_payroll' => false,
            'annual_revenue_range' => AnnualRevenueRange::Under25K,
            'monthly_revenue_range' => MonthlyRevenueRange::Under1K,
            'started_on' => fake()->dateTimeBetween('-3 years', '-1 month'),
            'first_sale_on' => null,
            'first_employee_on' => null,
            'filing_confidence' => FilingConfidence::SomeKnowledge,
        ];
    }

    /**
     * A brand-new business idea: nothing formed, nothing sold.
     */
    public function startingFromScratch(): static
    {
        return $this->state(fn (): array => [
            'name' => null,
            'desired_name' => fake()->company(),
            'dba_status' => YesNoUnsure::No,
            'legal_structure' => LegalStructure::NotStarted,
            'started_on' => null,
            'first_sale_on' => null,
            'annual_revenue_range' => AnnualRevenueRange::None,
            'monthly_revenue_range' => MonthlyRevenueRange::None,
        ]);
    }

    /**
     * An operating sole proprietor / DBA already making sales.
     */
    public function operatingDba(): static
    {
        return $this->state(fn (): array => [
            'dba_status' => YesNoUnsure::Yes,
            'legal_structure' => LegalStructure::DbaOnly,
            'first_sale_on' => fake()->dateTimeBetween('-2 years', '-1 month'),
            'monthly_revenue_range' => MonthlyRevenueRange::From1KTo5K,
        ]);
    }

    /**
     * A business with at least one employee.
     */
    public function withEmployees(int $count = 1): static
    {
        return $this->state(fn (): array => [
            'employee_count' => $count,
            'first_employee_on' => fake()->dateTimeBetween('-1 year', '-1 month'),
            'first_sale_on' => fake()->dateTimeBetween('-2 years', '-1 year'),
        ]);
    }

    /**
     * A formally registered LLC.
     */
    public function formalEntity(): static
    {
        return $this->state(fn (): array => [
            'legal_structure' => LegalStructure::Llc,
            'has_ein' => YesNoUnsure::Yes,
            'first_sale_on' => fake()->dateTimeBetween('-2 years', '-1 month'),
            'monthly_revenue_range' => MonthlyRevenueRange::From5KTo10K,
        ]);
    }
}
