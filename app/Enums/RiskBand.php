<?php

declare(strict_types=1);

namespace App\Enums;

enum RiskBand: string
{
    case A = 'A';
    case B = 'B';
    case C = 'C';
    case D = 'D';
}