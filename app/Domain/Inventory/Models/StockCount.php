<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Foundation\Models\Location;
use App\Domain\Inventory\Enums\StockCountScope;
use App\Domain\Inventory\Enums\StockCountStatus;
use App\Models\User;
use App\Support\Audit\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockCount extends Model
{
    use Audited;

    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'status' => 'draft',
        'scope' => 'full',
    ];

    protected $fillable = [
        'number',
        'location_id',
        'status',
        'scope',
        'started_at',
        'posted_at',
        'stock_adjustment_id',
        'surplus_adjustment_id',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => StockCountStatus::class,
            'scope' => StockCountScope::class,
            'started_at' => 'datetime',
            'posted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    /** @return BelongsTo<StockAdjustment, $this> */
    public function stockAdjustment(): BelongsTo
    {
        return $this->belongsTo(StockAdjustment::class, 'stock_adjustment_id');
    }

    /** @return BelongsTo<StockAdjustment, $this> the increase posted for the surpluses */
    public function surplusAdjustment(): BelongsTo
    {
        return $this->belongsTo(StockAdjustment::class, 'surplus_adjustment_id');
    }

    /**
     * Totals of the count. A line's difference is counted − expected (refreshed against the stock on hand when posting);
     * values are at the line's cost.
     *
     * @return array{lines: int, counted: int, shortage_lines: int, shortage_qty: float, shortage_value: float,
     *     surplus_lines: int, surplus_qty: float, surplus_value: float, expected_value: float, counted_value: float}
     */
    public function summary(): array
    {
        $row = $this->items()->toBase()->selectRaw('count(*) as lines_count,
            sum(case when counted_qty is not null then 1 else 0 end) as counted,
            sum(case when counted_qty is not null and difference < 0 then 1 else 0 end) as shortage_lines,
            sum(case when counted_qty is not null and difference < 0 then -difference else 0 end) as shortage_qty,
            sum(case when counted_qty is not null and difference < 0 then -difference * unit_cost else 0 end) as shortage_value,
            sum(case when counted_qty is not null and difference > 0 then 1 else 0 end) as surplus_lines,
            sum(case when counted_qty is not null and difference > 0 then difference else 0 end) as surplus_qty,
            sum(case when counted_qty is not null and difference > 0 then difference * unit_cost else 0 end) as surplus_value,
            sum(case when counted_qty is not null then expected_qty * unit_cost else 0 end) as expected_value,
            sum(case when counted_qty is not null then counted_qty * unit_cost else 0 end) as counted_value')->first();

        return [
            'lines' => (int) ($row->lines_count ?? 0), 'counted' => (int) ($row->counted ?? 0),
            'shortage_lines' => (int) ($row->shortage_lines ?? 0), 'shortage_qty' => (float) ($row->shortage_qty ?? 0), 'shortage_value' => (float) ($row->shortage_value ?? 0),
            'surplus_lines' => (int) ($row->surplus_lines ?? 0), 'surplus_qty' => (float) ($row->surplus_qty ?? 0), 'surplus_value' => (float) ($row->surplus_value ?? 0),
            'expected_value' => (float) ($row->expected_value ?? 0), 'counted_value' => (float) ($row->counted_value ?? 0),
        ];
    }

    public function isCounting(): bool
    {
        return $this->status === StockCountStatus::Counting;
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<StockCountItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(StockCountItem::class, 'stock_count_id');
    }
}
