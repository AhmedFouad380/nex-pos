<?php

namespace App\Filament\Support;

use App\Domain\Finance\Actions\RecordJournalEntry;
use App\Domain\Finance\Enums\AccountSubType;
use App\Domain\Finance\Enums\AccountType;
use App\Domain\Finance\Models\Account;
use Illuminate\Database\Eloquent\Builder;

/** Account pickers for finance forms: "code — name", grouped by account type. */
final class AccountOptions
{
    /** Cash boxes and banks — where money physically is. @return array<string, array<int, string>> */
    public static function money(): array
    {
        return self::grouped(fn (Builder $q) => $q->whereIn('sub_type', [AccountSubType::Cash, AccountSubType::Bank]));
    }

    /** Anything a manual entry or a general voucher may hit (no control accounts). @return array<string, array<int, string>> */
    public static function manual(): array
    {
        return self::grouped(fn (Builder $q) => $q->where(fn ($q) => $q->whereNull('sub_type')
            ->orWhereNotIn('sub_type', RecordJournalEntry::CONTROL_ACCOUNTS)));
    }

    /** Expense accounts, for expense categories. @return array<string, array<int, string>> */
    public static function expenses(): array
    {
        return self::grouped(fn (Builder $q) => $q->where('type', AccountType::Expense));
    }

    /** @return array<int, string> parent candidates (any account) */
    public static function parents(): array
    {
        return Account::query()->orderBy('code')->get(['id', 'code', 'name'])
            ->mapWithKeys(fn (Account $a) => [$a->id => $a->code.' — '.$a->name])->all();
    }

    /**
     * @param  \Closure(Builder<Account>): mixed  $scope
     * @return array<string, array<int, string>>
     */
    private static function grouped(\Closure $scope): array
    {
        $query = Account::query()->where('is_postable', true)->where('is_active', true)->orderBy('code');
        $scope($query);

        return $query->get(['id', 'code', 'name', 'type'])
            ->groupBy(fn (Account $a) => $a->type->getLabel())
            ->map(fn ($group) => $group->mapWithKeys(fn (Account $a) => [$a->id => $a->code.' — '.$a->name])->all())
            ->all();
    }
}
