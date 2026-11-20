<?php

namespace App\Filament\Admin\Widgets;

use App\Domain\Reporting\Services\OperationsReports;
use App\Filament\Admin\Pages\Reports\StockReportPage;

class LowStock extends ListWidget
{
    protected static ?int $sort = 4;

    public static function canView(): bool
    {
        return (bool) auth()->user()?->can('reports.stock');
    }

    public function heading(): string
    {
        return __('Low stock');
    }

    public function items(): array
    {
        $rows = array_slice(app(OperationsReports::class)->stock($this->locationIds(), null, true), 0, 8);

        return array_map(fn (array $r) => ['label' => $r['label'], 'hint' => __('Alert at').' '.(float) $r['alert'],
            'value' => rtrim(rtrim((string) $r['qty'], '0'), '.') ?: '0', 'danger' => true], $rows);
    }

    public function emptyText(): string
    {
        return __('No item is below its alert level.');
    }

    public function moreUrl(): ?string
    {
        return StockReportPage::getUrl();
    }
}
