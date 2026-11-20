<?php

namespace App\Domain\Finance\Models;

use App\Domain\Finance\Enums\AccountSubType;
use App\Domain\Finance\Enums\AccountType;
use App\Domain\Foundation\Models\Currency;
use App\Support\Audit\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    use Audited;

    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'is_system' => false,
        'is_postable' => true,
        'is_active' => true,
    ];

    protected $fillable = [
        'parent_id',
        'code',
        'name',
        'type',
        'sub_type',
        'is_system',
        'is_postable',
        'is_active',
        'bank_name',
        'account_number',
        'currency_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'sub_type' => AccountSubType::class,
            'is_system' => 'boolean',
            'is_postable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Account, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'parent_id');
    }

    /** @return BelongsTo<Currency, $this> */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    /** @return HasMany<Account, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(Account::class, 'parent_id');
    }

    /** @return HasMany<JournalLine, $this> */
    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'account_id');
    }
}
