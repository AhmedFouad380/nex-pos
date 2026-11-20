<?php

namespace App\Domain\Foundation\Models;

use App\Support\Audit\Audited;
use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    use Audited;

    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'precision' => 2,
        'symbol_position' => 'after',
        'thousand_separator' => ',',
        'decimal_separator' => '.',
        'is_active' => true,
    ];

    protected $fillable = [
        'code',
        'name',
        'symbol',
        'precision',
        'symbol_position',
        'thousand_separator',
        'decimal_separator',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
