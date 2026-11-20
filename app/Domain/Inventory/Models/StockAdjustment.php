<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Finance\Models\JournalEntry;
use App\Domain\Foundation\Models\Location;
use App\Domain\Inventory\Enums\StockAdjustmentType;
use App\Models\User;
use App\Support\Audit\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class StockAdjustment extends Model
{
    use Audited;

    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'total_cost' => 0,
        'recovered_amount' => 0,
    ];

    protected $fillable = [
        'number',
        'location_id',
        'adjusted_at',
        'type',
        'reason',
        'total_cost',
        'recovered_amount',
        'note',
        'created_by',
        'voided_at',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'adjusted_at' => 'datetime',
            'type' => StockAdjustmentType::class,
            'total_cost' => 'decimal:4',
            'recovered_amount' => 'decimal:4',
            'voided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<StockAdjustmentItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(StockAdjustmentItem::class, 'stock_adjustment_id');
    }

    /** @return MorphMany<JournalEntry, $this> */
    public function journalEntries(): MorphMany
    {
        return $this->morphMany(JournalEntry::class, 'source');
    }
}
