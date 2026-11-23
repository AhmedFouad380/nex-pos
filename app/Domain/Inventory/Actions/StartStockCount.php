<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Catalog\Enums\ProductType;
use App\Domain\Catalog\Models\Category;
use App\Domain\Foundation\Services\DocumentNumberGenerator;
use App\Domain\Inventory\Enums\StockCountScope;
use App\Domain\Inventory\Enums\StockCountStatus;
use App\Domain\Inventory\Models\StockCount;
use App\Domain\Shared\Enums\DocumentType;
use App\Support\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Opens a stock count (جرد) for a branch: one line per stock-tracked item in the scope, holding the quantity the
 * system expects right now and its cost. The counted quantities are entered afterwards, then the count is posted.
 */
final class StartStockCount
{
    public function __construct(private readonly DocumentNumberGenerator $numbers) {}

    /**
     * @param  list<int>  $categoryIds  scope "category": these categories and their sub-categories
     * @param  list<int>  $brandIds  scope "brand"
     */
    public function handle(int $locationId, StockCountScope $scope = StockCountScope::Full, array $categoryIds = [],
        array $brandIds = [], ?string $note = null): StockCount
    {
        $open = StockCount::query()->where('location_id', $locationId)->where('status', StockCountStatus::Counting)->value('number');
        if ($open !== null) {
            throw new DomainException(__('inventory.errors.count_open', ['number' => $open]));
        }
        if ($scope === StockCountScope::Category) {
            $categoryIds = array_values(array_unique([...$categoryIds,
                ...Category::query()->whereIn('parent_id', $categoryIds)->pluck('id')->all()]));
        }

        $lines = DB::table('variations')
            ->join('products', 'products.id', '=', 'variations.product_id')
            ->leftJoin('stock_levels', fn ($join) => $join->on('stock_levels.variation_id', '=', 'variations.id')
                ->where('stock_levels.location_id', '=', $locationId))
            ->whereNull('variations.deleted_at')->whereNull('products.deleted_at')
            ->where('products.is_active', true)->where('products.track_stock', true)
            ->whereNotIn('products.type', [ProductType::Service->value, ProductType::Combo->value])
            ->when($scope === StockCountScope::Category, fn ($q) => $q->whereIn('products.category_id', $categoryIds))
            ->when($scope === StockCountScope::Brand, fn ($q) => $q->whereIn('products.brand_id', $brandIds))
            ->orderBy('products.name')->orderBy('variations.id')
            ->get(['variations.id', 'variations.purchase_price', 'stock_levels.qty_on_hand', 'stock_levels.avg_cost']);
        if ($lines->isEmpty()) {
            throw new DomainException(__('inventory.errors.count_empty'));
        }

        return DB::transaction(function () use ($locationId, $scope, $note, $lines) {
            $count = StockCount::query()->create([
                'number' => $this->numbers->next(DocumentType::StockCount, $locationId),
                'location_id' => $locationId,
                'status' => StockCountStatus::Counting,
                'scope' => $scope,
                'started_at' => now(),
                'note' => $note,
                'created_by' => auth()->id(),
            ]);
            $now = now();
            foreach ($lines->chunk(500) as $chunk) {
                DB::table('stock_count_items')->insert($chunk->map(fn ($line) => [
                    'stock_count_id' => $count->id,
                    'variation_id' => $line->id,
                    'expected_qty' => $line->qty_on_hand ?? 0,
                    'counted_qty' => null,
                    'difference' => 0,
                    // per base unit: the branch's average cost, or the purchase price before any stock arrived
                    'unit_cost' => (float) $line->avg_cost > 0 ? $line->avg_cost : ($line->purchase_price ?? 0),
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->values()->all());
            }

            return $count;
        });
    }
}
