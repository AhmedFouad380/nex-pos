<?php

namespace App\Domain\Wallet\Models;

use App\Domain\Finance\Enums\PaymentDirection;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\Payment;
use App\Domain\Foundation\Models\Location;
use App\Domain\Wallet\Enums\WalletProvider;
use App\Domain\Wallet\Enums\WalletTarget;
use App\Domain\Wallet\Enums\WalletTransactionStatus;
use App\Models\User;
use App\Support\Audit\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One wallet message: raw text, what was read from it, and — once confirmed — the voucher it became. */
class WalletTransaction extends Model
{
    use Audited;

    protected $attributes = ['fee' => 0, 'status' => 'pending'];

    protected $fillable = [
        'wallet_device_id', 'location_id', 'wallet_id', 'wallet_message_template_id',
        'sender', 'body', 'body_hash', 'received_at', 'sim_slot',
        'provider', 'direction', 'amount', 'fee', 'balance', 'counterparty_phone', 'counterparty_name', 'wallet_phone',
        'reference', 'occurred_at',
        'status', 'target_type', 'target_id', 'payment_id', 'expense_id', 'fee_expense_id',
        'confirmed_by', 'confirmed_at', 'reject_reason', 'note',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'occurred_at' => 'datetime',
            'provider' => WalletProvider::class,
            'direction' => PaymentDirection::class,
            'amount' => 'decimal:4',
            'fee' => 'decimal:4',
            'balance' => 'decimal:4',
            'status' => WalletTransactionStatus::class,
            'target_type' => WalletTarget::class,
            'confirmed_at' => 'datetime',
        ];
    }

    /** Normalizes a body for duplicate detection: the same SMS from two phones may differ in whitespace. */
    public static function hashBody(string $body): string
    {
        return hash('sha256', (string) preg_replace('/\s+/u', ' ', trim($body)));
    }

    /** @return BelongsTo<WalletDevice, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(WalletDevice::class, 'wallet_device_id');
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    /** @return BelongsTo<Wallet, $this> */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'wallet_id');
    }

    /** @return BelongsTo<WalletMessageTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(WalletMessageTemplate::class, 'wallet_message_template_id');
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    /** @return BelongsTo<Expense, $this> */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class, 'expense_id');
    }

    /** @return BelongsTo<User, $this> */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
