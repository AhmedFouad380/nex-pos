<?php

namespace App\Domain\Sales\Models;

use App\Domain\Catalog\Models\TaxRate;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Catalog\Models\Variation;
use App\Domain\Shared\Enums\DiscountType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOrderItem extends Model
{
    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'unit_multiplier' => 1,
        'qty_invoiced' => 0,
        'unit_price' => 0,
        'discount_value' => 0,
        'tax_amount' => 0,
        'line_total' => 0,
    ];

    protected $fillable = [
        'sales_order_id',
        'variation_id',
        'unit_id',
        'unit_multiplier',
        'qty',
        'qty_invoiced',
        'unit_price',
        'discount_type',
        'discount_value',
        'tax_rate_id',
        'tax_amount',
        'line_total',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'unit_multiplier' => 'decimal:4',
            'qty' => 'decimal:4',
            'qty_invoiced' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'discount_type' => DiscountType::class,
            'discount_value' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'line_total' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<SalesOrder, $this> */
    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
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
