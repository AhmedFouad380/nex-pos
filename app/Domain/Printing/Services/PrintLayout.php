<?php

namespace App\Domain\Printing\Services;

use App\Domain\Foundation\Models\Location;
use App\Domain\Printing\Enums\PaperSize;
use App\Domain\Printing\Models\InvoiceLayout;

/**
 * The print settings of an invoice layout with safe defaults, so a printout never breaks on a missing key.
 * Which layout: the one asked for, else the location's (A4 invoice or thermal receipt), else the default.
 */
final readonly class PrintLayout
{
    /** Toggles a layout can switch, with their default. */
    public const TOGGLES = [
        'show_logo' => true, 'show_business_name' => true, 'show_location_name' => true, 'show_mobile_number' => true,
        'show_customer' => true, 'show_tax_number' => true, 'show_sku' => false, 'show_previous_balance' => false,
        'show_payments' => true, 'show_barcode' => false, 'show_amount_in_words' => true,
    ];

    /** Headings and captions a layout can rename. */
    public const LABELS = [
        'invoice_heading', 'quotation_heading', 'return_heading', 'invoice_no', 'date', 'customer', 'product', 'qty',
        'unit_price', 'subtotal', 'discount', 'tax', 'total', 'paid', 'due', 'change', 'previous_balance', 'cashier',
    ];

    /** @param array<string, mixed> $settings */
    public function __construct(public PaperSize $paper, private array $settings, public ?int $id = null) {}

    public static function resolve(?Location $location, bool $receipt, ?int $layoutId = null): self
    {
        $layout = ($layoutId ? InvoiceLayout::query()->find($layoutId) : null)
            ?? ($receipt ? $location?->receiptLayout : $location?->invoiceLayout)
            ?? InvoiceLayout::query()
                ->whereIn('paper', $receipt ? [PaperSize::Thermal80, PaperSize::Thermal58] : [PaperSize::A4, PaperSize::A5])
                ->orderByDesc('is_default')->orderBy('id')->first();

        return new self(
            $layout->paper ?? ($receipt ? PaperSize::Thermal80 : PaperSize::A4),
            $layout->settings ?? [],
            $layout?->id,
        );
    }

    public function show(string $toggle): bool
    {
        return (bool) ($this->settings[$toggle] ?? self::TOGGLES[$toggle] ?? false);
    }

    public function label(string $key): string
    {
        $custom = $this->settings['labels'][$key] ?? null;

        return filled($custom) ? (string) $custom : __("printing.labels.{$key}");
    }

    public function text(string $key): ?string
    {
        $value = $this->settings[$key] ?? null;

        return filled($value) ? (string) $value : null;
    }

    public function isThermal(): bool
    {
        return in_array($this->paper, [PaperSize::Thermal80, PaperSize::Thermal58], true);
    }

    /** CSS page width in mm. */
    public function width(): int
    {
        return match ($this->paper) {
            PaperSize::Thermal58 => 58,
            PaperSize::Thermal80 => 80,
            PaperSize::A5 => 148,
            PaperSize::A4 => 210,
        };
    }
}
