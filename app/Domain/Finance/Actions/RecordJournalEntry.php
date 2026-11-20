<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Data\JournalDraft;
use App\Domain\Finance\Enums\AccountSubType;
use App\Domain\Finance\Models\Account;
use App\Domain\Finance\Models\JournalEntry;
use App\Domain\Finance\Services\Posting;
use App\Support\Decimal;
use App\Support\DomainException;
use Carbon\CarbonInterface;

/** Manual journal entries (قيد يومية) — and reversing them. Automatic entries belong to their documents. */
final class RecordJournalEntry
{
    /** Fed only by their documents, so they always agree with the contact balances and the stock valuation. */
    public const CONTROL_ACCOUNTS = [AccountSubType::Receivable, AccountSubType::Payable, AccountSubType::EmployeeAccounts, AccountSubType::Inventory,
        AccountSubType::GoodsInTransit, AccountSubType::AccruedExpenses];

    public function __construct(private readonly Posting $posting) {}

    /** @param list<array{account_id: int, debit?: string|int|float|null, credit?: string|int|float|null, contact_id?: ?int, memo?: ?string}> $lines */
    public function handle(CarbonInterface $date, string $description, array $lines, ?int $locationId = null): JournalEntry
    {
        $draft = new JournalDraft($date, $description, $locationId);
        $accounts = Account::query()->whereKey(array_column($lines, 'account_id'))->get()->keyBy('id');

        foreach ($lines as $line) {
            $account = $accounts[$line['account_id']] ?? throw new DomainException(__('finance.errors.entry_needs_two_lines'));
            if (! $account->is_postable) {
                throw new DomainException(__('finance.errors.not_postable', ['account' => $account->name]));
            }
            if (in_array($account->sub_type, self::CONTROL_ACCOUNTS, true)) {
                throw new DomainException(__('finance.errors.control_account', ['account' => $account->name]));
            }
            $debit = Decimal::of($line['debit'] ?? 0);
            $credit = Decimal::of($line['credit'] ?? 0);
            if ($debit->isNegative() || $credit->isNegative()) {
                throw new DomainException(__('finance.errors.non_positive_amount'));
            }
            $draft->debit($account->id, $debit->minus($credit), $line['contact_id'] ?? null, $line['memo'] ?? null);
        }

        return $this->posting->manual($draft);
    }

    public function reverse(JournalEntry $entry, ?CarbonInterface $at = null): JournalEntry
    {
        if (! $entry->is_manual) {
            throw new DomainException(__('finance.errors.not_manual'));
        }

        return $this->posting->reverse($entry, $at);
    }
}
