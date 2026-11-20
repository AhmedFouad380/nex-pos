<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Catalog\Models\TaxRate;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Catalog\Models\Variation;
use App\Domain\Shared\Enums\DiscountType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'unit_multiplier' => 1,
        'qty_received' => 0,
        'unit_cost' => 0,
        'discount_value' => 0,
        'tax_amount' => 0,
        'line_total' => 0,
    ];

    protected $fillable = [
        'purchase_order_id',
        'purchase_requisition_item_id',
        'variation_id',
        'unit_id',
        'unit_multiplier',
        'qty',
        'qty_received',
        'unit_cost',
        'discount_type',
        'discount_value',
        'tax_rate_id',
        'tax_amount',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'unit_multiplier' => 'decimal:4',
            'qty' => 'decimal:4',
            'qty_received' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'discount_type' => DiscountType::class,
            'discount_value' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'line_total' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    /** @return BelongsTo<PurchaseRequisitionItem, $this> */
    public function purchaseRequisitionItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisitionItem::class, 'purchase_requisition_item_id');
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

    /** @return BelongsTo<TaxRate, $this> */
    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class, 'tax_rate_id');
    }
}
