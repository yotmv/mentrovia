<?php

namespace App\Models;

use App\Enums\AnnualRevenueRange;
use App\Enums\BusinessStage;
use App\Enums\FilingConfidence;
use App\Enums\LegalStructure;
use App\Enums\LocationType;
use App\Enums\MonthlyRevenueRange;
use App\Enums\YesNoUnsure;
use Database\Factories\BusinessFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $name
 * @property string|null $desired_name
 * @property YesNoUnsure $dba_status
 * @property BusinessStage|null $stage
 * @property LegalStructure $legal_structure
 * @property string|null $tax_classification
 * @property string $industry
 * @property string $city
 * @property string $county
 * @property string $state
 * @property LocationType $location_type
 * @property string|null $address
 * @property int $owner_count
 * @property int $employee_count
 * @property bool $uses_contractors
 * @property YesNoUnsure $sells_taxable_goods
 * @property YesNoUnsure $sells_taxable_services
 * @property YesNoUnsure $has_sales_tax_permit
 * @property YesNoUnsure $has_ein
 * @property bool $has_business_bank
 * @property bool $has_bookkeeping
 * @property bool $has_payroll
 * @property AnnualRevenueRange|null $annual_revenue_range
 * @property MonthlyRevenueRange|null $monthly_revenue_range
 * @property Carbon|null $started_on
 * @property Carbon|null $first_sale_on
 * @property Carbon|null $first_employee_on
 * @property FilingConfidence|null $filing_confidence
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id', 'name', 'desired_name', 'dba_status', 'stage', 'legal_structure',
    'tax_classification', 'industry', 'city', 'county', 'state', 'location_type',
    'address', 'owner_count', 'employee_count', 'uses_contractors',
    'sells_taxable_goods', 'sells_taxable_services', 'has_sales_tax_permit',
    'has_ein', 'has_business_bank', 'has_bookkeeping', 'has_payroll',
    'annual_revenue_range', 'monthly_revenue_range', 'started_on',
    'first_sale_on', 'first_employee_on', 'filing_confidence',
])]
class Business extends Model
{
    /** @use HasFactory<BusinessFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dba_status' => YesNoUnsure::class,
            'stage' => BusinessStage::class,
            'legal_structure' => LegalStructure::class,
            'location_type' => LocationType::class,
            'owner_count' => 'integer',
            'employee_count' => 'integer',
            'uses_contractors' => 'boolean',
            'sells_taxable_goods' => YesNoUnsure::class,
            'sells_taxable_services' => YesNoUnsure::class,
            'has_sales_tax_permit' => YesNoUnsure::class,
            'has_ein' => YesNoUnsure::class,
            'has_business_bank' => 'boolean',
            'has_bookkeeping' => 'boolean',
            'has_payroll' => 'boolean',
            'annual_revenue_range' => AnnualRevenueRange::class,
            'monthly_revenue_range' => MonthlyRevenueRange::class,
            'started_on' => 'date',
            'first_sale_on' => 'date',
            'first_employee_on' => 'date',
            'filing_confidence' => FilingConfidence::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Deeper per-module intake answers keyed by question.
     *
     * @return HasMany<BusinessProfile, $this>
     */
    public function profileAnswers(): HasMany
    {
        return $this->hasMany(BusinessProfile::class);
    }

    /**
     * Generated recurring tasks for this business.
     *
     * @return HasMany<BusinessTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(BusinessTask::class);
    }

    /**
     * Compliance validation runs scoped to this business profile.
     *
     * @return HasMany<ValidationRun, $this>
     */
    public function validationRuns(): HasMany
    {
        return $this->hasMany(ValidationRun::class);
    }

    /**
     * Generated brand kit versions for this business.
     *
     * @return HasMany<BrandKit, $this>
     */
    public function brandKits(): HasMany
    {
        return $this->hasMany(BrandKit::class);
    }

    /**
     * The name the business currently goes by.
     */
    public function displayName(): string
    {
        return $this->name ?? $this->desired_name ?? 'Your business';
    }

    /**
     * Whether the business is already doing business (selling or earning).
     */
    public function isOperating(): bool
    {
        return $this->first_sale_on !== null
            || ($this->monthly_revenue_range !== null && $this->monthly_revenue_range !== MonthlyRevenueRange::None)
            || $this->dba_status->isYes();
    }

    /**
     * Whether any intake answer suggests taxable sales.
     */
    public function mayHaveTaxableSales(): bool
    {
        return ! ($this->sells_taxable_goods === YesNoUnsure::No
            && $this->sells_taxable_services === YesNoUnsure::No);
    }
}
