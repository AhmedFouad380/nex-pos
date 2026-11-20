<?php

namespace App\Domain\Inventory\Enums;

use App\Support\Enums\EnumHelpers;
use Filament\Support\Contracts\HasLabel;

enum StockCountScope: string implements HasLabel
{
    use EnumHelpers;

    case Full = 'full';
    case Category = 'category';
    case Brand = 'brand';
    case Selected = 'selected';

    public function getLabel(): string
    {
        return __('enums.stock_count_scope.'.$this->value);
    }
}
