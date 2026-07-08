<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum FilingConfidence: string
{
    use HasOptions;

    case NoIdea = 'no_idea';
    case SomeKnowledge = 'some_knowledge';
    case HasProfessional = 'has_professional';
    case MostlySetUp = 'mostly_set_up';

    public function label(): string
    {
        return match ($this) {
            self::NoIdea => 'I have no idea',
            self::SomeKnowledge => 'I know some things',
            self::HasProfessional => 'I have a CPA / bookkeeper',
            self::MostlySetUp => 'I am mostly set up',
        };
    }
}
