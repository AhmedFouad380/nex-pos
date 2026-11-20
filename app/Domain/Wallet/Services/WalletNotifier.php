<?php

namespace App\Domain\Wallet\Services;

use App\Domain\Finance\Enums\PaymentDirection;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/** Tells the users who may confirm wallet transactions in that branch that one is waiting. */
final class WalletNotifier
{
    public function arrived(WalletTransaction $transaction): void
    {
        $in = $transaction->direction === PaymentDirection::In;
        $title = __($in ? 'wallet.notify.in' : 'wallet.notify.out', [
            'amount' => number_format((float) $transaction->amount, 2),
            'provider' => $transaction->provider?->getLabel(),
            'from' => $transaction->counterparty_name ?? $transaction->counterparty_phone ?? '—',
        ]);

        $notification = Notification::make()->title($title)->body(__('wallet.notify.confirm'))
            ->icon('heroicon-o-device-phone-mobile')->iconColor($in ? 'success' : 'warning')
            ->actions([Action::make('open')->label(__('wallet.notify.open'))->url(self::url($transaction))->markAsRead()]);

        foreach ($this->recipients($transaction->location_id) as $user) {
            $notification->sendToDatabase($user);
        }
    }

    /** @return iterable<User> */
    private function recipients(int $locationId): iterable
    {
        return User::query()->active()->get()->filter(fn (User $user) => $user->can('wallet.confirm')
            && $user->accessibleLocations()->whereKey($locationId)->exists());
    }

    private static function url(WalletTransaction $transaction): string
    {
        return route('filament.admin.resources.wallet-transactions.index', ['tableFilters[status][value]' => 'pending']);
    }
}
