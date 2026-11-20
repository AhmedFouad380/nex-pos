<?php

namespace App\Filament\Admin\Resources\Products\Pages;

use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Models\Product;
use App\Filament\Admin\Resources\Products\ProductResource;
use App\Filament\Concerns\HandlesDomainExceptions;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditProduct extends EditRecord
{
    use HandlesDomainExceptions;

    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [ViewAction::make(), DeleteAction::make()];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return SaveProduct::formState($this->product($this->getRecord()));
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $this->rescueDomain(fn () => app(SaveProduct::class)->handle($data, $this->product($record)));
    }

    private function product(Model $record): Product
    {
        return $record instanceof Product ? $record : throw new \LogicException('EditProduct expects a Product record.');
    }
}
