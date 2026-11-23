<?php

namespace App\Filament\Admin\Resources\Wallets\ServiceRates\Pages;

use App\Filament\Admin\Resources\Wallets\ServiceRates\WalletServiceRateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageWalletServiceRates extends ManageRecords
{
    protected static string $resource = WalletServiceRateResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
