<?php

namespace App\Filament\Admin\Resources\Wallets\Wallets\Pages;

use App\Filament\Admin\Resources\Wallets\Wallets\WalletResource;
use App\Filament\Admin\Widgets\BranchWallets;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageWallets extends ManageRecords
{
    protected static string $resource = WalletResource::class;

    protected function getHeaderWidgets(): array
    {
        return [BranchWallets::class];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
