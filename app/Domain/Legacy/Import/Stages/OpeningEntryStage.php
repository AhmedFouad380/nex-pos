<?php

namespace App\Domain\Legacy\Import\Stages;

use App\Domain\Finance\Actions\RecordJournalEntry;
use App\Domain\Finance\Enums\AccountSubType as Role;
use App\Domain\Finance\Services\SystemAccounts;
use App\Domain\Legacy\Import\ImportContext;
use App\Domain\Legacy\Import\LegacyPackage;
use App\Domain\Legacy\Import\Stage;
use App\Support\Decimal;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;

/**
 * The rest of the old opening entry (capital, cash boxes, other accounts) as one manual journal entry.
 * Contact lines were posted as contact opening balances (ContactsStage); stock lines come from the
 * opening-stock documents (type 8) at their item cost, so they're left out here to avoid counting stock twice.
 */
final class OpeningEntryStage implements Stage
{
    public function name(): string
    {
        return 'opening entry';
    }

    public function run(LegacyPackage $package, ImportContext $context): void
    {
        if ($context->newId('OpeningEntry', 'main')) {
            return;
        }
        $lines = [];
        $sum = BigDecimal::zero();
        foreach ($package->all('AccountsOpeningBalance') as $row) {
            $code = (string) $row['AccountId'];
            if ($context->newType('ChartOfAccount', $code) !== 'account' || str_starts_with($code, '102005')) {
                continue;
            }
            $debit = Decimal::of($row['DebitLocal'] ?? $row['Debit'] ?? 0);
            $credit = Decimal::of($row['CreditLocal'] ?? $row['Credit'] ?? 0);
            if ($debit->isZero() && $credit->isZero()) {
                continue;
            }
            $lines[] = ['account_id' => $context->newId('ChartOfAccount', $code), 'debit' => Decimal::toDb($debit), 'credit' => Decimal::toDb($credit),
                'memo' => trim((string) ($row['Description'] ?? '')) ?: null];
            $sum = $sum->plus($debit)->minus($credit);
        }
        if ($lines === []) {
            return;
        }
        // whatever doesn't balance (the contact and stock lines left out) goes to the opening-balances account
        if (! $sum->isZero()) {
            $lines[] = ['account_id' => app(SystemAccounts::class)->id(Role::OpeningBalance),
                'debit' => $sum->isNegative() ? Decimal::toDb($sum->negated()) : null, 'credit' => $sum->isPositive() ? Decimal::toDb($sum) : null];
        }

        $date = Carbon::parse(app(ContactsStage::class)->firstDay($package));
        $entry = app(RecordJournalEntry::class)->handle($date, __('legacy.opening_entry'), $lines);
        $context->remember('OpeningEntry', 'main', $entry);
        $context->count($this->name(), 'lines', count($lines));
    }
}
