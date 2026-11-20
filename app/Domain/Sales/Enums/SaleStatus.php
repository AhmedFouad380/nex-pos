<?php

namespace App\Domain\Sales\Enums;

use App\Support\Enums\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum SaleStatus: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Draft = 'draft';
    case Quotation = 'quotation';
    case Proforma = 'proforma';
    case Suspended = 'suspended';
    case Final = 'final';

    public function getLabel(): string
    {
        return __('enums.sale_status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Quotation => 'info',
            self::Proforma => 'info',
            self::Suspended => 'warning',
            self::Final => 'success',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Draft => 'heroicon-m-pencil-square',
            self::Quotation => 'heroicon-m-document-text',
            self::Proforma => 'heroicon-m-document-duplicate',
            self::Suspended => 'heroicon-m-pause-circle',
            self::Final => 'heroicon-m-check-badge',
        };
    }
}
