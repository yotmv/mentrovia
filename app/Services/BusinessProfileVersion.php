<?php

namespace App\Services;

use App\Models\Business;

class BusinessProfileVersion
{
    public function __construct(private BusinessProfileVersionService $versions) {}

    public function issue(Business $business): string
    {
        return $this->versions->issue($business);
    }
}
