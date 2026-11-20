<?php

namespace App\Domain\Printing\Models;

use App\Support\Audit\Audited;
use Illuminate\Database\Eloquent\Model;

class LabelTemplate extends Model
{
    use Audited;

    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'is_continuous' => false,
        'is_default' => false,
    ];

    protected $fillable = [
        'name',
        'description',
        'paper',
        'is_continuous',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'paper' => 'array',
            'is_continuous' => 'boolean',
            'is_default' => 'boolean',
        ];
    }
}
