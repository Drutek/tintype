<?php

namespace App\Enums;

enum ReviewState: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Denied = 'denied';
}
