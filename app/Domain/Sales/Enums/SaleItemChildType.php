<?php

namespace App\Domain\Sales\Enums;

use App\Support\Enums\EnumHelpers;
use Filament\Support\Contracts\HasLabel;

enum SaleItemChildType: string implements HasLabel
{
    use EnumHelpers;

    case Modifier = 'modifier';
    case Combo = 'combo';

    public function getLabel(): string
    {
        return __('enums.sale_item_child_type.'.$this->value);
    }
}
