<?php

namespace App\Domain\Sales\Enums;

use App\Support\Enums\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum SalesOrderStatus: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Open = 'open';
    case Partial = 'partial';
    case Invoiced = 'invoiced';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return __('enums.sales_order_status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'info',
            self::Partial => 'warning',
            self::Invoiced => 'success',
            self::Cancelled => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Open => 'heroicon-m-inbox',
            self::Partial => 'heroicon-m-ellipsis-horizontal-circle',
            self::Invoiced => 'heroicon-m-check-circle',
            self::Cancelled => 'heroicon-m-x-circle',
        };
    }
}
