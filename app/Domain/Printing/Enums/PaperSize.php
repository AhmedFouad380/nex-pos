<?php

namespace App\Domain\Printing\Enums;

use App\Support\Enums\EnumHelpers;
use Filament\Support\Contracts\HasLabel;

enum PaperSize: string implements HasLabel
{
    use EnumHelpers;

    case A4 = 'a4';
    case A5 = 'a5';
    case Thermal80 = 'thermal_80';
    case Thermal58 = 'thermal_58';

    public function getLabel(): string
    {
        return __('enums.paper_size.'.$this->value);
    }
}
