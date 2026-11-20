<?php

namespace App\Filament\Admin\Resources\Customers\Pages;

use App\Domain\CRM\Actions\SaveContact;
use App\Filament\Admin\Resources\Customers\CustomerResource;
use App\Filament\Concerns\HandlesDomainExceptions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCustomer extends CreateRecord
{
    use HandlesDomainExceptions;

    protected static string $resource = CustomerResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->rescueDomain(fn () => app(SaveContact::class)->handle($data));
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
