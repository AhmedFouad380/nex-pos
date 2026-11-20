<?php

namespace App\Filament\Admin\Pages\Reports;

use App\Domain\Reporting\Services\OperationsReports;
use Filament\Support\Icons\Heroicon;

/** VAT return helper: tax collected on sales minus tax paid on purchases and expenses. */
class TaxReportPage extends ReportPage
{
    protected static string $permission = 'reports.tax';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'reports/tax';

    public static function getNavigationLabel(): string
    {
        return __('Tax report');
    }

    public function cards(): array
    {
        $t = app(OperationsReports::class)->tax($this->filter());

        return [
            [__('Output tax (sales)'), $t['output']],
            [__('Input tax (purchases and expenses)'), $t['input']],
            [__('Net tax payable'), $t['net'], 'money', (float) $t['net'] < 0 ? 'success' : 'danger'],
        ];
    }

    public function tables(): array
    {
        $t = app(OperationsReports::class)->tax($this->filter());

        return [['columns' => ['kind' => [__('Document')], 'output' => [__('Output tax'), 'money'], 'input' => [__('Input tax'), 'money']],
            'rows' => $t['rows'], 'totals' => ['kind' => __('Total'), 'output' => $t['output'], 'input' => $t['input']]]];
    }
}
