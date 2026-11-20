<?php

namespace App\Filament\Admin\Resources\AdvanceRequests\Pages;

use App\Filament\Admin\Resources\AdvanceRequests\AdvanceRequestResource;
use Filament\Resources\Pages\ManageRecords;

class ManageAdvanceRequests extends ManageRecords
{
    protected static string $resource = AdvanceRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [AdvanceRequestResource::requestAction()];
    }
}
