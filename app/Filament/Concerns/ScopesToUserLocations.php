<?php

namespace App\Filament\Concerns;

use App\Filament\Support\UserLocations;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Limits a resource to the locations the user works in (super admins and holders of
 * `locations.access_all` see everything). Set $locationColumns when a record has several.
 */
trait ScopesToUserLocations
{
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if (! $user instanceof User || $user->isSuperAdmin() || $user->can('locations.access_all')) {
            return $query;
        }

        $ids = $user->locations()->pluck('locations.id');
        $columns = property_exists(static::class, 'locationColumns') ? static::$locationColumns : ['location_id'];

        return $query->where(function (Builder $q) use ($columns, $ids) {
            foreach ($columns as $column) {
                $q->orWhereIn($column, $ids);
            }
        });
    }

    /** @return array<int, string> id => name of active locations the current user may use */
    public static function userLocationOptions(): array
    {
        return UserLocations::options();
    }
}
