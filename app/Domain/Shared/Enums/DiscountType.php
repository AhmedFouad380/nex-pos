<?php

namespace App\Domain\Shared\Enums;

use App\Support\Enums\EnumHelpers;
use Filament\Support\Contracts\HasLabel;

enum DiscountType: string implements HasLabel
{
    use EnumHelpers;

    case Fixed = 'fixed';
    case Percent = 'percent';

    public function getLabel(): string
    {
        return __('enums.discount_type.'.$this->value);
    }
}
