<?php

namespace App\Domain\Reporting\Services;

use Illuminate\Support\Facades\DB;

/** Day / month bucket expressions that work on both SQLite (dev) and MySQL (production). */
final class SqlDates
{
    public static function day(string $column): string
    {
        return "date({$column})";
    }

    public static function month(string $column): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', {$column})"
            : "date_format({$column}, '%Y-%m')";
    }
}
