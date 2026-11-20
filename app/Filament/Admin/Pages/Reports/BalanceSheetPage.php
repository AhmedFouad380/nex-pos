<?php

namespace App\Filament\Admin\Pages\Reports;

use App\Domain\Reporting\Services\FinancialStatements;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

/** الميزانية العمومية on a date. Uses the "to" date only. */
class BalanceSheetPage extends ReportPage
{
    protected static string $permission = 'reports.accounting';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'reports/balance-sheet';

    protected static bool $usesLocation = false;

    public static function getNavigationLabel(): string
    {
        return __('Balance sheet');
    }

    /** @return array<string, mixed> */
    private function sheet(): array
    {
        return app(FinancialStatements::class)->balanceSheet(Carbon::parse($this->to ?? now()));
    }

    public function cards(): array
    {
        $s = $this->sheet();
        [$assets, $liabilities, $equity] = $s['sections'];

        return [
            [__('Assets'), $assets['total']],
            [__('Liabilities'), $liabilities['total']],
            [__('Equity'), $equity['total']],
            [__('Status'), $s['balanced'] ? __('Balanced') : __('Not balanced'), 'text', $s['balanced'] ? 'success' : 'danger'],
        ];
    }

    public function tables(): array
    {
        return array_map(fn (array $section) => [
            'title' => $section['title'],
            'columns' => ['name' => [__('Account')], 'code' => [__('Code')], 'amount' => [__('Amount'), 'money']],
            'rows' => array_map(fn ($r) => [...$r, '_depth' => $r['depth'], '_heading' => $r['heading']], $section['rows']),
            'totals' => ['name' => __('Total'), 'amount' => $section['total']],
        ], $this->sheet()['sections']);
    }
}
