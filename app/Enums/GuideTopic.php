<?php

namespace App\Enums;

enum GuideTopic: string
{
    case Formation = 'formation';
    case SalesTax = 'sales-tax';
    case Bookkeeping = 'bookkeeping';
    case Payroll = 'payroll';
    case Banking = 'banking';
    case OwnerPay = 'owner-pay';

    public function label(): string
    {
        return match ($this) {
            self::Formation => 'Formation and DBA',
            self::SalesTax => 'Sales-tax readiness',
            self::Bookkeeping => 'Bookkeeping setup',
            self::Payroll => 'First hire, payroll, and contractors',
            self::Banking => 'Business banking',
            self::OwnerPay => 'Owner pay',
        };
    }

    public function summary(): string
    {
        return match ($this) {
            self::Formation => 'Choose a structure, confirm registrations, and establish the records your business needs to operate clearly.',
            self::SalesTax => 'Understand whether Texas sales-tax obligations may apply before a filing or collection deadline surprises you.',
            self::Bookkeeping => 'Build a simple financial recordkeeping habit that makes taxes, pricing, and decisions easier to manage.',
            self::Payroll => 'Prepare for employee and contractor responsibilities before they become urgent payroll or reporting work.',
            self::Banking => 'Separate business money and create an account structure that supports better records and reserves.',
            self::OwnerPay => 'Understand the owner-pay methods that fit your structure before choosing how to take money from the business.',
        };
    }

    /** @return list<string> */
    public function roadmapItemKeys(): array
    {
        return match ($this) {
            self::Formation => ['decide-legal-structure', 'form-entity-or-register', 'file-assumed-name', 'get-ein', 'operating-agreement', 'licenses-and-permits'],
            self::SalesTax => ['sales-tax-permit', 'franchise-tax-awareness', 'federal-tax-planning'],
            self::Bookkeeping => ['bookkeeping-system', 'receipt-retention', 'monthly-close-routine'],
            self::Payroll => ['payroll-provider', 'twc-registration', 'new-hire-basics', 'workers-comp-decision', 'contractor-w9s'],
            self::Banking => ['business-bank-account', 'tax-reserve-account'],
            self::OwnerPay => ['owner-pay-method'],
        };
    }

    /** @return list<ArticleCategory> */
    public function articleCategories(): array
    {
        return match ($this) {
            self::Formation => [ArticleCategory::Formation],
            self::SalesTax => [ArticleCategory::SalesTax, ArticleCategory::FranchiseTax],
            self::Bookkeeping => [ArticleCategory::Accounting],
            self::Payroll => [ArticleCategory::Payroll, ArticleCategory::Contractors],
            self::Banking => [ArticleCategory::Banking],
            self::OwnerPay => [ArticleCategory::OwnerPay],
        };
    }

    /** @return list<TaskCategory> */
    public function taskCategories(): array
    {
        return match ($this) {
            self::Formation => [TaskCategory::Compliance],
            self::SalesTax => [TaskCategory::SalesTax, TaskCategory::TaxPlanning],
            self::Bookkeeping => [TaskCategory::Bookkeeping],
            self::Payroll => [TaskCategory::Payroll, TaskCategory::Contractors],
            self::Banking, self::OwnerPay => [TaskCategory::Operations],
        };
    }

    public function reviewerLabel(): string
    {
        return match ($this) {
            self::Formation => 'Attorney or CPA',
            self::SalesTax, self::Bookkeeping, self::OwnerPay => 'CPA',
            self::Payroll => 'Payroll provider or CPA',
            self::Banking => 'Bank or CPA',
        };
    }
}
