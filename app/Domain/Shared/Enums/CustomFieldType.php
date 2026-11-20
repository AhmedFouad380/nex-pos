<?php

namespace App\Domain\Shared\Enums;

use App\Support\Enums\EnumHelpers;
use Filament\Support\Contracts\HasLabel;

enum CustomFieldType: string implements HasLabel
{
    use EnumHelpers;

    case Text = 'text';
    case Number = 'number';
    case Date = 'date';
    case Select = 'select';
    case Bool = 'bool';

    public function getLabel(): string
    {
        return __('enums.custom_field_type.'.$this->value);
    }
}
