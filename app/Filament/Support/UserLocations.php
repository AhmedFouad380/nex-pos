<?php

namespace App\Filament\Support;

use App\Domain\Foundation\Models\Location;
use App\Models\User;

final class UserLocations
{
    /** @return array<int, string> id => name of active locations the current user may use */
    public static function options(): array
    {
        $user = auth()->user();

        return ($user instanceof User ? $user->accessibleLocations() : Location::query())
            ->where('is_active', true)->pluck('name', 'id')->all();
    }

    public static function first(): ?int
    {
        return array_key_first(self::options());
    }
}
