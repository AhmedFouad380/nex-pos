<?php

namespace App\Domain\Foundation\Models;

use App\Domain\Shared\Enums\DocumentType;
use App\Support\Audit\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentSequence extends Model
{
    use Audited;

    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'prefix' => '',
        'next_number' => 1,
        'padding' => 5,
    ];

    protected $fillable = [
        'document_type',
        'location_id',
        'year',
        'prefix',
        'next_number',
        'padding',
    ];

    protected function casts(): array
    {
        return [
            'document_type' => DocumentType::class,
        ];
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }
}
