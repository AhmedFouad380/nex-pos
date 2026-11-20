<?php

namespace App\Domain\Catalog\Models;

use App\Support\Audit\Audited;
use Illuminate\Database\Eloquent\Model;

class PriceGroup extends Model
{
    use Audited;

    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'is_active' => true,
    ];

    protected $fillable = [
        'name',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
