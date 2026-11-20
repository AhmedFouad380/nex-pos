<?php

namespace App\Domain\Legacy\Models;

use Illuminate\Database\Eloquent\Model;

class LegacyIdMap extends Model
{
    public $timestamps = false;

    protected $table = 'legacy_id_map';

    protected $fillable = [
        'legacy_table',
        'legacy_key',
        'new_type',
        'new_id',
        'migrated_at',
    ];

    protected function casts(): array
    {
        return ['migrated_at' => 'datetime'];
    }
}
