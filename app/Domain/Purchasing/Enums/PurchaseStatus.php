<?php

namespace App\Domain\Purchasing\Enums;

use App\Support\Enums\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum PurchaseStatus: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Pending = 'pending';
    case Received = 'received';

    public function getLabel(): string
    {
        return __('enums.purchase_status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Received => 'success',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Pending => 'heroicon-m-clock',
            self::Received => 'heroicon-m-check-circle',
        };
    }
}
