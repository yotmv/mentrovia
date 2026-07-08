<?php

namespace Database\Seeders;

use App\Enums\TaskCategory;
use App\Enums\TaskConfidence;
use App\Enums\TaskFrequency;
use App\Models\KnowledgeArticle;
use App\Models\RecurringTaskTemplate;
use Illuminate\Database\Seeder;

class RecurringTaskTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $articles = KnowledgeArticle::query()
            ->whereIn('slug', collect($this->templates())->pluck('article_slug')->unique())
            ->get()
            ->keyBy('slug');

        foreach ($this->templates() as $template) {
            $article = $articles->get($template['article_slug']);

            RecurringTaskTemplate::updateOrCreate(
                ['slug' => $template['slug']],
                [
                    'knowledge_article_id' => $article?->id,
                    'title' => $template['title'],
                    'description' => $template['description'],
                    'category' => $template['category'],
                    'frequency' => $template['frequency'],
                    'applies_to' => $template['applies_to'],
                    'due_rule' => $template['due_rule'],
                    'confidence' => $template['confidence'],
                    'requires_professional_review' => $template['requires_professional_review'],
                    'is_active' => true,
                ],
            );
        }
    }

    /**
     * @return array<int, array{
     *     article_slug: string,
     *     slug: string,
     *     title: string,
     *     description: string,
     *     category: TaskCategory,
     *     frequency: TaskFrequency,
     *     applies_to: array<string, mixed>,
     *     due_rule: array<string, mixed>,
     *     confidence: TaskConfidence,
     *     requires_professional_review: bool
     * }>
     */
    private function templates(): array
    {
        $anyBusiness = [
            'stages' => [],
            'legal_structures' => [],
            'employees' => 'any',
            'sales_tax' => 'any',
            'contractors' => 'any',
        ];

        return [
            [
                'article_slug' => 'weekly-business-admin-checklist',
                'slug' => 'weekly-admin-review',
                'title' => 'Review weekly business admin',
                'description' => 'Check receivables, bills, receipts, cash position, and open promises before the week disappears.',
                'category' => TaskCategory::Operations,
                'frequency' => TaskFrequency::Weekly,
                'applies_to' => $anyBusiness,
                'due_rule' => ['type' => 'end_of_week'],
                'confidence' => TaskConfidence::High,
                'requires_professional_review' => false,
            ],
            [
                'article_slug' => 'weekly-business-admin-checklist',
                'slug' => 'weekly-sales-tax-reserve-check',
                'title' => 'Check sales tax reserve',
                'description' => 'Glance at taxable sales and confirm collected sales tax is accruing in books or a reserve account.',
                'category' => TaskCategory::SalesTax,
                'frequency' => TaskFrequency::Weekly,
                'applies_to' => [...$anyBusiness, 'sales_tax' => 'exposed'],
                'due_rule' => ['type' => 'end_of_week'],
                'confidence' => TaskConfidence::Medium,
                'requires_professional_review' => true,
            ],
            [
                'article_slug' => 'weekly-business-admin-checklist',
                'slug' => 'weekly-payroll-hours-review',
                'title' => 'Review payroll hours',
                'description' => 'Catch timekeeping gaps before payroll runs.',
                'category' => TaskCategory::Payroll,
                'frequency' => TaskFrequency::Weekly,
                'applies_to' => [...$anyBusiness, 'employees' => 'with'],
                'due_rule' => ['type' => 'end_of_week'],
                'confidence' => TaskConfidence::Medium,
                'requires_professional_review' => true,
            ],
            [
                'article_slug' => 'monthly-bookkeeping-checklist',
                'slug' => 'monthly-bookkeeping-close',
                'title' => 'Close monthly bookkeeping',
                'description' => 'Reconcile bank and credit card activity, review reports, and close the prior month deliberately.',
                'category' => TaskCategory::Bookkeeping,
                'frequency' => TaskFrequency::Monthly,
                'applies_to' => $anyBusiness,
                'due_rule' => ['type' => 'end_of_month'],
                'confidence' => TaskConfidence::High,
                'requires_professional_review' => false,
            ],
            [
                'article_slug' => 'monthly-bookkeeping-checklist',
                'slug' => 'monthly-sales-tax-liability-review',
                'title' => 'Review monthly sales tax liability',
                'description' => 'Confirm taxable sales and accrued sales-tax liability match the reserve before any filing deadline.',
                'category' => TaskCategory::SalesTax,
                'frequency' => TaskFrequency::Monthly,
                'applies_to' => [...$anyBusiness, 'sales_tax' => 'exposed'],
                'due_rule' => ['type' => 'end_of_month'],
                'confidence' => TaskConfidence::Medium,
                'requires_professional_review' => true,
            ],
            [
                'article_slug' => 'monthly-bookkeeping-checklist',
                'slug' => 'monthly-contractor-payment-review',
                'title' => 'Review contractor payments',
                'description' => 'Keep contractor totals and W-9 gaps current so year-end forms do not become a scramble.',
                'category' => TaskCategory::Contractors,
                'frequency' => TaskFrequency::Monthly,
                'applies_to' => [...$anyBusiness, 'contractors' => 'uses'],
                'due_rule' => ['type' => 'end_of_month'],
                'confidence' => TaskConfidence::Medium,
                'requires_professional_review' => true,
            ],
            [
                'article_slug' => 'quarterly-tax-review-checklist',
                'slug' => 'quarterly-estimated-tax-review',
                'title' => 'Review quarterly estimated taxes',
                'description' => 'Compare year-to-date profit against estimated tax assumptions and confirm payment status.',
                'category' => TaskCategory::TaxPlanning,
                'frequency' => TaskFrequency::Quarterly,
                'applies_to' => $anyBusiness,
                'due_rule' => ['type' => 'end_of_quarter'],
                'confidence' => TaskConfidence::Medium,
                'requires_professional_review' => true,
            ],
            [
                'article_slug' => 'quarterly-tax-review-checklist',
                'slug' => 'quarterly-payroll-filing-review',
                'title' => 'Confirm quarterly payroll filings',
                'description' => 'Verify payroll provider filings and unemployment reports match business records.',
                'category' => TaskCategory::Payroll,
                'frequency' => TaskFrequency::Quarterly,
                'applies_to' => [...$anyBusiness, 'employees' => 'with'],
                'due_rule' => ['type' => 'end_of_quarter'],
                'confidence' => TaskConfidence::Medium,
                'requires_professional_review' => true,
            ],
            [
                'article_slug' => 'yearly-business-compliance-checklist',
                'slug' => 'yearly-compliance-sweep',
                'title' => 'Run annual compliance sweep',
                'description' => 'Review franchise tax, registered agent details, assumed names, permits, insurance, and annual planning.',
                'category' => TaskCategory::Compliance,
                'frequency' => TaskFrequency::Yearly,
                'applies_to' => $anyBusiness,
                'due_rule' => ['type' => 'month_day', 'month' => 1, 'day' => 31],
                'confidence' => TaskConfidence::Medium,
                'requires_professional_review' => true,
            ],
            [
                'article_slug' => 'yearly-business-compliance-checklist',
                'slug' => 'yearly-contractor-1099-prep',
                'title' => 'Prepare contractor form review',
                'description' => 'Confirm contractor totals, tax forms, and current IRS requirements before year-end filing season.',
                'category' => TaskCategory::Contractors,
                'frequency' => TaskFrequency::Yearly,
                'applies_to' => [...$anyBusiness, 'contractors' => 'uses'],
                'due_rule' => ['type' => 'month_day', 'month' => 1, 'day' => 15],
                'confidence' => TaskConfidence::Medium,
                'requires_professional_review' => true,
            ],
            [
                'article_slug' => 'yearly-business-compliance-checklist',
                'slug' => 'yearly-employee-w2-review',
                'title' => 'Confirm employee year-end forms',
                'description' => 'Verify employee year-end forms and payroll reports before filing season.',
                'category' => TaskCategory::Payroll,
                'frequency' => TaskFrequency::Yearly,
                'applies_to' => [...$anyBusiness, 'employees' => 'with'],
                'due_rule' => ['type' => 'month_day', 'month' => 1, 'day' => 15],
                'confidence' => TaskConfidence::Medium,
                'requires_professional_review' => true,
            ],
        ];
    }
}
