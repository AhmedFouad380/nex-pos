<?php

namespace App\Filament\Admin\Pages\Reports;

use App\Domain\Reporting\Services\OperationsReports;
use Filament\Support\Icons\Heroicon;

/** حركة النقدية: what came into and went out of each cash box and bank, and why. */
class MoneyMovementPage extends ReportPage
{
    protected static string $permission = 'reports.payments';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 70;

    protected static ?string $slug = 'reports/money-movement';

    public static function getNavigationLabel(): string
    {
        return __('Cash and bank movement');
    }

    public function tables(): array
    {
        $m = app(OperationsReports::class)->moneyMovement($this->filter());

        return [
            ['title' => __('By account'), 'columns' => ['label' => [__('Account')], 'opening' => [__('Opening balance'), 'money'],
                'in' => [__('Money in'), 'money'], 'out' => [__('Money out'), 'money'], 'closing' => [__('Closing balance'), 'money']],
                'rows' => $m['accounts'], 'totals' => self::totals($m['accounts'], ['opening', 'in', 'out', 'closing'])],
            ['title' => __('By source'), 'columns' => ['label' => [__('Source')], 'in' => [__('Money in'), 'money'],
                'out' => [__('Money out'), 'money'], 'net' => [__('Net'), 'money']],
                'rows' => $m['sources'], 'totals' => self::totals($m['sources'], ['in', 'out', 'net'])],
        ];
    }
}
