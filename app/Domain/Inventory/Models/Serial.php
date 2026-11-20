<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Catalog\Models\Variation;
use App\Domain\Foundation\Models\Location;
use App\Domain\Inventory\Enums\SerialStatus;
use App\Domain\Purchasing\Models\PurchaseItem;
use App\Domain\Sales\Models\SaleItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Serial extends Model
{
    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'status' => 'in_stock',
    ];

    protected $fillable = [
        'variation_id',
        'serial',
        'status',
        'location_id',
        'purchase_item_id',
        'sale_item_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => SerialStatus::class,
        ];
    }

    /** @return BelongsTo<Variation, $this> */
    public function variation(): BelongsTo
    {
        return $this->belongsTo(Variation::class, 'variation_id');
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    /** @return BelongsTo<PurchaseItem, $this> */
    public function purchaseItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseItem::class, 'purchase_item_id');
    }

    /** @return BelongsTo<SaleItem, $this> */
    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class, 'sale_item_id');
    }
}
