<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Purchasing\Enums\CostDistribution;
use App\Support\Audit\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseCost extends Model
{
    use Audited;

    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'distribute_by' => 'value',
    ];

    protected $fillable = [
        'purchase_id',
        'name',
        'amount',
        'distribute_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'distribute_by' => CostDistribution::class,
        ];
    }

    /** @return BelongsTo<Purchase, $this> */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class, 'purchase_id');
    }
}
