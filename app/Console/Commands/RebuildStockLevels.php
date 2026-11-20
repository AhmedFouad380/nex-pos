<?php

namespace App\Console\Commands;

use App\Domain\Inventory\Models\StockLevel;
use App\Support\Decimal;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes the stock_levels cache from the movement ledger and reports any drift.
 * Quantities come from SUM(stock_movements.qty); value-based average cost from the remaining layers.
 */
#[Signature('stock:rebuild {--dry-run : Only report differences}')]
#[Description('Rebuild stock levels from the stock movement ledger')]
class RebuildStockLevels extends Command
{
    public function handle(): int
    {
        $truth = DB::table('stock_movements')
            ->select('location_id', 'variation_id', DB::raw('SUM(qty) as qty'))
            ->groupBy('location_id', 'variation_id')
            ->get();

        $layerValues = DB::table('stock_layers')
            ->select('location_id', 'variation_id', DB::raw('SUM(remaining_qty * unit_cost) as value'), DB::raw('SUM(remaining_qty) as qty'))
            ->groupBy('location_id', 'variation_id')
            ->get()
            ->keyBy(fn ($r) => "{$r->location_id}:{$r->variation_id}");

        $drift = 0;
        foreach ($truth as $row) {
            $level = StockLevel::query()->firstOrNew(['location_id' => $row->location_id, 'variation_id' => $row->variation_id]);
            $qty = Decimal::toDb($row->qty);
            if ($level->exists && Decimal::of($level->qty_on_hand)->isEqualTo(Decimal::of($qty))) {
                continue;
            }
            $drift++;
            $this->warn("location {$row->location_id} / variation {$row->variation_id}: cached {$level->qty_on_hand} ≠ ledger {$qty}");

            if (! $this->option('dry-run')) {
                $layers = $layerValues["{$row->location_id}:{$row->variation_id}"] ?? null;
                $avg = $layers && Decimal::of($layers->qty)->isPositive() ? Decimal::divide($layers->value, $layers->qty) : Decimal::of($level->avg_cost);
                $level->fill(['qty_on_hand' => $qty, 'avg_cost' => Decimal::toDb($avg)])->save();
            }
        }

        $this->info($drift === 0 ? 'Stock levels match the ledger.' : "{$drift} level(s) ".($this->option('dry-run') ? 'differ.' : 'fixed.'));

        return self::SUCCESS;
    }
}
