<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum LegalStructure: string
{
    use HasOptions;

    case NotStarted = 'not_started';
    case SoleProprietor = 'sole_proprietor';
    case DbaOnly = 'dba_only';
    case Llc = 'llc';
    case Partnership = 'partnership';
    case SCorporation = 's_corporation';
    case CCorporation = 'c_corporation';
    case Unsure = 'unsure';

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Not started yet',
            self::SoleProprietor => 'Sole proprietor',
            self::DbaOnly => 'DBA only',
            self::Llc => 'LLC',
            self::Partnership => 'Partnership',
            self::SCorporation => 'S corporation election',
            self::CCorporation => 'C corporation',
            self::Unsure => 'Unsure',
        };
    }

    /**
     * Whether this structure is a formal registered entity.
     */
    public function isFormalEntity(): bool
    {
        return match ($this) {
            self::Llc, self::Partnership, self::SCorporation, self::CCorporation => true,
            default => false,
        };
    }

    /**
     * Whether the owner has not yet decided on (or does not know) their structure.
     */
    public function isUndecided(): bool
    {
        return $this === self::NotStarted || $this === self::Unsure;
    }
}
