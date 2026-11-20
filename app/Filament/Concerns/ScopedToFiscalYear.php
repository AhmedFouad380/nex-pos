<?php

namespace App\Filament\Concerns;

use App\Support\FiscalYear;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * For list pages: documents of the year chosen in the top bar only (FiscalYear::COLUMNS says which date).
 * Lists of things without a date (products, contacts…) are left as they are.
 */
trait ScopedToFiscalYear
{
    protected function getTableQuery(): Builder|Relation|null
    {
        $query = parent::getTableQuery();
        $column = FiscalYear::columnFor(static::getResource()::getModel());

        return $column !== null && $query instanceof Builder ? FiscalYear::scope($query, $column) : $query;
    }
}
