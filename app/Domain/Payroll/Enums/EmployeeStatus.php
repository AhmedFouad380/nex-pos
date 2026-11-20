<?php

namespace App\Domain\Payroll\Enums;

use App\Support\Enums\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum EmployeeStatus: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Active = 'active';
    case Suspended = 'suspended';
    case Terminated = 'terminated';

    public function getLabel(): string
    {
        return __('enums.employee_status.'.$this->value);
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Active => 'heroicon-m-check-circle',
            self::Suspended => 'heroicon-m-pause-circle',
            self::Terminated => 'heroicon-m-x-circle',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Suspended => 'warning',
            self::Terminated => 'gray',
        };
    }
}
