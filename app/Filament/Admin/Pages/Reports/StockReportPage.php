<?php

namespace App\Filament\Admin\Pages\Reports;

use App\Domain\Catalog\Models\Category;
use App\Domain\Reporting\Services\OperationsReports;
use Filament\Support\Icons\Heroicon;

/** Stock on hand and its value at cost and at selling price, with low-stock alerts. */
class StockReportPage extends ReportPage
{
    protected static string $permission = 'reports.stock';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'reports/stock';

    protected static bool $usesPeriod = false;

    public static function getNavigationLabel(): string
    {
        return __('Stock valuation');
    }

    public function optionChoices(): array
    {
        return ['' => __('All categories')] + Category::query()->orderBy('name')->pluck('name', 'id')->mapWithKeys(fn ($n, $id) => [(string) $id => $n])->all();
    }

    public function optionLabel(): string
    {
        return __('Category');
    }

    public function flagLabel(): string
    {
        return __('Low stock only');
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return app(OperationsReports::class)->stock($this->locationIds(), filled($this->option) ? (int) $this->option : null, $this->flag);
    }

    public function cards(): array
    {
        $rows = $this->rows();

        return [
            [__('Items'), count($rows), 'int'],
            [__('Value at cost'), array_sum(array_map(fn ($r) => (float) $r['value'], $rows))],
            [__('Value at selling price'), array_sum(array_map(fn ($r) => (float) $r['retail'], $rows))],
            [__('Low stock items'), count(array_filter($rows, fn ($r) => $r['low'])), 'int', 'danger'],
        ];
    }

    public function tables(): array
    {
        $rows = array_map(fn ($r) => [...$r, 'status' => $r['low'] ? __('Low') : ''], $this->rows());

        return [['columns' => [
            'label' => [__('Product')], 'sku' => [__('SKU')], 'category' => [__('Category')], 'qty' => [__('On hand'), 'qty'],
            'alert' => [__('Alert at'), 'qty'], 'status' => [__('Status')], 'cost' => [__('Average cost'), 'money'],
            'value' => [__('Value at cost'), 'money'], 'retail' => [__('Value at selling price'), 'money'],
        ], 'rows' => $rows, 'totals' => self::totals($rows, ['value', 'retail'])]];
    }
}
