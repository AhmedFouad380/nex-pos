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
