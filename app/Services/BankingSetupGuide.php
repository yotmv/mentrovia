<?php

namespace App\Services;

use App\Enums\LegalStructure;
use App\Models\Business;
use App\Models\BusinessProfile;
use Illuminate\Support\Collection;

class BankingSetupGuide
{
    private const string DoneValue = 'done';

    /**
     * @return list<string>
     */
    public static function completableKeys(): array
    {
        return [
            'dedicated-checking',
            'tax-reserve',
            'sales-tax-reserve',
            'payroll-reserve',
            'separate-card',
            'merchant-services',
        ];
    }

    public static function canCompleteKey(string $key): bool
    {
        return in_array($key, self::completableKeys(), true);
    }

    public function advise(Business $business): BankingSetupAdvice
    {
        $business->loadMissing('profileAnswers');

        $completed = $this->completedProfileKeys($business);
        $hasTaxableSales = $business->mayHaveTaxableSales();
        $hasPayrollNeed = $business->employee_count > 0 || $business->has_payroll;

        $checklist = [
            new BankingChecklistItem(
                key: 'dedicated-checking',
                title: __('Open a dedicated business checking account'),
                description: __('Route all customer income and business expenses through this account so your books, taxes, and entity separation stay clean.'),
                completed: $business->has_business_bank,
            ),
            new BankingChecklistItem(
                key: 'tax-reserve',
                title: __('Create a tax reserve savings account'),
                description: __('Move a planned percentage of each deposit into savings for federal income tax, self-employment tax, and other owner taxes.'),
                completed: $completed->contains('tax-reserve'),
            ),
            new BankingChecklistItem(
                key: 'separate-card',
                title: __('Use a separate business card for expenses'),
                description: __('Keep software, supplies, travel, and contractor costs off personal cards so statements match your bookkeeping.'),
                completed: $completed->contains('separate-card'),
            ),
            new BankingChecklistItem(
                key: 'merchant-services',
                title: __('Point merchant services to business checking'),
                description: __('Connect Stripe, Square, marketplace, or payment processor payouts to the business account instead of a personal account.'),
                completed: $completed->contains('merchant-services'),
            ),
        ];

        if ($hasTaxableSales) {
            $checklist[] = new BankingChecklistItem(
                key: 'sales-tax-reserve',
                title: __('Add a sales tax reserve'),
                description: __('If you collect Texas sales tax, keep collected tax out of operating cash and treat it as money held for the Comptroller.'),
                completed: $completed->contains('sales-tax-reserve'),
                recommended: $business->has_sales_tax_permit->isYes(),
            );
        }

        if ($hasPayrollNeed) {
            $checklist[] = new BankingChecklistItem(
                key: 'payroll-reserve',
                title: __('Add a payroll reserve'),
                description: __('Keep wages, employer taxes, and payroll provider debits funded before payroll runs.'),
                completed: $completed->contains('payroll-reserve'),
                recommended: $business->has_payroll,
            );
        }

        return new BankingSetupAdvice(
            checklist: $checklist,
            documents: $this->documents($business),
            warnings: $this->warnings($business),
            articleSlugs: [
                'business-banking-separation',
                'basic-bookkeeping-setup',
                'texas-sales-tax-permit-basics',
                'first-employee-checklist-texas',
            ],
        );
    }

    public function markCompleted(Business $business, string $key, bool $completed): void
    {
        if (! self::canCompleteKey($key)) {
            return;
        }

        if ($key === 'dedicated-checking') {
            $business->forceFill(['has_business_bank' => $completed])->save();

            return;
        }

        $questionKey = $this->profileKey($key);

        if (! $completed) {
            $business->profileAnswers()->where('question_key', $questionKey)->delete();

            return;
        }

        $business->profileAnswers()->updateOrCreate(
            ['question_key' => $questionKey],
            ['answer_value' => self::DoneValue, 'confidence' => 'user_confirmed'],
        );
    }

    /**
     * @return list<BankingDocumentItem>
     */
    private function documents(Business $business): array
    {
        $documents = [
            new BankingDocumentItem(
                title: __('Personal identification for every signer'),
                description: __('Bring government ID and any owner/signer information the bank requests.'),
                ready: true,
                status: __('Always needed'),
            ),
            new BankingDocumentItem(
                title: $business->has_ein->isYes()
                    ? __('EIN confirmation letter')
                    : __('Get or confirm an EIN before the bank visit'),
                description: $business->has_ein->isYes()
                    ? __('Use the IRS confirmation letter or bank-accepted proof for the business tax ID.')
                    : __('Many banks ask for an EIN, and it keeps your SSN off business paperwork. Sole proprietors may have alternatives, but confirming first avoids a wasted appointment.'),
                ready: $business->has_ein->isYes(),
                status: $business->has_ein->label(),
            ),
        ];

        if ($business->legal_structure->isFormalEntity()) {
            $documents[] = new BankingDocumentItem(
                title: __('Formation documents'),
                description: __('Bring the certificate of formation, filing acknowledgement, or equivalent entity records for the bank file.'),
                ready: ! $business->legal_structure->isUndecided(),
                status: $business->legal_structure->label(),
            );
        }

        if ($business->legal_structure === LegalStructure::Llc || $business->legal_structure === LegalStructure::Partnership) {
            $documents[] = new BankingDocumentItem(
                title: $business->legal_structure === LegalStructure::Llc
                    ? __('Operating agreement')
                    : __('Partnership agreement'),
                description: __('Banks often ask who can sign and who owns the company; the agreement is the cleanest proof.'),
                ready: true,
                status: __('Prepare before appointment'),
            );
        }

        if ($business->legal_structure === LegalStructure::DbaOnly || $business->dba_status->isYes()) {
            $documents[] = new BankingDocumentItem(
                title: __('Assumed name / DBA certificate'),
                description: __('Bring county or state assumed-name records if customers see a name different from the legal owner or entity name.'),
                ready: $business->dba_status->isYes() || $business->legal_structure === LegalStructure::DbaOnly,
                status: __('DBA path'),
            );
        } elseif ($business->dba_status->isUnsure()) {
            $documents[] = new BankingDocumentItem(
                title: __('Confirm DBA / assumed name status'),
                description: __('If the public business name differs from the legal owner or entity name, ask whether a certificate is needed before opening the account.'),
                ready: false,
                status: __('Not sure'),
            );
        }

        if ($business->mayHaveTaxableSales()) {
            $documents[] = new BankingDocumentItem(
                title: __('Sales tax permit status'),
                description: __('If you collect sales tax, make sure bank accounts and bookkeeping separate collected tax from operating revenue.'),
                ready: $business->has_sales_tax_permit->isYes(),
                status: $business->has_sales_tax_permit->label(),
            );
        }

        if ($business->employee_count > 0 || $business->has_payroll) {
            $documents[] = new BankingDocumentItem(
                title: __('Payroll provider debit details'),
                description: __('Know which account funds payroll, employer taxes, and provider fees before the first payroll run.'),
                ready: $business->has_payroll,
                status: $business->has_payroll ? __('Payroll set up') : __('Needs setup'),
            );
        }

        return $documents;
    }

    /**
     * @return list<string>
     */
    private function warnings(Business $business): array
    {
        $warnings = [];

        if (! $business->has_business_bank && $business->isOperating()) {
            $warnings[] = __('You are operating without a dedicated business bank account. Prioritize separation before more income or expenses run through personal accounts.');
        }

        if (! $business->has_ein->isYes()) {
            $warnings[] = __('Your profile does not show an EIN. Confirm whether your structure needs one before scheduling a bank appointment.');
        }

        if ($business->mayHaveTaxableSales() && ! $business->has_sales_tax_permit->isYes()) {
            $warnings[] = __('Your profile suggests possible taxable sales. Confirm permit status before treating sales tax reserves as routine collected tax.');
        }

        if ($business->employee_count > 0 && ! $business->has_payroll) {
            $warnings[] = __('Employees are on your profile but payroll is not set up. Do not run wages through ordinary transfers.');
        }

        return $warnings;
    }

    /**
     * @return Collection<int, string>
     */
    private function completedProfileKeys(Business $business): Collection
    {
        return $business->profileAnswers
            ->filter(fn (BusinessProfile $answer): bool => str_starts_with($answer->question_key, 'banking_setup.')
                && $answer->answer_value === self::DoneValue)
            ->map(fn (BusinessProfile $answer): string => (string) str($answer->question_key)->after('banking_setup.'))
            ->values();
    }

    private function profileKey(string $key): string
    {
        return 'banking_setup.'.$key;
    }
}
