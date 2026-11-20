<?php

namespace App\Domain\Finance\Enums;

use App\Support\Enums\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum PaymentDirection: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case In = 'in';
    case Out = 'out';

    public function getLabel(): string
    {
        return __('enums.payment_direction.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::In => 'success',
            self::Out => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::In => 'heroicon-m-arrow-down-tray',
            self::Out => 'heroicon-m-arrow-up-tray',
        };
    }
}
