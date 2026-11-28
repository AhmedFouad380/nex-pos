<?php

namespace App\Domain\CashManagement\Models;

use App\Domain\CashManagement\Enums\CashSessionStatus;
use App\Domain\Finance\Models\Account;
use App\Domain\Finance\Models\AccountTransfer;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\Payment;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleReturn;
use App\Models\User;
use App\Support\Audit\Audited;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class CashSession extends Model
{
    use Audited;

    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'status' => 'open',
        'opening_float' => 0,
        'card_slips_count' => 0,
        'cheques_count' => 0,
    ];

    protected $fillable = [
        'cash_register_id',
        'user_id',
        'status',
        'opened_at',
        'opening_float',
        'closed_at',
        'closed_by',
        'expected_cash',
        'counted_cash',
        'difference',
        'card_slips_count',
        'cheques_count',
        'denominations',
        'closing_note',
        'received_at',
        'received_by',
        'received_amount',
        'kept_float',
        'received_account_id',
        'account_transfer_id',
        'received_note',
    ];

    protected function casts(): array
    {
        return [
            'status' => CashSessionStatus::class,
            'opened_at' => 'datetime',
            'opening_float' => 'decimal:4',
            'closed_at' => 'datetime',
            'expected_cash' => 'decimal:4',
            'counted_cash' => 'decimal:4',
            'difference' => 'decimal:4',
            'denominations' => 'array',
            'received_at' => 'datetime',
            'received_amount' => 'decimal:4',
            'kept_float' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<CashRegister, $this> */
    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class, 'cash_register_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /** @return HasMany<CashMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class, 'cash_session_id');
    }

    /** @return HasMany<Sale, $this> */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class, 'cash_session_id');
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'cash_session_id');
    }

    /** @return HasMany<SaleReturn, $this> */
    public function saleReturns(): HasMany
    {
        return $this->hasMany(SaleReturn::class, 'cash_session_id');
    }

    /** @return HasMany<Expense, $this> */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'cash_session_id');
    }

    /** Closed in this system, and its cash not yet taken out of the drawer by a manager / the accountant. */
    public function awaitsReceipt(): bool
    {
        return $this->status === CashSessionStatus::Closed && $this->received_at === null && ! $this->isImported();
    }

    /** A shift copied from the old system: its cash was handled there, so it never waits for a receipt here. */
    public function isImported(): bool
    {
        return DB::table('legacy_id_map')->where('new_type', 'cash_session')->where('new_id', $this->id)->exists();
    }

    /** @param Builder<CashSession> $query */
    public function scopeAwaitingReceipt(Builder $query): void
    {
        $query->where('cash_sessions.status', CashSessionStatus::Closed)->whereNull('cash_sessions.received_at')
            ->whereNotExists(fn ($sub) => $sub->from('legacy_id_map')->where('new_type', 'cash_session')->whereColumn('legacy_id_map.new_id', 'cash_sessions.id'));
    }

    /** @return BelongsTo<User, $this> */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /** @return BelongsTo<Account, $this> */
    public function receivedAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'received_account_id');
    }

    /** @return BelongsTo<AccountTransfer, $this> */
    public function receiptTransfer(): BelongsTo
    {
        return $this->belongsTo(AccountTransfer::class, 'account_transfer_id');
    }
}
