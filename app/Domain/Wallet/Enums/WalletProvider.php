<?php

namespace App\Domain\Wallet\Enums;

use App\Support\Enums\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum WalletProvider: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case VodafoneCash = 'vodafone_cash';
    case EtisalatCash = 'etisalat_cash';
    case OrangeCash = 'orange_cash';
    case InstaPay = 'instapay';

    public function getLabel(): string
    {
        return __('enums.wallet_provider.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::VodafoneCash => 'danger',
            self::EtisalatCash => 'success',
            self::OrangeCash => 'warning',
            self::InstaPay => 'info',
        };
    }

    public function getIcon(): string
    {
        return $this === self::InstaPay ? 'heroicon-m-building-library' : 'heroicon-m-device-phone-mobile';
    }
}
