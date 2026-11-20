<?php

namespace App\Domain\Wallet\Enums;

use App\Support\Enums\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum WalletTransactionStatus: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Unparsed = 'unparsed';   // no template matched: kept to be read again
    case Pending = 'pending';     // waiting for an employee to confirm
    case Confirmed = 'confirmed'; // a voucher was recorded
    case Rejected = 'rejected';
    case Duplicate = 'duplicate'; // same provider reference as an earlier message

    public function getLabel(): string
    {
        return __('enums.wallet_transaction_status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Unparsed => 'gray',
            self::Pending => 'warning',
            self::Confirmed => 'success',
            self::Rejected, self::Duplicate => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Unparsed => 'heroicon-m-question-mark-circle',
            self::Pending => 'heroicon-m-clock',
            self::Confirmed => 'heroicon-m-check-circle',
            self::Rejected => 'heroicon-m-x-circle',
            self::Duplicate => 'heroicon-m-document-duplicate',
        };
    }
}
