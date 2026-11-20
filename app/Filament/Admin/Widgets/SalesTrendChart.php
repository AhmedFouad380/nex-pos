<?php

namespace App\Filament\Admin\Widgets;

use App\Domain\Reporting\Services\SalesReport;
use App\Filament\Admin\Widgets\Concerns\DashboardScope;
use Filament\Widgets\ChartWidget;

/** Net sales (and gross profit, for those who may see it) per day over the last 30 days. */
class SalesTrendChart extends ChartWidget
{
    use DashboardScope;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '280px';

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return (bool) auth()->user()?->can('dashboard.view');
    }

    public function getHeading(): string
    {
        return __('Sales — last 30 days');
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $rows = app(SalesReport::class)->by('day', $this->period(now()->subDays(29), now()))->keyBy('key');
        $days = collect(range(29, 0))->map(fn ($i) => now()->subDays($i)->toDateString());

        $datasets = [[
            'label' => __('Net sales'),
            'data' => $days->map(fn ($d) => (float) ($rows[$d]['net'] ?? 0))->all(),
            'borderColor' => '#f59e0b', 'backgroundColor' => 'rgba(245, 158, 11, 0.15)', 'fill' => true, 'tension' => 0.3,
        ]];
        if (auth()->user()?->can('reports.profit_loss')) {
            $datasets[] = [
                'label' => __('Gross profit'),
                'data' => $days->map(fn ($d) => (float) ($rows[$d]['profit'] ?? 0))->all(),
                'borderColor' => '#16a34a', 'backgroundColor' => 'rgba(22, 163, 74, 0.1)', 'fill' => false, 'tension' => 0.3,
            ];
        }

        return ['datasets' => $datasets, 'labels' => $days->map(fn ($d) => substr($d, 5))->all()];
    }
}
