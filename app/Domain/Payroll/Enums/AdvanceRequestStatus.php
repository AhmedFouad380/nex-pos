<?php

namespace App\Domain\Payroll\Enums;

use App\Support\Enums\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum AdvanceRequestStatus: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return __('enums.advance_request_status.'.$this->value);
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Pending => 'heroicon-m-clock',
            self::Approved => 'heroicon-m-check-badge',
            self::Rejected => 'heroicon-m-x-circle',
            self::Paid => 'heroicon-m-banknotes',
            self::Cancelled => 'heroicon-m-minus-circle',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'info',
            self::Rejected => 'danger',
            self::Paid => 'success',
            self::Cancelled => 'gray',
        };
    }
}
