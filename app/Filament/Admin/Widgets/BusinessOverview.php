<?php

namespace App\Filament\Admin\Widgets;

use App\Domain\Finance\Enums\AccountSubType;
use App\Domain\Reporting\Services\SalesReport;
use App\Filament\Admin\Widgets\Concerns\DashboardScope;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/** The dashboard period at a glance: sales, profit, expenses, and who owes whom / money on hand at its end. */
class BusinessOverview extends StatsOverviewWidget
{
    use DashboardScope;

    protected static ?int $sort = 1;

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return (bool) auth()->user()?->can('dashboard.view');
    }

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $p = $this->dashboardPeriod();
        $sales = app(SalesReport::class);
        $now = $sales->summary($this->period($p['from'], $p['to']));
        $before = $sales->summary($this->period($p['prevFrom'], $p['prevTo']));
        $fmt = fn ($v) => number_format((float) $v, 2);
        $change = (float) $before['net'] > 0 ? round(((float) $now['net'] - (float) $before['net']) / (float) $before['net'] * 100) : null;
        $average = $now['invoices'] > 0 ? (float) $now['net'] / $now['invoices'] : 0;
        $user = auth()->user();

        $stats = [
            Stat::make(__('Net sales').' — '.$p['label'], $fmt($now['net']))
                ->description(__(':count invoices', ['count' => $now['invoices']]).($change !== null ? ' · '.($change >= 0 ? '+' : '').$change.'% '.__('vs the period before') : ''))
                ->descriptionIcon($change !== null && $change < 0 ? 'heroicon-m-arrow-trending-down' : 'heroicon-m-arrow-trending-up')
                ->color($change !== null && $change < 0 ? 'danger' : 'success'),
            Stat::make(__('Average invoice'), $fmt($average))->description(__('Returns').': '.$fmt($now['returns'])),
        ];

        if ($user?->can('reports.profit_loss')) {
            $expenses = DB::table('expenses')->whereNull('voided_at')->whereBetween('expense_date', [$p['from'], $p['to']])
                ->when($this->locationIds() !== null, fn ($q) => $q->whereIn('location_id', $this->locationIds()))->sum('total');
            $stats[] = Stat::make(__('Gross profit'), $fmt($now['profit']))
                ->description($now['margin'] !== null ? __('Margin').' '.$now['margin'].'%' : null)
                ->color((float) $now['profit'] < 0 ? 'danger' : 'success');
            $stats[] = Stat::make(__('Expenses'), $fmt($expenses))->color('warning');
        }

        if ($user?->can('reports.accounting') || $user?->can('reports.contacts')) {
            // balances at the end of the period
            $balance = fn (array $roles) => (float) DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')
                ->whereIn('a.sub_type', array_map(fn ($r) => $r->value, $roles))->where('l.entry_date', '<=', $p['to']->toDateString())
                ->sum(DB::raw('l.debit - l.credit'));
            $asOf = __('On :date', ['date' => $p['to']->format('Y-m-d')]);
            $stats[] = Stat::make(__('Customers owe us'), $fmt($balance([AccountSubType::Receivable])))->description($asOf)->color('info');
            $stats[] = Stat::make(__('We owe suppliers'), $fmt(-$balance([AccountSubType::Payable])))->description($asOf)->color('warning');
            $stats[] = Stat::make(__('Cash on hand'), $fmt($balance([AccountSubType::Cash])))->description($asOf);
            $stats[] = Stat::make(__('In the bank'), $fmt($balance([AccountSubType::Bank])))->description($asOf);
        }

        return $stats;
    }
}
