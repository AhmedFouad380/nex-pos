<?php

namespace App\Domain\Shared\Enums;

use App\Support\Enums\EnumHelpers;
use Filament\Support\Contracts\HasLabel;

enum DurationUnit: string implements HasLabel
{
    use EnumHelpers;

    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';

    public function getLabel(): string
    {
        return __('enums.duration_unit.'.$this->value);
    }
}
