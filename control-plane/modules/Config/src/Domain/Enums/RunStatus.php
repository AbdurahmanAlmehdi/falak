<?php

namespace Falak\Config\Domain\Enums;

enum RunStatus: string
{
    case Planning = 'planning';
    case AwaitingApproval = 'awaiting_approval';
    case Applying = 'applying';
    case Applied = 'applied';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    /** A risky plan while the source is experimental (until approvals land in phase 4). */
    case Refused = 'refused';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Applied, self::Failed, self::Cancelled, self::Refused], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Planning => 'Planning',
            self::AwaitingApproval => 'Awaiting approval',
            self::Applying => 'Applying',
            self::Applied => 'Applied',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
            self::Refused => 'Refused',
        };
    }
}
