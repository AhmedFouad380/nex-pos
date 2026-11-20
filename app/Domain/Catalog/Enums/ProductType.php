<?php

namespace App\Domain\Catalog\Enums;

use App\Support\Enums\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum ProductType: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Single = 'single';
    case Variable = 'variable';
    case Combo = 'combo';
    case Service = 'service';
    case Modifier = 'modifier';

    public function getLabel(): string
    {
        return __('enums.product_type.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Single => 'gray',
            self::Variable => 'info',
            self::Combo => 'warning',
            self::Service => 'success',
            self::Modifier => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Single => 'heroicon-m-cube',
            self::Variable => 'heroicon-m-squares-2x2',
            self::Combo => 'heroicon-m-rectangle-stack',
            self::Service => 'heroicon-m-wrench-screwdriver',
            self::Modifier => 'heroicon-m-puzzle-piece',
        };
    }
}
