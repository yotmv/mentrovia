<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ArticleCategory: string
{
    use HasOptions;

    case Formation = 'formation';
    case SalesTax = 'sales_tax';
    case FranchiseTax = 'franchise_tax';
    case Banking = 'banking';
    case Accounting = 'accounting';
    case Payroll = 'payroll';
    case OwnerPay = 'owner_pay';
    case Contractors = 'contractors';
    case RecurringTasks = 'recurring_tasks';
    case Branding = 'branding';
    case Advertising = 'advertising';
    case Professionals = 'professionals';

    public function label(): string
    {
        return match ($this) {
            self::Formation => 'Formation',
            self::SalesTax => 'Sales tax',
            self::FranchiseTax => 'Franchise tax',
            self::Banking => 'Banking',
            self::Accounting => 'Accounting',
            self::Payroll => 'Payroll',
            self::OwnerPay => 'Owner pay',
            self::Contractors => 'Contractors',
            self::RecurringTasks => 'Recurring tasks',
            self::Branding => 'Branding',
            self::Advertising => 'Advertising',
            self::Professionals => 'Professionals',
        };
    }
}
