<?php

namespace App\Domain\Shared\Enums;

use App\Support\Enums\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum PaymentStatus: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Due = 'due';
    case Partial = 'partial';
    case Paid = 'paid';

    public function getLabel(): string
    {
        return __('enums.payment_status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Due => 'danger',
            self::Partial => 'warning',
            self::Paid => 'success',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Due => 'heroicon-m-exclamation-circle',
            self::Partial => 'heroicon-m-clock',
            self::Paid => 'heroicon-m-check-circle',
        };
    }
}
