<?php

use App\Domain\Catalog\Models\Unit;
use App\Domain\Foundation\Models\Location;
use App\Domain\Inventory\Actions\CreateStockAdjustment;
use App\Domain\Inventory\Data\StockLineData;
use App\Domain\Inventory\Enums\StockAdjustmentType;
use App\Domain\Inventory\Exceptions\StockAlreadyConsumed;
use App\Domain\Inventory\Models\StockLayer;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Services\StockLedger;
use App\Domain\Purchasing\Actions\CancelPurchaseOrder;
use App\Domain\Purchasing\Actions\CreatePurchase;
use App\Domain\Purchasing\Actions\CreatePurchaseOrder;
use App\Domain\Purchasing\Actions\CreatePurchaseReturn;
use App\Domain\Purchasing\Actions\ReceivePurchase;
use App\Domain\Purchasing\Actions\VoidPurchase;
use App\Domain\Purchasing\Actions\VoidPurchaseReturn;
use App\Domain\Purchasing\Data\PurchaseData;
use App\Domain\Purchasing\Data\PurchaseLineData;
use App\Domain\Purchasing\Enums\CostDistribution;
use App\Domain\Purchasing\Enums\PurchaseOrderStatus;
use App\Domain\Purchasing\Enums\PurchaseStatus;
use App\Domain\Shared\Enums\DiscountType;
use App\Domain\Shared\Enums\PaymentStatus;
use App\Support\DomainException;

beforeEach(function () {
    seedBaseData();
    $this->location = Location::query()->firstOrFail();
    $this->supplier = makeSupplier();
    $this->a = makeVariation([], ['purchase_price' => 1]);
    $this->b = makeVariation([], ['purchase_price' => 1]);
    $this->onHand = fn ($v) => (string) app(StockLedger::class)->onHand($v->id, $this->location->id);
    $this->cost = fn ($v) => StockLevel::query()->where('variation_id', $v->id)->value('avg_cost');
});

it('receives stock at landed cost: discount and shipping spread by value, extra cost by quantity', function () {
    // A: 10 × 10 = 100, B: 30 × 10 = 300 → net 400
    $purchase = makePurchase($this->supplier, [[$this->a, 10, 10], [$this->b, 30, 10]], [
        'discountType' => DiscountType::Fixed, 'discountValue' => '40',  // A −10, B −30 (by value)
        'shippingCharges' => '80',                                        // A +20, B +60 (by value)
        'costs' => [['name' => 'جمارك', 'amount' => '40', 'distribute_by' => CostDistribution::Qty]], // A +10, B +30 (by qty)
    ]);

    expect($purchase->number)->toBe('PU-00001')
        ->and((string) $purchase->total)->toBe('480.0000') // 400 − 40 + 80 + 40
        ->and($purchase->status)->toBe(PurchaseStatus::Received)
        ->and($purchase->payment_status)->toBe(PaymentStatus::Due)
        ->and(($this->onHand)($this->a))->toBe('10.0000')
        ->and(($this->cost)($this->a))->toBe('12.0000')  // (100 − 10 + 20 + 10) / 10
        ->and(($this->cost)($this->b))->toBe('12.0000')  // (300 − 30 + 60 + 30) / 30
        ->and($this->a->refresh()->purchase_price)->toBe('12.0000'); // latest landed cost becomes default
    assertStockConsistent();
});

it('converts foreign-currency purchases with the exchange rate', function () {
    makePurchase($this->supplier, [[$this->a, 2, 5]], ['exchangeRate' => '50']); // $5 × 50

    expect(($this->cost)($this->a))->toBe('250.0000');
});

it('keeps pending purchases out of stock until received', function () {
    $purchase = makePurchase($this->supplier, [[$this->a, 4, 3]], ['status' => PurchaseStatus::Pending]);
    expect(($this->onHand)($this->a))->toBe('0.0000')
        ->and($this->supplier->refresh()->balance)->toBe('-12.0000'); // owed even if not received

    app(ReceivePurchase::class)->handle($purchase);

    expect(($this->onHand)($this->a))->toBe('4.0000')
        ->and(fn () => app(ReceivePurchase::class)->handle($purchase->refresh()))->toThrow(DomainException::class);
});

it('updates the selling price from the purchase screen per base unit', function () {
    $carton = Unit::query()->where('name', 'كرتونة')->firstOrFail(); // 12
    app(CreatePurchase::class)->handle(new PurchaseData(
        $this->supplier->id, $this->location->id,
        [new PurchaseLineData($this->a->id, $carton->id, '1', '120', newSellPrice: '180')],
    ));

    expect($this->a->refresh()->sell_price_inc_tax)->toBe('15.0000') // 180 per carton → 15 per piece
        ->and(($this->onHand)($this->a))->toBe('12.0000');
});

it('voids an unpaid purchase but refuses one that was paid or partly sold', function () {
    $purchase = makePurchase($this->supplier, [[$this->a, 5, 2]]);
    app(VoidPurchase::class)->handle($purchase, 'خطأ');
    expect(($this->onHand)($this->a))->toBe('0.0000')->and($this->supplier->refresh()->balance)->toBe('0.0000');

    $sold = makePurchase($this->supplier, [[$this->a, 5, 2]]);
    app(CreateStockAdjustment::class)->handle($this->location->id, StockAdjustmentType::Decrease,
        [new StockLineData($this->a->id, $this->a->product->unit_id, '1')]);
    expect(fn () => app(VoidPurchase::class)->handle($sold, 'x'))->toThrow(StockAlreadyConsumed::class);
    assertStockConsistent();
});

it('returns goods to the supplier from the purchase layer, capped at what was bought', function () {
    makePurchase($this->supplier, [[$this->a, 10, 4]]);               // older, cheaper layer
    $purchase = makePurchase($this->supplier, [[$this->a, 5, 10]]);   // returning from this one
    $item = $purchase->items->sole();

    $return = app(CreatePurchaseReturn::class)->handle([['purchase_item_id' => $item->id, 'qty' => 2]], $purchase);

    expect((string) $return->total)->toBe('20.0000')
        ->and($item->refresh()->qty_returned)->toBe('2.0000')
        ->and(($this->onHand)($this->a))->toBe('13.0000')
        ->and($this->supplier->refresh()->balance)->toBe('-70.0000') // −40 −50 +20
        ->and(fn () => app(CreatePurchaseReturn::class)->handle([['purchase_item_id' => $item->id, 'qty' => 4]], $purchase))
        ->toThrow(DomainException::class);
    // took the 10-cost layer, not FIFO's 4-cost one
    expect(StockLayer::query()->where('unit_cost', 10)->value('remaining_qty'))->toBe('3.0000');

    expect(fn () => app(VoidPurchase::class)->handle($purchase, 'x'))->toThrow(DomainException::class); // has returns
    app(VoidPurchaseReturn::class)->handle($return, 'x');
    expect(($this->onHand)($this->a))->toBe('15.0000')->and($item->refresh()->qty_returned)->toBe('0.0000');
    assertStockConsistent();
});

it('tracks purchase order progress across partial purchases', function () {
    $order = app(CreatePurchaseOrder::class)->handle($this->supplier->id, $this->location->id,
        [new PurchaseLineData($this->a->id, $this->a->product->unit_id, '10', '5')], sent: true);
    $orderItem = $order->items->sole();

    $first = makePurchase($this->supplier, [], ['purchaseOrderId' => $order->id,
        'lines' => [new PurchaseLineData($this->a->id, $this->a->product->unit_id, '4', '5', purchaseOrderItemId: $orderItem->id)]]);
    expect($order->refresh()->status)->toBe(PurchaseOrderStatus::Partial)
        ->and(CreatePurchaseOrder::remainingLines($order)[0]['qty'])->toBe(6.0)
        ->and(fn () => app(CancelPurchaseOrder::class)->handle($order))->toThrow(DomainException::class);

    makePurchase($this->supplier, [], ['purchaseOrderId' => $order->id,
        'lines' => [new PurchaseLineData($this->a->id, $this->a->product->unit_id, '6', '5', purchaseOrderItemId: $orderItem->id)]]);
    expect($order->refresh()->status)->toBe(PurchaseOrderStatus::Received);

    app(VoidPurchase::class)->handle($first, 'x');
    expect($order->refresh()->status)->toBe(PurchaseOrderStatus::Partial);
});

it('refuses customers as suppliers', function () {
    expect(fn () => makePurchase(makeCustomer(), [[$this->a, 1, 1]]))->toThrow(DomainException::class);
});
