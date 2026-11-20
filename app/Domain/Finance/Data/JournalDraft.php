<?php

namespace App\Domain\Finance\Data;

use App\Support\Decimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;

/**
 * A journal entry before it is saved. Amounts are signed: debit(-x) is the same as credit(x), so rules
 * can post a value without caring about its sign. Same account + contact lines are merged.
 */
final class JournalDraft
{
    /** @var array<string, array{account_id: int, contact_id: ?int, amount: BigDecimal, memo: ?string}> */
    private array $lines = [];

    public function __construct(
        public readonly CarbonInterface $date,
        public readonly string $description,
        public readonly ?int $locationId = null,
    ) {}

    public function debit(int $accountId, BigDecimal|string|int|float $amount, ?int $contactId = null, ?string $memo = null): self
    {
        $amount = Decimal::of($amount)->toScale(4, RoundingMode::HalfUp);
        if ($amount->isZero()) {
            return $this;
        }
        $key = $accountId.'|'.($contactId ?? '');
        $this->lines[$key] = [
            'account_id' => $accountId,
            'contact_id' => $contactId,
            'amount' => ($this->lines[$key]['amount'] ?? BigDecimal::zero())->plus($amount),
            'memo' => $this->lines[$key]['memo'] ?? $memo,
        ];

        return $this;
    }

    public function credit(int $accountId, BigDecimal|string|int|float $amount, ?int $contactId = null, ?string $memo = null): self
    {
        return $this->debit($accountId, Decimal::of($amount)->negated(), $contactId, $memo);
    }

    /** Σ debit − Σ credit; zero when balanced. */
    public function imbalance(): BigDecimal
    {
        return array_reduce($this->lines, fn (BigDecimal $sum, array $line) => $sum->plus($line['amount']), BigDecimal::zero());
    }

    /** Posts whatever is left (rounding, price vs cost differences) to one account so the entry balances. */
    public function balanceWith(int $accountId, ?string $memo = null): self
    {
        return $this->credit($accountId, $this->imbalance(), null, $memo);
    }

    /**
     * Saved shape, debit/credit split and zero lines dropped, in a stable order.
     *
     * @return list<array{account_id: int, contact_id: ?int, debit: string, credit: string, memo: ?string}>
     */
    public function lines(): array
    {
        $rows = [];
        foreach ($this->lines as $line) {
            if ($line['amount']->isZero()) {
                continue;
            }
            $rows[] = [
                'account_id' => $line['account_id'],
                'contact_id' => $line['contact_id'],
                'debit' => Decimal::toDb($line['amount']->isPositive() ? $line['amount'] : BigDecimal::zero()),
                'credit' => Decimal::toDb($line['amount']->isNegative() ? $line['amount']->negated() : BigDecimal::zero()),
                'memo' => $line['memo'],
            ];
        }

        // debits first, then credits — the way accountants read an entry
        usort($rows, fn ($a, $b) => [Decimal::of($a['debit'])->isZero(), $a['account_id']] <=> [Decimal::of($b['debit'])->isZero(), $b['account_id']]);

        return $rows;
    }

    /** Comparable fingerprint of the lines (order-independent), to tell whether a posted entry is still right. */
    public function signature(): string
    {
        return self::signatureOf($this->lines());
    }

    /** @param iterable<array{account_id: int, contact_id: ?int, debit: string, credit: string}> $lines */
    public static function signatureOf(iterable $lines): string
    {
        $parts = [];
        foreach ($lines as $l) {
            $parts[] = $l['account_id'].'|'.($l['contact_id'] ?? '').'|'.Decimal::toDb($l['debit']).'|'.Decimal::toDb($l['credit']);
        }
        sort($parts);

        return implode(';', $parts);
    }
}
