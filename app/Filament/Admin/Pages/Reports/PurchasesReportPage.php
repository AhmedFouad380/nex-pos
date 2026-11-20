<?php

namespace App\Filament\Admin\Pages\Reports;

use App\Domain\Reporting\Services\OperationsReports;
use Filament\Support\Icons\Heroicon;

class PurchasesReportPage extends ReportPage
{
    protected static string $permission = 'reports.purchases';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'reports/purchases';

    public static function getNavigationLabel(): string
    {
        return __('Purchases report');
    }

    public function groupOptions(): array
    {
        return ['supplier' => __('Supplier'), 'product' => __('Product'), 'month' => __('Month'), 'location' => __('Location')];
    }

    public function tables(): array
    {
        $rows = app(OperationsReports::class)->purchases($this->group ?? 'supplier', $this->filter());
        $label = ['label' => [$this->groupOptions()[$this->group ?? 'supplier'] ?? '']];

        if ($this->group === 'product') {
            return [['columns' => $label + ['documents' => [__('Invoices'), 'int'], 'qty' => [__('Qty'), 'qty'],
                'amount' => [__('Cost'), 'money'], 'average' => [__('Average cost'), 'money']],
                'rows' => $rows, 'totals' => self::totals($rows, ['amount'])]];
        }

        return [['columns' => $label + ['documents' => [__('Invoices'), 'int'], 'amount' => [__('Purchases'), 'money'],
            'tax' => [__('Tax'), 'money'], 'returns' => [__('Returns'), 'money'], 'net' => [__('Net'), 'money'], 'due' => [__('Due'), 'money']],
            'rows' => $rows, 'totals' => self::totals($rows, ['amount', 'tax', 'returns', 'net', 'due'])]];
    }
}
