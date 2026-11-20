<?php

namespace App\Filament\Admin\Resources\PurchaseReturns\Pages;

use App\Domain\Purchasing\Actions\CreatePurchaseReturn as CreatePurchaseReturnAction;
use App\Domain\Purchasing\Models\Purchase;
use App\Filament\Admin\Resources\PurchaseReturns\PurchaseReturnResource;
use App\Filament\Concerns\HandlesDomainExceptions;
use App\Filament\Support\UserLocations;
use App\Support\Decimal;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;

class CreatePurchaseReturn extends CreateRecord
{
    use HandlesDomainExceptions;

    protected static string $resource = PurchaseReturnResource::class;

    protected static bool $canCreateAnother = false;

    #[Url(as: 'purchase')]
    public ?int $purchaseId = null;

    protected function fillForm(): void
    {
        $purchase = $this->purchase();
        if (! $purchase) {
            parent::fillForm();

            return;
        }

        $this->form->fill([
            'purchase_id' => $purchase->id,
            'return_date' => now(),
            'purchase_lines' => $purchase->items()->with('variation.product', 'unit')->get()
                ->map(fn ($item) => [
                    'purchase_item_id' => $item->id,
                    'label' => $item->variation->fullName().' ('.$item->unit->short_name.')',
                    'returnable' => Decimal::of($item->qty)->minus(Decimal::of($item->qty_returned))->toFloat(),
                    'qty' => 0,
                ])
                ->filter(fn ($line) => $line['returnable'] > 0)->values()->all(),
        ]);
    }

    /** The purchase to return from (?purchase=ID), if it is live and in one of the user's locations. */
    private function purchase(): ?Purchase
    {
        return $this->purchaseId
            ? Purchase::query()->whereNull('voided_at')->whereIn('location_id', array_keys(UserLocations::options()))->find($this->purchaseId)
            : null;
    }

    protected function handleRecordCreation(array $data): Model
    {
        $purchase = filled($data['purchase_id'] ?? null) ? Purchase::query()->whereNull('voided_at')->findOrFail($data['purchase_id']) : null;
        abort_unless(array_key_exists($purchase->location_id ?? $data['location_id'], UserLocations::options()), 403);

        $lines = $purchase
            ? array_values($data['purchase_lines'] ?? [])
            : array_map(fn ($l) => [...$l, 'tax_rate_id' => $data['tax_rate_id'] ?? null], array_values($data['items'] ?? []));

        return $this->rescueDomain(fn () => app(CreatePurchaseReturnAction::class)->handle(
            lines: $lines,
            purchase: $purchase,
            supplierId: isset($data['supplier_id']) ? (int) $data['supplier_id'] : null,
            locationId: isset($data['location_id']) ? (int) $data['location_id'] : null,
            date: Carbon::parse($data['return_date']),
            note: $data['note'] ?? null,
        ));
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
