<?php

namespace App\Filament\Admin\Resources\Suppliers\Pages;

use App\Domain\CRM\Actions\SaveContact;
use App\Filament\Admin\Resources\Suppliers\SupplierResource;
use App\Filament\Concerns\HandlesDomainExceptions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateSupplier extends CreateRecord
{
    use HandlesDomainExceptions;

    protected static string $resource = SupplierResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->rescueDomain(fn () => app(SaveContact::class)->handle($data));
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
