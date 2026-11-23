<?php

namespace App\Domain\Purchasing\Services;

use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Enums\TaxType;
use App\Domain\Catalog\Models\TaxRate;
use App\Domain\Catalog\Models\Variation;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Purchasing\Models\PurchaseItem;
use App\Support\Decimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A product's purchase price is the landed cost (per base unit) of its latest purchase that isn't voided.
 * Saving a purchase sets it directly; this recomputes it after a purchase is voided and after an import,
 * which writes purchases without going through CreatePurchase (there, a product never bought takes the
 * cost it was put into stock at).
 */
final class LastPurchasePrice
{
    public function __construct(private readonly SaveProduct $products) {}

    /** @param list<int>|null $variationIds null = every variation (the import) */
    public function refresh(?array $variationIds = null): int
    {
        $latest = PurchaseItem::query()
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->whereNull('purchases.voided_at')
            ->when($variationIds !== null, fn ($query) => $query->whereIn('purchase_items.variation_id', $variationIds))
            ->where('purchase_items.base_qty', '>', 0)
            ->select('purchase_items.variation_id', 'purchase_items.landed_unit_cost')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY purchase_items.variation_id ORDER BY purchases.purchase_date DESC, purchases.id DESC, purchase_items.id DESC) AS rn');
        $costs = DB::query()->fromSub($latest, 'last')->where('rn', 1)->pluck('landed_unit_cost', 'variation_id');
        if ($variationIds === null) {
            $costs = $costs->union($this->stockedWithoutPurchase($costs->keys()->all()));
        }

        $rates = TaxRate::query()->pluck('rate', 'id');
        $changed = 0;
        Variation::query()->with('product')->whereIn('id', $costs->keys())->chunkById(500, function ($variations) use ($costs, $rates, &$changed) {
            foreach ($variations as $variation) {
                $cost = Decimal::toDb($costs[$variation->id]);
                if (Decimal::of($variation->purchase_price)->isEqualTo($cost)) {
                    continue;
                }
                $product = $variation->product;
                $sell = $product->tax_type === TaxType::Inclusive ? $variation->sell_price_inc_tax : $variation->sell_price;
                $rate = Decimal::of($product->tax_rate_id ? ($rates[$product->tax_rate_id] ?? 0) : 0);
                $variation->update($this->products->prices($product, $cost, (string) $sell, $rate));
                $changed++;
            }
        });

        return $changed;
    }

    /**
     * Products never bought but put into stock (opening stock, a count) with a cost, whose price is still 0:
     * the cost of the latest time they came in.
     *
     * @param  list<int|string>  $bought
     * @return Collection<int|string, mixed>
     */
    private function stockedWithoutPurchase(array $bought): Collection
    {
        $latest = DB::table('stock_movements')
            ->whereIn('type', [StockMovementType::Opening->value, StockMovementType::Count->value, StockMovementType::Adjustment->value])
            ->whereNull('reverses_id')->where('qty', '>', 0)->where('unit_cost', '>', 0)
            ->whereNotIn('variation_id', $bought)
            ->whereIn('variation_id', Variation::query()->where('purchase_price', '<=', 0)->select('id'))
            ->select('variation_id', 'unit_cost')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY variation_id ORDER BY moved_at DESC, id DESC) AS rn');

        return DB::query()->fromSub($latest, 'last')->where('rn', 1)->pluck('unit_cost', 'variation_id');
    }
}
