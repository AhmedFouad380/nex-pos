<?php

namespace App\Domain\Purchasing\Enums;

use App\Support\Enums\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum PurchaseOrderStatus: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Draft = 'draft';
    case Sent = 'sent';
    case Partial = 'partial';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return __('enums.purchase_order_status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Sent => 'info',
            self::Partial => 'warning',
            self::Received => 'success',
            self::Cancelled => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Draft => 'heroicon-m-pencil-square',
            self::Sent => 'heroicon-m-paper-airplane',
            self::Partial => 'heroicon-m-ellipsis-horizontal-circle',
            self::Received => 'heroicon-m-check-circle',
            self::Cancelled => 'heroicon-m-x-circle',
        };
    }
}
