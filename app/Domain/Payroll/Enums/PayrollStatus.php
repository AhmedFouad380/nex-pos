<?php

namespace App\Domain\Payroll\Enums;

use App\Support\Enums\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum PayrollStatus: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Draft = 'draft';
    case Approved = 'approved';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';

    public function getLabel(): string
    {
        return __('enums.payroll_status.'.$this->value);
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Draft => 'heroicon-m-pencil-square',
            self::Approved => 'heroicon-m-check-badge',
            self::PartiallyPaid => 'heroicon-m-clock',
            self::Paid => 'heroicon-m-check-circle',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Approved => 'info',
            self::PartiallyPaid => 'warning',
            self::Paid => 'success',
        };
    }

    /** Approved or (partly) paid: the accrual entry is posted. */
    public function isPosted(): bool
    {
        return $this !== self::Draft;
    }
}
