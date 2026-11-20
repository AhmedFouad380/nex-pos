<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A number next to the resource in the sidebar ("3 invoices due"). The count goes through the
 * resource's own query, so it respects the user's locations. Hidden when zero.
 *
 * A resource implements:
 *   navigationCount(Builder $query): Builder        — narrows the query to what needs attention
 *   navigationCountTooltip(): string
 *   navigationCountColor(): string                  — optional, 'warning' by default
 */
trait HasNavigationCount
{
    /** @param Builder<Model> $query */
    abstract protected static function navigationCount(Builder $query): Builder;

    abstract protected static function navigationCountTooltip(): string;

    protected static function navigationCountColor(): string
    {
        return 'warning';
    }

    public static function getNavigationBadge(): ?string
    {
        $count = once(fn () => static::navigationCount(static::getEloquentQuery())->count());

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return static::navigationCountColor();
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return static::navigationCountTooltip();
    }
}
