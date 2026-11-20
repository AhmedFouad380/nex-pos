<?php

namespace App\Filament\Admin\Widgets;

use App\Domain\Reporting\Services\BranchSalesMonitor;
use App\Filament\Admin\Widgets\Concerns\DashboardScope;
use App\Settings\PosSettings;
use Filament\Widgets\Widget;

/** أداء الفروع اليوم: each branch's sales so far against its usual for this weekday and hour, and how long since its last sale. */
class BranchPerformance extends Widget
{
    use DashboardScope;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.admin.widgets.branch-performance';

    protected ?string $pollingInterval = '300s';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->can('dashboard.view');
    }

    /** @return list<array<string, mixed>> */
    public function rows(): array
    {
        return app(BranchSalesMonitor::class)->today($this->locationIds());
    }

    public function idleLimit(): int
    {
        return (int) rescue(fn () => app(PosSettings::class)->idle_alert_hours, 0, false);
    }

    /** green from 90%, amber from 60%, red below */
    public static function tone(?int $percent): string
    {
        return match (true) {
            $percent === null => 'gray',
            $percent >= 90 => 'success',
            $percent >= 60 => 'warning',
            default => 'danger',
        };
    }
}
