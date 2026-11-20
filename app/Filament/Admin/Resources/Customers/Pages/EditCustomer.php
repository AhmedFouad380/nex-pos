<?php

namespace App\Filament\Admin\Resources\Customers\Pages;

use App\Domain\CRM\Actions\SaveContact;
use App\Domain\CRM\Models\Contact;
use App\Filament\Admin\Resources\Customers\CustomerResource;
use App\Filament\Concerns\HandlesDomainExceptions;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditCustomer extends EditRecord
{
    use HandlesDomainExceptions;

    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();

        return [...$data, 'user_ids' => $record instanceof Contact ? $record->users()->pluck('users.id')->all() : []];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $this->rescueDomain(fn () => app(SaveContact::class)->handle($data, $record instanceof Contact ? $record : null));
    }
}
