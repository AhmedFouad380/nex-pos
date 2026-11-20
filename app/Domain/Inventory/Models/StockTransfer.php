<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Finance\Models\JournalEntry;
use App\Domain\Foundation\Models\Location;
use App\Domain\Inventory\Enums\StockTransferStatus;
use App\Models\User;
use App\Support\Audit\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class StockTransfer extends Model
{
    use Audited;

    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'status' => 'draft',
        'shipping_cost' => 0,
        'total_cost' => 0,
    ];

    protected $fillable = [
        'number',
        'from_location_id',
        'to_location_id',
        'status',
        'transfer_date',
        'sent_at',
        'received_at',
        'received_by',
        'shipping_cost',
        'total_cost',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => StockTransferStatus::class,
            'transfer_date' => 'datetime',
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
            'shipping_cost' => 'decimal:4',
            'total_cost' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<Location, $this> */
    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    /** @return BelongsTo<Location, $this> */
    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }

    /** @return BelongsTo<User, $this> */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<StockTransferItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class, 'stock_transfer_id');
    }

    /** @return MorphMany<JournalEntry, $this> */
    public function journalEntries(): MorphMany
    {
        return $this->morphMany(JournalEntry::class, 'source');
    }
}
