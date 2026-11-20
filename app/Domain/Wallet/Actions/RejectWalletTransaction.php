<?php

namespace App\Domain\Wallet\Actions;

use App\Domain\Wallet\Enums\WalletTransactionStatus as Status;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Models\User;
use App\Support\DomainException;
use Illuminate\Support\Facades\DB;

/** Not a business transaction (personal, test, a message read wrongly): kept, but nothing is recorded. */
final class RejectWalletTransaction
{
    public function handle(WalletTransaction $transaction, User $by, string $reason): WalletTransaction
    {
        return DB::transaction(function () use ($transaction, $by, $reason) {
            $transaction = WalletTransaction::query()->lockForUpdate()->findOrFail($transaction->id);
            if (! in_array($transaction->status, [Status::Pending, Status::Unparsed, Status::Duplicate], true)) {
                throw new DomainException(__('wallet.errors.not_pending'));
            }
            if (! $by->can('wallet.confirm') || ! $by->accessibleLocations()->whereKey($transaction->location_id)->exists()) {
                throw new DomainException(__('wallet.errors.not_allowed'));
            }
            $transaction->update(['status' => Status::Rejected, 'reject_reason' => $reason, 'confirmed_by' => $by->id, 'confirmed_at' => now()]);

            return $transaction;
        });
    }
}
