<?php

namespace App\Services;

/**
 * One document or decision the owner may need for a bank visit.
 */
final readonly class BankingDocumentItem
{
    public function __construct(
        public string $title,
        public string $description,
        public bool $ready,
        public ?string $status = null,
    ) {}
}
