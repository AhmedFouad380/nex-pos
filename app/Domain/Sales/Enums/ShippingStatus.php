<?php

namespace App\Domain\Sales\Enums;

use App\Support\Enums\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum ShippingStatus: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Ordered = 'ordered';
    case Packed = 'packed';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return __('enums.shipping_status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Ordered => 'gray',
            self::Packed => 'info',
            self::Shipped => 'warning',
            self::Delivered => 'success',
            self::Cancelled => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Ordered => 'heroicon-m-clipboard-document-list',
            self::Packed => 'heroicon-m-archive-box',
            self::Shipped => 'heroicon-m-truck',
            self::Delivered => 'heroicon-m-check-circle',
            self::Cancelled => 'heroicon-m-x-circle',
        };
    }
}
