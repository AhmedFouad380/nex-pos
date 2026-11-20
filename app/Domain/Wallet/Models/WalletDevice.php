<?php

namespace App\Domain\Wallet\Models;

use App\Domain\Foundation\Models\Location;
use App\Models\User;
use App\Support\Audit\Audited;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

/** A phone running the wallet app, paired to a branch by scanning a QR code. It authenticates with a Sanctum token (no password). */
class WalletDevice extends Model implements AuthenticatableContract
{
    use Audited, Authenticatable;
    use HasApiTokens;

    protected $fillable = ['name', 'location_id', 'platform', 'app_version', 'last_seen_at', 'paired_by', 'revoked_at'];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** No heartbeat for this long = the phone is probably off or offline. */
    public const OFFLINE_AFTER_MINUTES = 15;

    public function isOnline(): bool
    {
        return $this->revoked_at === null && $this->last_seen_at?->gt(now()->subMinutes(self::OFFLINE_AFTER_MINUTES)) === true;
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    /** @return BelongsTo<User, $this> */
    public function pairer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paired_by');
    }

    /** @return HasMany<WalletTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class, 'wallet_device_id');
    }

    /** Heartbeat. */
    protected function auditExcept(): array
    {
        return ['last_seen_at'];
    }
}
