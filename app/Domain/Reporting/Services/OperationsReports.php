<?php

namespace App\Domain\Reporting\Services;

use App\Domain\Catalog\Models\Variation;
use App\Domain\CRM\Enums\ContactType;
use App\Domain\CRM\Models\Contact;
use App\Domain\CRM\Services\ContactLedger;
use App\Domain\Finance\Enums\AccountSubType;
use App\Domain\Reporting\Data\ReportFilter;
use App\Domain\Sales\Enums\SaleStatus;
use App\Support\Decimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Purchases, stock, tax, aging, expenses and money movement reports. */
final class OperationsReports
{
    public const PURCHASE_DIMENSIONS = ['supplier', 'product', 'month', 'location'];

    public const EXPENSE_DIMENSIONS = ['category', 'month', 'location', 'user'];

    /** @return list<array<string, mixed>> */
    public function purchases(string $dimension, ReportFilter $filter): array
    {
        abort_unless(in_array($dimension, self::PURCHASE_DIMENSIONS, true), 422);
        $base = $filter->apply(DB::table('purchases as pu')->whereNull('pu.voided_at'), 'pu.purchase_date', 'pu.location_id');

        if ($dimension === 'product') {
            $rows = $base->join('purchase_items as pi', 'pi.purchase_id', '=', 'pu.id')
                ->groupBy('pi.variation_id')
                ->selectRaw('pi.variation_id as k, count(distinct pu.id) as documents, sum(pi.base_qty) as qty, sum(pi.landed_unit_cost * pi.base_qty) as amount')
                ->get();
            $names = Variation::query()->with('product')->whereKey($rows->pluck('k'))->get()->mapWithKeys(fn (Variation $v) => [$v->id => $v->fullName()]);

            return $rows->map(fn ($r) => [
                'label' => $names[$r->k] ?? '—', 'documents' => (int) $r->documents, 'qty' => Decimal::toDb((string) $r->qty),
                'amount' => Decimal::toDb((string) $r->amount, 2),
                'average' => (float) $r->qty ? Decimal::toDb(Decimal::divide((string) $r->amount, (string) $r->qty), 2) : '0.00',
            ])->sortByDesc(fn ($r) => (float) $r['amount'])->values()->all();
        }

        $key = match ($dimension) {
            'supplier' => 'pu.supplier_id',
            'month' => SqlDates::month('pu.purchase_date'),
            'location' => 'pu.location_id',
        };
        $rows = $base->groupByRaw($key)
            ->selectRaw("{$key} as k, count(*) as documents, sum(pu.total * pu.exchange_rate) as amount, sum(pu.tax_amount * pu.exchange_rate) as tax, sum(pu.paid_total) as paid, sum(pu.due_total) as due")
            ->get();
        $returnKey = match ($dimension) {
            'supplier' => 'pr.supplier_id',
            'month' => SqlDates::month('pr.return_date'),
            'location' => 'pr.location_id',
        };
        $returns = $filter->apply(DB::table('purchase_returns as pr')->whereNull('pr.voided_at'), 'pr.return_date', 'pr.location_id')
            ->groupByRaw($returnKey)->selectRaw("{$returnKey} as k, sum(pr.total) as amount")
            ->pluck('amount', 'k');
        $names = match ($dimension) {
            'supplier' => DB::table('contacts')->whereIn('id', $rows->pluck('k'))->pluck('name', 'id'),
            'location' => DB::table('locations')->whereIn('id', $rows->pluck('k'))->pluck('name', 'id'),
            default => collect(),
        };

        return $rows->map(fn ($r) => [
            'label' => $dimension === 'month' ? (string) $r->k : ($names[$r->k] ?? '—'),
            'documents' => (int) $r->documents,
            'amount' => Decimal::toDb((string) $r->amount, 2),
            'tax' => Decimal::toDb((string) $r->tax, 2),
            'returns' => Decimal::toDb((string) ($returns[$r->k] ?? 0), 2),
            'net' => Decimal::toDb(Decimal::of((string) $r->amount)->minus(Decimal::of((string) ($returns[$r->k] ?? 0))), 2),
            'due' => Decimal::toDb((string) $r->due, 2),
        ])->sortBy(fn ($r) => $dimension === 'month' ? $r['label'] : -(float) $r['amount'])->values()->all();
    }

    /**
     * On hand and value per item (value = Σ stock movements at cost, the same figure as the Inventory account).
     *
     * @param  list<int>|null  $locationIds
     * @return list<array<string, mixed>>
     */
    public function stock(?array $locationIds, ?int $categoryId = null, bool $lowOnly = false): array
    {
        $values = DB::table('stock_movements')
            ->when($locationIds !== null, fn ($q) => $q->whereIn('location_id', $locationIds))
            ->groupBy('variation_id')
            ->selectRaw('variation_id, sum(qty) as qty, sum(qty * unit_cost) as value')
            ->get()->keyBy('variation_id');

        $variations = Variation::query()->with('product.category')
            ->where(fn ($q) => $q->whereIn('id', $values->keys())
                // items with an alert level show up even if they never had stock here
                ->when($lowOnly, fn ($q) => $q->orWhereHas('product', fn ($p) => $p->whereNotNull('alert_qty'))))
            ->get()
            ->filter(fn (Variation $v) => $v->product->tracksStock())
            ->when($categoryId, fn ($c) => $c->filter(fn (Variation $v) => $v->product->category_id === $categoryId));

        return $variations->map(function (Variation $v) use ($values) {
            $qty = Decimal::of((string) ($values[$v->id]->qty ?? 0));
            $value = Decimal::of((string) ($values[$v->id]->value ?? 0));
            $alert = $v->product->alert_qty;

            return [
                'sku' => $v->sku,
                'label' => $v->fullName(),
                'category' => $v->product->category->name ?? '—',
                'qty' => Decimal::toDb($qty),
                'cost' => $qty->isPositive() ? Decimal::toDb($value->dividedBy($qty, 2, RoundingMode::HalfUp), 2) : '0.00',
                'value' => Decimal::toDb($value, 2),
                'retail' => Decimal::toDb($qty->multipliedBy(Decimal::of($v->sell_price_inc_tax)), 2),
                'alert' => $alert !== null ? Decimal::toDb($alert) : null,
                'low' => $alert !== null && ! $qty->isGreaterThan(Decimal::of($alert)),
            ];
        })
            ->filter(fn (array $r) => ! $lowOnly || $r['low'])
            ->sortBy('label')->values()->all();
    }

    /**
     * Tax collected on sales vs. tax paid on purchases and expenses, from the tax accounts of the journal.
     *
     * @return array{rows: list<array{kind: string, output: string, input: string}>, output: string, input: string, net: string}
     */
    public function tax(ReportFilter $filter): array
    {
        $accounts = DB::table('accounts')->whereIn('sub_type', [AccountSubType::TaxOutput->value, AccountSubType::TaxInput->value])->pluck('sub_type', 'id');
        $lines = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->whereIn('l.account_id', $accounts->keys())
            ->whereBetween('l.entry_date', [$filter->from->toDateTimeString(), $filter->to->toDateTimeString()])
            ->when($filter->locationIds !== null, fn ($q) => $q->whereIn('l.location_id', $filter->locationIds))
            ->groupBy('l.account_id', 'e.source_type')
            ->selectRaw('l.account_id, e.source_type, sum(l.debit) as d, sum(l.credit) as c')
            ->get();

        $rows = [];
        $output = $input = BigDecimal::zero();
        foreach ($lines as $line) {
            $kind = $line->source_type ?? 'manual';
            $rows[$kind] ??= ['kind' => $kind === 'manual' ? __('Manual entry') : __('statement.kinds.'.$kind), 'output' => BigDecimal::zero(), 'input' => BigDecimal::zero()];
            if ($accounts[$line->account_id] === AccountSubType::TaxOutput->value) {
                $v = Decimal::of((string) $line->c)->minus(Decimal::of((string) $line->d)); // owed to the tax office
                $rows[$kind]['output'] = $rows[$kind]['output']->plus($v);
                $output = $output->plus($v);
            } else {
                $v = Decimal::of((string) $line->d)->minus(Decimal::of((string) $line->c)); // reclaimable
                $rows[$kind]['input'] = $rows[$kind]['input']->plus($v);
                $input = $input->plus($v);
            }
        }

        return [
            'rows' => array_values(array_map(fn ($r) => [...$r, 'output' => Decimal::toDb($r['output'], 2), 'input' => Decimal::toDb($r['input'], 2)], $rows)),
            'output' => Decimal::toDb($output, 2),
            'input' => Decimal::toDb($input, 2),
            'net' => Decimal::toDb($output->minus($input), 2),
        ];
    }

    /**
     * What each customer owes (receivable) or each supplier is owed (payable), split by how old the debt is.
     * The account balance is what is really owed. Payments pay off the oldest debt first, so the balance is
     * spread over the newest movements that raised it: invoices, money paid out to them, opening balances,
     * journal entries. A settled account doesn't show, even with invoices that no payment was linked to
     * (most vouchers of the old system). For one branch: that branch's open invoices, up to the balance.
     *
     * @param  list<int>|null  $locationIds
     * @return list<array<string, mixed>>
     */
    public function aging(string $side, ?array $locationIds = null): array
    {
        $receivable = $side === 'receivable';
        $types = $receivable ? [ContactType::Customer->value, ContactType::Both->value] : [ContactType::Supplier->value, ContactType::Both->value];
        $contacts = Contact::query()->whereIn('type', $types)->where('balance', $receivable ? '>' : '<', 0)->get(['id', 'name', 'mobile', 'balance']);
        $branchDocs = $locationIds !== null ? $this->openDocuments($receivable, $locationIds) : null;
        $ledger = app(ContactLedger::class);
        $today = strtotime(now()->toDateString());

        return $contacts->map(function (Contact $contact) use ($receivable, $branchDocs, $ledger, $today) {
            $row = ['current' => BigDecimal::zero(), 'b31' => BigDecimal::zero(), 'b61' => BigDecimal::zero(), 'b90' => BigDecimal::zero()];
            $owed = Decimal::of($contact->balance)->abs();
            $charges = $branchDocs !== null ? ($branchDocs[$contact->id] ?? collect()) : $ledger->charges($contact, $receivable);
            foreach ($charges as $charge) {
                if (! $owed->isPositive()) {
                    break;
                }
                $amount = BigDecimal::min(Decimal::of((string) $charge->amount), $owed);
                $owed = $owed->minus($amount);
                $days = (int) floor(($today - strtotime(substr((string) $charge->entry_date, 0, 10))) / 86400);
                $bucket = match (true) {
                    $days <= 30 => 'current',
                    $days <= 60 => 'b31',
                    $days <= 90 => 'b61',
                    default => 'b90',
                };
                $row[$bucket] = $row[$bucket]->plus($amount);
            }
            $total = array_reduce($row, fn (BigDecimal $c, BigDecimal $v) => $c->plus($v), BigDecimal::zero());

            return [
                'label' => $contact->name,
                'mobile' => $contact->mobile,
                ...array_map(fn (BigDecimal $v) => Decimal::toDb($v, 2), $row),
                'total' => Decimal::toDb($total, 2),
                'balance' => Decimal::toDb(Decimal::of($contact->balance)->abs(), 2),
            ];
        })->filter(fn ($r) => (float) $r['total'] > 0)->sortByDesc(fn ($r) => (float) $r['total'])->values()->all();
    }

    /**
     * Open invoices of the given branches per contact, newest first (entry_date, amount).
     *
     * @param  list<int>  $locationIds
     * @return Collection<int|string, Collection<int, \stdClass>>
     */
    private function openDocuments(bool $receivable, array $locationIds): Collection
    {
        $docs = $receivable
            ? DB::table('sales')->whereNull('voided_at')->where('status', SaleStatus::Final->value)->where('due_total', '>', 0)
                ->whereIn('location_id', $locationIds)->selectRaw('customer_id as contact_id, sale_date as entry_date, due_total as amount')
            : DB::table('purchases')->whereNull('voided_at')->where('due_total', '>', 0)
                ->whereIn('location_id', $locationIds)->selectRaw('supplier_id as contact_id, purchase_date as entry_date, due_total * exchange_rate as amount');

        return $docs->orderByDesc('entry_date')->get()->groupBy('contact_id');
    }

    /** @return list<array<string, mixed>> */
    public function expenses(string $dimension, ReportFilter $filter): array
    {
        abort_unless(in_array($dimension, self::EXPENSE_DIMENSIONS, true), 422);
        $key = match ($dimension) {
            'category' => 'coalesce(ex.expense_category_id, 0)',
            'month' => SqlDates::month('ex.expense_date'),
            'location' => 'ex.location_id',
            'user' => 'coalesce(ex.expense_for_user_id, 0)',
        };
        $rows = $filter->apply(DB::table('expenses as ex')->whereNull('ex.voided_at'), 'ex.expense_date', 'ex.location_id')
            ->groupByRaw($key)
            ->selectRaw("{$key} as k, count(*) as documents, sum(case when ex.is_refund = 1 then -ex.subtotal else ex.subtotal end) as amount,
                sum(case when ex.is_refund = 1 then -ex.tax_amount else ex.tax_amount end) as tax,
                sum(case when ex.is_refund = 1 then -ex.total else ex.total end) as total, sum(ex.due_total) as due")
            ->get();
        $names = match ($dimension) {
            'category' => DB::table('expense_categories')->whereIn('id', $rows->pluck('k'))->pluck('name', 'id'),
            'location' => DB::table('locations')->whereIn('id', $rows->pluck('k'))->pluck('name', 'id'),
            'user' => DB::table('users')->whereIn('id', $rows->pluck('k'))->pluck('name', 'id'),
            default => collect(),
        };

        return $rows->map(fn ($r) => [
            'label' => $dimension === 'month' ? (string) $r->k : ($names[$r->k] ?? __('Not set')),
            'documents' => (int) $r->documents,
            'amount' => Decimal::toDb((string) $r->amount, 2),
            'tax' => Decimal::toDb((string) $r->tax, 2),
            'total' => Decimal::toDb((string) $r->total, 2),
            'due' => Decimal::toDb((string) $r->due, 2),
        ])->sortBy(fn ($r) => $dimension === 'month' ? $r['label'] : -(float) $r['total'])->values()->all();
    }

    /**
     * Money in and out of every cash box and bank in a period, from the journal.
     *
     * @return array{accounts: list<array<string, string>>, sources: list<array<string, string>>}
     */
    public function moneyMovement(ReportFilter $filter): array
    {
        $accounts = DB::table('accounts')->whereIn('sub_type', [AccountSubType::Cash->value, AccountSubType::Bank->value])
            ->where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name']);
        $ids = $accounts->pluck('id');
        $lines = fn () => DB::table('journal_lines as l')->whereIn('l.account_id', $ids)
            ->when($filter->locationIds !== null, fn ($q) => $q->where(fn ($q) => $q->whereIn('l.location_id', $filter->locationIds)->orWhereNull('l.location_id')));

        $opening = $lines()->where('l.entry_date', '<', $filter->from->toDateString())
            ->groupBy('l.account_id')->selectRaw('l.account_id, sum(l.debit) - sum(l.credit) as v')->pluck('v', 'account_id');
        $period = $lines()->whereBetween('l.entry_date', [$filter->from->toDateTimeString(), $filter->to->toDateTimeString()])
            ->groupBy('l.account_id')->selectRaw('l.account_id, sum(l.debit) as d, sum(l.credit) as c')->get()->keyBy('account_id');

        $rows = $accounts->map(function ($a) use ($opening, $period) {
            $open = Decimal::of((string) ($opening[$a->id] ?? 0));
            $in = Decimal::of((string) ($period[$a->id]->d ?? 0));
            $out = Decimal::of((string) ($period[$a->id]->c ?? 0));

            return ['label' => $a->code.' — '.$a->name, 'opening' => Decimal::toDb($open, 2), 'in' => Decimal::toDb($in, 2),
                'out' => Decimal::toDb($out, 2), 'closing' => Decimal::toDb($open->plus($in)->minus($out), 2)];
        })->reject(fn ($r) => (float) $r['opening'] === 0.0 && (float) $r['in'] === 0.0 && (float) $r['out'] === 0.0)->values();

        $sources = $lines()->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->whereBetween('l.entry_date', [$filter->from->toDateTimeString(), $filter->to->toDateTimeString()])
            ->groupBy('e.source_type')->selectRaw('e.source_type, sum(l.debit) as d, sum(l.credit) as c')->get()
            ->map(fn ($r) => ['label' => $r->source_type ? __('statement.kinds.'.$r->source_type) : __('Manual entry'),
                'in' => Decimal::toDb((string) $r->d, 2), 'out' => Decimal::toDb((string) $r->c, 2),
                'net' => Decimal::toDb(Decimal::of((string) $r->d)->minus(Decimal::of((string) $r->c)), 2)])
            ->values();

        return ['accounts' => $rows->all(), 'sources' => $sources->all()];
    }
}
