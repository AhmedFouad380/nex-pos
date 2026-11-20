<?php

namespace App\Domain\Legacy\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One run of the import from the old system. */
class LegacyImport extends Model
{
    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'status' => 'pending',
    ];

    protected $fillable = [
        'source_name',
        'package_path',
        'status',
        'stage',
        'manifest',
        'options',
        'progress',
        'report',
        'error',
        'created_by',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'manifest' => 'array',
            'options' => 'array',
            'progress' => 'array',
            'report' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
