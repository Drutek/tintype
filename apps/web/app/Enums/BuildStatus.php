<?php

namespace App\Enums;

enum BuildStatus: string
{
    case Uploading = 'uploading';
    case Queued = 'queued';
    case Capturing = 'capturing';
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Denied = 'denied';
    case Passed = 'passed';
    case Broken = 'broken';
    case Failed = 'failed';
    case Canceled = 'canceled';
    /** A newer build on the same branch finished before this one was reviewed. */
    case Superseded = 'superseded';

    /** Capture is finished and snapshots exist (may still be under review). */
    public function isComplete(): bool
    {
        return in_array($this, [self::Pending, self::Accepted, self::Denied, self::Passed, self::Broken, self::Superseded], true);
    }

    public function isInProgress(): bool
    {
        return in_array($this, [self::Uploading, self::Queued, self::Capturing], true);
    }

    /** CLI exit code / CI conclusion. */
    public function exitCode(): int
    {
        return match ($this) {
            self::Passed, self::Accepted => 0,
            self::Pending, self::Denied, self::Superseded => 1,
            self::Broken, self::Failed, self::Canceled => 2,
            default => 0,
        };
    }
}
