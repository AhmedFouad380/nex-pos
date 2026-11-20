<?php

namespace App\Domain\Inventory\Enums;

use App\Support\Enums\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum SerialStatus: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case InStock = 'in_stock';
    case Sold = 'sold';
    case Returned = 'returned';
    case Damaged = 'damaged';

    public function getLabel(): string
    {
        return __('enums.serial_status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::InStock => 'success',
            self::Sold => 'gray',
            self::Returned => 'warning',
            self::Damaged => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::InStock => 'heroicon-m-archive-box',
            self::Sold => 'heroicon-m-check-circle',
            self::Returned => 'heroicon-m-arrow-uturn-left',
            self::Damaged => 'heroicon-m-exclamation-triangle',
        };
    }
}
