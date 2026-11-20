<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Support\DomainException;

/** Drafts, quotations and suspended sales never touched stock or money, so they can simply be deleted. */
final class DeleteSaleDraft
{
    public function handle(Sale $sale): void
    {
        if ($sale->status === SaleStatus::Final) {
            throw new DomainException(__('sales.errors.final_is_immutable'));
        }
        $sale->items()->delete();
        $sale->delete();
    }
}
