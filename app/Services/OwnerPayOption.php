<?php

namespace App\Services;

use App\Enums\OwnerPayFit;
use App\Enums\OwnerPayMethod;

/**
 * A single owner-pay method assessed against a business's structure.
 */
final readonly class OwnerPayOption
{
    /**
     * @param  list<string>  $caveats
     */
    public function __construct(
        public OwnerPayMethod $method,
        public OwnerPayFit $fit,
        public string $summary,
        public array $caveats = [],
    ) {}
}
