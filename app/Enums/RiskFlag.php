<?php

namespace App\Enums;

enum RiskFlag: string
{
    case PersonalBankCommingling = 'personal_bank_commingling';
    case MissingEin = 'missing_ein';
    case SalesTaxPermitGap = 'sales_tax_permit_gap';
    case NoBookkeeping = 'no_bookkeeping';
    case EmployeesWithoutPayroll = 'employees_without_payroll';
    case UnclearLegalStructure = 'unclear_legal_structure';
    case OperatingWithoutEntityDecision = 'operating_without_entity_decision';

    public function label(): string
    {
        return match ($this) {
            self::PersonalBankCommingling => 'Personal and business funds may be mixed',
            self::MissingEin => 'No EIN on file',
            self::SalesTaxPermitGap => 'Possible sales tax permit gap',
            self::NoBookkeeping => 'No bookkeeping system',
            self::EmployeesWithoutPayroll => 'Employees without a payroll setup',
            self::UnclearLegalStructure => 'Legal structure is unclear',
            self::OperatingWithoutEntityDecision => 'Operating without an entity decision',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::PersonalBankCommingling => 'Running business activity through a personal bank account makes bookkeeping, taxes, and liability separation harder. Open a dedicated business account.',
            self::MissingEin => 'Many businesses need an EIN for banking, hiring, and tax filings. Confirm whether yours does and apply free through the IRS.',
            self::SalesTaxPermitGap => 'You may be selling taxable goods or services without a Texas sales tax permit. Confirm taxability with the Texas Comptroller or a CPA before collecting or skipping sales tax.',
            self::NoBookkeeping => 'Without a bookkeeping system, tax filings, sales tax tracking, and financial decisions get harder every month. Set one up early.',
            self::EmployeesWithoutPayroll => 'Employees create federal and Texas employer obligations. Set up a payroll provider and confirm Texas Workforce Commission registration.',
            self::UnclearLegalStructure => 'Not knowing your legal structure makes tax, banking, and liability guidance unreliable. Review your filings or ask a professional to confirm.',
            self::OperatingWithoutEntityDecision => 'You are doing business before deciding on a legal structure. That may be fine, but review the trade-offs with an attorney or CPA.',
        };
    }
}
