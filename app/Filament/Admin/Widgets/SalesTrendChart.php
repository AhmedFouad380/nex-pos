<?php

namespace App\Filament\Admin\Widgets;

use App\Domain\Reporting\Services\SalesReport;
use App\Filament\Admin\Widgets\Concerns\DashboardScope;
use App\Filament\Support\UserLocations;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\ChartWidget\Concerns\HasFiltersForm;

/** Net sales (and gross profit, for those who may see it) over the dashboard period: by month for a year, by day otherwise. */
class SalesTrendChart extends ChartWidget
{
    use DashboardScope, HasFiltersForm {
        DashboardScope::locationIds as allowedLocationIds;
    }

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '280px';

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return (bool) auth()->user()?->can('dashboard.view');
    }

    public function getHeading(): string
    {
        $p = $this->dashboardPeriod();
        $branch = is_numeric($this->filters['location'] ?? null) ? (UserLocations::options()[(int) $this->filters['location']] ?? null) : null;

        return __('Net sales').' — '.$p['label'].($branch ? ' — '.$branch : '');
    }

    protected function getType(): string
    {
        return 'line';
    }

    /** The chart's own filters: one branch (or all of them) and the grouping. */
    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('location')->label(__('Location'))->placeholder(__('All branches'))
                ->options(fn () => UserLocations::options()),
            Select::make('group')->label(__('Group by'))->selectablePlaceholder(false)->default('auto')
                ->options(['auto' => __('Automatic'), 'day' => __('Day'), 'month' => __('Month')]),
        ]);
    }

    /** @return list<int>|null the branch picked in the chart, within the user's branches */
    protected function locationIds(): ?array
    {
        $allowed = $this->allowedLocationIds();
        $picked = $this->filters['location'] ?? null;
        if (! is_numeric($picked)) {
            return $allowed;
        }

        return $allowed === null || in_array((int) $picked, $allowed, true) ? [(int) $picked] : $allowed;
    }

    protected function getData(): array
    {
        $p = $this->dashboardPeriod();
        $group = $this->filters['group'] ?? 'auto';
        if ($group === 'auto') {
            $group = $this->periodType() === 'year' ? 'month' : 'day';
        }
        // by month: the months of the period (a year), or the 12 months up to it; by day: its days, or the 30 up to a single day
        [$dimension, $from, $keys] = match (true) {
            $group === 'month' && $this->periodType() === 'year' => ['month', $p['from'],
                collect(range(0, 11))->map(fn ($i) => $p['from']->addMonthsNoOverflow($i))->filter(fn ($d) => $d->lte($p['to']))->map(fn ($d) => $d->format('Y-m'))],
            $group === 'month' => ['month', $p['to']->startOfMonth()->subMonthsNoOverflow(11),
                collect(range(11, 0))->map(fn ($i) => $p['to']->startOfMonth()->subMonthsNoOverflow($i)->format('Y-m'))],
            $this->periodType() === 'day' => ['day', $p['to']->subDays(29)->startOfDay(),
                collect(range(29, 0))->map(fn ($i) => $p['to']->subDays($i)->toDateString())],
            default => ['day', $p['from'], collect(range(0, (int) $p['from']->diffInDays($p['to'])))->map(fn ($i) => $p['from']->addDays($i)->toDateString())],
        };
        $rows = app(SalesReport::class)->by($dimension, $this->period($from, $p['to']))->keyBy('key');

        $datasets = [[
            'label' => __('Net sales'),
            'data' => $keys->map(fn ($k) => (float) ($rows[$k]['net'] ?? 0))->values()->all(),
            'borderColor' => '#f59e0b', 'backgroundColor' => 'rgba(245, 158, 11, 0.15)', 'fill' => true, 'tension' => 0.3,
        ]];
        if (auth()->user()?->can('reports.profit_loss')) {
            $datasets[] = [
                'label' => __('Gross profit'),
                'data' => $keys->map(fn ($k) => (float) ($rows[$k]['profit'] ?? 0))->values()->all(),
                'borderColor' => '#16a34a', 'backgroundColor' => 'rgba(22, 163, 74, 0.1)', 'fill' => false, 'tension' => 0.3,
            ];
        }

        $labels = $keys->map(fn ($k) => $dimension === 'month' ? CarbonImmutable::parse($k.'-01')->translatedFormat('M') : substr($k, 5));

        return ['datasets' => $datasets, 'labels' => $labels->values()->all()];
    }
}
