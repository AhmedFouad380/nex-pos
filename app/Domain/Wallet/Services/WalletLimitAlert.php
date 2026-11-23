<?php

namespace App\Domain\Wallet\Services;

use App\Domain\Wallet\Models\Wallet;
use App\Models\User;
use App\Support\BellNotification;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;

/**
 * Warns once when a line has used 80% of its daily or monthly sending limit, and again when it is used up — to the
 * branch's confirmers and the wallet managers, so the next transfers go through another line.
 */
final class WalletLimitAlert
{
    public function __construct(private readonly WalletUsage $usage) {}

    public function check(Wallet $wallet, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();
        $use = $this->usage->for(collect([$wallet]), $now)[$wallet->id];
        $periods = [
            'day' => [$wallet->daily_out_limit, $use['out_today'], $now->endOfDay()],
            'month' => [$wallet->monthly_out_limit, $use['out_month'], $now->endOfMonth()],
        ];
        foreach ($periods as $period => [$limit, $used, $until]) {
            if ($limit === null || (float) $limit <= 0) {
                continue;
            }
            $percent = $used / (float) $limit * 100;
            $level = $percent >= 100 ? 'full' : ($percent >= WalletUsage::WARN_PERCENT ? 'warn' : null);
            // once per line, period and level; "full" is still sent after "warn"
            if ($level === null || ! Cache::add("wallet-limit:{$wallet->id}:{$period}:{$now->format($period === 'day' ? 'Y-m-d' : 'Y-m')}:{$level}", true, $until)) {
                continue;
            }
            $notification = Notification::make()
                ->title(__('wallet.limit.'.$level, ['line' => $wallet->name, 'period' => __('wallet.limit.'.$period), 'percent' => (int) round($percent)]))
                ->body(__('wallet.limit.body', ['used' => number_format($used, 2), 'limit' => number_format((float) $limit, 2),
                    'available' => number_format((float) ($use['available_out'] ?? 0), 2)]))
                ->icon('heroicon-o-exclamation-triangle')->iconColor($level === 'full' ? 'danger' : 'warning');
            BellNotification::send($notification, $this->recipients($wallet));
        }
    }

    /** @return iterable<User> */
    private function recipients(Wallet $wallet): iterable
    {
        return User::query()->active()->get()->filter(fn (User $user) => $user->can('wallet.manage')
            || ($user->can('wallet.confirm') && $wallet->location_id && $user->accessibleLocations()->whereKey($wallet->location_id)->exists()));
    }
}
