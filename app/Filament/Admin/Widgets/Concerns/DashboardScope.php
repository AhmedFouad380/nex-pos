<?php

namespace App\Filament\Admin\Widgets\Concerns;

use App\Domain\Reporting\Data\ReportFilter;
use App\Filament\Support\UserLocations;
use App\Models\User;
use Carbon\CarbonInterface;

/** Dashboard widgets show the locations the user works in (all of them for managers who see everything). */
trait DashboardScope
{
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
}
