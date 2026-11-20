<?php

namespace App\Domain\Payroll\Enums;

use App\Support\Enums\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum AdvanceStatus: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Open = 'open';
    case Settled = 'settled';

    public function getLabel(): string
    {
        return __('enums.advance_status.'.$this->value);
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Open => 'heroicon-m-clock',
            self::Settled => 'heroicon-m-check-circle',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'warning',
            self::Settled => 'success',
        };
    }
}
