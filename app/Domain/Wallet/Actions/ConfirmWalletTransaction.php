<?php

namespace App\Domain\Wallet\Actions;

use App\Domain\CRM\Models\Contact;
use App\Domain\Finance\Actions\CreateExpense;
use App\Domain\Finance\Actions\RecordJournalEntry;
use App\Domain\Finance\Actions\RecordPayment;
use App\Domain\Finance\Data\PaymentData;
use App\Domain\Finance\Enums\PaymentDirection;
use App\Domain\Finance\Enums\PaymentMethodType;
use App\Domain\Finance\Models\Account;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentMethod;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Domain\Wallet\Enums\WalletTarget;
use App\Domain\Wallet\Enums\WalletTransactionStatus as Status;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Models\User;
use App\Support\Decimal;
use App\Support\DomainException;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * An employee says what a wallet transaction was for; only now is it recorded:
 *   sale     → receipt allocated to that invoice (any rest stays on the customer as credit)
 *   contact  → receipt from / payment to the contact, settling their oldest open documents
 *   expense  → an expense of that category, paid from the wallet
 *   account  → a voucher against any other account (other income, moving money to the cash box…)
 * The provider's fee on money sent out is recorded as a separate "transfer fees" expense.
 */
final class ConfirmWalletTransaction
{
    /** Name of the expense category the provider fees go to (created by the seeder). */
    public const FEES_CATEGORY = 'عمولات التحويل';

    public function __construct(
        private readonly RecordPayment $payments,
        private readonly CreateExpense $expenses,
    ) {}

    public function handle(WalletTransaction $transaction, WalletTarget $target, int $targetId, User $by, ?int $walletId = null, ?string $note = null): WalletTransaction
    {
        return DB::transaction(function () use ($transaction, $target, $targetId, $by, $walletId, $note) {
            $transaction = WalletTransaction::query()->lockForUpdate()->findOrFail($transaction->id);
            if ($transaction->status !== Status::Pending) {
                throw new DomainException(__('wallet.errors.not_pending'));
            }
            $direction = $transaction->direction ?? throw new DomainException(__('wallet.errors.not_pending'));
            if (! $target->allows($direction)) {
                throw new DomainException(__('wallet.errors.target_direction'));
            }
            if (! $by->can('wallet.confirm') || ! $by->accessibleLocations()->whereKey($transaction->location_id)->exists()) {
                throw new DomainException(__('wallet.errors.not_allowed'));
            }
            if ($walletId !== null) {
                $transaction->wallet_id = $walletId;
            }
            $wallet = $transaction->wallet_id ? Wallet::query()->find($transaction->wallet_id) : null;
            $wallet ?? throw new DomainException(__('wallet.errors.wallet_required'));

            $methodId = PaymentMethod::query()->where('type', PaymentMethodType::Wallet)->where('is_active', true)->orderBy('sort')->value('id')
                ?? throw new DomainException(__('wallet.errors.no_wallet_method'));
            $amount = Decimal::toDb($transaction->amount);
            $memo = collect([$transaction->provider?->getLabel(), $transaction->reference ? '#'.$transaction->reference : null,
                $transaction->counterparty_name ?? $transaction->counterparty_phone, $note])->filter()->implode(' - ');
            $voucher = fn (array $extra) => new PaymentData(...[
                'direction' => $direction,
                'paymentMethodId' => $methodId,
                'amount' => $amount,
                'locationId' => $transaction->location_id,
                'paidAt' => $transaction->occurred_at ?? $transaction->received_at,
                'accountId' => $wallet->account_id,
                'reference' => $transaction->reference,
                'note' => $memo,
                ...$extra,
            ]);

            [$payment, $expenseId] = match ($target) {
                WalletTarget::Sale => [$this->forSale($transaction, $targetId, $voucher), null],
                WalletTarget::Contact => [$this->payments->handle($voucher(['contactId' => Contact::query()->findOrFail($targetId)->id, 'autoAllocate' => true])), null],
                WalletTarget::Account => [$this->payments->handle($voucher(['counterAccountId' => $this->counterAccount($targetId, $wallet)])), null],
                WalletTarget::Expense => $this->forExpense($transaction, $targetId, $methodId, $wallet, $memo),
            };

            $feeExpense = null;
            if ($direction === PaymentDirection::Out && Decimal::of($transaction->fee)->isPositive()) {
                $feeExpense = $this->expenses->handle([
                    'location_id' => $transaction->location_id,
                    'expense_category_id' => ExpenseCategory::query()->where('name', self::FEES_CATEGORY)->value('id'),
                    'amount' => $transaction->fee,
                    'expense_date' => $transaction->occurred_at ?? $transaction->received_at,
                    'ref_no' => $transaction->reference,
                    'note' => __('wallet.fee_note', ['memo' => $memo]),
                ], ['payment_method_id' => $methodId, 'account_id' => $wallet->account_id]);
            }

            $transaction->fill([
                'status' => Status::Confirmed,
                'target_type' => $target,
                'target_id' => $targetId,
                'payment_id' => $payment->id,
                'expense_id' => $expenseId,
                'fee_expense_id' => $feeExpense?->id,
                'confirmed_by' => $by->id,
                'confirmed_at' => now(),
                'note' => $note,
            ])->save();

            return $transaction;
        });
    }

    /** @param \Closure(array<string, mixed>): PaymentData $voucher */
    private function forSale(WalletTransaction $transaction, int $saleId, \Closure $voucher): Payment
    {
        $sale = Sale::query()->findOrFail($saleId);
        if ($sale->status !== SaleStatus::Final || $sale->voided_at !== null) {
            throw new DomainException(__('wallet.errors.sale_not_open'));
        }
        $toInvoice = BigDecimal::min(Decimal::of($transaction->amount), Decimal::of($sale->due_total));

        return $this->payments->handle($voucher([
            'contactId' => $sale->customer_id,
            'allocations' => $toInvoice->isPositive() ? [[$sale, Decimal::toDb($toInvoice)]] : [],
        ]));
    }

    /** @return array{0: Payment, 1: int} the voucher that paid it, and the expense */
    private function forExpense(WalletTransaction $transaction, int $categoryId, int $methodId, Wallet $wallet, string $memo): array
    {
        $expense = $this->expenses->handle([
            'location_id' => $transaction->location_id,
            'expense_category_id' => ExpenseCategory::query()->findOrFail($categoryId)->id,
            'amount' => $transaction->amount,
            'expense_date' => $transaction->occurred_at ?? $transaction->received_at,
            'ref_no' => $transaction->reference,
            'note' => $memo,
        ], ['payment_method_id' => $methodId, 'account_id' => $wallet->account_id]);

        return [Payment::query()->findOrFail($expense->allocations()->value('payment_id')), $expense->id];
    }

    /** Any postable account except control accounts (they belong to contacts) and the wallet's own. */
    private function counterAccount(int $accountId, Wallet $wallet): int
    {
        $account = Account::query()->where('is_postable', true)->where('is_active', true)->findOrFail($accountId);
        if (in_array($account->sub_type, RecordJournalEntry::CONTROL_ACCOUNTS, true) || $account->id === $wallet->account_id) {
            throw new DomainException(__('wallet.errors.account_not_allowed'));
        }

        return $account->id;
    }
}
