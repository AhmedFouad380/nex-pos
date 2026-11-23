<?php

namespace App\Domain\Wallet\Services;

use App\Domain\Finance\Enums\PaymentDirection;
use App\Domain\Wallet\Enums\WalletTransactionStatus as Status;
use App\Domain\Wallet\Models\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * How much of each line's limits is used today and this month, and how much it can still send: the least of its balance
 * and what is left of the daily and monthly limits. Counted from the messages (waiting or confirmed — a transfer happened
 * either way), at the time the provider gives.
 */
final class WalletUsage
{
    public const WARN_PERCENT = 80;

    /**
     * @param  Collection<int, Wallet>  $wallets
     * @return array<int, array{out_today: float, out_month: float, in_today: float, in_month: float, available_out: ?float,
     *     available_in: ?float, out_percent: ?int, level: string}> keyed by wallet id
     */
    public function for(Collection $wallets, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        if ($wallets->isEmpty()) {
            return [];
        }
        $at = 'coalesce(occurred_at, received_at)';
        $rows = DB::table('wallet_transactions')
            ->whereIn('wallet_id', $wallets->map(fn (Wallet $w) => $w->id)->all())
            ->whereIn('status', [Status::Pending->value, Status::Confirmed->value])
            ->whereRaw("{$at} >= ?", [$now->startOfMonth()->toDateTimeString()])
            ->whereRaw("{$at} <= ?", [$now->toDateTimeString()])
            ->groupBy('wallet_id', 'direction')
            ->selectRaw("wallet_id, direction, sum(amount) as month_total,
                sum(case when {$at} >= ? then amount else 0 end) as today_total", [$now->startOfDay()->toDateTimeString()])
            ->get();
        $sum = function (int $id, PaymentDirection $direction, string $column) use ($rows): float {
            $row = $rows->first(fn ($r) => (int) $r->wallet_id === $id && $r->direction === $direction->value);

            return $row === null ? 0.0 : (float) $row->{$column};
        };

        $usage = [];
        foreach ($wallets as $wallet) {
            $outToday = $sum($wallet->id, PaymentDirection::Out, 'today_total');
            $outMonth = $sum($wallet->id, PaymentDirection::Out, 'month_total');
            $inToday = $sum($wallet->id, PaymentDirection::In, 'today_total');
            $inMonth = $sum($wallet->id, PaymentDirection::In, 'month_total');
            $left = fn (?string $limit, float $used) => $limit === null ? null : max(0.0, (float) $limit - $used);
            $min = fn (?float ...$values) => ($values = array_filter($values, fn ($v) => $v !== null)) ? min($values) : null;

            $availableOut = $min($wallet->last_balance === null ? null : max(0.0, (float) $wallet->last_balance),
                $left($wallet->daily_out_limit, $outToday), $left($wallet->monthly_out_limit, $outMonth));
            $percents = array_filter([
                $wallet->daily_out_limit ? $outToday / (float) $wallet->daily_out_limit * 100 : null,
                $wallet->monthly_out_limit ? $outMonth / (float) $wallet->monthly_out_limit * 100 : null,
            ], fn ($p) => $p !== null);
            $percent = $percents ? (int) round(max($percents)) : null;

            $usage[$wallet->id] = [
                'out_today' => $outToday, 'out_month' => $outMonth, 'in_today' => $inToday, 'in_month' => $inMonth,
                'available_out' => $availableOut,
                'available_in' => $min($left($wallet->daily_in_limit, $inToday), $left($wallet->monthly_in_limit, $inMonth)),
                'out_percent' => $percent,
                'level' => match (true) {
                    $percent !== null && $percent >= 100, $availableOut !== null && $availableOut <= 0 => 'danger',
                    $percent !== null && $percent >= self::WARN_PERCENT => 'warning',
                    default => 'success',
                },
            ];
        }

        return $usage;
    }
}
