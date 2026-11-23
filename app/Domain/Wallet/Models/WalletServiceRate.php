<?php

namespace App\Domain\Wallet\Models;

use App\Domain\Finance\Enums\PaymentDirection;
use App\Domain\Foundation\Models\Location;
use App\Support\Audit\Audited;
use App\Support\Decimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A branch's commission on the wallet service: a percentage of the amount, never less than the minimum. */
class WalletServiceRate extends Model
{
    use Audited;

    protected $attributes = ['transfer_percent' => 0, 'transfer_min' => 0, 'withdraw_percent' => 0, 'withdraw_min' => 0];

    protected $fillable = ['location_id', 'transfer_percent', 'transfer_min', 'withdraw_percent', 'withdraw_min'];

    protected function casts(): array
    {
        return [
            'transfer_percent' => 'decimal:4',
            'transfer_min' => 'decimal:4',
            'withdraw_percent' => 'decimal:4',
            'withdraw_min' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    /** The commission on $amount: out = a transfer the shop sends for the customer, in = a withdrawal. */
    public function commission(PaymentDirection $direction, BigDecimal|string $amount): BigDecimal
    {
        [$percent, $min] = $direction === PaymentDirection::Out
            ? [$this->transfer_percent, $this->transfer_min]
            : [$this->withdraw_percent, $this->withdraw_min];
        $byPercent = Decimal::of($amount)->multipliedBy(Decimal::of($percent))->dividedBy(100, 2, RoundingMode::HalfUp);

        return BigDecimal::max($byPercent, Decimal::of($min)->toScale(2, RoundingMode::HalfUp));
    }

    public static function commissionFor(int $locationId, PaymentDirection $direction, BigDecimal|string $amount): BigDecimal
    {
        $rate = self::query()->where('location_id', $locationId)->first();

        return $rate ? $rate->commission($direction, $amount) : BigDecimal::zero();
    }
}
