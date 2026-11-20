<?php

namespace App\Domain\CRM\Models;

use App\Domain\Catalog\Models\PriceGroup;
use App\Support\Audit\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerGroup extends Model
{
    use Audited;

    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'discount_percent' => 0,
    ];

    protected $fillable = [
        'name',
        'discount_percent',
        'price_group_id',
    ];

    protected function casts(): array
    {
        return [
            'discount_percent' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<PriceGroup, $this> */
    public function priceGroup(): BelongsTo
    {
        return $this->belongsTo(PriceGroup::class, 'price_group_id');
    }

    /** @return HasMany<Contact, $this> */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class, 'customer_group_id');
    }
}
