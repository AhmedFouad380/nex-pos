<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Catalog\Models\Variation;
use App\Domain\Foundation\Models\Location;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Purchasing\Models\PurchaseItem;
use App\Domain\Purchasing\Models\PurchaseReturnItem;
use App\Domain\Sales\Models\SaleItem;
use App\Domain\Sales\Models\SaleReturnItem;
use App\Models\User;
use App\Support\DocumentNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;

class StockMovement extends Model
{
    public const UPDATED_AT = null;

    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'unit_cost' => 0,
    ];

    protected $fillable = [
        'location_id',
        'variation_id',
        'lot_id',
        'qty',
        'unit_cost',
        'type',
        'source_type',
        'source_id',
        'reverses_id',
        'moved_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'type' => StockMovementType::class,
            'moved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    /** @return BelongsTo<Variation, $this> */
    public function variation(): BelongsTo
    {
        return $this->belongsTo(Variation::class, 'variation_id');
    }

    /** @return BelongsTo<Lot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class, 'lot_id');
    }

    /** @return BelongsTo<StockMovement, $this> */
    public function reversedMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'reverses_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return MorphTo<Model, $this> */
    public function source(): MorphTo
    {
        return $this->morphTo('source');
    }

    /** Document line class => relation to its parent document (for "which document moved this?"). */
    public const SOURCE_DOCUMENTS = [
        StockAdjustmentItem::class => 'stockAdjustment',
        StockTransferItem::class => 'stockTransfer',
        StockCountItem::class => 'stockCount',
        PurchaseItem::class => 'purchase',
        PurchaseReturnItem::class => 'purchaseReturn',
        SaleItem::class => 'sale',
        SaleReturnItem::class => 'saleReturn',
    ];

    /** Eager-loads source line + its parent document in one go. */
    public function scopeWithDocument(Builder $query): void
    {
        self::eagerLoadDocument($query);
    }

    /**
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function eagerLoadDocument(Builder $query): Builder
    {
        return $query->with('source', function (Relation $relation): void {
            if ($relation instanceof MorphTo) {
                $relation->morphWith(collect(self::SOURCE_DOCUMENTS)->map(fn (string $parent) => [$parent])->all());
            }
        });
    }

    /** The parent document of the line that caused this movement (adjustment, transfer, invoice...). */
    public function document(): ?Model
    {
        $source = $this->source;
        $relation = $source instanceof Model ? (self::SOURCE_DOCUMENTS[$source::class] ?? null) : null;
        $document = $relation ? $source->getRelationValue($relation) : null;

        return $document instanceof Model ? $document : null;
    }

    public function documentNumber(): ?string
    {
        return DocumentNumber::of($this->document());
    }
}
