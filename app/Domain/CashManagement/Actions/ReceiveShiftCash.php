<?php

namespace App\Domain\CashManagement\Actions;

use App\Domain\CashManagement\Models\CashSession;
use App\Domain\CashManagement\Services\BranchCash;
use App\Domain\Finance\Actions\TransferBetweenAccounts;
use App\Support\Decimal;
use App\Support\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * استلام نقدية الوردية: a manager / the accountant takes a closed shift's cash out of the branch drawer into
 * another box (an account transfer), leaving a float for the next shift. What was counted but neither taken
 * nor left stays visible as the receipt's shortfall.
 */
final class ReceiveShiftCash
{
    public function __construct(private readonly TransferBetweenAccounts $transfers, private readonly BranchCash $cash) {}

    public function handle(CashSession $session, string|int|float $amount, int $toAccountId, string|int|float $keptFloat = 0, ?string $note = null): CashSession
    {
        if (! $session->awaitsReceipt()) {
            throw new DomainException(__('cash.errors.not_awaiting_receipt'));
        }
        $amount = Decimal::of($amount);
        $kept = Decimal::of($keptFloat);
        if ($amount->isNegative() || $kept->isNegative()) {
            throw new DomainException(__('finance.errors.non_positive_amount'));
        }
        if ($amount->plus($kept)->isGreaterThan(Decimal::of($session->counted_cash ?? 0))) {
            throw new DomainException(__('cash.errors.receipt_above_counted'));
        }
        $register = $session->cashRegister;
        $box = $register->cash_account_id ?? $this->cash->boxFor($register->location_id)
            ?? throw new DomainException(__('cash.errors.no_cash_method'));

        return DB::transaction(function () use ($session, $amount, $kept, $toAccountId, $note, $box, $register) {
            $session = CashSession::query()->lockForUpdate()->findOrFail($session->id);
            if (! $session->awaitsReceipt()) {
                throw new DomainException(__('cash.errors.not_awaiting_receipt'));
            }
            $transfer = $amount->isPositive()
                ? $this->transfers->handle($box, $toAccountId, Decimal::toDb($amount), now(),
                    __('cash.receipt_note', ['shift' => $session->id, 'cashier' => $session->user->name, 'branch' => $register->name]))
                : null;

            $session->update([
                'received_at' => now(),
                'received_by' => auth()->id(),
                'received_amount' => Decimal::toDb($amount),
                'kept_float' => Decimal::toDb($kept),
                'received_account_id' => $amount->isPositive() ? $toAccountId : null,
                'account_transfer_id' => $transfer?->id,
                'received_note' => $note,
            ]);

            return $session;
        });
    }

    /** Undoes a receipt: the transfer is voided and the shift waits for its receipt again. */
    public function cancel(CashSession $session): CashSession
    {
        if ($session->received_at === null) {
            throw new DomainException(__('cash.errors.not_received'));
        }

        return DB::transaction(function () use ($session) {
            if ($session->receiptTransfer && $session->receiptTransfer->voided_at === null) {
                $this->transfers->void($session->receiptTransfer);
            }
            $session->update(['received_at' => null, 'received_by' => null, 'received_amount' => null, 'kept_float' => null,
                'received_account_id' => null, 'account_transfer_id' => null, 'received_note' => null]);

            return $session;
        });
    }

    /** What was counted but neither taken nor left in the drawer (positive = missing). */
    public static function shortfall(CashSession $session): string
    {
        if ($session->received_at === null) {
            return '0';
        }

        return Decimal::toDb(Decimal::of($session->counted_cash ?? 0)->minus(Decimal::of($session->received_amount ?? 0))->minus(Decimal::of($session->kept_float ?? 0)));
    }
}
