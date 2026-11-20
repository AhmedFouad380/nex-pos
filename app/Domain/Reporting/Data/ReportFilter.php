<?php

namespace App\Domain\Reporting\Data;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;

/** Period + locations a report runs on. `locationIds` null = every location. */
final readonly class ReportFilter
{
    public CarbonImmutable $from;

    public CarbonImmutable $to;

    /** @param list<int>|null $locationIds */
    public function __construct(CarbonInterface $from, CarbonInterface $to, public ?array $locationIds = null)
    {
        $this->from = CarbonImmutable::instance($from)->startOfDay();
        $this->to = CarbonImmutable::instance($to)->endOfDay();
    }

    /** Applies the period on a datetime / date column and the location restriction. */
    public function apply(Builder $query, string $dateColumn, ?string $locationColumn = 'location_id'): Builder
    {
        return $query->whereBetween($dateColumn, [$this->from->toDateTimeString(), $this->to->toDateTimeString()])
            ->when($locationColumn !== null && $this->locationIds !== null, fn (Builder $q) => $q->whereIn($locationColumn, $this->locationIds));
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to->startOfDay()) + 1;
    }
}
