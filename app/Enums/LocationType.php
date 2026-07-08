<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum LocationType: string
{
    use HasOptions;

    case OnlineOnly = 'online_only';
    case PhysicalLocation = 'physical_location';
    case MobileService = 'mobile_service';
    case Hybrid = 'hybrid';

    public function label(): string
    {
        return match ($this) {
            self::OnlineOnly => 'Online only',
            self::PhysicalLocation => 'Physical location',
            self::MobileService => 'Mobile service',
            self::Hybrid => 'Hybrid',
        };
    }
}
