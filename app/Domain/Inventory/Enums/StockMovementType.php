<?php

namespace App\Domain\Inventory\Enums;

use App\Support\Enums\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum StockMovementType: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Opening = 'opening';
    case Purchase = 'purchase';
    case PurchaseReturn = 'purchase_return';
    case Sale = 'sale';
    case SaleReturn = 'sale_return';
    case Adjustment = 'adjustment';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';
    case Count = 'count';

    public function getLabel(): string
    {
        return __('enums.stock_movement_type.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Opening => 'gray',
            self::Purchase => 'success',
            self::PurchaseReturn => 'danger',
            self::Sale => 'danger',
            self::SaleReturn => 'success',
            self::Adjustment => 'warning',
            self::TransferIn => 'info',
            self::TransferOut => 'info',
            self::Count => 'warning',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Opening => 'heroicon-m-flag',
            self::Purchase => 'heroicon-m-shopping-cart',
            self::PurchaseReturn => 'heroicon-m-arrow-uturn-right',
            self::Sale => 'heroicon-m-shopping-bag',
            self::SaleReturn => 'heroicon-m-arrow-uturn-left',
            self::Adjustment => 'heroicon-m-adjustments-horizontal',
            self::TransferIn => 'heroicon-m-arrow-down-on-square',
            self::TransferOut => 'heroicon-m-arrow-up-on-square',
            self::Count => 'heroicon-m-clipboard-document-check',
        };
    }
}
