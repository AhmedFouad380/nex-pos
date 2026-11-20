<?php

use App\Domain\CashManagement\Actions\CloseCashSession;
use App\Domain\CashManagement\Actions\RecordCashMovement;
use App\Domain\CashManagement\Services\CashSessionSummary;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Models\PriceGroup;
use App\Domain\Catalog\Models\TaxRate;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Finance\Enums\PaymentDirection;
use App\Domain\Foundation\Models\Location;
use App\Domain\Inventory\Exceptions\InsufficientStock;
use App\Domain\Inventory\Services\StockLedger;
use App\Domain\Sales\Actions\CreateSaleReturn;
use App\Domain\Sales\Actions\SaveSale;
use App\Domain\Sales\Actions\VoidSale;
use App\Domain\Sales\Actions\VoidSaleReturn;
use App\Domain\Sales\Data\SaleData;
use App\Domain\Sales\Data\SaleLineData;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Domain\Shared\Enums\DiscountType;
use App\Domain\Shared\Enums\PaymentStatus;
use App\Models\User;
use App\Support\DomainException;

beforeEach(function () {
    seedBaseData();
    $this->admin = User::query()->firstOrFail();
    $this->actingAs($this->admin);
    $this->location = Location::query()->firstOrFail();
    $this->session = openSession($this->admin, 100);
    // 10 in stock at cost 6, sells for 10 (no tax)
    $this->tea = makeVariation([], ['purchase_price' => 6, 'sell_price' => 10, 'sell_price_inc_tax' => 10]);
    makePurchase(makeSupplier(), [[$this->tea, 10, 6]]);
    $this->onHand = fn ($v = null) => (string) app(StockLedger::class)->onHand(($v ?? $this->tea)->id, $this->location->id);
    $this->pos = fn () => ['cashSessionId' => $this->session->id];
});

it('sells for cash, gives change, takes stock out at cost', function () {
    $sale = sell([[$this->tea, 3]], [cash(50)], ($this->pos)());

    expect($sale->status)->toBe(SaleStatus::Final)
        ->and($sale->invoice_no)->toBe('INV-00001')
        ->and((string) $sale->total)->toBe('30.0000')
        ->and((string) $sale->cost_total)->toBe('18.0000')
        ->and($sale->payment_status)->toBe(PaymentStatus::Paid)
        ->and((string) SaveSale::change([cash(50)], '30'))->toBe('20')
        ->and((float) $sale->allocations()->sum('amount'))->toBe(30.0) // the 20 change is not recorded as received
        ->and(($this->onHand)())->toBe('7.0000');
    assertStockConsistent();
});

it('splits payment between card and cash', function () {
    $sale = sell([[$this->tea, 5]], [card(20), cash(40)], ($this->pos)()); // 50 due → card 20 + cash 30, change 10

    expect($sale->payment_status)->toBe(PaymentStatus::Paid)->and($sale->allocations()->count())->toBe(2);
    expect(fn () => sell([[$this->tea, 1]], [card(20)], ($this->pos)()))->toThrow(DomainException::class); // card can't give change
});

it('refuses credit for the walk-in customer and above the credit limit', function () {
    expect(fn () => sell([[$this->tea, 1]], [], ($this->pos)()))->toThrow(DomainException::class);

    // a cashier allowed to sell on credit, without the "override limits" permission the admin has
    $cashier = User::factory()->create();
    $cashier->assignRole('cashier');
    $cashier->givePermissionTo('pos.credit_sale');
    $this->actingAs($cashier);

    $customer = makeCustomer(['credit_limit' => 25]);
    $sale = sell([[$this->tea, 2]], [], [...($this->pos)(), 'customerId' => $customer->id]); // 20 on credit
    expect($sale->payment_status)->toBe(PaymentStatus::Due)->and($customer->refresh()->balance)->toBe('20.0000');

    expect(fn () => sell([[$this->tea, 1]], [], [...($this->pos)(), 'customerId' => $customer->id]))->toThrow(DomainException::class); // 30 > 25
    expect(($this->onHand)())->toBe('8.0000'); // the refused sale took nothing
});

it('requires an open shift for POS sales', function () {
    expect(fn () => sell([[$this->tea, 1]], [cash(10)]))->toThrow(DomainException::class);
});

it('applies price groups, sub-units and tax-inclusive prices', function () {
    $vat = TaxRate::query()->where('rate', 14)->firstOrFail();
    $carton = Unit::query()->where('name', 'كرتونة')->firstOrFail();
    $product = app(SaveProduct::class)->handle(['name' => 'عصير', 'type' => 'single', 'unit_id' => $this->tea->product->unit_id,
        'tax_rate_id' => $vat->id, 'tax_type' => 'inclusive', 'purchase_price' => 5, 'sell_price' => 11.4,
        'group_prices' => [['price_group_id' => PriceGroup::query()->firstOrFail()->id, 'value' => 9.12]]]);
    $juice = $product->defaultVariation;
    makePurchase(makeSupplier(), [[$juice->load('product'), 24, 5]]);

    $sale = sell([[$juice, 1, ['unitId' => $carton->id]]], [cash(200)], ($this->pos)());
    expect((string) $sale->total)->toBe('136.8000')   // 12 × 11.40 (inclusive)
        ->and((string) $sale->tax_amount)->toBe('16.8000');

    $wholesale = sell([[$juice, 2]], [cash(100)], [...($this->pos)(), 'priceGroupId' => PriceGroup::query()->firstOrFail()->id]);
    expect((string) $wholesale->total)->toBe('18.2400');
});

it('allows only one open shift per drawer', function () {
    $cashier = User::factory()->create();
    $cashier->assignRole('cashier');

    openSession($cashier); // the admin already holds the only drawer
})->throws(DomainException::class);

it('checks the discount limit of the cashier', function () {
    $this->admin->update(['max_discount_percent' => 10]);
    $cashier = User::factory()->create(['max_discount_percent' => 10]);
    $cashier->assignRole('cashier');
    app(CloseCashSession::class)->handle($this->session, 100);
    $this->actingAs($cashier);
    $session = openSession($cashier);

    expect(fn () => sell([[$this->tea, 1, ['discountType' => DiscountType::Percent, 'discountValue' => '15']]], [cash(10)], ['cashSessionId' => $session->id]))
        ->toThrow(DomainException::class);
    expect(sell([[$this->tea, 1, ['discountType' => DiscountType::Percent, 'discountValue' => '10']]], [cash(9)], ['cashSessionId' => $session->id])->total)
        ->toBe('9.0000');
});

it('sells combos by taking their components out of stock', function () {
    $combo = app(SaveProduct::class)->handle(['name' => 'باكيت', 'type' => 'combo', 'unit_id' => $this->tea->product->unit_id, 'tax_type' => 'inclusive',
        'sell_price' => 25, 'combo_items' => [['component_variation_id' => $this->tea->id, 'qty' => 3]]]);

    $sale = sell([[$combo->defaultVariation, 2]], [cash(50)], ($this->pos)());

    expect(($this->onHand)())->toBe('4.0000')            // 2 × 3 teas
        ->and((string) $sale->cost_total)->toBe('36.0000') // 6 × 6
        ->and($sale->items()->whereNotNull('parent_item_id')->count())->toBe(1);
    assertStockConsistent();
});

it('saves drafts and suspended sales without stock or money, then finalizes them', function () {
    $draft = sell([[$this->tea, 2]], [], [...($this->pos)(), 'status' => SaleStatus::Suspended]);
    expect($draft->draft_no)->toBe('DR-00001')->and($draft->invoice_no)->toBeNull()->and(($this->onHand)())->toBe('10.0000');

    $quote = sell([[$this->tea, 1]], [], [...($this->pos)(), 'status' => SaleStatus::Quotation]);
    expect($quote->draft_no)->toBe('QT-00001');

    $final = app(SaveSale::class)->handle(new SaleData($this->location->id,
        [new SaleLineData($this->tea->id, '3')], tenders: [cash(30)], cashSessionId: $this->session->id), $draft);

    expect($final->id)->toBe($draft->id)->and($final->invoice_no)->toBe('INV-00001')->and(($this->onHand)())->toBe('7.0000')
        ->and(fn () => app(SaveSale::class)->handle(new SaleData($this->location->id,
            [new SaleLineData($this->tea->id, '1')]), $final))->toThrow(DomainException::class);
});

it('voids a sale: stock back and its payments voided', function () {
    $sale = sell([[$this->tea, 4]], [cash(40)], ($this->pos)());

    app(VoidSale::class)->handle($sale, 'خطأ كاشير');

    expect(($this->onHand)())->toBe('10.0000')
        ->and($sale->allocations()->first()->payment->voided_at)->not->toBeNull()
        ->and(app(CashSessionSummary::class)->summarize($this->session)['expected_cash'])->toBe('100.0000');
    assertStockConsistent();
});

it('returns part of a sale at the discounted price and refunds from the drawer', function () {
    // 5 × 10 = 50, invoice discount 10 → paid 40; returning 2 refunds 2 × 8 = 16
    $sale = sell([[$this->tea, 5]], [cash(40)], [...($this->pos)(), 'discountType' => DiscountType::Fixed, 'discountValue' => '10']);
    $item = $sale->items->sole();

    $return = app(CreateSaleReturn::class)->handle($sale, [['sale_item_id' => $item->id, 'qty' => 2]], cashMethodId(), $this->session->id);

    expect((string) $return->total)->toBe('16.0000')
        ->and($return->payment_status)->toBe(PaymentStatus::Paid)
        ->and(($this->onHand)())->toBe('7.0000')
        ->and(fn () => app(CreateSaleReturn::class)->handle($sale, [['sale_item_id' => $item->id, 'qty' => 4]]))->toThrow(DomainException::class)
        ->and(fn () => app(VoidSale::class)->handle($sale, 'x'))->toThrow(DomainException::class);

    app(VoidSaleReturn::class)->handle($return, 'x');
    expect(($this->onHand)())->toBe('5.0000')->and($item->refresh()->qty_returned)->toBe('0.0000');
    assertStockConsistent();
});

it('closes a shift comparing counted cash with sales, refunds and cash movements', function () {
    sell([[$this->tea, 3]], [cash(50)], ($this->pos)());                    // +30 cash
    sell([[$this->tea, 2]], [card(20)], ($this->pos)());                    // card, not in drawer
    app(RecordCashMovement::class)->handle($this->session, PaymentDirection::Out, 25, 'إيداع بنك'); // −25

    $summary = app(CashSessionSummary::class)->summarize($this->session);
    expect($summary['expected_cash'])->toBe('105.0000')  // 100 float + 30 − 25
        ->and($summary['sales_count'])->toBe(2)
        ->and($summary['sales_total'])->toBe('50.0000');

    $closed = app(CloseCashSession::class)->handle($this->session, 100, ['100' => 1]);
    expect($closed->difference)->toBe('-5.0000')
        ->and(fn () => sell([[$this->tea, 1]], [cash(10)], ($this->pos)()))->toThrow(DomainException::class); // closed shift
});

it('refuses to oversell when overselling is off', function () {
    expect(fn () => sell([[$this->tea, 11]], [cash(110)], ($this->pos)()))->toThrow(InsufficientStock::class);
    expect(Sale::query()->count())->toBe(0);
});
