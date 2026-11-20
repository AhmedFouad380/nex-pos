<?php

namespace App\Domain\Purchasing\Services;

use App\Domain\Purchasing\Enums\PurchaseOrderStatus;
use App\Domain\Purchasing\Models\PurchaseItem;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Support\Decimal;

/** Recomputes received quantities (from live purchases) and the status of a purchase order. */
final class PurchaseOrderProgress
{
    public function refresh(int $purchaseOrderId): void
    {
        $order = PurchaseOrder::query()->with('items')->findOrFail($purchaseOrderId);
        if ($order->status === PurchaseOrderStatus::Cancelled) {
            return;
        }

        $anyReceived = false;
        $allReceived = true;
        foreach ($order->items as $orderItem) {
            $baseReceived = Decimal::of(PurchaseItem::query()
                ->where('purchase_order_item_id', $orderItem->id)
                ->whereHas('purchase', fn ($q) => $q->whereNull('voided_at'))
                ->sum('base_qty'));
            $received = Decimal::divide($baseReceived, $orderItem->unit_multiplier);
            $orderItem->update(['qty_received' => Decimal::toDb($received)]);

            $anyReceived = $anyReceived || $received->isPositive();
            $allReceived = $allReceived && ! $received->isLessThan(Decimal::of($orderItem->qty));
        }

        $order->update(['status' => match (true) {
            $allReceived && $anyReceived => PurchaseOrderStatus::Received,
            $anyReceived => PurchaseOrderStatus::Partial,
            default => $order->status === PurchaseOrderStatus::Draft ? PurchaseOrderStatus::Draft : PurchaseOrderStatus::Sent,
        }]);
    }
}
