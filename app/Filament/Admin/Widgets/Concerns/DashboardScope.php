<?php

namespace App\Filament\Admin\Widgets\Concerns;

use App\Domain\Reporting\Data\ReportFilter;
use App\Filament\Support\UserLocations;
use App\Models\User;
use App\Support\FiscalYear;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * Dashboard widgets: the locations the user works in, and the period picked in the dashboard filter
 * (a year — the default —, a month or a day), with the period before it for comparisons.
 */
trait DashboardScope
{
    use InteractsWithPageFilters;

    /** @return list<int>|null */
    protected function locationIds(): ?array
    {
        $user = auth()->user();

        return $user instanceof User && ($user->isSuperAdmin() || $user->can('locations.access_all'))
            ? null
            : array_keys(UserLocations::options());
    }

    protected function period(CarbonInterface $from, CarbonInterface $to): ReportFilter
    {
        return new ReportFilter($from, $to, $this->locationIds());
    }

    /** 'year', 'month' or 'day' */
    protected function periodType(): string
    {
        $type = $this->pageFilters['period'] ?? 'year';

        return in_array($type, ['year', 'month', 'day'], true) ? $type : 'year';
    }

    /**
     * The chosen period — never past today — and the one before it.
     *
     * @return array{from: CarbonImmutable, to: CarbonImmutable, prevFrom: CarbonImmutable, prevTo: CarbonImmutable, label: string}
     */
    protected function dashboardPeriod(): array
    {
        $year = is_numeric($this->pageFilters['year'] ?? null) ? (int) $this->pageFilters['year'] : FiscalYear::selected();
        $cap = fn (CarbonImmutable $end) => $end->isFuture() ? CarbonImmutable::now() : $end;

        switch ($this->periodType()) {
            case 'day':
                $day = CarbonImmutable::parse($this->pageFilters['day'] ?? now()->toDateString())->startOfDay();

                return ['from' => $day, 'to' => $day->endOfDay(), 'prevFrom' => $day->subDay(), 'prevTo' => $day->subDay()->endOfDay(),
                    'label' => $day->format('Y-m-d')];
            case 'month':
                $month = CarbonImmutable::create($year, (int) ($this->pageFilters['month'] ?? now()->month), 1);
                $through = $cap($month->endOfMonth());
                $days = (int) $month->diffInDays($through); // compare like with like: the same days of the month before

                return ['from' => $month, 'to' => $through, 'prevFrom' => $month->subMonthNoOverflow(),
                    'prevTo' => $month->subMonthNoOverflow()->addDays($days)->endOfDay()->min($month->subMonthNoOverflow()->endOfMonth()),
                    'label' => $month->translatedFormat('F Y')];
            default:
                [$start, $end] = FiscalYear::range($year);
                $through = $cap($end);
                [$prevStart, $prevEnd] = FiscalYear::range($year - 1);

                return ['from' => $start, 'to' => $through, 'prevFrom' => $prevStart,
                    'prevTo' => $prevStart->add($start->diff($through))->min($prevEnd), 'label' => __('Year :year', ['year' => FiscalYear::label($year)])];
        }
    }
}
