<?php

namespace App\Filament\Admin\Widgets;

use App\Domain\Reporting\Services\SalesReport;
use App\Filament\Admin\Pages\Reports\SalesReportPage;

class TopProducts extends ListWidget
{
    protected static ?int $sort = 5;

    public static function canView(): bool
    {
        return (bool) auth()->user()?->can('dashboard.view');
    }

    public function heading(): string
    {
        return __('Best sellers').' — '.$this->dashboardPeriod()['label'];
    }

    public function items(): array
    {
        $p = $this->dashboardPeriod();

        return app(SalesReport::class)->by('product', $this->period($p['from'], $p['to']))->take(8)
            ->map(fn (array $r) => ['label' => $r['label'], 'hint' => '× '.rtrim(rtrim((string) $r['qty'], '0'), '.'), 'value' => number_format((float) $r['net'], 2)])
            ->all();
    }

    public function moreUrl(): ?string
    {
        return SalesReportPage::canAccess() ? SalesReportPage::getUrl() : null;
    }
}
