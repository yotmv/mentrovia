<?php

namespace App\Enums;

enum AccountEntitlementStatus: string
{
    case Active = 'active';
    case Trialing = 'trialing';
    case Suspended = 'suspended';
    case Canceled = 'canceled';
}
