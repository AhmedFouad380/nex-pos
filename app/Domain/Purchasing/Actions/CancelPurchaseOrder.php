<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Purchasing\Enums\PurchaseOrderStatus;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Support\DomainException;

final class CancelPurchaseOrder
{
    public function handle(PurchaseOrder $order): PurchaseOrder
    {
        if (in_array($order->status, [PurchaseOrderStatus::Received, PurchaseOrderStatus::Cancelled], true)
            || $order->purchases()->whereNull('voided_at')->exists()) {
            throw new DomainException(__('inventory.errors.invalid_status', ['status' => $order->status->getLabel()]));
        }
        $order->update(['status' => PurchaseOrderStatus::Cancelled]);

        return $order;
    }
}
