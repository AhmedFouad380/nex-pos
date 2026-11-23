<?php

namespace App\Filament\Admin\Widgets;

use App\Domain\Reporting\Services\OperationsReports;
use App\Filament\Admin\Pages\Reports\AgingReportPage;

class OverdueCustomers extends ListWidget
{
    protected static ?int $sort = 7;

    public static function canView(): bool
    {
        return (bool) auth()->user()?->can('reports.contacts');
    }

    public function heading(): string
    {
        return __('Customers who owe the most');
    }

    public function items(): array
    {
        $rows = array_slice(app(OperationsReports::class)->aging('receivable', $this->locationIds()), 0, 8);

        return array_map(fn (array $r) => ['label' => $r['label'], 'hint' => $r['mobile'],
            'value' => number_format((float) $r['total'], 2), 'danger' => (float) $r['b61'] + (float) $r['b90'] > 0], $rows);
    }

    public function emptyText(): string
    {
        return __('No customer owes anything.');
    }

    public function moreUrl(): ?string
    {
        return AgingReportPage::getUrl();
    }
}
