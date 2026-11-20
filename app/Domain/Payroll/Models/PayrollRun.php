<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Finance\Models\JournalEntry;
use App\Domain\Foundation\Models\Location;
use App\Domain\Payroll\Enums\PayrollStatus;
use App\Models\User;
use App\Support\Audit\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** A month's payroll (مسير رواتب) for one branch or all of them. */
class PayrollRun extends Model
{
    use Audited;

    protected $attributes = [
        'status' => 'draft',
        'total_earned' => 0,
        'total_advances' => 0,
        'total_net' => 0,
        'total_paid' => 0,
    ];

    protected $fillable = [
        'number',
        'period',
        'posting_date',
        'location_id',
        'status',
        'total_earned',
        'total_advances',
        'total_net',
        'total_paid',
        'note',
        'approved_at',
        'approved_by',
        'created_by',
        'voided_at',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'period' => 'date',
            'posting_date' => 'date',
            'status' => PayrollStatus::class,
            'total_earned' => 'decimal:4',
            'total_advances' => 'decimal:4',
            'total_net' => 'decimal:4',
            'total_paid' => 'decimal:4',
            'approved_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /** "2026-11" style label of the month. */
    public function periodLabel(): string
    {
        return $this->period->translatedFormat('F Y');
    }

    /** @return HasMany<PayrollLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PayrollLine::class, 'payroll_run_id');
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return MorphMany<JournalEntry, $this> */
    public function journalEntries(): MorphMany
    {
        return $this->morphMany(JournalEntry::class, 'source');
    }
}
