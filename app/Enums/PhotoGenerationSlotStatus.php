<?php

namespace App\Enums;

enum PhotoGenerationSlotStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Claimed = 'claimed';
    case ProviderStarted = 'provider_started';
    case Staged = 'staged';
    case Completed = 'completed';
    case Failed = 'failed';
    case Ambiguous = 'ambiguous';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Ambiguous], true);
    }
}
