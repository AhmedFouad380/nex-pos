<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Models\Account;
use App\Domain\Finance\Models\AccountTransfer;
use App\Domain\Finance\Services\Posting;
use App\Domain\Foundation\Services\DocumentNumberGenerator;
use App\Domain\Shared\Enums\DocumentType;
use App\Support\Decimal;
use App\Support\DomainException;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/** Moves money between cash boxes / banks (إيداع في البنك، سحب، تحويل بين الخزن). */
final class TransferBetweenAccounts
{
    public function __construct(private readonly DocumentNumberGenerator $numbers) {}

    public function handle(int $fromAccountId, int $toAccountId, string|int|float $amount, ?CarbonInterface $at = null, ?string $note = null): AccountTransfer
    {
        if ($fromAccountId === $toAccountId) {
            throw new DomainException(__('finance.errors.same_account'));
        }
        if (! Decimal::of($amount)->isPositive()) {
            throw new DomainException(__('finance.errors.non_positive_amount'));
        }
        foreach (Account::query()->whereKey([$fromAccountId, $toAccountId])->get() as $account) {
            if (! $account->is_postable) {
                throw new DomainException(__('finance.errors.not_postable', ['account' => $account->name]));
            }
        }

        return DB::transaction(function () use ($fromAccountId, $toAccountId, $amount, $at, $note) {
            $transfer = AccountTransfer::query()->create([
                'number' => $this->numbers->next(DocumentType::AccountTransfer),
                'from_account_id' => $fromAccountId,
                'to_account_id' => $toAccountId,
                'amount' => Decimal::toDb($amount),
                'transferred_at' => $at ?? now(),
                'note' => $note,
                'created_by' => auth()->id(),
            ]);
            app(Posting::class)->sync($transfer);

            return $transfer;
        });
    }

    public function void(AccountTransfer $transfer): AccountTransfer
    {
        if ($transfer->voided_at !== null) {
            throw new DomainException(__('finance.errors.already_voided'));
        }

        return DB::transaction(function () use ($transfer) {
            $transfer->update(['voided_at' => now()]);
            app(Posting::class)->sync($transfer);

            return $transfer;
        });
    }
}
