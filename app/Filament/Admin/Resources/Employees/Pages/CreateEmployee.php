<?php

namespace App\Filament\Admin\Resources\Employees\Pages;

use App\Domain\CRM\Actions\SaveContact;
use App\Filament\Admin\Resources\Employees\EmployeeResource;
use App\Filament\Concerns\HandlesDomainExceptions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateEmployee extends CreateRecord
{
    use HandlesDomainExceptions;

    protected static string $resource = EmployeeResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->rescueDomain(fn () => app(SaveContact::class)->handle($data));
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
