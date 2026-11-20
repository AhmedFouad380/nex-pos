<?php

namespace App\Domain\Sales\Enums;

use App\Support\Enums\EnumHelpers;
use Filament\Support\Contracts\HasLabel;

enum SaleChannel: string implements HasLabel
{
    use EnumHelpers;

    case Pos = 'pos';
    case Direct = 'direct';
    case Import = 'import';
    case Online = 'online';

    public function getLabel(): string
    {
        return __('enums.sale_channel.'.$this->value);
    }
}
