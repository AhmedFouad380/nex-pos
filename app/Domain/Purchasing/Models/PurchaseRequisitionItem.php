<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Catalog\Models\Unit;
use App\Domain\Catalog\Models\Variation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseRequisitionItem extends Model
{
    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'qty_ordered' => 0,
    ];

    protected $fillable = [
        'purchase_requisition_id',
        'variation_id',
        'unit_id',
        'qty',
        'qty_ordered',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
            'qty_ordered' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<PurchaseRequisition, $this> */
    public function purchaseRequisition(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id');
    }

    /** @return BelongsTo<Variation, $this> */
    public function variation(): BelongsTo
    {
        return $this->belongsTo(Variation::class, 'variation_id');
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }
}
