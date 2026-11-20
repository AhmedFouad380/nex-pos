<?php

namespace App\Domain\Catalog\Enums;

use App\Support\Enums\EnumHelpers;
use Filament\Support\Contracts\HasLabel;

enum PriceType: string implements HasLabel
{
    use EnumHelpers;

    case Fixed = 'fixed';
    case Percent = 'percent';

    public function getLabel(): string
    {
        return __('enums.price_type.'.$this->value);
    }
}
