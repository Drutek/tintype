<?php

namespace App\Enums;

enum SnapshotStatus: string
{
    case New = 'new';
    case Unchanged = 'unchanged';
    case Changed = 'changed';
    case Error = 'error';
    case Skipped = 'skipped';
    case Removed = 'removed';
    case Inconsistent = 'inconsistent';

    public function needsReview(): bool
    {
        return $this === self::New || $this === self::Changed;
    }
}
