<?php

namespace App\Domain\CRM\Enums;

use App\Support\Enums\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum LoyaltyTransactionType: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Earn = 'earn';
    case Redeem = 'redeem';
    case Expire = 'expire';
    case Adjust = 'adjust';

    public function getLabel(): string
    {
        return __('enums.loyalty_transaction_type.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Earn => 'success',
            self::Redeem => 'info',
            self::Expire => 'danger',
            self::Adjust => 'warning',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Earn => 'heroicon-m-plus-circle',
            self::Redeem => 'heroicon-m-gift',
            self::Expire => 'heroicon-m-clock',
            self::Adjust => 'heroicon-m-adjustments-horizontal',
        };
    }
}
