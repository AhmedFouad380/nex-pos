<?php

namespace App\Domain\Inventory\Enums;

use App\Support\Enums\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum StockAdjustmentType: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Opening = 'opening';
    case Increase = 'increase';
    case Decrease = 'decrease';
    case Damage = 'damage';
    case Expired = 'expired';

    public function getLabel(): string
    {
        return __('enums.stock_adjustment_type.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Opening => 'gray',
            self::Increase => 'success',
            self::Decrease => 'danger',
            self::Damage => 'danger',
            self::Expired => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Opening => 'heroicon-m-flag',
            self::Increase => 'heroicon-m-plus-circle',
            self::Decrease => 'heroicon-m-minus-circle',
            self::Damage => 'heroicon-m-exclamation-triangle',
            self::Expired => 'heroicon-m-calendar-days',
        };
    }
}
