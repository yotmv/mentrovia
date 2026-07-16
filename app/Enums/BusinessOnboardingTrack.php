<?php

namespace App\Enums;

enum BusinessOnboardingTrack: string
{
    case NewCompany = 'new_company';

    case EstablishedCompany = 'established_company';

    public function label(): string
    {
        return match ($this) {
            self::NewCompany => __('Starting a company'),
            self::EstablishedCompany => __('Already running a company'),
        };
    }

    public function stepCount(): int
    {
        return match ($this) {
            self::NewCompany => 5,
            self::EstablishedCompany => 3,
        };
    }
}
