<?php

namespace App\Services;

use App\Enums\ArticleCategory;
use App\Enums\FilingConfidence;
use App\Enums\LegalStructure;
use App\Enums\RoadmapPhase;
use App\Enums\RoadmapPriority;
use App\Enums\RoadmapStatus;
use App\Models\Business;
use Illuminate\Support\Collection;

/**
 * Builds the static v1 roadmap: a fixed Texas-first template whose item
 * statuses and priorities are derived from the business profile. Phase 3
 * (personalization) replaces the template internals; the page and dashboard
 * consume only the public API.
 */
class RoadmapBuilder
{
    /**
     * All roadmap items, ordered by phase then priority.
     *
     * @return Collection<int, RoadmapItem>
     */
    public function build(Business $business): Collection
    {
        $phaseOrder = array_flip(array_column(RoadmapPhase::cases(), 'value'));

        return collect($this->template($business))
            ->sortBy([
                fn (RoadmapItem $a, RoadmapItem $b): int => $phaseOrder[$a->phase->value] <=> $phaseOrder[$b->phase->value],
                fn (RoadmapItem $a, RoadmapItem $b): int => $a->priority->weight() <=> $b->priority->weight(),
            ])
            ->values();
    }

    /**
     * Items grouped by phase for the roadmap page, preserving phase order.
     *
     * @return Collection<string, Collection<int, RoadmapItem>>
     */
    public function buildGrouped(Business $business): Collection
    {
        return $this->build($business)->groupBy(fn (RoadmapItem $item): string => $item->phase->value);
    }

    /**
     * The next open (to-do or needs-info) items, most urgent first.
     *
     * @return Collection<int, RoadmapItem>
     */
    public function nextActions(Business $business, int $limit = 5): Collection
    {
        return $this->build($business)
            ->filter(fn (RoadmapItem $item): bool => $item->isOpen())
            ->take($limit)
            ->values();
    }

    /**
     * The static v1 item template.
     *
     * @return list<RoadmapItem>
     */
    private function template(Business $business): array
    {
        $structure = $business->legal_structure;
        $hasEmployees = $business->employee_count > 0;

        return [
            // Foundation
            new RoadmapItem(
                key: 'name-your-business',
                phase: RoadmapPhase::Foundation,
                title: __('Settle on a business name'),
                whyItMatters: __('Your name flows into every filing: DBA certificates, entity formation, bank accounts, and permits. Locking it in early avoids re-filing later.'),
                priority: RoadmapPriority::Required,
                status: $business->name !== null ? RoadmapStatus::Complete : RoadmapStatus::ToDo,
                href: route('branding'),
                hrefLabel: __('Generate name ideas in Branding'),
            ),
            new RoadmapItem(
                key: 'decide-legal-structure',
                phase: RoadmapPhase::Foundation,
                title: __('Decide on a legal structure'),
                whyItMatters: __('Sole proprietor, DBA, LLC, or corporation changes your liability exposure, taxes, and what you must file with Texas. Most later steps depend on this decision.'),
                priority: RoadmapPriority::Required,
                status: match (true) {
                    ! $structure->isUndecided() => RoadmapStatus::Complete,
                    $structure === LegalStructure::Unsure => RoadmapStatus::NeedsInfo,
                    default => RoadmapStatus::ToDo,
                },
                reviewer: __('Attorney or CPA'),
                href: route('knowledge.articles.index', ['category' => ArticleCategory::Formation->value]),
                hrefLabel: __('Compare legal structures'),
            ),

            // Legal setup
            new RoadmapItem(
                key: 'form-entity-or-register',
                phase: RoadmapPhase::LegalSetup,
                title: __('Form your entity or confirm your registration'),
                whyItMatters: __('Formal entities file with the Texas Secretary of State; sole proprietors and partnerships using a business name typically file an assumed name with the county. Unregistered operation can expose you personally.'),
                priority: RoadmapPriority::Required,
                status: match (true) {
                    $structure->isFormalEntity() => RoadmapStatus::Complete,
                    $structure->isUndecided() => RoadmapStatus::NeedsInfo,
                    default => $business->dba_status->isYes() ? RoadmapStatus::Complete : RoadmapStatus::ToDo,
                },
                reviewer: __('Attorney'),
            ),
            new RoadmapItem(
                key: 'file-assumed-name',
                phase: RoadmapPhase::LegalSetup,
                title: __('File a DBA / assumed name if you operate under one'),
                whyItMatters: __('If the business operates under a name different from your legal or entity name, Texas assumed-name rules likely apply at the state or county level.'),
                priority: RoadmapPriority::Recommended,
                status: match (true) {
                    $business->dba_status->isYes() => RoadmapStatus::Complete,
                    $business->dba_status->isUnsure() => RoadmapStatus::NeedsInfo,
                    default => RoadmapStatus::ToDo,
                },
            ),
            new RoadmapItem(
                key: 'get-ein',
                phase: RoadmapPhase::LegalSetup,
                title: __('Get an EIN from the IRS'),
                whyItMatters: __('Banks, payroll, and many filings ask for an EIN. It is free directly from the IRS and keeps your SSN off business paperwork.'),
                priority: RoadmapPriority::Required,
                status: match (true) {
                    $business->has_ein->isYes() => RoadmapStatus::Complete,
                    $business->has_ein->isUnsure() => RoadmapStatus::NeedsInfo,
                    default => RoadmapStatus::ToDo,
                },
            ),
            new RoadmapItem(
                key: 'operating-agreement',
                phase: RoadmapPhase::LegalSetup,
                title: __('Put an operating or partnership agreement in place'),
                whyItMatters: __('Ownership percentages, decision rights, and exit terms are far cheaper to agree on now than to litigate later.'),
                priority: RoadmapPriority::Recommended,
                status: $structure === LegalStructure::Llc || $structure === LegalStructure::Partnership
                    ? RoadmapStatus::ToDo
                    : RoadmapStatus::NotApplicable,
                reviewer: __('Attorney'),
            ),
            new RoadmapItem(
                key: 'licenses-and-permits',
                phase: RoadmapPhase::LegalSetup,
                title: __('Check industry, city, and county licenses or permits'),
                whyItMatters: __('Texas has no general state business license, but many industries and local governments require their own permits. Confirm with your city and county.'),
                priority: RoadmapPriority::Recommended,
                status: RoadmapStatus::NeedsInfo,
                href: route('advisor'),
                hrefLabel: __('Ask the Advisor about local permits'),
            ),

            // Taxes
            new RoadmapItem(
                key: 'sales-tax-permit',
                phase: RoadmapPhase::Taxes,
                title: __('Resolve your Texas sales tax permit status'),
                whyItMatters: __('Selling taxable goods or services in Texas without a permit risks back taxes and penalties. Confirm taxability with the Comptroller before your first taxable sale.'),
                priority: RoadmapPriority::Required,
                status: match (true) {
                    ! $business->mayHaveTaxableSales() => RoadmapStatus::NotApplicable,
                    $business->has_sales_tax_permit->isYes() => RoadmapStatus::Complete,
                    $business->has_sales_tax_permit->isUnsure() => RoadmapStatus::NeedsInfo,
                    default => RoadmapStatus::ToDo,
                },
                reviewer: __('CPA'),
                href: route('knowledge.articles.index', ['category' => ArticleCategory::SalesTax->value]),
                hrefLabel: __('Read the sales tax guidance'),
            ),
            new RoadmapItem(
                key: 'franchise-tax-awareness',
                phase: RoadmapPhase::Taxes,
                title: __('Understand Texas franchise tax filing requirements'),
                whyItMatters: __('Texas taxable entities generally owe an annual franchise tax report even when no tax is due. Missing it can cost your entity its good standing.'),
                priority: RoadmapPriority::Required,
                status: $structure->isFormalEntity() ? RoadmapStatus::ToDo : RoadmapStatus::NotApplicable,
                reviewer: __('CPA'),
                href: route('knowledge.articles.index', ['category' => ArticleCategory::FranchiseTax->value]),
                hrefLabel: __('Read the franchise tax guidance'),
            ),
            new RoadmapItem(
                key: 'federal-tax-planning',
                phase: RoadmapPhase::Taxes,
                title: __('Plan for federal income and self-employment taxes'),
                whyItMatters: __('Owners usually owe quarterly estimated taxes once the business earns money. Setting aside a percentage of every deposit avoids a painful April surprise.'),
                priority: RoadmapPriority::Recommended,
                status: RoadmapStatus::ToDo,
                reviewer: __('CPA'),
            ),

            // Banking
            new RoadmapItem(
                key: 'business-bank-account',
                phase: RoadmapPhase::Banking,
                title: __('Open a dedicated business bank account'),
                whyItMatters: __('Mixing personal and business money undermines bookkeeping, taxes, and the liability separation an entity is supposed to provide.'),
                priority: RoadmapPriority::Required,
                status: $business->has_business_bank ? RoadmapStatus::Complete : RoadmapStatus::ToDo,
                href: route('banking-setup'),
                hrefLabel: __('Open the banking checklist'),
            ),
            new RoadmapItem(
                key: 'tax-reserve-account',
                phase: RoadmapPhase::Banking,
                title: __('Set up a tax reserve savings account'),
                whyItMatters: __('Moving a slice of every deposit into a reserve account means sales tax and estimated taxes are already funded when they come due.'),
                priority: RoadmapPriority::Recommended,
                status: RoadmapStatus::ToDo,
                href: route('banking-setup'),
                hrefLabel: __('Plan reserve accounts'),
            ),

            // Accounting
            new RoadmapItem(
                key: 'bookkeeping-system',
                phase: RoadmapPhase::Accounting,
                title: __('Set up a bookkeeping system'),
                whyItMatters: __('Clean books power every tax filing, loan application, and pricing decision. Starting early is dramatically easier than cleaning up later.'),
                priority: RoadmapPriority::Required,
                status: $business->has_bookkeeping ? RoadmapStatus::Complete : RoadmapStatus::ToDo,
                href: route('knowledge.articles.index', ['category' => ArticleCategory::Accounting->value]),
                hrefLabel: __('Read the bookkeeping guidance'),
            ),
            new RoadmapItem(
                key: 'receipt-retention',
                phase: RoadmapPhase::Accounting,
                title: __('Adopt a receipt and record retention habit'),
                whyItMatters: __('Deductions survive an audit only when receipts back them up. A simple capture habit (photo + folder) is enough to start.'),
                priority: RoadmapPriority::Recommended,
                status: RoadmapStatus::ToDo,
            ),
            new RoadmapItem(
                key: 'monthly-close-routine',
                phase: RoadmapPhase::Accounting,
                title: __('Establish a monthly close routine'),
                whyItMatters: __('Reconciling accounts and reviewing profit and loss monthly catches errors, missed invoices, and cash problems while they are still small.'),
                priority: RoadmapPriority::Recommended,
                status: RoadmapStatus::ToDo,
            ),

            // Payroll
            new RoadmapItem(
                key: 'payroll-provider',
                phase: RoadmapPhase::Payroll,
                title: __('Set up a payroll provider'),
                whyItMatters: __('Payroll taxes have strict deposit schedules and penalties. A provider automates withholding, filings, and W-2s for a modest monthly cost.'),
                priority: RoadmapPriority::Required,
                status: match (true) {
                    ! $hasEmployees => RoadmapStatus::NotApplicable,
                    $business->has_payroll => RoadmapStatus::Complete,
                    default => RoadmapStatus::ToDo,
                },
                href: route('knowledge.articles.index', ['category' => ArticleCategory::Payroll->value]),
                hrefLabel: __('Read the payroll guidance'),
            ),
            new RoadmapItem(
                key: 'twc-registration',
                phase: RoadmapPhase::Payroll,
                title: __('Confirm Texas Workforce Commission employer registration'),
                whyItMatters: __('Texas employers generally must register with the TWC for state unemployment tax once they hire. Confirm your registration and account status.'),
                priority: RoadmapPriority::Required,
                status: $hasEmployees ? RoadmapStatus::NeedsInfo : RoadmapStatus::NotApplicable,
                reviewer: __('Payroll provider or CPA'),
            ),
            new RoadmapItem(
                key: 'new-hire-basics',
                phase: RoadmapPhase::Payroll,
                title: __('Collect new-hire paperwork and report new hires'),
                whyItMatters: __('W-4s, I-9s, and Texas new-hire reporting are required for each employee, with deadlines measured in days after the start date.'),
                priority: RoadmapPriority::Required,
                status: $hasEmployees ? RoadmapStatus::ToDo : RoadmapStatus::NotApplicable,
            ),
            new RoadmapItem(
                key: 'workers-comp-decision',
                phase: RoadmapPhase::Payroll,
                title: __('Make a workers\' compensation coverage decision'),
                whyItMatters: __('Texas does not require most private employers to carry workers\' comp, but opting out has notice requirements and real liability trade-offs.'),
                priority: RoadmapPriority::Recommended,
                status: $hasEmployees ? RoadmapStatus::NeedsInfo : RoadmapStatus::NotApplicable,
                reviewer: __('Insurance agent or attorney'),
            ),
            new RoadmapItem(
                key: 'contractor-w9s',
                phase: RoadmapPhase::Payroll,
                title: __('Collect W-9s from your contractors'),
                whyItMatters: __('You will need each contractor\'s W-9 to issue 1099-NEC forms in January. Collecting them before the first payment is far easier than chasing them at year end.'),
                priority: RoadmapPriority::Recommended,
                status: $business->uses_contractors ? RoadmapStatus::ToDo : RoadmapStatus::NotApplicable,
                href: route('knowledge.articles.index', ['category' => ArticleCategory::Contractors->value]),
                hrefLabel: __('Read the contractor guidance'),
            ),

            // Owner pay
            new RoadmapItem(
                key: 'owner-pay-method',
                phase: RoadmapPhase::OwnerPay,
                title: __('Choose how you will pay yourself'),
                whyItMatters: __('Draws, distributions, guaranteed payments, and W-2 salary each fit different structures and carry different tax consequences. The right answer depends on your entity and tax election.'),
                priority: RoadmapPriority::Recommended,
                status: $structure->isUndecided() ? RoadmapStatus::NeedsInfo : RoadmapStatus::ToDo,
                reviewer: __('CPA'),
                href: route('owner-pay'),
                hrefLabel: __('Compare your owner-pay options'),
            ),

            // Branding
            new RoadmapItem(
                key: 'brand-basics',
                phase: RoadmapPhase::Branding,
                title: __('Put together basic brand assets'),
                whyItMatters: __('A consistent name, logo, and voice make a small business look established and keep marketing materials coherent.'),
                priority: RoadmapPriority::Optional,
                status: RoadmapStatus::ToDo,
                href: route('branding'),
                hrefLabel: __('Generate a brand kit'),
            ),
            new RoadmapItem(
                key: 'online-presence',
                phase: RoadmapPhase::Branding,
                title: __('Claim your online presence'),
                whyItMatters: __('A simple website or business profile plus matching social handles is how most local customers will verify you exist.'),
                priority: RoadmapPriority::Optional,
                status: RoadmapStatus::ToDo,
                href: route('branding'),
                hrefLabel: __('Grab social bios from your brand kit'),
            ),

            // Advertising
            new RoadmapItem(
                key: 'first-30-days-marketing',
                phase: RoadmapPhase::Advertising,
                title: __('Sketch a first-30-days marketing plan'),
                whyItMatters: __('A short, concrete plan (who you serve, where they look, what you will post or run) beats sporadic ads and keeps early spend focused.'),
                priority: RoadmapPriority::Optional,
                status: RoadmapStatus::ToDo,
                href: route('advertising'),
                hrefLabel: __('Generate your 30-day marketing plan'),
            ),

            // Growth readiness
            new RoadmapItem(
                key: 'recurring-task-calendar',
                phase: RoadmapPhase::GrowthReadiness,
                title: __('Adopt a weekly / monthly / quarterly compliance rhythm'),
                whyItMatters: __('Most small business trouble comes from missed routine tasks, not big decisions. A recurring calendar keeps books, taxes, and filings on track.'),
                priority: RoadmapPriority::Recommended,
                status: RoadmapStatus::ToDo,
                href: route('tasks.index'),
                hrefLabel: __('Open your task list'),
            ),
            new RoadmapItem(
                key: 'professional-support',
                phase: RoadmapPhase::GrowthReadiness,
                title: __('Line up professional support'),
                whyItMatters: __('A CPA or bookkeeper who knows your business before a deadline hits is cheaper and calmer than one hired during an emergency.'),
                priority: RoadmapPriority::Recommended,
                status: $business->filing_confidence === FilingConfidence::HasProfessional
                    ? RoadmapStatus::Complete
                    : RoadmapStatus::ToDo,
                reviewer: __('CPA'),
                href: route('knowledge.articles.index', ['category' => ArticleCategory::Professionals->value]),
                hrefLabel: __('Read the guidance on professionals'),
            ),
        ];
    }
}
