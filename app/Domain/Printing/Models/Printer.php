<?php

namespace App\Domain\Printing\Models;

use App\Domain\Foundation\Models\Location;
use App\Domain\Printing\Enums\PrinterConnection;
use App\Support\Audit\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Printer extends Model
{
    use Audited;

    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'connection' => 'browser',
        'capability_profile' => 'default',
        'chars_per_line' => 42,
    ];

    protected $fillable = [
        'name',
        'connection',
        'ip_address',
        'port',
        'capability_profile',
        'chars_per_line',
        'location_id',
    ];

    protected function casts(): array
    {
        return [
            'connection' => PrinterConnection::class,
        ];
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }
}
