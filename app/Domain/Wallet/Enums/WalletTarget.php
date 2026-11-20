<?php

namespace App\Domain\Wallet\Enums;

use App\Domain\Finance\Enums\PaymentDirection;
use App\Support\Enums\EnumHelpers;
use Filament\Support\Contracts\HasLabel;

/** What a confirmed wallet transaction was for — decides the voucher it records. */
enum WalletTarget: string implements HasLabel
{
    use EnumHelpers;

    case Sale = 'sale';                 // in: pays a sale invoice
    case Contact = 'contact';           // in: from a customer / out: to a supplier or employee, on their account
    case Expense = 'expense';           // out: an expense of a category
    case Account = 'account';           // either: against any other account (other income, cash box transfer…)

    public function getLabel(): string
    {
        return __('enums.wallet_target.'.$this->value);
    }

    public function allows(PaymentDirection $direction): bool
    {
        return match ($this) {
            self::Sale => $direction === PaymentDirection::In,
            self::Expense => $direction === PaymentDirection::Out,
            default => true,
        };
    }
}
