<?php

namespace App\Domain\CRM\Services;

use App\Domain\CRM\Models\Contact;
use App\Domain\Payroll\Enums\PayrollStatus;
use App\Domain\Sales\Enums\SaleStatus;
use App\Support\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Account statement (كشف حساب) of a customer / supplier, built straight from the documents.
 * Debit = increases what the contact owes us; credit = decreases it (or increases what we owe them).
 * Balance = Σ debit − Σ credit: positive → they owe us, negative → we owe them.
 */
final class ContactLedger
{
    /**
     * @return array{opening: string, rows: Collection<int, \stdClass>, closing: string}
     *                                                                                   rows: entry_date, kind, number, ref_id, debit, credit, recorded_at, balance (running)
     */
    public function statement(Contact $contact, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $opening = $from ? $this->sumUntil($contact, $from->copy()->startOfDay(), exclusive: true) : '0';

        $rows = DB::query()->fromSub($this->entries($contact), 'e')
            ->when($from, fn ($q) => $q->where('entry_date', '>=', $from->copy()->startOfDay()))
            ->when($to, fn ($q) => $q->where('entry_date', '<=', $to->copy()->endOfDay()))
            // some documents have a date only, others a date + time: order by day, then by when it was recorded
            ->orderByRaw('date(entry_date)')->orderBy('recorded_at')->orderBy('sort')->orderBy('ref_id')
            ->get();

        $running = Decimal::of($opening);
        foreach ($rows as $row) {
            $running = $running->plus(Decimal::of($row->debit))->minus(Decimal::of($row->credit));
            $row->balance = Decimal::toDb($running);
        }

        return ['opening' => Decimal::toDb($opening), 'rows' => $rows, 'closing' => Decimal::toDb($running)];
    }

    public function balance(Contact $contact): string
    {
        $totals = DB::query()->fromSub($this->entries($contact), 'e')
            ->selectRaw('coalesce(sum(debit), 0) as d, coalesce(sum(credit), 0) as c')->first();

        return Decimal::toDb(Decimal::of($totals->d)->minus(Decimal::of($totals->c)));
    }

    /** @var array<int, true>|null contacts waiting for a refresh while deferring */
    private static ?array $deferred = null;

    /**
     * Runs $callback with balance refreshes postponed, then refreshes each touched contact once.
     * For bulk work (imports): the walk-in customer alone may have tens of thousands of documents.
     *
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    public static function deferring(\Closure $callback): mixed
    {
        $outer = self::$deferred !== null;
        self::$deferred ??= [];
        try {
            return $callback();
        } finally {
            if (! $outer) {
                $ids = array_keys(self::$deferred);
                self::$deferred = null;
                foreach (Contact::query()->whereKey($ids)->get() as $contact) {
                    app(self::class)->refresh($contact);
                }
            }
        }
    }

    /** Recomputes and stores the cached contacts.balance. Call after any document touching the contact. */
    public function refresh(Contact $contact): void
    {
        if (self::$deferred !== null) {
            self::$deferred[(int) $contact->getKey()] = true;

            return;
        }

        // write straight to the row: an in-memory copy loaded earlier may already hold the "new" value,
        // in which case save() would see nothing dirty and silently skip the update
        $balance = $this->balance($contact);
        Contact::query()->whereKey($contact->getKey())->update(['balance' => $balance]);
        $contact->forceFill(['balance' => $balance])->syncOriginalAttribute('balance');
    }

    private function sumUntil(Contact $contact, CarbonInterface $date, bool $exclusive): string
    {
        $totals = DB::query()->fromSub($this->entries($contact), 'e')
            ->where('entry_date', $exclusive ? '<' : '<=', $date)
            ->selectRaw('coalesce(sum(debit), 0) as d, coalesce(sum(credit), 0) as c')->first();

        return Decimal::toDb(Decimal::of($totals->d)->minus(Decimal::of($totals->c)));
    }

    /** UNION ALL of every document that moves the contact's balance. */
    private function entries(Contact $contact): Builder
    {
        $id = $contact->id;
        $zero = DB::raw('0');

        $select = fn (string $table, string $date, string $kind, string $number, int $sort, $debit, $credit) => DB::table($table)
            ->whereNull("{$table}.voided_at")
            ->select([
                DB::raw("{$table}.{$date} as entry_date"),
                DB::raw("'{$kind}' as kind"),
                DB::raw("{$table}.{$number} as number"),
                DB::raw("{$table}.id as ref_id"),
                DB::raw("{$sort} as sort"),
                DB::raw("{$table}.created_at as recorded_at"),
                DB::raw(is_string($debit) ? "{$debit} as debit" : '0 as debit'),
                DB::raw(is_string($credit) ? "{$credit} as credit" : '0 as credit'),
            ]);

        $receivable = "case when direction = 'receivable' then amount else 0 end";
        $payable = "case when direction = 'payable' then amount else 0 end";

        return $select('contact_opening_balances', 'as_of_date', 'opening_balance', 'id', 0, $receivable, $payable)->where('contact_id', $id)
            ->unionAll($select('sales', 'sale_date', 'sale', 'invoice_no', 1, 'total', $zero)->where('customer_id', $id)->where('status', SaleStatus::Final->value))
            ->unionAll($select('sale_returns', 'return_date', 'sale_return', 'number', 2, $zero, 'total')->where('customer_id', $id))
            ->unionAll($select('purchases', 'purchase_date', 'purchase', 'number', 1, $zero, 'total * exchange_rate')->where('supplier_id', $id))
            ->unionAll($select('purchase_returns', 'return_date', 'purchase_return', 'number', 2, 'total', $zero)->where('supplier_id', $id))
            ->unionAll($select('payments', 'paid_at', 'payment', 'number', 3,
                "case when direction = 'out' then amount else 0 end", "case when direction = 'in' then amount else 0 end")->where('contact_id', $id))
            ->unionAll($select('ledger_discounts', 'date', 'ledger_discount', 'number', 4, $payable, $receivable)->where('contact_id', $id))
            // salaries an employee earned in approved payroll runs (advances and payouts are payments)
            ->unionAll(DB::table('payroll_lines as pl')->join('payroll_runs as pr', 'pr.id', '=', 'pl.payroll_run_id')
                ->whereNull('pr.voided_at')->where('pr.status', '!=', PayrollStatus::Draft->value)->where('pl.contact_id', $id)
                ->select([DB::raw('pr.posting_date as entry_date'), DB::raw("'payroll' as kind"), DB::raw('pr.number as number'),
                    DB::raw('pr.id as ref_id'), DB::raw('1 as sort'), DB::raw('pr.approved_at as recorded_at'),
                    DB::raw('0 as debit'), DB::raw('pl.earned as credit')]))
            // manual journal lines naming the contact (e.g. salaries due to an employee); reversals cancel them out
            ->unionAll(DB::table('journal_lines as jl')->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
                ->where('je.is_manual', true)->where('jl.contact_id', $id)
                ->select([DB::raw('jl.entry_date as entry_date'), DB::raw("'journal_entry' as kind"), DB::raw('je.number as number'),
                    DB::raw('je.id as ref_id'), DB::raw('5 as sort'), DB::raw('je.created_at as recorded_at'),
                    DB::raw('jl.debit as debit'), DB::raw('jl.credit as credit')]));
    }
}
