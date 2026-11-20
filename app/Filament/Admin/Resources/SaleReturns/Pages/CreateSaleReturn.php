<?php

namespace App\Filament\Admin\Resources\SaleReturns\Pages;

use App\Domain\Sales\Actions\CreateSaleReturn as CreateSaleReturnAction;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Filament\Admin\Resources\SaleReturns\SaleReturnResource;
use App\Filament\Concerns\HandlesDomainExceptions;
use App\Filament\Support\UserLocations;
use App\Support\Decimal;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;

/** Returns are always made from an invoice (open it from the invoice page). */
class CreateSaleReturn extends CreateRecord
{
    use HandlesDomainExceptions;

    protected static string $resource = SaleReturnResource::class;

    protected static bool $canCreateAnother = false;

    #[Url(as: 'sale')]
    public ?int $saleId = null;

    public function mount(): void
    {
        abort_unless($this->sale() !== null, 404);
        parent::mount();
    }

    protected function fillForm(): void
    {
        $sale = $this->sale();
        $this->form->fill([
            'sale_id' => $sale->id,
            'return_date' => now(),
            'lines' => $sale->items()->whereNull('parent_item_id')->with('variation.product', 'unit')->get()
                ->map(fn ($item) => [
                    'sale_item_id' => $item->id,
                    'label' => $item->variation->fullName().' ('.$item->unit->short_name.')',
                    'returnable' => Decimal::of($item->qty)->minus(Decimal::of($item->qty_returned))->toFloat(),
                    'qty' => 0,
                    'restock' => true,
                ])
                ->filter(fn ($l) => $l['returnable'] > 0)->values()->all(),
        ]);
    }

    private function sale(): ?Sale
    {
        return $this->saleId
            ? Sale::query()->where('status', SaleStatus::Final)->whereNull('voided_at')
                ->whereIn('location_id', array_keys(UserLocations::options()))->find($this->saleId)
            : null;
    }

    protected function handleRecordCreation(array $data): Model
    {
        $sale = $this->sale() ?? abort(404);

        return $this->rescueDomain(fn () => app(CreateSaleReturnAction::class)->handle(
            sale: $sale,
            lines: array_values($data['lines'] ?? []),
            refundMethodId: filled($data['refund_method_id'] ?? null) ? (int) $data['refund_method_id'] : null,
            date: Carbon::parse($data['return_date']),
            reason: $data['reason'] ?? null,
        ));
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
