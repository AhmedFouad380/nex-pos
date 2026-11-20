<?php

namespace App\Domain\Catalog\Enums;

use App\Support\Enums\EnumHelpers;
use Filament\Support\Contracts\HasLabel;

enum BarcodeType: string implements HasLabel
{
    use EnumHelpers;

    case C128 = 'C128';
    case C39 = 'C39';
    case EAN13 = 'EAN13';
    case EAN8 = 'EAN8';
    case UPCA = 'UPCA';
    case UPCE = 'UPCE';

    public function getLabel(): string
    {
        return __('enums.barcode_type.'.$this->value);
    }
}
