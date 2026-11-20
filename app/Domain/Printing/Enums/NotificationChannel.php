<?php

namespace App\Domain\Printing\Enums;

use App\Support\Enums\EnumHelpers;
use Filament\Support\Contracts\HasLabel;

enum NotificationChannel: string implements HasLabel
{
    use EnumHelpers;

    case Mail = 'mail';
    case Sms = 'sms';
    case Whatsapp = 'whatsapp';

    public function getLabel(): string
    {
        return __('enums.notification_channel.'.$this->value);
    }
}
