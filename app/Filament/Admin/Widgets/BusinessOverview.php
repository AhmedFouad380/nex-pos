<?php

namespace App\Filament\Admin\Widgets;

use App\Domain\Finance\Enums\AccountSubType;
use App\Domain\Reporting\Services\SalesReport;
use App\Filament\Admin\Widgets\Concerns\DashboardScope;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/** Today / this month at a glance: sales, profit, expenses, who owes whom, money on hand. */
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
        $sales = app(SalesReport::class);
        $today = $sales->summary($this->period(now(), now()));
        $yesterday = $sales->summary($this->period(now()->subDay(), now()->subDay()));
        $month = $sales->summary($this->period(now()->startOfMonth(), now()));
        $fmt = fn ($v) => number_format((float) $v, 2);
        $change = (float) $yesterday['net'] > 0 ? round(((float) $today['net'] - (float) $yesterday['net']) / (float) $yesterday['net'] * 100) : null;
        $user = auth()->user();

        $stats = [
            Stat::make(__('Sales today'), $fmt($today['net']))
                ->description(__(':count invoices', ['count' => $today['invoices']]).($change !== null ? ' · '.($change >= 0 ? '+' : '').$change.'% '.__('vs yesterday') : ''))
                ->descriptionIcon($change !== null && $change < 0 ? 'heroicon-m-arrow-trending-down' : 'heroicon-m-arrow-trending-up')
                ->color($change !== null && $change < 0 ? 'danger' : 'success'),
            Stat::make(__('Sales this month'), $fmt($month['net']))->description(__(':count invoices', ['count' => $month['invoices']])),
        ];

        if ($user?->can('reports.profit_loss')) {
            $expenses = DB::table('expenses')->whereNull('voided_at')->where('expense_date', '>=', now()->startOfMonth())
                ->when($this->locationIds() !== null, fn ($q) => $q->whereIn('location_id', $this->locationIds()))->sum('total');
            $stats[] = Stat::make(__('Gross profit this month'), $fmt($month['profit']))
                ->description($month['margin'] !== null ? __('Margin').' '.$month['margin'].'%' : null)
                ->color((float) $month['profit'] < 0 ? 'danger' : 'success');
            $stats[] = Stat::make(__('Expenses this month'), $fmt($expenses))->color('warning');
        }

        if ($user?->can('reports.accounting') || $user?->can('reports.contacts')) {
            $balance = fn (array $roles) => (float) DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')
                ->whereIn('a.sub_type', array_map(fn ($r) => $r->value, $roles))->sum(DB::raw('l.debit - l.credit'));
            $stats[] = Stat::make(__('Customers owe us'), $fmt($balance([AccountSubType::Receivable])))->color('info');
            $stats[] = Stat::make(__('We owe suppliers'), $fmt(-$balance([AccountSubType::Payable])))->color('warning');
            $stats[] = Stat::make(__('Cash on hand'), $fmt($balance([AccountSubType::Cash])));
            $stats[] = Stat::make(__('In the bank'), $fmt($balance([AccountSubType::Bank])));
        }

        return $stats;
    }
}
