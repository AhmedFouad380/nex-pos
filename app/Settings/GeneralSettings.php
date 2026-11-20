<?php

namespace App\Settings;

use Illuminate\Support\Facades\Storage;
use Spatie\LaravelSettings\Settings;

class GeneralSettings extends Settings
{
    public string $business_name;

    /** on the public disk; printed on invoices, receipts and vouchers */
    public ?string $logo_path;

    /** ISO 4217, must exist in currencies */
    public string $currency_code;

    public int $currency_precision;

    public int $quantity_precision;

    /** before|after */
    public string $currency_symbol_position;

    public string $timezone;

    public string $date_format;

    /** 12|24 */
    public string $time_format;

    /** financial year start month 1-12 */
    public int $fy_start_month;

    /** business start date (Y-m-d) */
    public ?string $start_date;

    public string $theme_color;

    public string $default_locale;

    public ?string $tax_label_1;

    public ?string $tax_number_1;

    public ?string $tax_label_2;

    public ?string $tax_number_2;

    public ?string $code_label_1;

    public ?string $code_1;

    public ?string $code_label_2;

    public ?string $code_2;

    public ?string $address;

    public ?string $phone;

    public ?string $email;

    public static function group(): string
    {
        return 'general';
    }

    /** Logo as a data: URI, so printouts work without `storage:link` and inside print frames. */
    public function logoDataUri(): ?string
    {
        if (! $this->logo_path || ! Storage::disk('public')->exists($this->logo_path)) {
            return null;
        }
        $disk = Storage::disk('public');

        return 'data:'.$disk->mimeType($this->logo_path).';base64,'.base64_encode((string) $disk->get($this->logo_path));
    }
}
