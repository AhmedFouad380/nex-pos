<?php

namespace App\Domain\Inventory\Enums;

use App\Support\Enums\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum StockTransferStatus: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Draft = 'draft';
    case InTransit = 'in_transit';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return __('enums.stock_transfer_status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::InTransit => 'warning',
            self::Received => 'success',
            self::Cancelled => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Draft => 'heroicon-m-pencil-square',
            self::InTransit => 'heroicon-m-truck',
            self::Received => 'heroicon-m-check-circle',
            self::Cancelled => 'heroicon-m-x-circle',
        };
    }
}
