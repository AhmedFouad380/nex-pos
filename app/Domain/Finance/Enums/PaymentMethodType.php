<?php

namespace App\Domain\Finance\Enums;

use App\Support\Enums\EnumHelpers;
use Filament\Support\Contracts\HasLabel;

enum PaymentMethodType: string implements HasLabel
{
    use EnumHelpers;

    case Cash = 'cash';
    case Card = 'card';
    case BankTransfer = 'bank_transfer';
    case Cheque = 'cheque';
    case Wallet = 'wallet';
    case Advance = 'advance';
    case Other = 'other';

    public function getLabel(): string
    {
        return __('enums.payment_method_type.'.$this->value);
    }
}
