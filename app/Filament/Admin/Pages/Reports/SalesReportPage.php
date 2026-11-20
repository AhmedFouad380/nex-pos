<?php

namespace App\Filament\Admin\Pages\Reports;

use App\Domain\Reporting\Services\SalesReport;
use Filament\Support\Icons\Heroicon;

class SalesReportPage extends ReportPage
{
    protected static string $permission = 'reports.sales';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'reports/sales';

    public static function getNavigationLabel(): string
    {
        return __('Sales and profit');
    }

    public function groupOptions(): array
    {
        return [
            'day' => __('Day'), 'month' => __('Month'), 'product' => __('Product'), 'category' => __('Category'), 'brand' => __('Brand'),
            'customer' => __('Customer'), 'user' => __('Cashier / user'), 'location' => __('Location'),
        ];
    }

    public function cards(): array
    {
        $s = app(SalesReport::class)->summary($this->filter());

        return [
            [__('Invoices'), $s['invoices'], 'int'],
            [__('Gross sales'), $s['sales']],
            [__('Returns'), $s['returns']],
            [__('Net sales'), $s['net']],
            [__('Cost of goods sold'), $s['cost']],
            [__('Gross profit'), $s['profit'], 'money', (float) $s['profit'] < 0 ? 'danger' : 'success'],
            [__('Margin'), $s['margin'], 'percent'],
            [__('Average invoice'), $s['average']],
            [__('Tax collected'), $s['tax']],
        ];
    }

    public function tables(): array
    {
        $rows = app(SalesReport::class)->by($this->group ?? 'day', $this->filter())->all();
        $columns = ['label' => [$this->groupOptions()[$this->group ?? 'day'] ?? '']];
        if ($this->group === 'product') {
            $columns['qty'] = [__('Qty'), 'qty'];
        } else {
            $columns['invoices'] = [__('Invoices'), 'int'];
        }
        $columns += [
            'sales' => [__('Gross sales'), 'money'], 'returns' => [__('Returns'), 'money'], 'net' => [__('Net sales'), 'money'],
            'cost' => [__('Cost'), 'money'], 'profit' => [__('Gross profit'), 'money'], 'margin' => [__('Margin'), 'percent'],
        ];
        $totals = self::totals($rows, ['sales', 'returns', 'net', 'cost', 'profit']);
        $totals['invoices'] = array_sum(array_column($rows, 'invoices'));

        return [['columns' => $columns, 'rows' => $rows, 'totals' => $totals]];
    }
}
