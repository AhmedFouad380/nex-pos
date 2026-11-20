<?php

namespace App\Domain\Foundation\Models;

use App\Domain\Shared\Enums\CustomFieldType;
use App\Support\Audit\Audited;
use Illuminate\Database\Eloquent\Model;

class CustomFieldDefinition extends Model
{
    use Audited;

    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'type' => 'text',
        'is_required' => false,
        'show_on_invoice' => false,
        'sort' => 0,
    ];

    protected $fillable = [
        'entity',
        'key',
        'label',
        'type',
        'options',
        'is_required',
        'show_on_invoice',
        'sort',
    ];

    protected function casts(): array
    {
        return [
            'type' => CustomFieldType::class,
            'options' => 'array',
            'is_required' => 'boolean',
            'show_on_invoice' => 'boolean',
        ];
    }
}
