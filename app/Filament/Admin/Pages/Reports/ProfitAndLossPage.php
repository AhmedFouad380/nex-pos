<?php

namespace App\Filament\Admin\Pages\Reports;

use App\Domain\Reporting\Services\FinancialStatements;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

/** قائمة الدخل from the journal. */
class ProfitAndLossPage extends ReportPage
{
    protected static string $permission = 'reports.profit_loss';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'reports/profit-and-loss';

    protected static bool $usesLocation = false;

    public static function getNavigationLabel(): string
    {
        return __('Profit and loss');
    }

    /** @return array<string, mixed> */
    private function statement(): array
    {
        return app(FinancialStatements::class)->incomeStatement(Carbon::parse($this->from ?? now()->startOfMonth()), Carbon::parse($this->to ?? now()));
    }

    public function cards(): array
    {
        $s = $this->statement();

        return [
            [__('Net sales'), $s['net_sales']],
            [__('Cost of sales'), $s['cost_of_sales']],
            [__('Gross profit'), $s['gross_profit'], 'money', (float) $s['gross_profit'] < 0 ? 'danger' : 'success'],
            [__('Net profit'), $s['net_profit'], 'money', (float) $s['net_profit'] < 0 ? 'danger' : 'success'],
        ];
    }

    public function tables(): array
    {
        $s = $this->statement();
        $tables = array_map(fn (array $section) => [
            'title' => $section['title'],
            'columns' => ['code' => [__('Code')], 'name' => [__('Account')], 'amount' => [__('Amount'), 'money']],
            'rows' => $section['rows'],
            'totals' => ['code' => __('Total'), 'amount' => $section['total']],
        ], $s['sections']);

        $tables[] = [
            'title' => __('Result'),
            'columns' => ['name' => [''], 'amount' => [__('Amount'), 'money']],
            'rows' => [
                ['name' => __('Gross profit'), 'amount' => $s['gross_profit']],
                ['name' => __('Net profit'), 'amount' => $s['net_profit'], '_heading' => true],
            ],
        ];

        return $tables;
    }
}
