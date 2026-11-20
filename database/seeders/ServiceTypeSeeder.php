<?php

namespace Database\Seeders;

use App\Domain\Sales\Models\ServiceType;
use App\Domain\Shared\Enums\DiscountType;
use Illuminate\Database\Seeder;

/** Restaurant service types — created inactive; enable them when the restaurant module is used. */
class ServiceTypeSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([['محلي', true], ['تيك أواي', false], ['توصيل', false]] as [$name, $requiresTable]) {
            ServiceType::query()->firstOrCreate(['name' => $name], [
                'packing_charge_type' => DiscountType::Fixed,
                'packing_charge' => 0,
                'requires_table' => $requiresTable,
                'is_active' => false,
            ]);
        }
    }
}
