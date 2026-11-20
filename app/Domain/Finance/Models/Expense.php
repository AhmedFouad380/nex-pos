<?php

namespace App\Domain\Finance\Models;

use App\Domain\CashManagement\Models\CashSession;
use App\Domain\Catalog\Models\TaxRate;
use App\Domain\CRM\Models\Contact;
use App\Domain\Foundation\Models\Location;
use App\Domain\Sales\Models\RecurringProfile;
use App\Domain\Shared\Enums\PaymentStatus;
use App\Models\User;
use App\Support\Audit\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Expense extends Model
{
    use Audited;

    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'subtotal' => 0,
        'tax_amount' => 0,
        'total' => 0,
        'paid_total' => 0,
        'due_total' => 0,
        'payment_status' => 'due',
        'is_refund' => false,
    ];

    protected $fillable = [
        'number',
        'ref_no',
        'location_id',
        'expense_category_id',
        'contact_id',
        'expense_for_user_id',
        'cash_session_id',
        'expense_date',
        'subtotal',
        'tax_rate_id',
        'tax_amount',
        'total',
        'paid_total',
        'due_total',
        'payment_status',
        'is_refund',
        'note',
        'custom_fields',
        'created_by',
        'voided_at',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'expense_date' => 'datetime',
            'subtotal' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'total' => 'decimal:4',
            'paid_total' => 'decimal:4',
            'due_total' => 'decimal:4',
            'payment_status' => PaymentStatus::class,
            'is_refund' => 'boolean',
            'custom_fields' => 'array',
            'voided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    /** @return BelongsTo<ExpenseCategory, $this> */
    public function expenseCategory(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /** @return BelongsTo<User, $this> */
    public function expenseForUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'expense_for_user_id');
    }

    /** @return BelongsTo<CashSession, $this> */
    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class, 'cash_session_id');
    }

    /** @return BelongsTo<TaxRate, $this> */
    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class, 'tax_rate_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return MorphMany<PaymentAllocation, $this> */
    public function allocations(): MorphMany
    {
        return $this->morphMany(PaymentAllocation::class, 'allocatable');
    }

    /** @return MorphMany<JournalEntry, $this> */
    public function journalEntries(): MorphMany
    {
        return $this->morphMany(JournalEntry::class, 'source');
    }

    /** @return MorphOne<RecurringProfile, $this> */
    public function recurringProfile(): MorphOne
    {
        return $this->morphOne(RecurringProfile::class, 'recurrable');
    }
}
