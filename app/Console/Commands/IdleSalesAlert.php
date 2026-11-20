<?php

namespace App\Console\Commands;

use App\Domain\CashManagement\Enums\CashSessionStatus;
use App\Domain\CashManagement\Models\CashSession;
use App\Domain\Reporting\Services\BranchSalesMonitor;
use App\Models\User;
use App\Settings\PosSettings;
use App\Support\BellNotification;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * تنبيه عدم البيع: a branch with an open shift and no sale for the hours set in the POS settings —
 * the till may be down, or selling happens off the system. Each quiet spell is reported once.
 * Runs every 15 minutes from the scheduler.
 */
class IdleSalesAlert extends Command
{
    protected $signature = 'sales:idle-alert';

    protected $description = 'Alert the managers about branches with an open shift and no sales for too long';

    public function handle(BranchSalesMonitor $monitor): int
    {
        $limit = (int) rescue(fn () => app(PosSettings::class)->idle_alert_hours, 0, false);
        if ($limit <= 0) {
            return self::SUCCESS;
        }

        foreach ($monitor->today() as $row) {
            if (! $row['shift_open'] || ($row['idle_hours'] ?? 0) < $limit) {
                continue;
            }
            // once per quiet spell: the key changes with the next sale
            $key = 'idle-alert:'.$row['location_id'].':'.($row['last_sale_at']->timestamp ?? 'none').':'.now()->toDateString();
            if (! Cache::add($key, true, now()->addDay())) {
                continue;
            }

            $session = CashSession::query()->with('user')->where('status', CashSessionStatus::Open)
                ->whereHas('cashRegister', fn ($q) => $q->where('location_id', $row['location_id']))->first();
            $notification = Notification::make()->danger()->icon('heroicon-o-exclamation-triangle')
                ->title(__('reports.idle.title', ['branch' => $row['name'], 'hours' => $row['idle_hours']]))
                ->body(__('reports.idle.body', [
                    'last' => $row['last_sale_at']?->format('Y-m-d H:i') ?? '—',
                    'cashier' => $session?->user->name ?? '—',
                ]))
                ->actions([Action::make('open')->label(__('reports.idle.open'))->url(route('filament.admin.pages.dashboard'))->markAsRead()]);

            BellNotification::send($notification, User::query()->active()->get()->filter(fn (User $user) => $user->can('cash_register.view')
                && $user->accessibleLocations()->whereKey($row['location_id'])->exists()));
            $this->line("alerted: {$row['name']} ({$row['idle_hours']} h)");
        }

        return self::SUCCESS;
    }
}
