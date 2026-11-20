<?php

namespace App\Domain\Printing\Models;

use App\Domain\Printing\Enums\PaperSize;
use App\Support\Audit\Audited;
use Illuminate\Database\Eloquent\Model;

class InvoiceLayout extends Model
{
    use Audited;

    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'paper' => 'a4',
        'design' => 'classic',
        'is_default' => false,
    ];

    protected $fillable = [
        'name',
        'paper',
        'design',
        'settings',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'paper' => PaperSize::class,
            'settings' => 'array',
            'is_default' => 'boolean',
        ];
    }
}
