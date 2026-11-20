<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\Account;
use App\Domain\Finance\Models\JournalLine;
use App\Support\Decimal;
use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reads the journal: account balances, account statements (دفتر الأستاذ) and the trial balance (ميزان المراجعة).
 * Balances are Σ debit − Σ credit; for credit-normal accounts (liabilities, equity, income) the UI flips the sign.
 */
final class GeneralLedger
{
    /** Signed balance (debit − credit) of an account and all its sub-accounts. */
    public function balance(Account $account, ?CarbonInterface $to = null): BigDecimal
    {
        $row = JournalLine::query()
            ->whereIn('account_id', $this->subtreeIds($account))
            ->when($to, fn ($q) => $q->whereDate('entry_date', '<=', $to))
            ->selectRaw('coalesce(sum(debit), 0) as d, coalesce(sum(credit), 0) as c')->toBase()->first();

        return Decimal::of((string) $row->d)->minus(Decimal::of((string) $row->c));
    }

    /**
     * Movements of an account (with its sub-accounts) in a period, with a running balance.
     *
     * @return array{opening: string, rows: Collection<int, \stdClass>, debit: string, credit: string, closing: string}
     */
    public function statement(Account $account, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $ids = $this->subtreeIds($account);
        $opening = $from ? $this->balance($account, $from->copy()->subDay()) : BigDecimal::zero();

        $rows = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->leftJoin('contacts as c', 'c.id', '=', 'l.contact_id')
            ->whereIn('l.account_id', $ids)
            ->when($from, fn ($q) => $q->whereDate('l.entry_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('l.entry_date', '<=', $to))
            ->orderBy('l.entry_date')->orderBy('e.id')->orderBy('l.id')
            ->get(['l.entry_date', 'e.id as entry_id', 'e.number', 'e.description', 'e.source_type', 'e.source_id',
                'l.memo', 'l.debit', 'l.credit', 'c.name as contact']);

        $running = $opening;
        $debit = $credit = BigDecimal::zero();
        foreach ($rows as $row) {
            $debit = $debit->plus(Decimal::of((string) $row->debit));
            $credit = $credit->plus(Decimal::of((string) $row->credit));
            $running = $running->plus(Decimal::of((string) $row->debit))->minus(Decimal::of((string) $row->credit));
            $row->balance = Decimal::toDb($running);
        }

        return ['opening' => Decimal::toDb($opening), 'rows' => $rows, 'debit' => Decimal::toDb($debit),
            'credit' => Decimal::toDb($credit), 'closing' => Decimal::toDb($running)];
    }

    /**
     * Every account with opening balance, period debit / credit and closing balance. Parent accounts
     * carry the totals of their sub-accounts; `depth` is for indenting.
     *
     * @return Collection<int, array{account: Account, depth: int, opening: string, debit: string, credit: string, closing: string}>
     */
    public function trialBalance(?CarbonInterface $from = null, ?CarbonInterface $to = null): Collection
    {
        $before = $from ? $this->sumsByAccount(null, $from->copy()->subDay()) : collect();
        $period = $this->sumsByAccount($from, $to);
        $accounts = Account::query()->orderBy('code')->get();
        $children = $accounts->groupBy('parent_id');

        $rows = collect();
        $walk = function (?int $parentId, int $depth) use (&$walk, $children, $before, $period, $rows): array {
            $totals = [BigDecimal::zero(), BigDecimal::zero(), BigDecimal::zero()]; // opening, debit, credit
            foreach ($children->get($parentId ?? '', collect()) as $account) {
                $index = $rows->count();
                $rows->push(null); // reserve the slot so parents print above their children
                $own = [
                    Decimal::of($before[$account->id]->net ?? '0'),
                    Decimal::of($period[$account->id]->d ?? '0'),
                    Decimal::of($period[$account->id]->c ?? '0'),
                ];
                $sub = $walk($account->id, $depth + 1);
                $sum = [$own[0]->plus($sub[0]), $own[1]->plus($sub[1]), $own[2]->plus($sub[2])];
                $rows[$index] = [
                    'account' => $account, 'depth' => $depth,
                    'opening' => Decimal::toDb($sum[0]), 'debit' => Decimal::toDb($sum[1]), 'credit' => Decimal::toDb($sum[2]),
                    'closing' => Decimal::toDb($sum[0]->plus($sum[1])->minus($sum[2])),
                ];
                $totals = [$totals[0]->plus($sum[0]), $totals[1]->plus($sum[1]), $totals[2]->plus($sum[2])];
            }

            return $totals;
        };
        $walk(null, 0);

        return $rows->filter();
    }

    /** @return Collection<int|string, \stdClass> keyed by account_id: d, c, net */
    private function sumsByAccount(?CarbonInterface $from, ?CarbonInterface $to): Collection
    {
        return DB::table('journal_lines')
            ->when($from, fn ($q) => $q->whereDate('entry_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('entry_date', '<=', $to))
            ->groupBy('account_id')
            ->selectRaw('account_id, coalesce(sum(debit), 0) as d, coalesce(sum(credit), 0) as c, coalesce(sum(debit), 0) - coalesce(sum(credit), 0) as net')
            ->get()
            ->each(function ($r) {
                $r->d = (string) $r->d;
                $r->c = (string) $r->c;
                $r->net = Decimal::of($r->d)->minus(Decimal::of($r->c))->__toString();
            })
            ->keyBy('account_id');
    }

    /** @return list<int> */
    public function subtreeIds(Account $account): array
    {
        $ids = [$account->id];
        $frontier = [$account->id];
        while ($frontier !== []) {
            $frontier = Account::query()->whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids = [...$ids, ...$frontier];
        }

        return $ids;
    }
}
