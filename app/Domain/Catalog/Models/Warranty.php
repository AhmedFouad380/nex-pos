<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Shared\Enums\DurationUnit;
use App\Support\Audit\Audited;
use Illuminate\Database\Eloquent\Model;

class Warranty extends Model
{
    use Audited;

    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'duration_unit' => 'month',
    ];

    protected $fillable = [
        'name',
        'duration',
        'duration_unit',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'duration_unit' => DurationUnit::class,
        ];
    }
}
