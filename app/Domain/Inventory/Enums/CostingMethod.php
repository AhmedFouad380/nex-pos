<?php

namespace App\Domain\Inventory\Enums;

use App\Support\Enums\EnumHelpers;
use Filament\Support\Contracts\HasLabel;

enum CostingMethod: string implements HasLabel
{
    use EnumHelpers;

    case Fifo = 'fifo';
    case Lifo = 'lifo';
    case Avg = 'avg';

    public function getLabel(): string
    {
        return __('enums.costing_method.'.$this->value);
    }
}
