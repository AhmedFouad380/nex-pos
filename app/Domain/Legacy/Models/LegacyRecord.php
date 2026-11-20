<?php

namespace App\Domain\Legacy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One row of the old database, exactly as it was. */
class LegacyRecord extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'source_table',
        'record_key',
        'data',
        'legacy_import_id',
    ];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    /** @return BelongsTo<LegacyImport, $this> */
    public function legacyImport(): BelongsTo
    {
        return $this->belongsTo(LegacyImport::class);
    }
}
