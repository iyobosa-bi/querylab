<?php

namespace App\Enums;

enum ServiceRequestStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /** Statuses where the job is still "open" (not finished, not cancelled). */
    public static function open(): array
    {
        return [self::Pending, self::Accepted, self::InProgress];
    }
}
