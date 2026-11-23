<?php

namespace App\Filament\Admin\Resources\Employees\Widgets;

use App\Domain\CRM\Models\Contact;
use App\Domain\Finance\Enums\PaymentDirection;
use App\Domain\Finance\Models\Payment;
use App\Domain\Payroll\Models\PayrollLine;
use App\Domain\Payroll\Services\AdvanceBalances;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Support\FiscalYear;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Number;

/** The employee at a glance for the selected year: what we paid them, what came back, salaries, purchases, advances and the balance. */
class EmployeeOverview extends StatsOverviewWidget
{
    public ?Model $record = null;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        $employee = $this->record;
        if (! $employee instanceof Contact) {
            return [];
        }
        $year = FiscalYear::label(FiscalYear::selected());
        $money = fn ($value) => Number::format((float) (string) $value, 2);
        $vouchers = fn (PaymentDirection $direction) => FiscalYear::scope(Payment::query(), 'paid_at')
            ->where('contact_id', $employee->id)->where('direction', $direction->value)->whereNull('voided_at');
        $out = $vouchers(PaymentDirection::Out);
        $in = $vouchers(PaymentDirection::In);
        [$from, $to] = FiscalYear::range();
        $salaries = PayrollLine::query()->where('contact_id', $employee->id)
            ->whereHas('run', fn ($run) => $run->whereBetween('period', [$from->toDateString(), $to->toDateString()]));
        $purchases = FiscalYear::scope(Sale::query(), 'sale_date')->where('customer_id', $employee->id)
            ->where('status', SaleStatus::Final->value)->whereNull('voided_at');
        $balance = (float) $employee->balance;
        $open = (string) app(AdvanceBalances::class)->openTotal($employee);

        return [
            Stat::make(__('Paid to the employee').' — '.$year, $money((clone $out)->sum('amount')))
                ->description(trans_choice(':count voucher|:count vouchers', (clone $out)->count()))->color('danger'),
            Stat::make(__('Received from the employee').' — '.$year, $money((clone $in)->sum('amount')))
                ->description(trans_choice(':count voucher|:count vouchers', (clone $in)->count()))->color('success'),
            Stat::make(__('Net salaries').' — '.$year, $money($salaries->sum('net')))
                ->description(trans_choice(':count payroll|:count payrolls', $salaries->count())),
            Stat::make(__('Purchases from the store').' — '.$year, $money($purchases->sum('total')))
                ->description(trans_choice(':count invoice|:count invoices', $purchases->count())),
            Stat::make(__('Open advances'), $money($open))->color((float) $open > 0 ? 'warning' : 'gray'),
            Stat::make(__('Balance now'), $money(abs($balance)))
                ->description(match (true) {
                    $balance > 0 => __('Owed by the employee'),
                    $balance < 0 => __('Owed to the employee'),
                    default => __('Settled'),
                })->color($balance > 0 ? 'warning' : ($balance < 0 ? 'info' : 'gray')),
        ];
    }
}
