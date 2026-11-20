<?php

namespace App\Support;

use App\Models\User;
use App\Settings\GeneralSettings;
use Carbon\CarbonInterface;

/**
 * قفل السنوات السابقة: with the setting on, a user can't post anything dated before the current fiscal year
 * — no new, changed or voided document, no manual entry. Checked where every movement is posted (Posting).
 * The system itself (imports, rebuilding the ledger, console) is never locked, nor users allowed to edit closed years.
 */
final class PeriodLock
{
    public static function enabled(): bool
    {
        return (bool) rescue(fn () => app(GeneralSettings::class)->lock_past_years, false, false);
    }

    public static function isLocked(CarbonInterface $date): bool
    {
        if (! self::enabled() || FiscalYear::of($date) >= FiscalYear::current()) {
            return false;
        }
        $user = auth()->user();

        return $user instanceof User && ! $user->can('fiscal_year.edit_closed');
    }

    /** @throws DomainException when any of the dates is in a closed year */
    public static function assertOpen(?CarbonInterface ...$dates): void
    {
        foreach ($dates as $date) {
            if ($date !== null && self::isLocked($date)) {
                throw new DomainException(__('finance.errors.year_closed', ['year' => FiscalYear::label(FiscalYear::of($date))]));
            }
        }
    }
}
