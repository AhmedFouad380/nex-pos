<?php

namespace App\Domain\Sales\Models;

use App\Domain\Catalog\Models\PriceGroup;
use App\Domain\Foundation\Models\Location;
use App\Domain\Shared\Enums\DiscountType;
use App\Support\Audit\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ServiceType extends Model
{
    use Audited;

    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'packing_charge_type' => 'fixed',
        'packing_charge' => 0,
        'requires_table' => false,
        'is_active' => true,
    ];

    protected $fillable = [
        'name',
        'description',
        'price_group_id',
        'packing_charge_type',
        'packing_charge',
        'requires_table',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'packing_charge_type' => DiscountType::class,
            'packing_charge' => 'decimal:4',
            'requires_table' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<PriceGroup, $this> */
    public function priceGroup(): BelongsTo
    {
        return $this->belongsTo(PriceGroup::class, 'price_group_id');
    }

    /** @return BelongsToMany<Location, $this> */
    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'location_service_type', 'service_type_id', 'location_id')
            ->withPivot('price_group_id');
    }
}
