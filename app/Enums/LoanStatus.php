<?php

declare(strict_types=1);

namespace App\Enums;

enum LoanStatus: string
{
    case PENDING = 'pending';
    case APPROVAL_HOLD = 'approval_hold';
    case DISBURSED = 'disbursed';
    case RESTRUCTURED = 'restructured';
    case DEFAULTED = 'defaulted';
    case SETTLED = 'settled';
    case WRITTEN_OFF = 'written_off';
}