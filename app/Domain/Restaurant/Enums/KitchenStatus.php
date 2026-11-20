<?php

namespace App\Domain\Restaurant\Enums;

use App\Support\Enums\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum KitchenStatus: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Pending = 'pending';
    case Cooking = 'cooking';
    case Ready = 'ready';
    case Served = 'served';

    public function getLabel(): string
    {
        return __('enums.kitchen_status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Cooking => 'warning',
            self::Ready => 'success',
            self::Served => 'info',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Pending => 'heroicon-m-clock',
            self::Cooking => 'heroicon-m-fire',
            self::Ready => 'heroicon-m-bell-alert',
            self::Served => 'heroicon-m-check-circle',
        };
    }
}
