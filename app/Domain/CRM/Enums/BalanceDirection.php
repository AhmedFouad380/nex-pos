<?php

namespace App\Domain\CRM\Enums;

use App\Support\Enums\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum BalanceDirection: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Receivable = 'receivable';
    case Payable = 'payable';

    public function getLabel(): string
    {
        return __('enums.balance_direction.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Receivable => 'success',
            self::Payable => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Receivable => 'heroicon-m-arrow-down-left',
            self::Payable => 'heroicon-m-arrow-up-right',
        };
    }
}
