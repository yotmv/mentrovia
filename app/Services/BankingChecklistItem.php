<?php

namespace App\Services;

/**
 * One setup step on the banking module checklist.
 */
final readonly class BankingChecklistItem
{
    public function __construct(
        public string $key,
        public string $title,
        public string $description,
        public bool $completed,
        public bool $recommended = true,
    ) {}
}
