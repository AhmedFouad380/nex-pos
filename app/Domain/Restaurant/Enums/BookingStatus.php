<?php

namespace App\Domain\Restaurant\Enums;

use App\Support\Enums\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum BookingStatus: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Booked = 'booked';
    case Arrived = 'arrived';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return __('enums.booking_status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Booked => 'info',
            self::Arrived => 'warning',
            self::Completed => 'success',
            self::Cancelled => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Booked => 'heroicon-m-calendar',
            self::Arrived => 'heroicon-m-user-plus',
            self::Completed => 'heroicon-m-check-circle',
            self::Cancelled => 'heroicon-m-x-circle',
        };
    }
}
