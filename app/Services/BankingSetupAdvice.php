<?php

namespace App\Services;

/**
 * Profile-aware banking setup guidance for one business.
 */
final readonly class BankingSetupAdvice
{
    /**
     * @param  list<BankingChecklistItem>  $checklist
     * @param  list<BankingDocumentItem>  $documents
     * @param  list<string>  $warnings
     * @param  list<string>  $articleSlugs
     */
    public function __construct(
        public array $checklist,
        public array $documents,
        public array $warnings,
        public array $articleSlugs,
    ) {}

    public function completedCount(): int
    {
        return count(array_filter(
            $this->checklist,
            fn (BankingChecklistItem $item): bool => $item->completed,
        ));
    }

    public function checklistCount(): int
    {
        return count($this->checklist);
    }
}
