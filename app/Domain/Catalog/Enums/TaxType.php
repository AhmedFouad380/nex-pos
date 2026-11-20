<?php

namespace App\Domain\Catalog\Enums;

use App\Support\Enums\EnumHelpers;
use Filament\Support\Contracts\HasLabel;

enum TaxType: string implements HasLabel
{
    use EnumHelpers;

    case Inclusive = 'inclusive';
    case Exclusive = 'exclusive';

    public function getLabel(): string
    {
        return __('enums.tax_type.'.$this->value);
    }
}
