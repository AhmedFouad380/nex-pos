<?php

namespace App\Domain\CashManagement\Actions;

use App\Domain\CashManagement\Services\BranchCash;
use App\Domain\Finance\Actions\RecordJournalEntry;
use App\Domain\Finance\Enums\AccountSubType;
use App\Domain\Finance\Models\Account;
use App\Domain\Finance\Models\JournalEntry;
use App\Domain\Foundation\Models\Location;
use App\Support\Decimal;
use App\Support\DomainException;
use Carbon\CarbonInterface;

/**
 * تسوية الخزنة بالجرد: after counting a box, a manual entry brings its book balance to what was counted; the
 * difference goes to an account the accountant chooses (e.g. the owner's drawings or a settlement account).
 * Not while a shift is open on a branch that uses the box: the count would miss that shift's cash.
 */
final class AdjustBoxToCount
{
    public function __construct(private readonly RecordJournalEntry $entries, private readonly BranchCash $cash) {}

    /** @return JournalEntry|null null when the box already matches the count */
    public function handle(Account $box, string|int|float $counted, int $counterAccountId, CarbonInterface $date, ?string $note = null): ?JournalEntry
    {
        if (! in_array($box->sub_type, [AccountSubType::Cash, AccountSubType::Bank], true)) {
            throw new DomainException(__('cash.errors.not_a_box'));
        }
        $counted = Decimal::of($counted);
        if ($counted->isNegative()) {
            throw new DomainException(__('finance.errors.non_positive_amount'));
        }
        if ($counterAccountId === $box->id) {
            throw new DomainException(__('finance.errors.same_account'));
        }
        foreach ($this->cash->branchesByBox()[$box->id] ?? [] as $locationId) {
            if ($this->cash->openShift($locationId) !== null) {
                throw new DomainException(__('cash.errors.branch_shift_open', ['branch' => Location::query()->whereKey($locationId)->value('name')]));
            }
        }

        $book = $this->cash->balances([$box->id])[$box->id];
        $difference = $counted->minus($book); // + the box gets more, − it loses
        if ($difference->isZero()) {
            return null;
        }
        $amount = Decimal::toDb($difference->abs());
        $description = __('cash.count_adjustment', ['box' => $box->name, 'book' => number_format((float) (string) $book, 2), 'counted' => number_format((float) (string) $counted, 2)])
            .($note ? ' - '.$note : '');

        return $this->entries->handle($date, $description, $difference->isPositive()
            ? [['account_id' => $box->id, 'debit' => $amount], ['account_id' => $counterAccountId, 'credit' => $amount]]
            : [['account_id' => $counterAccountId, 'debit' => $amount], ['account_id' => $box->id, 'credit' => $amount]]);
    }
}
