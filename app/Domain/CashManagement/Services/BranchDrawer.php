<?php

namespace App\Domain\CashManagement\Services;

use App\Domain\CashManagement\Enums\CashSessionStatus;
use App\Domain\CashManagement\Models\CashRegister;
use App\Domain\Foundation\Models\Location;
use Illuminate\Support\Facades\DB;

/**
 * الفرع هو الدرج: each branch has exactly one cash drawer, which the system manages and nobody picks.
 * Shifts are opened on a branch; the drawer row stays underneath because shifts (and the imported history)
 * are tied to it. Its cash goes to the branch's cash account (cash_account_id left empty).
 */
final class BranchDrawer
{
    /** The branch's drawer, created the first time it is needed. */
    public function for(Location|int $location): CashRegister
    {
        $location = $location instanceof Location ? $location : Location::query()->findOrFail($location);

        return CashRegister::query()->where('location_id', $location->id)->where('is_active', true)
            ->withCount(['sessions as open_sessions' => fn ($q) => $q->where('status', CashSessionStatus::Open)])
            ->orderByDesc('open_sessions')->orderBy('id')->first()
            ?? CashRegister::query()->create(['location_id' => $location->id, 'name' => $location->name, 'is_active' => true]);
    }

    /**
     * Leaves one active drawer per branch: the one with an open shift, else the most used one.
     * Drawers with history are switched off (their shifts stay as they are); unused ones are deleted.
     *
     * @return array{kept: int, deactivated: int, deleted: int, created: int}
     */
    public function consolidate(): array
    {
        $result = ['kept' => 0, 'deactivated' => 0, 'deleted' => 0, 'created' => 0];

        DB::transaction(function () use (&$result) {
            foreach (Location::query()->get() as $location) {
                $drawers = CashRegister::query()->where('location_id', $location->id)
                    ->withCount(['sessions', 'sessions as open_sessions' => fn ($q) => $q->where('status', CashSessionStatus::Open)])->get();
                if ($drawers->isEmpty()) {
                    $this->for($location);
                    $result['created']++;

                    continue;
                }

                $keep = $drawers->sortBy([['open_sessions', 'desc'], ['is_active', 'desc'], ['sessions_count', 'desc'], ['id', 'asc']])->first();
                $keep->update(['is_active' => true, 'name' => $location->name]);
                $result['kept']++;

                foreach ($drawers->where('id', '!=', $keep->id) as $drawer) {
                    if ($drawer->getAttribute('open_sessions') > 0) {
                        continue; // a shift still open on it: left until it is closed
                    }
                    if ($drawer->getAttribute('sessions_count') > 0) {
                        if ($drawer->is_active) {
                            $drawer->update(['is_active' => false]);
                            $result['deactivated']++;
                        }

                        continue;
                    }
                    $drawer->delete();
                    $result['deleted']++;
                }
            }
        });

        return $result;
    }
}
