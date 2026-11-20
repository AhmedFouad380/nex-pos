<?php

namespace App\Filament\Support;

use App\Domain\Foundation\Models\Currency;
use App\Settings\GeneralSettings;
use Filament\Support\Colors\Color;
use Illuminate\Support\HtmlString;

/**
 * The business's look in both panels: the main colour chosen in the business settings, a fixed
 * meaning for the other colours (green = paid / good, red = due / voided, orange = partial / pending,
 * blue = information) and the logo in the top bar and browser tab.
 */
final class Branding
{
    /** The main colour of a new install (sky blue). */
    public const DEFAULT_COLOR = 'sky';

    /** Palettes the owner can pick from (key stored in general.theme_color). */
    public const PALETTES = [
        'amber' => Color::Amber, 'orange' => Color::Orange, 'rose' => Color::Rose, 'pink' => Color::Pink,
        'violet' => Color::Violet, 'indigo' => Color::Indigo, 'blue' => Color::Blue, 'sky' => Color::Sky,
        'teal' => Color::Teal, 'emerald' => Color::Emerald, 'green' => Color::Green, 'slate' => Color::Slate,
    ];

    private static function settings(): ?GeneralSettings
    {
        return rescue(fn () => app(GeneralSettings::class), null, false);
    }

    public static function primaryKey(): string
    {
        $key = rescue(fn () => self::settings()?->theme_color, null, false);

        return is_string($key) && array_key_exists($key, self::PALETTES) ? $key : self::DEFAULT_COLOR;
    }

    /** @return array<string, array<int|string, string>> */
    public static function colors(): array
    {
        $primary = self::primaryKey();

        return [
            'primary' => self::PALETTES[$primary],
            'success' => Color::Emerald,
            'danger' => Color::Rose,
            // keep "warning" distinguishable from an orange / amber brand colour
            'warning' => in_array($primary, ['amber', 'orange'], true) ? Color::Orange : Color::Amber,
            'info' => $primary === 'sky' ? Color::Blue : Color::Sky,
            'gray' => Color::Slate,
        ];
    }

    public static function name(): string
    {
        return rescue(fn () => self::settings()?->business_name, null, false) ?: (string) config('app.name');
    }

    public static function currencySymbol(): string
    {
        $code = rescue(fn () => self::settings()?->currency_code, null, false) ?: 'EGP';

        return rescue(fn () => Currency::query()->where('code', $code)->value('symbol'), null, false) ?: $code;
    }

    public static function logo(): ?string
    {
        return rescue(fn () => self::settings()?->logoDataUri(), null, false);
    }

    /** The product name: browser tabs, the top bar and the login screens. */
    public const SYSTEM_NAME = 'Coreon';

    /** The Coreon logo (dark letters, or white ones for dark mode) and the business name beside it, for the top bar. */
    public static function brand(bool $dark = false): HtmlString
    {
        $logo = asset($dark ? 'images/brand/coreon-logo-dark.png' : 'images/brand/coreon-logo.png');
        $business = rescue(fn () => self::settings()?->business_name, null, false);
        $name = $business ? '<span style="font-weight:600;font-size:.95rem;opacity:.75;border-inline-start:1px solid currentColor;padding-inline-start:.6rem">'.e($business).'</span>' : '';

        return new HtmlString('<span style="display:inline-flex;align-items:center;gap:.6rem" dir="ltr">'
            .'<img src="'.e($logo).'" alt="'.self::SYSTEM_NAME.'" style="height:1.9rem;width:auto">'.$name.'</span>');
    }

    public static function icon(): string
    {
        return asset('images/brand/coreon-icon.png');
    }
}
