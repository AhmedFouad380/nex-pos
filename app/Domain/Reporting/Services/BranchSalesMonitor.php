<?php

namespace App\Domain\Reporting\Services;

use App\Domain\CashManagement\Enums\CashSessionStatus;
use App\Domain\CashManagement\Models\CashSession;
use App\Domain\Foundation\Models\Location;
use App\Domain\Sales\Enums\SaleStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * How each branch is selling today, against its own normal: the average of the same weekday over the last
 * 8 weeks, up to the same time of day (days without any sale are left out). And whether a branch with an open
 * shift has gone too long without a sale.
 */
final class BranchSalesMonitor
{
    public const WEEKS = 8;

    /**
     * @param  list<int>|null  $locationIds  null = every branch
     * @return list<array{location_id: int, name: string, today: float, invoices: int, normal: ?float, percent: ?int,
     *     last_sale_at: ?CarbonImmutable, shift_open: bool, idle_hours: ?float}>
     */
    public function today(?array $locationIds = null, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $locations = Location::query()->where('is_active', true)->when($locationIds !== null, fn ($q) => $q->whereIn('id', $locationIds))
            ->orderBy('name')->pluck('name', 'id');
        if ($locations->isEmpty()) {
            return [];
        }
        $ids = $locations->keys()->all();
        $sales = fn () => DB::table('sales')->where('status', SaleStatus::Final->value)->whereNull('voided_at')->whereIn('location_id', $ids);

        $today = $sales()->whereBetween('sale_date', [$now->startOfDay(), $now])
            ->groupBy('location_id')->selectRaw('location_id, SUM(total) as total, COUNT(*) as invoices')->get()->keyBy('location_id');

        // the same weekday of the past weeks, each up to the same time of day
        $history = [];
        for ($week = 1; $week <= self::WEEKS; $week++) {
            $day = $now->subWeeks($week);
            foreach ($sales()->whereBetween('sale_date', [$day->startOfDay(), $day])->groupBy('location_id')
                ->selectRaw('location_id, SUM(total) as total')->get() as $row) {
                if ((float) $row->total > 0) {
                    $history[(int) $row->location_id][] = (float) $row->total;
                }
            }
        }

        $last = $sales()->groupBy('location_id')->selectRaw('location_id, MAX(sale_date) as at')->pluck('at', 'location_id');
        $open = CashSession::query()->where('status', CashSessionStatus::Open)
            ->join('cash_registers', 'cash_registers.id', '=', 'cash_sessions.cash_register_id')
            ->whereIn('cash_registers.location_id', $ids)->pluck('cash_sessions.opened_at', 'cash_registers.location_id');

        return $locations->map(function (string $name, int $id) use ($today, $history, $last, $open, $now) {
            $total = (float) ($today[$id]->total ?? 0);
            $normal = isset($history[$id]) ? array_sum($history[$id]) / count($history[$id]) : null;
            $lastSale = isset($last[$id]) ? CarbonImmutable::parse($last[$id]) : null;
            // idle time counts from the last sale, or from the opening of the shift if nothing was sold since
            $since = $open->has($id) ? collect([$lastSale, CarbonImmutable::parse($open[$id])])->filter()->max() : null;

            return [
                'location_id' => $id,
                'name' => $name,
                'today' => $total,
                'invoices' => (int) ($today[$id]->invoices ?? 0),
                'normal' => $normal,
                'percent' => $normal ? (int) round($total / $normal * 100) : null,
                'last_sale_at' => $lastSale,
                'shift_open' => $open->has($id),
                'idle_hours' => $since ? round($since->diffInMinutes($now) / 60, 1) : null,
            ];
        })->values()->all();
    }
}
