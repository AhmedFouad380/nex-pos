<?php

namespace App\Domain\Printing\Enums;

use App\Support\Enums\EnumHelpers;
use Filament\Support\Contracts\HasLabel;

enum PrinterConnection: string implements HasLabel
{
    use EnumHelpers;

    case Browser = 'browser';
    case Network = 'network';
    case Qz = 'qz';

    public function getLabel(): string
    {
        return __('enums.printer_connection.'.$this->value);
    }
}
