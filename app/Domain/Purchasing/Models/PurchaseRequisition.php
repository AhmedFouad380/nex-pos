<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Foundation\Models\Location;
use App\Domain\Purchasing\Enums\RequisitionStatus;
use App\Models\User;
use App\Support\Audit\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseRequisition extends Model
{
    use Audited;

    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'status' => 'open',
    ];

    protected $fillable = [
        'number',
        'location_id',
        'requisition_date',
        'required_by',
        'status',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'requisition_date' => 'datetime',
            'required_by' => 'date',
            'status' => RequisitionStatus::class,
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

    /** @return HasMany<PurchaseRequisitionItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseRequisitionItem::class, 'purchase_requisition_id');
    }

    /** @return HasMany<PurchaseOrder, $this> */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'purchase_requisition_id');
    }
}
