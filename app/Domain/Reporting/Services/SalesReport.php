<?php

namespace App\Domain\Reporting\Services;

use App\Domain\Catalog\Models\Variation;
use App\Domain\Reporting\Data\ReportFilter;
use App\Domain\Sales\Enums\SaleStatus;
use App\Support\Decimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sales, returns and gross profit, grouped by a dimension. Amounts exclude tax and charges (shipping,
 * packing) and carry each line's share of the invoice discount — the same basis as the journal.
 *   net = sales − returns;   profit = net − (cost of goods sold − cost of goods returned to stock)
 */
final class SalesReport
{
    public const DIMENSIONS = ['day', 'month', 'product', 'category', 'brand', 'customer', 'user', 'location'];

    /** Line revenue excluding tax, after its share of the invoice discount. */
    private const LINE_NET = 'si.unit_price * si.qty * (case when s.subtotal > 0 then (s.subtotal - s.discount_amount) / s.subtotal else 1 end)';

    /**
     * @return Collection<int, array{key: string, label: string, qty: ?string, invoices: int, sales: string, returns: string,
     *     net: string, cost: string, profit: string, margin: ?float}>
     */
    public function by(string $dimension, ReportFilter $filter): Collection
    {
        abort_unless(in_array($dimension, self::DIMENSIONS, true), 422);

        $sales = $this->saleLines($filter)
            ->selectRaw($this->keyExpression($dimension, 'sale').' as k')
            ->selectRaw('count(distinct s.id) as invoices')
            ->selectRaw('coalesce(sum('.self::LINE_NET.'), 0) as amount')
            ->selectRaw('coalesce(sum(si.cost_total), 0) as cost')
            ->selectRaw('coalesce(sum(si.base_qty), 0) as qty')
            ->groupByRaw($this->keyExpression($dimension, 'sale'))
            ->get()->keyBy('k');

        $returns = $this->returnLines($filter)
            ->selectRaw($this->keyExpression($dimension, 'return').' as k')
            ->selectRaw('coalesce(sum((ri.unit_price - ri.tax_amount) * ri.qty), 0) as amount')
            ->selectRaw('coalesce(sum(case when ri.restock = 1 then ri.unit_cost * ri.qty else 0 end), 0) as cost')
            ->selectRaw('coalesce(sum(ri.base_qty), 0) as qty')
            ->groupByRaw($this->keyExpression($dimension, 'return'))
            ->get()->keyBy('k');

        $keys = $sales->keys()->merge($returns->keys())->unique()->values();
        $labels = $this->labels($dimension, $keys);

        return $keys->map(function ($key) use ($sales, $returns, $labels, $dimension) {
            $s = $sales->get($key);
            $r = $returns->get($key);
            $net = Decimal::of((string) ($s->amount ?? 0))->minus(Decimal::of((string) ($r->amount ?? 0)));
            $cost = Decimal::of((string) ($s->cost ?? 0))->minus(Decimal::of((string) ($r->cost ?? 0)));
            $profit = $net->minus($cost);

            return [
                'key' => (string) $key,
                'label' => $labels[(string) $key] ?? (string) $key,
                'qty' => $dimension === 'product' ? Decimal::toDb(Decimal::of((string) ($s->qty ?? 0))->minus(Decimal::of((string) ($r->qty ?? 0)))) : null,
                'invoices' => (int) ($s->invoices ?? 0),
                'sales' => Decimal::toDb((string) ($s->amount ?? 0), 2),
                'returns' => Decimal::toDb((string) ($r->amount ?? 0), 2),
                'net' => Decimal::toDb($net, 2),
                'cost' => Decimal::toDb($cost, 2),
                'profit' => Decimal::toDb($profit, 2),
                'margin' => $net->isZero() ? null : $profit->multipliedBy(100)->dividedBy($net, 1, RoundingMode::HalfUp)->toFloat(),
            ];
        })
            ->sortBy(fn (array $row) => in_array($dimension, ['day', 'month'], true) ? $row['key'] : -(float) $row['net'])
            ->values();
    }

    /** @return array{invoices: int, sales: string, returns: string, net: string, cost: string, profit: string, margin: ?float, tax: string, average: string} */
    public function summary(ReportFilter $filter): array
    {
        $rows = $this->by('location', $filter);
        $sum = fn (string $key) => $rows->reduce(fn (BigDecimal $c, array $r) => $c->plus(Decimal::of($r[$key])), BigDecimal::zero());
        $invoices = (int) $rows->sum('invoices');
        $net = $sum('net');
        $profit = $sum('profit');
        $tax = Decimal::of((string) $filter->apply($this->finalSales(), 's.sale_date', 's.location_id')->sum('s.tax_amount'))
            ->minus(Decimal::of((string) $filter->apply(DB::table('sale_returns as r')->whereNull('r.voided_at'), 'r.return_date', 'r.location_id')->sum('r.tax_amount')));

        return [
            'invoices' => $invoices,
            'sales' => Decimal::toDb($sum('sales'), 2),
            'returns' => Decimal::toDb($sum('returns'), 2),
            'net' => Decimal::toDb($net, 2),
            'cost' => Decimal::toDb($sum('cost'), 2),
            'profit' => Decimal::toDb($profit, 2),
            'margin' => $net->isZero() ? null : $profit->multipliedBy(100)->dividedBy($net, 1, RoundingMode::HalfUp)->toFloat(),
            'tax' => Decimal::toDb($tax, 2),
            'average' => $invoices > 0 ? Decimal::toDb($sum('sales')->dividedBy($invoices, 2, RoundingMode::HalfUp), 2) : '0.00',
        ];
    }

    private function finalSales(): Builder
    {
        return DB::table('sales as s')->where('s.status', SaleStatus::Final->value)->whereNull('s.voided_at');
    }

    /** Top-level lines (combo components carry no price; their cost is already in the combo line). */
    private function saleLines(ReportFilter $filter): Builder
    {
        return $filter->apply($this->finalSales(), 's.sale_date', 's.location_id')
            ->join('sale_items as si', 'si.sale_id', '=', 's.id')
            ->whereNull('si.parent_item_id')
            ->join('variations as v', 'v.id', '=', 'si.variation_id')
            ->join('products as p', 'p.id', '=', 'v.product_id');
    }

    private function returnLines(ReportFilter $filter): Builder
    {
        return $filter->apply(DB::table('sale_returns as r')->whereNull('r.voided_at'), 'r.return_date', 'r.location_id')
            ->join('sale_return_items as ri', 'ri.sale_return_id', '=', 'r.id')
            ->join('variations as v', 'v.id', '=', 'ri.variation_id')
            ->join('products as p', 'p.id', '=', 'v.product_id');
    }

    private function keyExpression(string $dimension, string $side): string
    {
        $doc = $side === 'sale' ? 's' : 'r';
        $date = $side === 'sale' ? 's.sale_date' : 'r.return_date';

        return match ($dimension) {
            'day' => SqlDates::day($date),
            'month' => SqlDates::month($date),
            'product' => 'v.id',
            'category' => 'coalesce(p.category_id, 0)',
            'brand' => 'coalesce(p.brand_id, 0)',
            'customer' => "{$doc}.customer_id",
            'user' => "coalesce({$doc}.created_by, 0)",
            'location' => "{$doc}.location_id",
            default => throw new \InvalidArgumentException($dimension),
        };
    }

    /**
     * @param  Collection<int, mixed>  $keys
     * @return array<string, string>
     */
    private function labels(string $dimension, Collection $keys): array
    {
        $ids = $keys->map(fn ($k) => (int) $k)->filter()->all();
        $none = __('Not set');

        $names = match ($dimension) {
            'product' => Variation::query()->with('product')->whereKey($ids)->get()->mapWithKeys(fn (Variation $v) => [$v->id => $v->fullName()])->all(),
            'category' => DB::table('categories')->whereIn('id', $ids)->pluck('name', 'id')->all(),
            'brand' => DB::table('brands')->whereIn('id', $ids)->pluck('name', 'id')->all(),
            'customer' => DB::table('contacts')->whereIn('id', $ids)->pluck('name', 'id')->all(),
            'user' => DB::table('users')->whereIn('id', $ids)->pluck('name', 'id')->all(),
            'location' => DB::table('locations')->whereIn('id', $ids)->pluck('name', 'id')->all(),
            default => [],
        };

        return $keys->mapWithKeys(fn ($k) => [(string) $k => in_array($dimension, ['day', 'month'], true)
            ? (string) $k : ($names[(int) $k] ?? $none)])->all();
    }
}
