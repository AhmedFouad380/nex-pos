<?php

namespace App\Domain\CashManagement\Services;

use App\Domain\CashManagement\Models\CashSession;
use App\Models\User;
use App\Support\BellNotification;
use App\Support\Decimal;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * After a shift is closed: tells the managers of the branch whether the counted cash matches,
 * with the cashier's invoice count and total. The cashier may close "blind" (without seeing the expected cash),
 * so the person who closed the shift is never among the recipients.
 */
final class ShiftCloseNotifier
{
    public function __construct(private readonly CashSessionSummary $summary) {}

    public function closed(CashSession $session): void
    {
        $session->loadMissing(['user', 'cashRegister']);
        $summary = $this->summary->summarize($session);
        $difference = Decimal::of($session->difference);
        $money = fn ($value) => number_format((float) (string) $value, 2);

        $status = match (true) {
            $difference->isZero() => __('cash.closed.matched'),
            $difference->isNegative() => __('cash.closed.short', ['amount' => $money($difference->abs())]),
            default => __('cash.closed.over', ['amount' => $money($difference)]),
        };

        $notification = Notification::make()
            ->title(__('cash.closed.title', ['cashier' => $session->user->name ?? '—', 'register' => $session->cashRegister->name ?? '—']).' — '.$status)
            ->body(__('cash.closed.body', [
                'counted' => $money($session->counted_cash), 'expected' => $money($session->expected_cash),
                'count' => $summary['sales_count'], 'total' => $money($summary['sales_total']),
            ]))
            ->icon($difference->isZero() ? 'heroicon-o-check-circle' : 'heroicon-o-exclamation-triangle')
            ->iconColor($difference->isZero() ? 'success' : ($difference->isNegative() ? 'danger' : 'warning'))
            ->actions([Action::make('open')->label(__('cash.closed.open'))
                ->url(route('filament.admin.resources.cash-sessions.view', ['record' => $session]))->markAsRead()]);

        BellNotification::send($notification, $this->recipients($session));
    }

    /** @return iterable<User> */
    private function recipients(CashSession $session): iterable
    {
        $locationId = $session->cashRegister->location_id ?? null;

        return User::query()->active()->whereKeyNot($session->closed_by ?? 0)->get()
            ->filter(fn (User $user) => $user->can('cash_register.view')
                && ($locationId === null || $user->accessibleLocations()->whereKey($locationId)->exists()));
    }
}
