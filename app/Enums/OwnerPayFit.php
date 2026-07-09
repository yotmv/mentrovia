<?php

namespace App\Enums;

/**
 * How well an owner-pay method fits a business's legal and tax structure.
 */
enum OwnerPayFit: string
{
    case Typical = 'typical';
    case Available = 'available';
    case NotAvailable = 'not_available';
    case DependsOnStructure = 'depends_on_structure';

    public function label(): string
    {
        return match ($this) {
            self::Typical => 'Typical for you',
            self::Available => 'Available',
            self::NotAvailable => 'Not available',
            self::DependsOnStructure => 'Depends on structure',
        };
    }

    /**
     * Whether the method should be presented as an option rather than ruled
     * out. DependsOnStructure counts: those entries are informational for
     * undecided profiles, not confirmed usable.
     */
    public function isAvailable(): bool
    {
        return $this !== self::NotAvailable;
    }
}
