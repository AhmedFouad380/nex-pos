<?php

namespace App\Domain\Restaurant\Models;

use App\Domain\Catalog\Models\Category;
use App\Domain\Foundation\Models\Location;
use App\Domain\Printing\Models\Printer;
use App\Support\Audit\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class KitchenStation extends Model
{
    use Audited;

    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'is_active' => true,
    ];

    protected $fillable = [
        'location_id',
        'name',
        'printer_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    /** @return BelongsTo<Printer, $this> */
    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class, 'printer_id');
    }

    /** @return BelongsToMany<Category, $this> */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_kitchen_station', 'kitchen_station_id', 'category_id');
    }
}
