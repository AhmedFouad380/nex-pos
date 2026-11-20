<?php

namespace App\Domain\CashManagement\Enums;

use App\Support\Enums\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum CashSessionStatus: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Open = 'open';
    case Closed = 'closed';

    public function getLabel(): string
    {
        return __('enums.cash_session_status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'success',
            self::Closed => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Open => 'heroicon-m-lock-open',
            self::Closed => 'heroicon-m-lock-closed',
        };
    }
}
