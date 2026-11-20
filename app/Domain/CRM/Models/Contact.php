<?php

namespace App\Domain\CRM\Models;

use App\Domain\CRM\Enums\ContactType;
use App\Domain\Finance\Models\JournalLine;
use App\Domain\Finance\Models\Payment;
use App\Domain\Foundation\Models\Note;
use App\Domain\Payroll\Models\EmployeeAdvance;
use App\Domain\Payroll\Models\EmployeeProfile;
use App\Domain\Payroll\Models\PayrollLine;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Purchasing\Models\PurchaseReturn;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleReturn;
use App\Domain\Shared\Enums\DurationUnit;
use App\Models\User;
use App\Support\Audit\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contact extends Model
{
    use Audited, SoftDeletes;

    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'is_default' => false,
        'is_active' => true,
        'balance' => 0,
        'loyalty_points' => 0,
    ];

    protected $fillable = [
        'type',
        'code',
        'name',
        'business_name',
        'mobile',
        'alt_mobile',
        'landline',
        'email',
        'tax_number',
        'address',
        'city',
        'state',
        'country',
        'zip_code',
        'shipping_address',
        'latitude',
        'longitude',
        'customer_group_id',
        'credit_limit',
        'pay_term_number',
        'pay_term_unit',
        'is_default',
        'is_active',
        'dob',
        'balance',
        'loyalty_points',
        'note',
        'custom_fields',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => ContactType::class,
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'credit_limit' => 'decimal:4',
            'pay_term_unit' => DurationUnit::class,
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'dob' => 'date',
            'balance' => 'decimal:4',
            'custom_fields' => 'array',
        ];
    }

    /** @return BelongsTo<CustomerGroup, $this> */
    public function customerGroup(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class, 'customer_group_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<Sale, $this> */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class, 'customer_id');
    }

    /** @return HasMany<SaleReturn, $this> */
    public function saleReturns(): HasMany
    {
        return $this->hasMany(SaleReturn::class, 'customer_id');
    }

    /** @return HasMany<Purchase, $this> */
    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class, 'supplier_id');
    }

    /** @return HasMany<PurchaseReturn, $this> */
    public function purchaseReturns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class, 'supplier_id');
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'contact_id');
    }

    /** @return HasMany<ContactOpeningBalance, $this> */
    public function openingBalances(): HasMany
    {
        return $this->hasMany(ContactOpeningBalance::class, 'contact_id');
    }

    /** @return HasMany<LedgerDiscount, $this> */
    public function ledgerDiscounts(): HasMany
    {
        return $this->hasMany(LedgerDiscount::class, 'contact_id');
    }

    /** @return HasMany<LoyaltyTransaction, $this> */
    public function loyaltyTransactions(): HasMany
    {
        return $this->hasMany(LoyaltyTransaction::class, 'contact_id');
    }

    /** @return HasMany<JournalLine, $this> */
    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'contact_id');
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'contact_user', 'contact_id', 'user_id');
    }

    /** @return MorphMany<Note, $this> */
    public function notes(): MorphMany
    {
        return $this->morphMany(Note::class, 'notable');
    }

    /**
     * Salary data (employees only).
     *
     * @return HasOne<EmployeeProfile, $this>
     */
    public function employeeProfile(): HasOne
    {
        return $this->hasOne(EmployeeProfile::class, 'contact_id');
    }

    /** @return HasMany<EmployeeAdvance, $this> */
    public function advances(): HasMany
    {
        return $this->hasMany(EmployeeAdvance::class, 'contact_id');
    }

    /** @return HasMany<PayrollLine, $this> */
    public function payrollLines(): HasMany
    {
        return $this->hasMany(PayrollLine::class, 'contact_id');
    }

    /** Cached ledger balance, refreshed after every transaction. */
    protected function auditExcept(): array
    {
        return ['balance'];
    }
}
