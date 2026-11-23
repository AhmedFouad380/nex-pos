<?php

namespace App\Domain\Wallet\Actions;

use App\Domain\Finance\Enums\PaymentDirection;
use App\Domain\Wallet\Enums\WalletTransactionStatus as Status;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletDevice;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Domain\Wallet\Services\MessageParser;
use App\Domain\Wallet\Services\WalletLimitAlert;
use App\Domain\Wallet\Services\WalletNotifier;
use App\Support\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Stores a message a phone caught and reads it. It waits as "pending" for an employee to confirm it —
 * nothing is posted yet. The same SMS from two phones is stored once; the same provider reference twice
 * (a resent / reworded message) is marked duplicate.
 */
final class IngestWalletMessage
{
    public function __construct(
        private readonly MessageParser $parser,
        private readonly WalletNotifier $notifier,
    ) {}

    /** @return array{0: WalletTransaction, 1: bool} the transaction and whether it was new */
    public function handle(WalletDevice $device, string $body, ?string $sender = null, ?CarbonInterface $receivedAt = null, ?int $simSlot = null): array
    {
        $hash = WalletTransaction::hashBody($body);
        if ($existing = WalletTransaction::query()->where('body_hash', $hash)->first()) {
            return [$existing, false];
        }

        try {
            $transaction = DB::transaction(function () use ($device, $body, $sender, $receivedAt, $simSlot, $hash) {
                $transaction = new WalletTransaction([
                    'wallet_device_id' => $device->id,
                    'location_id' => $device->location_id,
                    'sender' => $sender !== null ? mb_substr($sender, 0, 60) : null,
                    'body' => $body,
                    'body_hash' => $hash,
                    'received_at' => $receivedAt ?? now(),
                    'sim_slot' => $simSlot,
                ]);
                $this->read($transaction);
                $transaction->save();

                return $transaction;
            });
        } catch (UniqueConstraintViolationException) { // two phones sent it at the same moment
            return [WalletTransaction::query()->where('body_hash', $hash)->firstOrFail(), false];
        }

        if ($transaction->status === Status::Pending) {
            $this->notifier->arrived($transaction);
            if ($transaction->wallet_id && ($wallet = Wallet::query()->find($transaction->wallet_id))) {
                rescue(fn () => app(WalletLimitAlert::class)->check($wallet), report: true);
            }
        }

        return [$transaction, true];
    }

    /** Fills the parsed fields and status from the body (also used to read an unparsed message again). */
    public function read(WalletTransaction $transaction): void
    {
        $parsed = $this->parser->parse($transaction->body, $transaction->sender);
        if ($parsed === null) {
            $transaction->status = Status::Unparsed;

            return;
        }
        $template = $parsed->template;
        $transaction->fill([
            'wallet_message_template_id' => $template->id,
            'provider' => $template->provider,
            'direction' => $template->direction,
            'amount' => Decimal::toDb($parsed->amount),
            'fee' => Decimal::toDb($parsed->fee),
            'balance' => $parsed->balance !== null ? Decimal::toDb($parsed->balance) : null,
            'counterparty_phone' => $parsed->counterpartyPhone,
            'counterparty_name' => $parsed->counterpartyName !== null ? mb_substr($parsed->counterpartyName, 0, 255) : null,
            'wallet_phone' => $parsed->walletPhone,
            'reference' => $parsed->reference,
            'occurred_at' => $parsed->occurredAt ?? $transaction->received_at,
        ]);
        $transaction->wallet_id ??= $this->walletFor($transaction)?->id;

        $duplicate = $parsed->reference !== null && WalletTransaction::query()
            ->where('provider', $template->provider)->where('reference', $parsed->reference)
            ->when($transaction->exists, fn ($q) => $q->whereKeyNot($transaction->id))
            ->whereIn('status', [Status::Pending, Status::Confirmed])->exists();
        $transaction->status = $duplicate ? Status::Duplicate : Status::Pending;

        if (! $duplicate && $transaction->balance !== null && $transaction->wallet_id !== null) {
            $this->recordBalance($transaction);
        }
    }

    /**
     * The line the message is about: by the wallet number in it; else by the SIM it came on; else the branch line whose
     * last balance, moved by this amount, gives the balance in the message ("تم تحويل" messages don't name our number);
     * else the branch's only line of that provider.
     */
    private function walletFor(WalletTransaction $transaction): ?Wallet
    {
        $wallets = Wallet::query()->where('is_active', true)->where('provider', $transaction->provider);
        if ($transaction->wallet_phone !== null && ($byPhone = (clone $wallets)->where('phone', $transaction->wallet_phone)->first())) {
            return $byPhone;
        }
        $branch = (clone $wallets)->where('location_id', $transaction->location_id)->get();
        if ($transaction->sim_slot !== null && ($bySim = $branch->firstWhere('sim_slot', $transaction->sim_slot))) {
            return $bySim;
        }
        if ($transaction->balance !== null && $transaction->amount !== null && $branch->count() > 1) {
            $moved = $transaction->direction === PaymentDirection::In
                ? (float) $transaction->amount
                : -((float) $transaction->amount + (float) $transaction->fee);
            $matches = $branch->filter(fn (Wallet $w) => $w->last_balance !== null
                && abs((float) $w->last_balance + $moved - (float) $transaction->balance) < 0.01);
            if ($matches->count() === 1) {
                return $matches->first();
            }
        }

        return $branch->count() === 1 ? $branch->first() : null;
    }

    private function recordBalance(WalletTransaction $transaction): void
    {
        $wallet = Wallet::query()->find($transaction->wallet_id);
        $at = $transaction->occurred_at ?? $transaction->received_at;
        if ($wallet && ($wallet->last_balance_at === null || $wallet->last_balance_at->lte($at))) {
            $wallet->update(['last_balance' => $transaction->balance, 'last_balance_at' => $at]);
        }
    }
}
