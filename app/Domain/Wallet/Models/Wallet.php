<?php

namespace App\Domain\Wallet\Models;

use App\Domain\Finance\Models\Account;
use App\Domain\Foundation\Models\Location;
use App\Domain\Wallet\Enums\WalletProvider;
use App\Support\Audit\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A wallet number the business owns (one SIM / InstaPay account). */
class Wallet extends Model
{
    use Audited;

    protected $attributes = ['is_active' => true];

    protected $fillable = ['provider', 'name', 'phone', 'location_id', 'account_id', 'last_balance', 'last_balance_at', 'is_active'];

    protected function casts(): array
    {
        return [
            'provider' => WalletProvider::class,
            'last_balance' => 'decimal:4',
            'last_balance_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    /** @return HasMany<WalletTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class, 'wallet_id');
    }

    /** Updated from every SMS. */
    protected function auditExcept(): array
    {
        return ['last_balance', 'last_balance_at'];
    }
}
