<?php

namespace App\Domain\Purchasing\Enums;

use App\Support\Enums\EnumHelpers;
use Filament\Support\Contracts\HasLabel;

enum CostDistribution: string implements HasLabel
{
    use EnumHelpers;

    case Value = 'value';
    case Qty = 'qty';
    case Weight = 'weight';

    public function getLabel(): string
    {
        return __('enums.cost_distribution.'.$this->value);
    }
}
