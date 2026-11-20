<?php

namespace App\Filament\Admin\Resources\Wallets\Transactions\Pages;

use App\Filament\Admin\Resources\Wallets\Transactions\WalletTransactionResource;
use Filament\Resources\Pages\ListRecords;

class ListWalletTransactions extends ListRecords
{
    protected static string $resource = WalletTransactionResource::class;
}
