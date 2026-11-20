<?php

namespace App\Domain\Legacy\Import\Stages;

use App\Domain\Legacy\Import\Custody;
use App\Domain\Legacy\Import\ImportContext;
use App\Domain\Legacy\Import\LegacyPackage;
use App\Domain\Legacy\Import\Stage;
use App\Domain\Sales\Enums\SaleStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Compares the imported system with the old one, from the old system's own records:
 *   accounts  — balance of every old account (its journal, without year-closing vouchers) vs the same account here
 *   contacts  — every customer / supplier / employee: old account balance vs contact balance here
 *   stock     — every item × store: old store receipts / issues (8, 11, 12, transfers) vs stock here
 *   sales     — net sales per year
 * Writes reconciliation-*.csv next to the package and a summary into the import report.
 */
final class ReconciliationStage implements Stage
{
    private const TOLERANCE = 0.01;

    public function name(): string
    {
        return 'reconciliation';
    }

    public function run(LegacyPackage $package, ImportContext $context): void
    {
        $sections = [
            'contacts' => $this->contacts($package, $context),
            'accounts' => $this->accounts($package, $context),
            'stock' => $this->stock($package, $context),
            'sales' => $this->sales($package),
        ];

        $summary = [];
        foreach ($sections as $name => $rows) {
            $diffs = array_values(array_filter($rows, fn ($r) => abs((float) $r['difference']) > self::TOLERANCE));
            $summary[$name] = ['checked' => count($rows), 'different' => count($diffs),
                'old_total' => round(array_sum(array_column($rows, 'old')), 2), 'new_total' => round(array_sum(array_column($rows, 'new')), 2)];
            $this->csv($package->dir."/reconciliation-{$name}.csv", $rows);
        }

        $report = $context->import->report ?? [];
        $report['reconciliation'] = $summary;
        $context->import->forceFill(['report' => $report])->save();
        foreach ($summary as $name => $s) {
            $context->count($this->name(), "{$name}: {$s['different']} of {$s['checked']} differ", 0);
        }
    }

    /** Σ debit − Σ credit per old account, from its journal (year closings left out, as they were not imported). @return array<string, float> */
    private function oldBalances(LegacyPackage $package): array
    {
        $closing = [];
        foreach ($package->rows('JournalVoucher') as $jv) {
            if ((int) ($jv['Source'] ?? 0) === 6) {
                $closing[ImportContext::key($jv['BranchId'], $jv['JVDId'], $jv['FYId'], $jv['JVId'])] = true;
            }
        }
        $balances = [];
        foreach ($package->rows('JournalVoucherDetails') as $d) {
            if (! isset($closing[ImportContext::key($d['BranchId'], $d['JVDId'], $d['FYId'], $d['JVId'])])) {
                $code = (string) $d['AccountId'];
                $balances[$code] = ($balances[$code] ?? 0) + (float) ($d['DebitLocal'] ?? $d['Debit'] ?? 0) - (float) ($d['CreditLocal'] ?? $d['Credit'] ?? 0);
            }
        }
        // the old opening entry is not a journal voucher
        foreach ($package->rows('AccountsOpeningBalance') as $ob) {
            $code = (string) $ob['AccountId'];
            $balances[$code] = ($balances[$code] ?? 0) + (float) ($ob['DebitLocal'] ?? 0) - (float) ($ob['CreditLocal'] ?? 0);
        }

        return $balances;
    }

    /** @return list<array<string, mixed>> */
    private function contacts(LegacyPackage $package, ImportContext $context): array
    {
        $old = $this->oldBalances($package);
        $rows = [];
        foreach ($package->all('ChartOfAccount') as $account) {
            $code = (string) $account['AccountId'];
            if ($context->newType('ChartOfAccount', $code) !== 'contact') {
                continue;
            }
            $contact = DB::table('contacts')->where('id', $context->newId('ChartOfAccount', $code))->first(['name', 'type', 'balance']);
            // an old custody account was both the person and a cash box: compare with both together
            $custody = $context->newId('Custody', $code);
            $box = $custody ? (float) DB::table('journal_lines')->where('account_id', $custody)->sum(DB::raw('debit - credit')) : 0;
            $rows[] = $this->row($code, (string) ($contact->name ?? $account['NameA']), ($contact->type ?? '').($custody ? ' + custody' : ''),
                $old[$code] ?? 0, (float) ($contact->balance ?? 0) + $box);
        }

        return $rows;
    }

    /**
     * Account by account — except where the old chart split what one account holds here, which is compared as
     * a group: sales (cash / credit / general), cost of sales and stock (one per store), cash (boxes + cashier
     * custodies), and each control account (one old account per customer / supplier / employee, custodies apart).
     *
     * @return list<array<string, mixed>>
     */
    private function accounts(LegacyPackage $package, ImportContext $context): array
    {
        $old = $this->oldBalances($package);
        $newByAccount = DB::table('journal_lines')->groupBy('account_id')->selectRaw('account_id, sum(debit) - sum(credit) as net')->pluck('net', 'account_id');
        $newByRole = DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->whereNotNull('a.sub_type')
            ->groupBy('a.sub_type')->selectRaw('a.sub_type as role, sum(l.debit) - sum(l.credit) as net')->pluck('net', 'role');
        $chart = $package->all('ChartOfAccount');
        $children = fn (string $parent) => array_map(fn ($a) => (string) $a['AccountId'], array_filter($chart, fn ($a) => (string) $a['AccountIdParent'] === $parent));
        $custody = Custody::codes($package);
        $settings = $package->all('GeneralSetting')[0] ?? [];
        $stores = $package->all('Store');

        $groups = [
            'sales' => [__('legacy.group_sales'), array_filter([(string) ($settings['Sales'] ?? ''), (string) ($settings['SalesCash'] ?? ''), (string) ($settings['SalesCredit'] ?? '')])],
            'cogs' => [__('legacy.group_cogs'), array_filter(array_map(fn ($st) => (string) ($st['CostOfSales'] ?? ''), $stores))],
            'inventory' => [__('legacy.group_inventory'), $children('102005')],
            'cash' => [__('legacy.group_cash'), [...$children('102001'), ...$custody]],
        ];
        foreach (AccountsStage::CONTACT_PARENTS as $parent => [$role]) {
            $groups[$role->value] = [__('legacy.group_contacts', ['name' => collect($chart)->firstWhere('AccountId', (string) $parent)['NameA'] ?? $parent]),
                array_diff([(string) $parent, ...$children((string) $parent)], $custody)];
        }

        $rows = [];
        $grouped = [];
        foreach ($groups as $role => [$label, $codes]) {
            $codes = array_values(array_unique($codes));
            $grouped = [...$grouped, ...$codes];
            $rows[] = $this->row($role, $label, 'group', array_sum(array_map(fn ($c) => $old[$c] ?? 0, $codes)), (float) ($newByRole[$role] ?? 0));
        }
        foreach ($chart as $account) {
            $code = (string) $account['AccountId'];
            if (in_array($code, $grouped, true) || $context->newType('ChartOfAccount', $code) !== 'account') {
                continue;
            }
            $rows[] = $this->row($code, (string) $account['NameA'], '', $old[$code] ?? 0, (float) ($newByAccount[$context->newId('ChartOfAccount', $code)] ?? 0));
        }

        return $rows;
    }

    /**
     * Old stock rebuilt from the old documents the way the old system counted it (its store issues / receipts
     * are mostly empty headers): purchases, returns, opening stock, write-offs, counts and transfers, per store.
     *
     * @return list<array<string, mixed>>
     */
    private function stock(LegacyPackage $package, ImportContext $context): array
    {
        $old = [];
        $add = function (mixed $item, mixed $store, float $qty) use (&$old) {
            $key = $item.'|'.$store;
            $old[$key] = ($old[$key] ?? 0) + $qty;
        };
        foreach ($package->rows('InvoiceDetails') as $l) {
            $sign = match ((int) $l['InvoiceType']) {
                8, 9, 17 => 1, 10, 16, 18 => -1, default => 0,
            };
            if ($sign !== 0) {
                $add($l['ItemId'], $l['StoreId'], $sign * (float) $l['Quantity']);
            }
        }
        $counts = []; // stock-count headers only — the whole Invoice table is too big to hold
        foreach ($package->rows('Invoice') as $h) {
            if ((int) $h['InvoiceType'] === 19) {
                $counts[ImportContext::key($h['BranchId'], $h['FYId'], $h['InvoiceId'])] = ['StoreId' => $h['StoreId']];
            }
        }
        foreach ($package->rows('StocktakingDetails') as $d) {
            $h = $counts[ImportContext::key($d['BranchId'], $d['FYId'], $d['STId'])] ?? null;
            if ($h) {
                $add($d['ItemId'], $h['StoreId'] ?: $d['BranchId'], (float) $d['ActualQuantity'] - (float) $d['SystemQuantity']);
            }
        }
        $transfers = collect($package->all('GoodTransfer'))->keyBy(fn ($t) => ImportContext::key($t['BranchId'], $t['FYId'], $t['GTId']));
        foreach ($package->rows('GoodTransferDetails') as $d) {
            $t = $transfers[ImportContext::key($d['BranchId'], $d['FYId'], $d['GTId'])] ?? null;
            if ($t) {
                $add($d['ItemId'], $t['StoreIdFrom'], -(float) $d['Quantity']);
                $add($d['ItemId'], $t['StoreIdTo'], (float) $d['Quantity']);
            }
        }
        $new = DB::table('stock_levels')->get(['variation_id', 'location_id', 'qty_on_hand'])
            ->mapWithKeys(fn ($s) => [$s->variation_id.'|'.$s->location_id => (float) $s->qty_on_hand]);
        $names = DB::table('variations')->join('products', 'products.id', '=', 'variations.product_id')->pluck('products.name', 'variations.id');

        $rows = [];
        foreach ($old as $key => $qty) {
            [$item, $store] = explode('|', $key);
            $variation = $context->newId('ItemVariation', $item);
            if (! $variation) {
                continue;
            }
            $location = $context->newId('Store', $store);
            $rows[] = $this->row($item.' @ '.$store, (string) ($names[$variation] ?? ''), '', $qty, (float) ($new[$variation.'|'.$location] ?? 0));
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function sales(LegacyPackage $package): array
    {
        $old = [];
        foreach ($package->rows('Invoice') as $r) {
            $sign = match ((int) $r['InvoiceType']) {
                10 => 1, 17 => -1, default => 0,
            };
            if ($sign !== 0) {
                $year = substr((string) $r['InvoiceDate'], 0, 4);
                $old[$year] = ($old[$year] ?? 0) + $sign * (float) $r['NetTotal'];
            }
        }
        $rows = [];
        foreach ($old as $year => $value) {
            $sales = (float) DB::table('sales')->where('status', SaleStatus::Final->value)->whereNull('voided_at')
                ->whereBetween('sale_date', ["{$year}-01-01 00:00:00", "{$year}-12-31 23:59:59"])->sum('total');
            $returns = (float) DB::table('sale_returns')->whereNull('voided_at')
                ->whereBetween('return_date', ["{$year}-01-01 00:00:00", "{$year}-12-31 23:59:59"])->sum('total');
            $rows[] = $this->row((string) $year, '', '', $value, $sales - $returns);
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function row(string $key, string $name, string $kind, float $old, float $new): array
    {
        return ['key' => $key, 'name' => $name, 'kind' => $kind, 'old' => round($old, 2), 'new' => round($new, 2), 'difference' => round($new - $old, 2)];
    }

    /** @param list<array<string, mixed>> $rows */
    private function csv(string $path, array $rows): void
    {
        $handle = fopen($path, 'w');
        if ($handle === false) {
            return;
        }
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['key', 'name', 'kind', 'old', 'new', 'difference']);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);
        File::chmod($path, 0664);
    }
}
