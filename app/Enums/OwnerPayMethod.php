<?php

namespace App\Enums;

enum OwnerPayMethod: string
{
    case OwnerDraw = 'owner_draw';
    case Distribution = 'distribution';
    case GuaranteedPayment = 'guaranteed_payment';
    case W2Salary = 'w2_salary';
    case Dividend = 'dividend';
    case RetainedEarnings = 'retained_earnings';
    case AccountablePlanReimbursement = 'accountable_plan_reimbursement';

    public function label(): string
    {
        return match ($this) {
            self::OwnerDraw => 'Owner draw',
            self::Distribution => 'Distribution',
            self::GuaranteedPayment => 'Guaranteed payment',
            self::W2Salary => 'W-2 salary',
            self::Dividend => 'Dividend',
            self::RetainedEarnings => 'Retained earnings',
            self::AccountablePlanReimbursement => 'Accountable plan reimbursement',
        };
    }
}
