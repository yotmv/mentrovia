<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum TaskCategory: string
{
    use HasOptions;

    case Operations = 'operations';
    case Bookkeeping = 'bookkeeping';
    case SalesTax = 'sales_tax';
    case Payroll = 'payroll';
    case Contractors = 'contractors';
    case TaxPlanning = 'tax_planning';
    case Compliance = 'compliance';

    public function label(): string
    {
        return match ($this) {
            self::Operations => 'Operations',
            self::Bookkeeping => 'Bookkeeping',
            self::SalesTax => 'Sales tax',
            self::Payroll => 'Payroll',
            self::Contractors => 'Contractors',
            self::TaxPlanning => 'Tax planning',
            self::Compliance => 'Compliance',
        };
    }
}
