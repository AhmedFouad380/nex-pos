<?php

namespace Database\Seeders;

use App\Domain\Catalog\Models\PriceGroup;
use App\Domain\Catalog\Models\TaxRate;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Printing\Models\LabelTemplate;
use App\Settings\InventorySettings;
use Illuminate\Database\Seeder;

class CatalogDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        // base units
        $piece = $this->unit('قطعة', 'قطعة');
        $kg = $this->unit('كيلوجرام', 'كجم', allowDecimal: true);
        $liter = $this->unit('لتر', 'لتر', allowDecimal: true);
        $meter = $this->unit('متر', 'م', allowDecimal: true);

        // sub-units (multiples of a base unit)
        $this->unit('جرام', 'جم', allowDecimal: true, base: $kg, multiplier: 0.001);
        $this->unit('مللي', 'مل', allowDecimal: true, base: $liter, multiplier: 0.001);
        $this->unit('سنتيمتر', 'سم', allowDecimal: true, base: $meter, multiplier: 0.01);
        $this->unit('دستة', 'دستة', base: $piece, multiplier: 12);
        $this->unit('كرتونة', 'كرتونة', base: $piece, multiplier: 12);

        TaxRate::query()->updateOrCreate(['name' => 'ضريبة القيمة المضافة 14%'], ['rate' => 14, 'is_active' => true]);
        TaxRate::query()->updateOrCreate(['name' => 'معفى من الضريبة'], ['rate' => 0, 'is_active' => true]);

        PriceGroup::query()->firstOrCreate(['name' => 'سعر الجملة'], ['description' => 'أسعار خاصة لعملاء الجملة']);

        LabelTemplate::query()->updateOrCreate(['name' => 'رول 50×25 مم'], [
            'paper' => ['label_width' => 50, 'label_height' => 25],
            'is_continuous' => true,
            'is_default' => true,
        ]);
        LabelTemplate::query()->updateOrCreate(['name' => 'A4 — 40 ملصق (4×10)'], [
            // keys: see LabelSheet::PAPER (millimetres)
            'paper' => ['label_width' => 48.5, 'label_height' => 25.4, 'columns' => 4, 'page_width' => 201.5, 'gap_x' => 2.5, 'gap_y' => 0,
                'margin_top' => 21.5, 'margin_side' => 4],
            'is_continuous' => false,
        ]);

        $settings = app(InventorySettings::class);
        if ($settings->default_unit_id === null) {
            $settings->default_unit_id = $piece->id;
            $settings->save();
        }
    }

    private function unit(string $name, string $short, bool $allowDecimal = false, ?Unit $base = null, float $multiplier = 1): Unit
    {
        return Unit::query()->updateOrCreate(['name' => $name], [
            'short_name' => $short,
            'allow_decimal' => $allowDecimal,
            'base_unit_id' => $base?->id,
            'base_multiplier' => $multiplier,
        ]);
    }
}
