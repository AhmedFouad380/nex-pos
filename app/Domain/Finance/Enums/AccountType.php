<?php

namespace App\Domain\Finance\Enums;

use App\Support\Enums\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum AccountType: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Income = 'income';
    case Expense = 'expense';

    public function getLabel(): string
    {
        return __('enums.account_type.'.$this->value);
    }

    /** Assets and expenses grow on the debit side; liabilities, equity and income on the credit side. */
    public function isDebitNormal(): bool
    {
        return $this === self::Asset || $this === self::Expense;
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Asset => 'info',
            self::Liability => 'warning',
            self::Equity => 'gray',
            self::Income => 'success',
            self::Expense => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Asset => 'heroicon-m-building-library',
            self::Liability => 'heroicon-m-credit-card',
            self::Equity => 'heroicon-m-scale',
            self::Income => 'heroicon-m-arrow-trending-up',
            self::Expense => 'heroicon-m-arrow-trending-down',
        };
    }
}
