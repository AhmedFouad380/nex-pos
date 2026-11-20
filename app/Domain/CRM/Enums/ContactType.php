<?php

namespace App\Domain\CRM\Enums;

use App\Support\Enums\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum ContactType: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Customer = 'customer';
    case Supplier = 'supplier';
    case Both = 'both';
    case Employee = 'employee';

    public function getLabel(): string
    {
        return __('enums.contact_type.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Customer => 'success',
            self::Supplier => 'info',
            self::Both => 'warning',
            self::Employee => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Customer => 'heroicon-m-user',
            self::Supplier => 'heroicon-m-truck',
            self::Both => 'heroicon-m-users',
            self::Employee => 'heroicon-m-briefcase',
        };
    }
}
