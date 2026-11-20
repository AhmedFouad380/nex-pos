<?php

namespace App\Domain\Wallet\Models;

use App\Domain\Finance\Enums\PaymentDirection;
use App\Domain\Wallet\Enums\WalletProvider;
use App\Support\Audit\Audited;
use Illuminate\Database\Eloquent\Model;

/** The wording of one kind of provider message, with {placeholders} where the values are. */
class WalletMessageTemplate extends Model
{
    use Audited;

    protected $attributes = ['date_format' => 'H:i y-m-d', 'sort' => 0, 'is_active' => true];

    protected $fillable = ['provider', 'direction', 'name', 'sender', 'template', 'date_format', 'sort', 'is_active'];

    protected function casts(): array
    {
        return [
            'provider' => WalletProvider::class,
            'direction' => PaymentDirection::class,
            'is_active' => 'boolean',
        ];
    }
}
