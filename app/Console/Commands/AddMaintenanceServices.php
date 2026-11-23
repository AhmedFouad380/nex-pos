<?php

namespace App\Console\Commands;

use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Enums\ProductType;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Foundation\Models\Location;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Adds the maintenance services (database/data/maintenance-services.php) as service products — no stock — under the
 * "صيانة" category, one sub-category per group, sold in every active branch. Safe to run again: existing ones are kept.
 */
class AddMaintenanceServices extends Command
{
    protected $signature = 'catalog:maintenance-services {--price=0 : selling price of the new services}';

    protected $description = 'Add the maintenance services as service products under the maintenance category';

    public function handle(SaveProduct $save): int
    {
        /** @var array<string, list<string>> $groups */
        $groups = require database_path('data/maintenance-services.php');
        $unit = Unit::query()->where('name', 'قطعة')->value('id') ?? Unit::query()->orderBy('id')->value('id');
        $locations = Location::query()->where('is_active', true)->pluck('id')->all();
        if (! $unit || $locations === []) {
            $this->error('A unit and an active branch are needed first.');

            return self::FAILURE;
        }

        $added = 0;
        DB::transaction(function () use ($groups, $unit, $locations, $save, &$added) {
            $parent = Category::query()->whereNull('parent_id')->whereRaw('trim(name) in (?, ?)', ['صيانة', 'الصيانة'])->first()
                ?? Category::query()->create(['name' => 'صيانة']);
            foreach ($groups as $group => $services) {
                $category = Category::query()->firstOrCreate(['parent_id' => $parent->id, 'name' => $group]);
                foreach ($services as $name) {
                    if (Product::query()->where('name', $name)->where('type', ProductType::Service)->exists()) {
                        continue;
                    }
                    $save->handle([
                        'name' => $name, 'type' => ProductType::Service->value, 'unit_id' => $unit, 'category_id' => $category->id,
                        'is_sellable' => true, 'is_active' => true,
                        'purchase_price' => 0, 'sell_price' => (string) $this->option('price'), 'location_ids' => $locations,
                    ]);
                    $added++;
                }
            }
        });

        $this->info("Added {$added} services.");

        return self::SUCCESS;
    }
}
