<?php

namespace App\Domain\CashManagement\Models;

use Illuminate\Database\Eloquent\Model;

class CashDenomination extends Model
{
    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'is_active' => true,
        'sort' => 0,
    ];

    protected $fillable = [
        'value',
        'is_active',
        'sort',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }
}
