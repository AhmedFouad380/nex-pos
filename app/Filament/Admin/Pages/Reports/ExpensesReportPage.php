<?php

namespace App\Filament\Admin\Pages\Reports;

use App\Domain\Reporting\Services\OperationsReports;
use Filament\Support\Icons\Heroicon;

class ExpensesReportPage extends ReportPage
{
    protected static string $permission = 'reports.expenses';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptRefund;

    protected static ?int $navigationSort = 60;

    protected static ?string $slug = 'reports/expenses';

    public static function getNavigationLabel(): string
    {
        return __('Expenses report');
    }

    public function groupOptions(): array
    {
        return ['category' => __('Expense category'), 'month' => __('Month'), 'location' => __('Location'), 'user' => __('Expense for')];
    }

    public function tables(): array
    {
        $rows = app(OperationsReports::class)->expenses($this->group ?? 'category', $this->filter());

        return [['columns' => [
            'label' => [$this->groupOptions()[$this->group ?? 'category'] ?? ''], 'documents' => [__('Count'), 'int'],
            'amount' => [__('Amount before tax'), 'money'], 'tax' => [__('Tax'), 'money'], 'total' => [__('Total'), 'money'], 'due' => [__('Due'), 'money'],
        ], 'rows' => $rows, 'totals' => self::totals($rows, ['amount', 'tax', 'total', 'due'])]];
    }
}
