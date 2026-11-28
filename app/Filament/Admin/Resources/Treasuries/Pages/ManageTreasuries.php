<?php

namespace App\Filament\Admin\Resources\Treasuries\Pages;

use App\Filament\Admin\Resources\Treasuries\TreasuryResource;
use App\Filament\Concerns\HandlesDomainExceptions;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageTreasuries extends ManageRecords
{
    use HandlesDomainExceptions;

    protected static string $resource = TreasuryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('New cash box'))
                ->using(fn (array $data) => $this->rescueDomain(fn () => TreasuryResource::createBox($data))),
        ];
    }
}
