<?php

namespace App\Filament\Admin\Pages\Reports;

use App\Domain\Reporting\Services\OperationsReports;
use Filament\Support\Icons\Heroicon;

/** أعمار الديون: what customers owe us / what we owe suppliers, by how overdue it is. */
class AgingReportPage extends ReportPage
{
    protected static string $permission = 'reports.contacts';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?int $navigationSort = 50;

    protected static ?string $slug = 'reports/aging';

    protected static bool $usesPeriod = false;

    public static function getNavigationLabel(): string
    {
        return __('Debt aging');
    }

    public function groupOptions(): array
    {
        return ['receivable' => __('Customers (receivable)'), 'payable' => __('Suppliers (payable)')];
    }

    public function tables(): array
    {
        $rows = app(OperationsReports::class)->aging($this->group ?? 'receivable', $this->locationIds());

        return [['columns' => [
            'label' => [__('Name')], 'mobile' => [__('Mobile')], 'current' => [__('0–30 days'), 'money'], 'b31' => [__('31–60 days'), 'money'],
            'b61' => [__('61–90 days'), 'money'], 'b90' => [__('Over 90 days'), 'money'], 'total' => [__('Total due'), 'money'],
        ], 'rows' => $rows, 'totals' => self::totals($rows, ['current', 'b31', 'b61', 'b90', 'total'])]];
    }
}
