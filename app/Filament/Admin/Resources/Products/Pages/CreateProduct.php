<?php

namespace App\Filament\Admin\Resources\Products\Pages;

use App\Domain\Catalog\Actions\SaveProduct;
use App\Filament\Admin\Resources\Products\ProductResource;
use App\Filament\Concerns\HandlesDomainExceptions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateProduct extends CreateRecord
{
    use HandlesDomainExceptions;

    protected static string $resource = ProductResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->rescueDomain(fn () => app(SaveProduct::class)->handle($data));
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
