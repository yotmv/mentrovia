<?php

namespace App\Services;

use App\Enums\OwnerPayFit;

/**
 * The tailored owner-pay guidance for one business: which methods fit its
 * legal/tax structure, what to watch out for, and what to ask a CPA.
 */
final readonly class OwnerPayAdvice
{
    /**
     * @param  list<OwnerPayOption>  $options
     * @param  list<string>  $cpaQuestions
     * @param  list<string>  $articleSlugs
     */
    public function __construct(
        public bool $needsStructureDecision,
        public string $structureSummary,
        public array $options,
        public array $cpaQuestions,
        public array $articleSlugs,
    ) {}

    /**
     * Methods the owner can actually use with the current structure.
     *
     * @return list<OwnerPayOption>
     */
    public function availableOptions(): array
    {
        return array_values(array_filter(
            $this->options,
            fn (OwnerPayOption $option): bool => $option->fit->isAvailable(),
        ));
    }

    /**
     * Methods that do not apply to the current structure, with the reason why.
     *
     * @return list<OwnerPayOption>
     */
    public function unavailableOptions(): array
    {
        return array_values(array_filter(
            $this->options,
            fn (OwnerPayOption $option): bool => $option->fit === OwnerPayFit::NotAvailable,
        ));
    }
}
