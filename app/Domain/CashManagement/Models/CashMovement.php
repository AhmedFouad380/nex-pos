<?php

namespace App\Domain\CashManagement\Models;

use App\Domain\Finance\Enums\PaymentDirection;
use App\Models\User;
use App\Support\Audit\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashMovement extends Model
{
    use Audited;

    protected $fillable = [
        'cash_session_id',
        'direction',
        'amount',
        'reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'direction' => PaymentDirection::class,
            'amount' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<CashSession, $this> */
    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class, 'cash_session_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
