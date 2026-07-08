<?php

namespace App\Enums;

enum RoadmapPhase: string
{
    case Foundation = 'foundation';
    case LegalSetup = 'legal_setup';
    case Taxes = 'taxes';
    case Banking = 'banking';
    case Accounting = 'accounting';
    case Payroll = 'payroll';
    case OwnerPay = 'owner_pay';
    case Branding = 'branding';
    case Advertising = 'advertising';
    case GrowthReadiness = 'growth_readiness';

    public function label(): string
    {
        return match ($this) {
            self::Foundation => 'Foundation',
            self::LegalSetup => 'Legal setup',
            self::Taxes => 'Taxes',
            self::Banking => 'Banking',
            self::Accounting => 'Accounting',
            self::Payroll => 'Payroll',
            self::OwnerPay => 'Owner pay',
            self::Branding => 'Branding',
            self::Advertising => 'Advertising',
            self::GrowthReadiness => 'Growth readiness',
        };
    }
}
