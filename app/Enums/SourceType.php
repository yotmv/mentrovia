<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum SourceType: string
{
    use HasOptions;

    case StateAgency = 'state_agency';
    case FederalAgency = 'federal_agency';
    case CountyClerk = 'county_clerk';
    case CityGovernment = 'city_government';
    case Statute = 'statute';
    case Educational = 'educational';

    public function label(): string
    {
        return match ($this) {
            self::StateAgency => 'State agency',
            self::FederalAgency => 'Federal agency',
            self::CountyClerk => 'County clerk',
            self::CityGovernment => 'City government',
            self::Statute => 'Statute',
            self::Educational => 'Educational',
        };
    }
}
