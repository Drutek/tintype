<?php

namespace App\Enums;

enum Role: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Reviewer = 'reviewer';
    case Viewer = 'viewer';

    public function canReview(): bool
    {
        return $this !== self::Viewer;
    }

    public function canManage(): bool
    {
        return $this === self::Owner || $this === self::Admin;
    }
}
