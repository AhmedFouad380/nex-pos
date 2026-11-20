<?php

namespace App\Domain\Sales\Models;

use App\Domain\Shared\Enums\DurationUnit;
use App\Support\Audit\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class RecurringProfile extends Model
{
    use Audited;

    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'interval' => 1,
        'interval_unit' => 'month',
        'generated_count' => 0,
        'is_active' => true,
    ];

    protected $fillable = [
        'recurrable_type',
        'recurrable_id',
        'interval',
        'interval_unit',
        'repeat_on',
        'repetitions',
        'generated_count',
        'next_run_at',
        'is_active',
        'stopped_at',
    ];

    protected function casts(): array
    {
        return [
            'interval_unit' => DurationUnit::class,
            'next_run_at' => 'datetime',
            'is_active' => 'boolean',
            'stopped_at' => 'datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function recurrable(): MorphTo
    {
        return $this->morphTo('recurrable');
    }
}
