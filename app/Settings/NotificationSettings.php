<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class NotificationSettings extends Settings
{
    public bool $mail_enabled;

    public ?string $mail_from_address;

    public ?string $mail_from_name;

    public bool $sms_enabled;

    /** generic|twilio */
    public string $sms_gateway;

    /** gateway credentials / request params */
    public array $sms_config;

    public bool $whatsapp_enabled;

    /** Cloud API token, phone number id */
    public array $whatsapp_config;

    public bool $auto_payment_reminders;

    public int $reminder_days_after_due;

    public static function group(): string
    {
        return 'notifications';
    }
}
