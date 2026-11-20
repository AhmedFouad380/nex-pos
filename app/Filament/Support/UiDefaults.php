<?php

namespace App\Filament\Support;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;

/**
 * Small visual defaults applied everywhere, by field name / section heading, so every form gets
 * the same icons without repeating them: a phone icon on phone fields, the currency on money fields…
 */
final class UiDefaults
{
    /** last segment of the field name => prefix icon */
    private const FIELD_ICONS = [
        'mobile' => 'heroicon-m-device-phone-mobile', 'alt_mobile' => 'heroicon-m-device-phone-mobile', 'phone' => 'heroicon-m-phone',
        'email' => 'heroicon-m-envelope', 'address' => 'heroicon-m-map-pin', 'city' => 'heroicon-m-building-office-2',
        'tax_number' => 'heroicon-m-identification', 'website' => 'heroicon-m-globe-alt', 'reference' => 'heroicon-m-hashtag',
        'ref_no' => 'heroicon-m-hashtag', 'cheque_number' => 'heroicon-m-document-text', 'sku' => 'heroicon-m-qr-code',
    ];

    /** money fields that get the currency symbol as a suffix */
    private const MONEY_FIELDS = ['amount', 'paid_amount', 'credit_limit', 'opening_balance', 'opening_float', 'counted',
        'shipping_charges', 'default_credit_limit'];

    /** English section heading => icon */
    private const SECTION_ICONS = [
        'Items' => 'heroicon-o-list-bullet', 'Totals' => 'heroicon-o-calculator', 'Product details' => 'heroicon-o-cube',
        'Payments' => 'heroicon-o-banknotes', 'Payment' => 'heroicon-o-banknotes', 'Lines' => 'heroicon-o-list-bullet',
        'Contact details' => 'heroicon-o-user-circle', 'Combo components' => 'heroicon-o-rectangle-stack', 'Variations' => 'heroicon-o-squares-2x2',
        'Variations & prices' => 'heroicon-o-squares-2x2', 'Terms' => 'heroicon-o-clipboard-document-check', 'Tax' => 'heroicon-o-receipt-percent',
        'Stock' => 'heroicon-o-archive-box', 'Stock by location' => 'heroicon-o-building-storefront', 'Pricing' => 'heroicon-o-tag',
        'Balance' => 'heroicon-o-scale', 'Availability' => 'heroicon-o-building-storefront', 'Access' => 'heroicon-o-key',
        'Settled documents' => 'heroicon-o-document-check', 'What to print' => 'heroicon-o-printer', 'Header and footer' => 'heroicon-o-document-text',
        'Currency and dates' => 'heroicon-o-currency-dollar', 'Tax and registration numbers' => 'heroicon-o-identification',
        'Keyboard shortcuts' => 'heroicon-o-command-line', 'Buttons and permissions on the cashier screen' => 'heroicon-o-cursor-arrow-rays',
        'Label size' => 'heroicon-o-arrows-pointing-out', 'Sheet layout' => 'heroicon-o-table-cells', 'Captions' => 'heroicon-o-language',
        'Status' => 'heroicon-o-signal', 'More' => 'heroicon-o-ellipsis-horizontal-circle',
    ];

    public static function register(): void
    {
        TextInput::configureUsing(function (TextInput $input): void {
            $field = last(explode('.', $input->getName()));
            if (isset(self::FIELD_ICONS[$field])) {
                $input->prefixIcon(self::FIELD_ICONS[$field]);
            }
            if (in_array($field, self::MONEY_FIELDS, true)) {
                $input->suffix(fn () => Branding::currencySymbol());
            }
        });

        Section::configureUsing(function (Section $section): void {
            // read the raw heading: a closure heading can't be evaluated before the schema has a container
            $heading = (fn () => $this->heading ?? null)->call($section);
            if (! is_string($heading)) {
                return;
            }
            foreach (self::SECTION_ICONS as $key => $icon) {
                if ($heading === __($key)) {
                    $section->icon($icon)->iconColor('gray');

                    return;
                }
            }
        });
    }
}
