<?php

namespace App\Domain\Sales\Models;

use App\Domain\CashManagement\Models\CashSession;
use App\Domain\Catalog\Models\PriceGroup;
use App\Domain\Catalog\Models\TaxRate;
use App\Domain\CRM\Models\Contact;
use App\Domain\CRM\Models\CustomerGroup;
use App\Domain\Finance\Models\JournalEntry;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Foundation\Models\Location;
use App\Domain\Foundation\Models\Note;
use App\Domain\Restaurant\Enums\KitchenStatus;
use App\Domain\Restaurant\Models\RestaurantTable;
use App\Domain\Sales\Enums\SaleChannel;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Enums\ShippingStatus;
use App\Domain\Shared\Enums\DiscountType;
use App\Domain\Shared\Enums\PaymentStatus;
use App\Models\User;
use App\Support\Audit\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Sale extends Model
{
    use Audited;

    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'status' => 'draft',
        'channel' => 'pos',
        'subtotal' => 0,
        'discount_value' => 0,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'shipping_charges' => 0,
        'packing_charge' => 0,
        'round_off' => 0,
        'total' => 0,
        'paid_total' => 0,
        'due_total' => 0,
        'payment_status' => 'due',
        'cost_total' => 0,
        'points_earned' => 0,
        'points_redeemed' => 0,
        'points_redeem_amount' => 0,
    ];

    protected $fillable = [
        'invoice_no',
        'draft_no',
        'status',
        'channel',
        'location_id',
        'customer_id',
        'cash_session_id',
        'sales_order_id',
        'sale_date',
        'due_date',
        'valid_until',
        'delivery_date',
        'customer_group_id',
        'price_group_id',
        'service_type_id',
        'table_id',
        'waiter_id',
        'commission_agent_id',
        'subtotal',
        'discount_type',
        'discount_value',
        'discount_amount',
        'tax_rate_id',
        'tax_amount',
        'shipping_charges',
        'packing_charge',
        'round_off',
        'total',
        'paid_total',
        'due_total',
        'payment_status',
        'cost_total',
        'points_earned',
        'points_redeemed',
        'points_redeem_amount',
        'kitchen_status',
        'shipping_status',
        'shipping_address',
        'shipping_details',
        'delivered_to',
        'delivery_person_id',
        'note',
        'staff_note',
        'public_token',
        'import_batch',
        'custom_fields',
        'created_by',
        'updated_by',
        'voided_at',
        'void_reason',
        'voided_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => SaleStatus::class,
            'channel' => SaleChannel::class,
            'sale_date' => 'datetime',
            'due_date' => 'date',
            'valid_until' => 'date',
            'delivery_date' => 'date',
            'subtotal' => 'decimal:4',
            'discount_type' => DiscountType::class,
            'discount_value' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'shipping_charges' => 'decimal:4',
            'packing_charge' => 'decimal:4',
            'round_off' => 'decimal:4',
            'total' => 'decimal:4',
            'paid_total' => 'decimal:4',
            'due_total' => 'decimal:4',
            'payment_status' => PaymentStatus::class,
            'cost_total' => 'decimal:4',
            'points_redeem_amount' => 'decimal:4',
            'kitchen_status' => KitchenStatus::class,
            'shipping_status' => ShippingStatus::class,
            'custom_fields' => 'array',
            'voided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    /** @return BelongsTo<Contact, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'customer_id');
    }

    /** @return BelongsTo<CashSession, $this> */
    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class, 'cash_session_id');
    }

    /** @return BelongsTo<SalesOrder, $this> */
    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    /** @return BelongsTo<CustomerGroup, $this> */
    public function customerGroup(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class, 'customer_group_id');
    }

    /** @return BelongsTo<PriceGroup, $this> */
    public function priceGroup(): BelongsTo
    {
        return $this->belongsTo(PriceGroup::class, 'price_group_id');
    }

    /** @return BelongsTo<ServiceType, $this> */
    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class, 'service_type_id');
    }

    /** @return BelongsTo<RestaurantTable, $this> */
    public function table(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'table_id');
    }

    /** @return BelongsTo<User, $this> */
    public function waiter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waiter_id');
    }

    /** @return BelongsTo<User, $this> */
    public function commissionAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'commission_agent_id');
    }

    /** @return BelongsTo<TaxRate, $this> */
    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class, 'tax_rate_id');
    }

    /** @return BelongsTo<User, $this> */
    public function deliveryPerson(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivery_person_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** @return BelongsTo<User, $this> */
    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /** @return HasMany<SaleItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class, 'sale_id');
    }

    /**
     * How much of the invoice came back: 'full' (every line returned), 'partial', or null (nothing).
     * Counts the invoice's own lines (not bundle components); a voided return gives its quantity back.
     */
    public function returnState(): ?string
    {
        $lines = $this->items()->whereNull('parent_item_id')->where('qty', '>', 0)
            ->selectRaw('count(*) as n, sum(case when qty_returned > 0 then 1 else 0 end) as touched, sum(case when qty_returned >= qty then 1 else 0 end) as full')
            ->first();
        if (! $lines || (int) $lines->getAttribute('n') === 0 || (int) $lines->getAttribute('touched') === 0) {
            return null;
        }

        return (int) $lines->getAttribute('full') === (int) $lines->getAttribute('n') ? 'full' : 'partial';
    }

    /** @return HasMany<SaleReturn, $this> */
    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class, 'sale_id');
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

    /** @return MorphMany<Note, $this> */
    public function notes(): MorphMany
    {
        return $this->morphMany(Note::class, 'notable');
    }

    /** @return MorphOne<RecurringProfile, $this> */
    public function recurringProfile(): MorphOne
    {
        return $this->morphOne(RecurringProfile::class, 'recurrable');
    }
}
