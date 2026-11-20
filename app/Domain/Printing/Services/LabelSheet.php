<?php

namespace App\Domain\Printing\Services;

use App\Domain\Catalog\Models\Variation;
use App\Domain\Printing\Models\LabelTemplate;

/** Lays out barcode labels: which items, how many of each, on what paper. */
final class LabelSheet
{
    /** Paper keys of a label template (millimetres) with their defaults. */
    public const PAPER = [
        'label_width' => 38.0, 'label_height' => 25.0, 'columns' => 1, 'gap_x' => 2.0, 'gap_y' => 2.0,
        'margin_top' => 0.0, 'margin_side' => 0.0, 'page_width' => 38.0,
    ];

    public const FIELDS = ['business_name', 'name', 'price', 'sku', 'barcode'];

    /** @return array<string, float|int> */
    public static function paper(LabelTemplate $template): array
    {
        $paper = array_merge(self::PAPER, array_filter($template->paper ?? [], fn ($v) => $v !== null && $v !== ''));
        $paper['columns'] = max(1, (int) $paper['columns']);
        foreach (['label_width', 'label_height', 'gap_x', 'gap_y', 'margin_top', 'margin_side', 'page_width'] as $key) {
            $paper[$key] = (float) $paper[$key];
        }

        return $paper;
    }

    /**
     * One entry per physical label.
     *
     * @param  array<int|string, int|string>  $quantities  variation id => number of labels
     * @return list<array{name: string, sku: string, barcode: string, price: string}>
     */
    public static function labels(array $quantities, int $max = 2000): array
    {
        $variations = Variation::query()->with(['product', 'barcodes'])->whereKey(array_keys($quantities))->get()->keyBy('id');
        $labels = [];
        foreach ($quantities as $id => $count) {
            $variation = $variations[(int) $id] ?? null;
            if (! $variation) {
                continue;
            }
            $label = [
                'name' => $variation->fullName(),
                'sku' => $variation->sku,
                // a unit barcode (not a carton's) if there is one, else the SKU — the POS scanner finds both
                'barcode' => (string) ($variation->barcodes->firstWhere('unit_id', null)->barcode ?? $variation->barcodes->first()->barcode ?? $variation->sku),
                'price' => (string) $variation->sell_price_inc_tax,
            ];
            for ($i = 0; $i < min((int) $count, $max - count($labels)); $i++) {
                $labels[] = $label;
            }
        }

        return $labels;
    }
}
