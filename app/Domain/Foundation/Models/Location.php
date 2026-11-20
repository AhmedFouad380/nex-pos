<?php

namespace App\Domain\Foundation\Models;

use App\Domain\CashManagement\Models\CashRegister;
use App\Domain\CashManagement\Services\BranchDrawer;
use App\Domain\Catalog\Models\PriceGroup;
use App\Domain\Catalog\Models\Product;
use App\Domain\Finance\Models\PaymentMethod;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Printing\Models\InvoiceLayout;
use App\Domain\Printing\Models\Printer;
use App\Domain\Restaurant\Models\KitchenStation;
use App\Domain\Restaurant\Models\RestaurantTable;
use App\Domain\Sales\Models\ServiceType;
use App\Models\User;
use App\Support\Audit\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Location extends Model
{
    use Audited, SoftDeletes;

    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'is_active' => true,
    ];

    protected $fillable = [
        'code',
        'name',
        'address',
        'city',
        'state',
        'country',
        'zip_code',
        'phone',
        'alt_phone',
        'email',
        'website',
        'is_active',
        'default_price_group_id',
        'invoice_layout_id',
        'receipt_layout_id',
        'printer_id',
        'custom_fields',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'custom_fields' => 'array',
        ];
    }

    /** @return BelongsTo<PriceGroup, $this> */
    public function defaultPriceGroup(): BelongsTo
    {
        return $this->belongsTo(PriceGroup::class, 'default_price_group_id');
    }

    /** @return BelongsTo<InvoiceLayout, $this> */
    public function invoiceLayout(): BelongsTo
    {
        return $this->belongsTo(InvoiceLayout::class, 'invoice_layout_id');
    }

    /** @return BelongsTo<InvoiceLayout, $this> */
    public function receiptLayout(): BelongsTo
    {
        return $this->belongsTo(InvoiceLayout::class, 'receipt_layout_id');
    }

    /** @return BelongsTo<Printer, $this> */
    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class, 'printer_id');
    }

    /** @return HasMany<StockLevel, $this> */
    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class, 'location_id');
    }

    /** Every branch is its own cash drawer: created with the branch, managed by the system (BranchDrawer). */
    protected static function booted(): void
    {
        static::created(fn (Location $location) => app(BranchDrawer::class)->for($location));
    }

    /** @return HasMany<CashRegister, $this> */
    public function cashRegisters(): HasMany
    {
        return $this->hasMany(CashRegister::class, 'location_id');
    }

    /** @return HasMany<RestaurantTable, $this> */
    public function restaurantTables(): HasMany
    {
        return $this->hasMany(RestaurantTable::class, 'location_id');
    }

    /** @return HasMany<KitchenStation, $this> */
    public function kitchenStations(): HasMany
    {
        return $this->hasMany(KitchenStation::class, 'location_id');
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'location_user', 'location_id', 'user_id');
    }

    /** @return BelongsToMany<Product, $this> */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'location_product', 'location_id', 'product_id')
            ->withPivot('rack', 'row', 'position', 'is_featured');
    }

    /** @return BelongsToMany<PaymentMethod, $this> */
    public function paymentMethods(): BelongsToMany
    {
        return $this->belongsToMany(PaymentMethod::class, 'location_payment_method', 'location_id', 'payment_method_id')
            ->withPivot('account_id', 'is_enabled');
    }

    /** @return BelongsToMany<ServiceType, $this> */
    public function serviceTypes(): BelongsToMany
    {
        return $this->belongsToMany(ServiceType::class, 'location_service_type', 'location_id', 'service_type_id')
            ->withPivot('price_group_id');
    }
}
